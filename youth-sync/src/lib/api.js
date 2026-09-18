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

export const API_BASE_URL = String(
  import.meta.env.VITE_API_BASE_URL || 'http://localhost/YouthSync_UI_Refresh/api'
).replace(/\/$/, '');

/** Default ceiling so a stuck Apache/PHP request cannot leave fetch pending forever. */
export const API_TIMEOUT_MS = 15000;

const attachAbortListener = (signal, onAbort) => {
  if (!signal) return () => {};
  if (signal.aborted) {
    onAbort();
    return () => {};
  }
  signal.addEventListener('abort', onAbort, { once: true });
  return () => signal.removeEventListener('abort', onAbort);
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

  if (body !== undefined && method !== 'GET' && method !== 'HEAD') {
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
      attachAbortListener(signal, () => {
        const abortErr = new Error('Request was cancelled.');
        abortErr.name = 'AbortError';
        reject(abortErr);
      });
    })
    : null;

  // Do not pass AbortSignal into fetch: some Chromium embeds report abort as
  // TypeError "Failed to fetch", which was shown as "Apache is not running".
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

export const api = {
  get: (path, options) => apiRequest('GET', path, options),
  post: (path, body, options) => apiRequest('POST', path, { ...options, body }),
  put: (path, body, options) => apiRequest('PUT', path, { ...options, body }),
  patch: (path, body, options) => apiRequest('PATCH', path, { ...options, body }),
  delete: (path, options) => apiRequest('DELETE', path, options),
};

export const friendlyApiMessage = (err, fallback = 'Something went wrong.') => {
  if (!err) return fallback;
  if (typeof err === 'string') return err;
  if (err.code === 'TIMEOUT') return err.message || 'The YouthSync API did not respond in time. Please try again.';
  return err.message || fallback;
};
