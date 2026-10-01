import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { PLANS } from '../data/mock.js';
import { api, friendlyApiMessage } from '../lib/api.js';
import { itemsOf } from '../lib/mappers.js';

/** One page is plenty for a console listing; the loaders below walk the rest. */
const PER_PAGE = 100;

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

/** Walks every page of an admin listing so counts and badges stay honest. */
const fetchAllPages = async (path, query = {}) => {
  const first = await api.get(path, { query: { ...query, page: 1, perPage: PER_PAGE } });
  const items = [...itemsOf(first)];
  const pages = Math.max(1, Number(first?.pages) || 1);
  for (let page = 2; page <= pages; page += 1) {
    // eslint-disable-next-line no-await-in-loop
    const next = await api.get(path, { query: { ...query, page, perPage: PER_PAGE } });
    items.push(...itemsOf(next));
  }
  return { payload: first, items };
};

/**
 * The System Administrator console, backed by the PHP API.
 *
 * Organizations, accounts, payments and the activity trail are live. Plan and
 * billing-cycle changes are not part of this phase and say so rather than
 * quietly editing a copy that the server never sees.
 */
export function useAdminApiStore(mock, snapshot) {
  const [orgs, setOrgs] = useState([]);
  const [users, setUsers] = useState([]);
  const [payments, setPayments] = useState([]);
  const [activity, setActivity] = useState([]);
  const [paymentTotals, setPaymentTotals] = useState({ collected: 0, refunded: 0, pending: 0, failed: 0 });
  const [adminLoading, setAdminLoading] = useState(false);
  const [adminError, setAdminError] = useState(null);
  const [flash, setFlash] = useState(null);
  const [mutating, setMutating] = useState(false);
  const loadGen = useRef(0);

  const user = useMemo(() => (snapshot ? mapAdminUser(snapshot) : null), [snapshot]);

  const notify = useCallback((message, tone = 'success') => setFlash({ message, tone }), []);

  const fail = useCallback((err, fallback) => {
    if (err?.name === 'AbortError') return null;
    const message = friendlyApiMessage(err, fallback);
    notify(message, err?.status === 403 || err?.status === 409 ? 'warning' : 'error');
    return null;
  }, [notify]);

  const load = useCallback(async () => {
    const gen = ++loadGen.current;
    setAdminLoading(true);
    setAdminError(null);
    try {
      const [orgResult, userResult, paymentResult, activityResult] = await Promise.allSettled([
        fetchAllPages('/admin/organizations', { sort: 'status' }),
        fetchAllPages('/admin/users'),
        fetchAllPages('/admin/payments'),
        api.get('/admin/activity', { query: { page: 1, perPage: 200 } }),
      ]);
      if (gen !== loadGen.current) return;

      if (orgResult.status === 'rejected') throw orgResult.reason;

      setOrgs(orgResult.value.items);
      setUsers(userResult.status === 'fulfilled' ? userResult.value.items : []);
      setPayments(paymentResult.status === 'fulfilled' ? paymentResult.value.items : []);
      setPaymentTotals(
        paymentResult.status === 'fulfilled'
          ? paymentResult.value.payload?.totals || { collected: 0, refunded: 0, pending: 0, failed: 0 }
          : { collected: 0, refunded: 0, pending: 0, failed: 0 }
      );
      setActivity(activityResult.status === 'fulfilled' ? itemsOf(activityResult.value) : []);

      const partial = [userResult, paymentResult, activityResult].find((r) => r.status === 'rejected');
      setAdminError(partial ? friendlyApiMessage(partial.reason, 'Some console data could not be loaded.') : null);
    } catch (err) {
      if (gen !== loadGen.current || err?.name === 'AbortError') return;
      setAdminError(friendlyApiMessage(err, 'Unable to load the administrator console.'));
    } finally {
      if (gen === loadGen.current) setAdminLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!snapshot) {
      setOrgs([]);
      setUsers([]);
      setPayments([]);
      setActivity([]);
      return undefined;
    }
    load();
    return () => { loadGen.current += 1; };
  }, [snapshot, load]);

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
    } finally {
      setMutating(false);
    }
  }, [load, notify]);

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

  const orgPayments = useCallback(
    (id, query = {}) => api.get(`/admin/organizations/${id}/payments`, { query: { perPage: 50, ...query } }),
    []
  );

  /** Throws on failure so the form can show the API's field messages inline. */
  const recordPayment = useCallback(async (organizationId, values) => {
    setMutating(true);
    try {
      const saved = await api.post(`/admin/organizations/${organizationId}/payments`, values);
      await load();
      notify(`Payment ${saved.reference} recorded.`);
      return saved;
    } finally {
      setMutating(false);
    }
  }, [load, notify]);

  // ---- not part of this phase --------------------------------------------

  const readOnly = (what) => () => notify(
    `${what} is managed on the server and is not editable from this console yet.`,
    'warning'
  );

  return {
    sessionKind: 'admin',
    user,
    adminLoading,
    adminError,
    retryAdmin: load,
    mutating,

    orgs,
    users,
    plans: PLANS,
    paymentTotals,
    db: {
      ...mock.db,
      youth: [],
      payments,
      activity,
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
    orgPayments,
    recordPayment,

    runCycle: readOnly('The subscription cycle'),
    setOrgPlan: readOnly('Plan assignment'),
    extendOrg: readOnly('Subscription extension'),
    cancelOrgSub: readOnly('Subscription cancellation'),
    toggleUser: readOnly('Account activation'),
  };
}
