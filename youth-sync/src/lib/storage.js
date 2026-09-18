/**
 * localStorage persistence for the system.
 * Bump VERSION when the seeded shape changes so old saves are discarded cleanly.
 */
const KEY = 'youthsync.state';
const VERSION = 5;

export function loadState() {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (parsed.version !== VERSION) return null;
    return parsed.state;
  } catch {
    return null;
  }
}

function stripSecrets(value) {
  if (Array.isArray(value)) return value.map(stripSecrets);
  if (value && typeof value === 'object') {
    const out = {};
    Object.entries(value).forEach(([key, nested]) => {
      if (['password', 'password_hash', 'temporaryPassword', 'sessionId', 'PHPSESSID'].includes(key)) return;
      out[key] = stripSecrets(nested);
    });
    return out;
  }
  return value;
}

export function saveState(state) {
  try {
    localStorage.setItem(KEY, JSON.stringify({ version: VERSION, state: stripSecrets(state) }));
    return true;
  } catch (error) {
    // Photos are stored as data URLs, so the 5 MB quota is the realistic failure here.
    if (error?.name === 'QuotaExceededError') {
      console.warn('Storage full — the newest changes were not saved. Clear records to continue.');
    }
    return false;
  }
}

export function clearState() {
  localStorage.removeItem(KEY);
}
