import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api, fetchAllPages as fetchEveryPage, friendlyApiMessage } from '../lib/api.js';
import { itemsOf } from '../lib/mappers.js';


export const mapAdminUser = (snapshot) => {
  const user = snapshot?.user || {};
  const first = user.first_name ?? user.firstName ?? '';
  const last = user.last_name ?? user.lastName ?? '';
  return {
    id: user.id,
    email: user.email,
    firstName: first,
    lastName: last,
    name: `${first} ${last}`.trim() || user.email,
    role: 'system_admin',
    orgId: null,
    active: (user.status || 'active') === 'active',
    mustChangePassword: false,
    lastLogin: user.last_login_at ?? user.lastLoginAt ?? 'Never',
  };
};

/**
 * Walks every page of an admin listing. Only used for collections a screen
 * asks for (see ensureData); the list screens page on the server.
 */
const fetchAllPages = async (path, query = {}) => {
  const all = await fetchEveryPage(path, query, { perPage: 100 });
  return { payload: all, items: itemsOf(all) };
};

/**
 * The System Administrator console, backed by the PHP API.
 *
 * Organizations, accounts, the activity trail and subscription
 * changes (change plan, extend, cancel, run cycle) are all live API calls; the
 * plan catalog comes from the backend PlanLimits config.
 */
const EMPTY_ORG_COUNTS = Object.freeze({});

/**
 * Whole collections a screen can ask for with useStoreData / NeedsStoreData.
 * Only 'orgs' (the subscription board) still needs every row; everything else
 * is read a page at a time or by id.
 */
const ADMIN_GROUPS = ['orgs'];

