import { useCallback, useEffect, useRef, useState } from 'react';
import { api, friendlyApiMessage, totalPagesOf } from './api.js';

/** Rows-per-page options: the screen's default plus 10 / 25 / 50. */
export const pageSizesFor = (preferred) => [...new Set([10, 25, 50, preferred])].sort((a, b) => a - b);

/** The value, held back until it has stopped changing for `delay` ms (search boxes). */
export function useDebouncedValue(value, delay = 300) {
  const [settled, setSettled] = useState(value);
  useEffect(() => {
    const timer = setTimeout(() => setSettled(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);
  return settled;
}

/**
 * Server-side list state for one screen: the API does the search, filters and
 * paging; this only remembers which page is showing.
 *
 * - `params` holds the screen's search text and filters. Any change to them
 *   goes back to page 1 in the same render, so no request is sent for the old
 *   page with the new filters.
 * - Only the newest request may update the screen; an older response that
 *   arrives late is dropped (and its request aborted).
 * - If rows were removed and the current page is now past the end, it steps
 *   back to the last real page instead of showing an empty page.
 * - `refreshKey` reloads the current page (e.g. after a save or delete).
 */
export function usePagedList(path, params, {
  perPage: initialPerPage = 25,
  enabled = true,
  refreshKey,
  errorMessage = 'Unable to load this list.',
} = {}) {
  const key = JSON.stringify(params ?? {});
  const [pageState, setPageState] = useState({ key, page: 1 });
  const [perPage, setPerPageState] = useState(initialPerPage);
  // The rows on screen and the page/size they were fetched for. While the next
  // page is loading the old rows stay up, and the "Showing X–Y" line keeps
  // describing them rather than the page that has not arrived yet.
  const [loaded, setLoaded] = useState(null);
  const [loading, setLoading] = useState(Boolean(enabled));
  const [error, setError] = useState(null);
  const [reloadKey, setReloadKey] = useState(0);
  const seq = useRef(0);
  const hasLoaded = useRef(false);
  const fallback = useRef(errorMessage);
  fallback.current = errorMessage;

  // A new search / filter set always starts on page 1, and that reset is
  // stored, not just derived: otherwise going back to an earlier filter set
  // (e.g. clearing the search) would find the old { key, page: 2 } entry and
  // jump back to page 2. Updating state while rendering is React's pattern for
  // "reset when an input changes"; React re-renders before anything is shown.
  if (pageState.key !== key) {
    setPageState({ key, page: 1 });
  }
  const page = pageState.key === key ? pageState.page : 1;
  const data = loaded ? loaded.payload : null;
  const totalPages = totalPagesOf(data);

  useEffect(() => {
    if (!enabled) {
      setLoading(false);
      return undefined;
    }
    const id = ++seq.current;
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    api.get(path, { query: { ...JSON.parse(key), page, perPage }, signal: controller.signal })
      .then((result) => {
        if (id !== seq.current) return;
        const payload = result || { items: [], total: 0 };
        const last = totalPagesOf(payload);
        if (page > last) {
          setPageState({ key, page: last });
          return;
        }
        hasLoaded.current = true;
        setLoaded({ payload, page, perPage });
        setError(null);
        setLoading(false);
      })
      .catch((err) => {
        if (id !== seq.current || err?.name === 'AbortError') return;
        if (!hasLoaded.current) setError(friendlyApiMessage(err, fallback.current));
        setLoading(false);
      });
    return () => controller.abort();
  }, [path, key, page, perPage, enabled, reloadKey, refreshKey]);

  const setPage = useCallback((next) => {
    setPageState((current) => {
      const from = current.key === key ? current.page : 1;
      const wanted = typeof next === 'function' ? next(from) : next;
      const bounded = Math.min(Math.max(1, Math.trunc(Number(wanted)) || 1), totalPages);
      return { key, page: bounded };
    });
  }, [key, totalPages]);

  const setPerPage = useCallback((value) => {
    setPerPageState(Math.max(1, Number(value) || initialPerPage));
    setPageState({ key, page: 1 });
  }, [key, initialPerPage]);

  const reload = useCallback(() => setReloadKey((n) => n + 1), []);

  return {
    data,
    rows: Array.isArray(data?.items) ? data.items : [],
    page: loaded ? loaded.page : page,
    perPage: loaded ? loaded.perPage : perPage,
    total: Number(data?.total) || 0,
    totalPages,
    loading,
    error,
    setPage,
    setPerPage,
    reload,
  };
}
