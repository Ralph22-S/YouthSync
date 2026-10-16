/**
 * Single HTTP client for the PHP API.
 * Session cookies are sent with credentials: "include". The session id is never stored in JS.
 */

export class ApiError extends Error {
  constructor(message, { status = 0, code = 'REQUEST_FAILED', details = null, fields = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.details = details;
    this.fields = fields;
  }
}

const sameOriginApiBase = (raw) => {
  const trimmed = String(raw || '').replace(/\/$/, '');
  if (!/^https?:\/\//i.test(trimmed)) {
    return trimmed || '/YouthSync/api';
  }
  try {
    const parsed = new URL(trimmed);
    const host = parsed.hostname.toLowerCase();
    const path = parsed.pathname.replace(/\/$/, '');
    if ((host === 'localhost' || host === '127.0.0.1') && path === '/YouthSync/api') {
      return '/YouthSync/api';
    }
    if ((host === 'localhost' || host === '127.0.0.1') && path === '/YouthSync_UI_Refresh/api') {
      return '/YouthSync/api';
    }
  } catch {
    return '/YouthSync/api';
  }
  return trimmed;
};

export const API_BASE_URL = sameOriginApiBase(
  import.meta.env.VITE_API_BASE_URL || '/YouthSync/api'
);

/** Default ceiling so a stuck Apache/PHP request cannot leave fetch pending forever. */
export const API_TIMEOUT_MS = 15000;

export const mediaSrc = (file) => {
  if (!file) return '';
  if (file.dataUrl) return file.dataUrl;
  if (typeof file.url === 'string' && file.url) return joinUrl(file.url);
  return '';
};

const joinUrl = (path) => {
  if (!path) return API_BASE_URL;
  if (/^https?:\/\//i.test(path)) return path;
  return `${API_BASE_URL}${path.startsWith('/') ? path : `/${path}`}`;
};

const buildQuery = (query) => {
  if (!query || typeof query !== 'object') return '';
  const params = new URLSearchParams();
  Object.entries(query).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return;
    params.set(key, String(value));
  });
  const encoded = params.toString();
  return encoded ? `?${encoded}` : '';
};

const parseBody = async (response) => {
  const text = await response.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return { raw: text };
  }
};

const toApiError = (response, payload) => {
  const error = payload?.error && typeof payload.error === 'object' ? payload.error : {};
  const fields = error.fields || payload?.fields || null;
  const message = error.message
    || payload?.message
    || (response.status === 401 ? 'Authentication is required.' : `Request failed (${response.status}).`);
  return new ApiError(message, {
    status: response.status,
    code: error.code || payload?.code || `HTTP_${response.status}`,
    details: payload,
    fields,
  });
};

export async function apiRequest(method, path, { query, body, signal, timeoutMs = API_TIMEOUT_MS } = {}) {
  const url = `${joinUrl(path)}${buildQuery(query)}`;
  const headers = { Accept: 'application/json' };
  const init = { method, credentials: 'include', headers };

  if (body instanceof FormData) {
    init.body = body;
  } else if (body !== undefined && method !== 'GET' && method !== 'HEAD') {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }

  const timeoutError = new ApiError('The YouthSync API did not respond in time. Please try again.', {
    code: 'TIMEOUT',
  });

  const timeout = Number(timeoutMs);
  let timer = null;
  const timeoutPromise = Number.isFinite(timeout) && timeout > 0
    ? new Promise((_, reject) => {
      timer = setTimeout(() => reject(timeoutError), timeout);
    })
    : null;

  const abortPromise = signal
    ? new Promise((_, reject) => {
      if (signal.aborted) {
        const abortErr = new Error('Request was cancelled.');
        abortErr.name = 'AbortError';
        reject(abortErr);
        return;
      }
      signal.addEventListener('abort', () => {
        const abortErr = new Error('Request was cancelled.');
        abortErr.name = 'AbortError';
        reject(abortErr);
      }, { once: true });
    })
    : null;

  // Do not pass AbortSignal into fetch. Chrome can surface abort as TypeError
  // "Failed to fetch"; the bootstrap catch then sets sessionReady with no user
  // and AdminLayout redirects to /login even though the PHP session is valid.
  let response;
  try {
    response = await Promise.race(
      [fetch(url, init), timeoutPromise, abortPromise].filter(Boolean)
    );
  } catch (err) {
    if (err === timeoutError || err?.code === 'TIMEOUT') throw timeoutError;
    if (err?.name === 'AbortError' || signal?.aborted) {
      const abortErr = err?.name === 'AbortError' ? err : new Error('Request was cancelled.');
      abortErr.name = 'AbortError';
      throw abortErr;
    }
    throw new ApiError('Unable to reach the YouthSync API. Check that Apache is running.', {
      code: 'NETWORK_ERROR',
    });
  } finally {
    if (timer) clearTimeout(timer);
  }

  const payload = await parseBody(response);

  if (!response.ok) {
    throw toApiError(response, payload);
  }

  if (payload && typeof payload === 'object' && Object.prototype.hasOwnProperty.call(payload, 'success')) {
    if (!payload.success) throw toApiError(response, payload);
    return payload.data;
  }

  return payload;
}