export function useAdminApiStore(mock, snapshot) {
  const [orgs, setOrgs] = useState([]);
  // Organization status counts ({ all, pending, active, ... }) from a one-row
  // /admin/organizations request: the sidebar badge and console counters.
  const [orgCounts, setOrgCounts] = useState(EMPTY_ORG_COUNTS);
  const [readyGroups, setReadyGroups] = useState(() => new Set());
  const wantedGroups = useRef(new Set());
  const [adminLoading, setAdminLoading] = useState(false);
  const [adminError, setAdminError] = useState(null);
  const [flash, setFlash] = useState(null);
  const [mutating, setMutating] = useState(false);
  // Bumped after every console reload so server-paged screens refetch their page.
  const [dataVersion, setDataVersion] = useState(0);
  const loadGen = useRef(0);

  const user = useMemo(() => (snapshot ? mapAdminUser(snapshot) : null), [snapshot]);

  const notify = useCallback((message, tone = 'success') => setFlash({ message, tone }), []);

  const fail = useCallback((err, fallback) => {
    if (err?.name === 'AbortError') return null;
    const message = friendlyApiMessage(err, fallback);
    notify(message, err?.status === 403 || err?.status === 409 ? 'warning' : 'error');
    return null;
  }, [notify]);

  /**
   * Sign-in (and every save) reads only the organization status counts, one
   * row. Whole collections are fetched when a screen asks for them and are
   * then refreshed with each reload.
   */
  const load = useCallback(async () => {
    const gen = ++loadGen.current;
    setAdminLoading(true);
    setAdminError(null);
    const groups = [...wantedGroups.current];
    try {
      const [countResult, orgResult] = await Promise.allSettled([
        api.get('/admin/organizations', { query: { page: 1, perPage: 1 } }),
        groups.includes('orgs') ? fetchAllPages('/admin/organizations', { sort: 'status' }) : Promise.resolve(null),
      ]);
      if (gen !== loadGen.current) return;

      if (countResult.status === 'rejected') throw countResult.reason;
      setOrgCounts(countResult.value?.counts || EMPTY_ORG_COUNTS);

      if (orgResult.status === 'fulfilled' && orgResult.value) setOrgs(orgResult.value.items);
      if (orgResult.status === 'rejected') {
        setAdminError(friendlyApiMessage(orgResult.reason, 'The organization list could not be loaded.'));
      } else {
        setReadyGroups((current) => {
          const next = new Set(current);
          groups.forEach((group) => next.add(group));
          return next;
        });
      }
    } catch (err) {
      if (gen !== loadGen.current || err?.name === 'AbortError') return;
      setAdminError(friendlyApiMessage(err, 'Unable to load the administrator console.'));
    } finally {
      if (gen === loadGen.current) {
        setAdminLoading(false);
        setDataVersion((n) => n + 1);
      }
    }
  }, []);

  // The sign-in load and a screen asking for a collection usually happen in the
  // same commit (the screen's effect runs first); one queued load serves both.
  const pendingLoad = useRef(null);
  const scheduleLoad = useCallback(() => {
    if (!pendingLoad.current) {
      pendingLoad.current = Promise.resolve().then(() => {
        pendingLoad.current = null;
        return load();
      });
    }
    return pendingLoad.current;
  }, [load]);

  useEffect(() => {
    if (!snapshot) {
      setOrgs([]);
      setOrgCounts(EMPTY_ORG_COUNTS);
      wantedGroups.current = new Set();
      setReadyGroups(new Set());
      return undefined;
    }
    scheduleLoad();
    return () => { loadGen.current += 1; };
  }, [snapshot, scheduleLoad]);

  /** A screen needs whole collections: fetch the ones not asked for before. */
  const ensureData = useCallback((keys) => {
    const missing = keys.filter((key) => ADMIN_GROUPS.includes(key) && !wantedGroups.current.has(key));
    if (missing.length === 0 || !snapshot) return;
    missing.forEach((key) => wantedGroups.current.add(key));
    scheduleLoad();
  }, [scheduleLoad, snapshot]);

  const dataReady = useCallback(
    (keys) => keys.every((key) => !ADMIN_GROUPS.includes(key) || readyGroups.has(key)),
    [readyGroups]
  );

  const run = useCallback(async (fn, successMessage) => {
    setMutating(true);
    try {
      const result = await fn();
      await load();
      if (successMessage) notify(successMessage);
      return result;
    } catch (err) {
      fail(err);
      return null;
    } finally {
      setMutating(false);
    }
  }, [fail, load, notify]);

  // ---- organizations ------------------------------------------------------

  const approveOrg = (id) => run(
    () => api.post(`/admin/organizations/${id}/approve`, {}),
    'Organization approved. Its Premium trial has started.'
  );

  const setOrgStatus = (id, status, note = '') => run(
    () => api.post(`/admin/organizations/${id}/status`, { status, note }),
    'Organization status updated.'
  );

  const orgDetail = useCallback((id) => api.get(`/admin/organizations/${id}`), []);

  /** Throws on failure so the form can pin the API's field messages to its inputs. */
  const createOrg = useCallback(async (values) => {
    setMutating(true);
    try {
      const created = await api.post('/admin/organizations', values);
      await load();
      notify(`${created.name} was created and its Premium trial has started.`);
      return created;
    } catch (err) {
      fail(err, 'Unable to create the organization.');
      throw err;
    } finally {
      setMutating(false);
    }
  }, [fail, load, notify]);

  /**
   * One filtered page straight from the database. The console list uses this so
   * search, status and paging are resolved by SQL rather than over a snapshot,
   * while `orgs` above stays whole for the sidebar badge and the dashboard.
   */
  const queryOrganizations = useCallback(
    (query = {}) => api.get('/admin/organizations', { query }),
    []
  );

  // ---- per-organization drill-downs ---------------------------------------

  const orgActivity = useCallback(
    (id, query = {}) => api.get(`/admin/organizations/${id}/activity`, { query: { perPage: 50, ...query } }),
    []
  );

  // ---- subscriptions -------------------------------------------------------

  /** GET /admin/organizations/{id}/subscription: plan, status, usage, limits and the plan catalog. */
  const orgSubscription = useCallback((id) => api.get(`/admin/organizations/${id}/subscription`), []);

  /**
   * Runs one subscription action, reloads the console on success and returns
   * the server response. Throws the ApiError so the dialog can show the
   * server's own message; nothing is reported as done unless the API said so.
   */
  const subscriptionAction = useCallback(async (request, successMessage) => {
    setMutating(true);
    try {
      const result = await request();
      await load();
      notify(successMessage(result), result?.warning ? 'warning' : 'success');
      return result;
    } finally {
      setMutating(false);
    }
  }, [load, notify]);

  const setOrgPlan = useCallback((id, plan, cycle) => subscriptionAction(
    () => api.post(`/admin/organizations/${id}/subscription/plan`, { plan, cycle }),
    (result) => result?.warning || `Plan changed to ${result?.subscription?.planName}.`
  ), [subscriptionAction]);

  /** `value` is a number of days or { days } / { expiresAt: 'YYYY-MM-DD' }. */
  const extendOrg = useCallback((id, value) => subscriptionAction(
    () => api.post(`/admin/organizations/${id}/subscription/extend`, typeof value === 'object' && value !== null ? value : { days: Number(value) }),
    (result) => `Subscription extended to ${result?.subscription?.expiresAt}.`
  ), [subscriptionAction]);

  const cancelOrgSub = useCallback((id, reason = '') => subscriptionAction(
    () => api.post(`/admin/organizations/${id}/subscription/cancel`, { reason }),
    () => 'Subscription cancelled. Free plan limits now apply; records are kept.'
  ), [subscriptionAction]);

  const runCycle = useCallback(async () => {
    try {
      return await subscriptionAction(
        () => api.post('/admin/subscriptions/run-cycle', {}),
        (result) => (result?.expired
          ? `${result.expired} subscription(s) past their end date were marked expired.`
          : 'No subscriptions were past their end date.')
      );
    } catch (err) {
      fail(err, 'Unable to run the subscription cycle.');
      return null;
    }
  }, [fail, subscriptionAction]);

  return {
    sessionKind: 'admin',
    user,
    adminLoading,
    adminError,
    retryAdmin: load,
    dataVersion,
    mutating,
    ensureData,
    dataReady,

    // Whole list only after a screen called useStoreData('orgs').
    orgs,
    orgCounts,
    db: {
      ...mock.db,
      youth: [],
      payments: [],
      activity: [],
    },

    flash,
    clearFlash: () => setFlash(null),
    notify,

    approveOrg,
    setOrgStatus,
    orgDetail,
    createOrg,
    queryOrganizations,
    orgActivity,

    orgSubscription,
    runCycle,
    setOrgPlan,
    extendOrg,
    cancelOrgSub,
    queryUsers: useCallback(
      (query = {}) => api.get('/admin/users', { query }),
      []
    ),

    toggleUser: (id, active) => run(
      () => api.post(`/admin/users/${id}/status`, { status: active ? 'inactive' : 'active' }),
      active ? 'Account deactivated.' : 'Account activated.'
    ),
    resetUserPassword: (id) => run(
      () => api.post(`/admin/users/${id}/reset-password`, {}),
      'A temporary password was generated. The user must change it on next login.'
    ),
  };
}
