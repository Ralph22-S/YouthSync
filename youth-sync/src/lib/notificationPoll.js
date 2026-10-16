import { useEffect, useRef } from 'react';

/** How often an open session asks the server for notices. */
export const NOTIFICATION_POLL_MS = 12000;

/** Keep the first row for each id. Later copies from the same response are dropped. */
export function uniqueById(rows) {
  const seen = new Set();
  const unique = [];
  for (const row of rows || []) {
    const id = row?.id;
    if (id === undefined || id === null || seen.has(id)) continue;
    seen.add(id);
    unique.push(row);
  }
  return unique;
}

/**
 * Call `tick` on an interval while `active` is true.
 * A tick still running is not started again. The timer is cleared on logout
 * or when the screen unmounts. Hidden tabs wait until they are visible.
 * `tick` should catch its own request errors.
 */
export function useNotificationPoll(active, tick) {
  const tickRef = useRef(tick);
  tickRef.current = tick;

  useEffect(() => {
    if (!active) return undefined;
    let stopped = false;
    let running = false;
    let timer = 0;

    const schedule = () => {
      if (stopped) return;
      timer = window.setTimeout(run, NOTIFICATION_POLL_MS);
    };

    const run = async () => {
      if (stopped) return;
      if (document.hidden || running) {
        schedule();
        return;
      }
      running = true;
      try {
        await tickRef.current();
      } catch {
        // The caller decides how to report a failed poll. Polling itself stays quiet.
      } finally {
        running = false;
        schedule();
      }
    };

    schedule();
    const onVisible = () => {
      if (stopped || document.hidden || running) return;
      window.clearTimeout(timer);
      run();
    };
    document.addEventListener('visibilitychange', onVisible);
    return () => {
      stopped = true;
      window.clearTimeout(timer);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [active]);
}