export async function apiBlob(path) {
  const response = await fetch(joinUrl(path), {
    credentials: 'include',
    headers: { Accept: '*/*' },
  });
  if (!response.ok) {
    const payload = await parseBody(response);
    throw toApiError(response, payload);
  }
  const blob = await response.blob();
  const type = response.headers.get('Content-Type') || blob.type || 'application/octet-stream';
  return new Blob([await blob.arrayBuffer()], { type });
}

export const api = {
  get: (path, options) => apiRequest('GET', path, options),
  post: (path, body, options) => apiRequest('POST', path, { ...options, body }),
  put: (path, body, options) => apiRequest('PUT', path, { ...options, body }),
  patch: (path, body, options) => apiRequest('PATCH', path, { ...options, body }),
  delete: (path, options) => apiRequest('DELETE', path, options),
  upload: (path, file, field = 'file') => {
    const body = new FormData();
    body.append(field, file, file.name || 'upload');
    return apiRequest('POST', path, { body });
  },
};

/** Page count from a list payload (`totalPages`, or the older `pages`). */
export const totalPagesOf = (payload) => Math.max(1, Number(payload?.totalPages ?? payload?.pages) || 1);

/**
 * Every row of a list endpoint, page by page. Only for data that genuinely
 * needs the whole collection (the SK store behind pickers, detail pages and
 * badges; console counts). List screens page on the server instead.
 * Stops after `maxPages` pages and reports `truncated` rather than hanging.
 */
export async function fetchAllPages(path, query = {}, { perPage = 100, maxPages = 50, signal } = {}) {
  const first = await api.get(path, { query: { ...query, page: 1, perPage }, signal });
  const rowsOf = (payload) => (Array.isArray(payload?.items) ? payload.items : []);
  const pages = totalPagesOf(first);
  const last = Math.min(pages, maxPages);
  const rest = [];
  for (let page = 2; page <= last; page += 1) {
    rest.push(api.get(path, { query: { ...query, page, perPage }, signal }));
  }
  const more = await Promise.all(rest);
  const items = [...rowsOf(first)];
  more.forEach((payload) => items.push(...rowsOf(payload)));
  return { ...first, items, truncated: pages > maxPages };
}

export const friendlyApiMessage = (err, fallback = 'Something went wrong.') => {
  if (!err) return fallback;
  if (typeof err === 'string') return err;
  if (err.code === 'TIMEOUT') return err.message || 'The YouthSync API did not respond in time. Please try again.';
  return err.message || fallback;
};

const filenameFromDisposition = (header) => {
  if (!header) return '';
  const star = /filename\*\s*=\s*UTF-8''([^;]+)/i.exec(header);
  if (star) {
    try { return decodeURIComponent(star[1].trim()); } catch { /* fall through */ }
  }
  const plain = /filename\s*=\s*"?([^";]+)"?/i.exec(header);
  return plain ? plain[1].trim() : '';
};

/**
 * Authenticated file download (session cookie). Resolves to the file blob and
 * the server-provided filename; a JSON error response becomes an ApiError.
 */
export async function apiDownload(path, { query, timeoutMs = 60000 } = {}) {
  const url = `${joinUrl(path)}${buildQuery(query)}`;
  const timeoutError = new ApiError('The export took too long. Please try again.', { code: 'TIMEOUT' });
  let timer = null;
  let response;
  try {
    response = await Promise.race([
      fetch(url, { method: 'GET', credentials: 'include', headers: { Accept: '*/*' } }),
      new Promise((_, reject) => { timer = setTimeout(() => reject(timeoutError), timeoutMs); }),
    ]);
  } catch (err) {
    if (err === timeoutError) throw timeoutError;
    throw new ApiError('Unable to reach the YouthSync API. Check that Apache is running.', { code: 'NETWORK_ERROR' });
  } finally {
    if (timer) clearTimeout(timer);
  }
  const contentType = response.headers.get('Content-Type') || '';
  if (!response.ok || contentType.includes('application/json')) {
    const payload = await parseBody(response);
    throw toApiError(response, payload);
  }
  const blob = await response.blob();
  return {
    blob,
    filename: filenameFromDisposition(response.headers.get('Content-Disposition')),
    contentType,
  };
}

/** Saves a blob through a temporary object URL and an <a download> click. */
export function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename || 'download';
  link.rel = 'noopener';
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
