import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader, subLabel } from '../../components/layouts.jsx';
import { Badge, BarChart, Confirm, Empty, Field, FormErrors, Modal, NeedsStoreData, Pagination, Section, Skeleton, Stat, Table } from '../../components/ui.jsx';
import { MUNICIPALITIES, planByCode, SUBSCRIPTION_STATUSES } from '../../data/mock.js';
import { CONTACT_LENGTH, digitsOnly } from '../../lib/validation.js';
import { api, apiDownload, friendlyApiMessage, saveBlob } from '../../lib/api.js';
import { itemsOf } from '../../lib/mappers.js';
import { pageSizesFor, useDebouncedValue, usePagedList } from '../../lib/usePagedList.js';
import { useNotificationPoll } from '../../lib/notificationPoll.js';
import { methodText, PaymentStatusBadge, pesoAmount, VerificationBadge } from '../../components/payments.jsx';

const isCount = (value) => typeof value === 'number' && Number.isFinite(value);
const showCount = (value) => (isCount(value) ? value.toLocaleString() : '-');
const peso = (value) => (isCount(value) ? `₱${value.toLocaleString()}` : '-');
const titleCase = (key) => {
  const text = String(key).replace(/_/g, ' ');
  return text.charAt(0).toUpperCase() + text.slice(1);
};

const planSeries = (series) => (series && typeof series === 'object'
  ? Object.fromEntries(Object.entries(series).map(([code, value]) => [planByCode(code)?.name || titleCase(code), value]))
  : null);

function YouthRecordsPerOrganization({ rows }) {
  const list = Array.isArray(rows) ? rows : [];
  const [organizationId, setOrganizationId] = useState('');
  const selected = list.find((row) => String(row.organizationId) === organizationId) || list[0] || null;

  return (
    <div className="card p-4">
      <h3 className="text-sm font-semibold text-navy-800">Youth records per organization</h3>
      {list.length === 0 ? (
        <p className="mt-3 text-sm text-[#7A889B]">No organizations yet.</p>
      ) : (
        <>
          <label className="mt-3 block text-xs text-[#5A6C82]" htmlFor="youth-records-org">Organization</label>
          <select id="youth-records-org" className="field mt-1" value={selected ? String(selected.organizationId) : ''}
            onChange={(e) => setOrganizationId(e.target.value)}>
            {list.map((row) => (
              <option key={row.organizationId} value={row.organizationId}>{row.name}</option>
            ))}
          </select>
          {selected && (
            <div className="mt-3">
              <div className="flex items-baseline justify-between text-xs text-[#5A6C82]">
                <span>{selected.name}</span>
                <span className="font-semibold text-navy-900">{showCount(selected.youth)}</span>
              </div>
              <div className="mt-1 h-2 w-full rounded-full bg-[#EDF0F4]">
                <div className="h-2 rounded-full" style={{
                  width: `${Math.max(3, Math.round(((selected.youth || 0) / Math.max(1, ...list.map((row) => row.youth || 0))) * 100))}%`,
                  background: '#265280',
                }} />
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}

/**
 * System overview. Every figure comes from GET /admin/dashboard (SQL aggregates
 * across all organizations). A value the API did not return is shown as "-".
 */
export function AdminDashboard() {
  const { dataVersion, runCycle } = useStore();
  const [stats, setStats] = useState(null);
  // The newest 8 entries of the trail: one small page, not the whole log.
  const [recent, setRecent] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const request = useRef(null);

  const load = useCallback(async () => {
    request.current?.abort();
    const controller = new AbortController();
    request.current = controller;
    setLoading(true);
    setError(null);
    try {
      const [statsResult, recentResult] = await Promise.allSettled([
        api.get('/admin/dashboard', { signal: controller.signal }),
        api.get('/admin/activity', { query: { page: 1, perPage: 8 }, signal: controller.signal }),
      ]);
      if (controller.signal.aborted) return;
      if (recentResult.status === 'fulfilled') setRecent(itemsOf(recentResult.value));
      else if (recentResult.reason?.name !== 'AbortError') setRecent((current) => current ?? false);
      if (statsResult.status === 'rejected') throw statsResult.reason;
      const payload = statsResult.value;
      setStats(payload && typeof payload === 'object' ? payload : null);
    } catch (err) {
      if (err?.name === 'AbortError' || controller.signal.aborted) return;
      setError(friendlyApiMessage(err, 'Unable to load the system statistics.'));
    } finally {
      if (request.current === controller) setLoading(false);
    }
  }, []);

  // Reload whenever the console reloads after a save (approve, status change, ...).
  useEffect(() => {
    if (dataVersion === 0) return undefined;
    load();
    return () => request.current?.abort();
  }, [load, dataVersion]);

  const o = stats?.organizations;
  const pendingCount = o?.pending;

  const cards = [
    ['SK organizations', showCount(o?.total)],
    ['Active subscriptions', showCount(o?.activeSubscriptions), 'Active or trial'],
    ['Expiring in 3 days', showCount(o?.expiringSoon), 'Active or trial, ends within 3 days'],
    ['Expired', showCount(o?.expired)],
    ['Free plan', showCount(o?.freePlan)],
    ['Trial', showCount(o?.trial)],
    ['Basic', showCount(o?.basicActive), 'Active (paid) subscriptions'],
    ['Premium', showCount(o?.premiumActive), 'Active (paid) subscriptions'],
    ['Total users', showCount(stats?.users?.total), isCount(stats?.users?.active) ? `${stats.users.active.toLocaleString()} active` : undefined],
    ['Pending registrations', showCount(pendingCount)],
    ['Verified online revenue', peso(stats?.payments?.onlineVerified), 'Verified by PayMongo, net of refunds'],
    ['Youth records', showCount(stats?.youth?.active), isCount(stats?.youth?.archived) ? `${stats.youth.archived.toLocaleString()} archived` : undefined],
    ['Programs', showCount(stats?.programs?.programs)],
    ['Events', showCount(stats?.programs?.events)],
    ['Applications', showCount(stats?.applications?.total)],
  ];

  return (
    <>
      <PageHeader title="System overview" subtitle="Every SK organization, subscription, and payment on this deployment."
        actions={<>
          <button type="button" className="btn btn-ghost" onClick={load} disabled={loading}>{loading ? 'Refreshing...' : 'Refresh'}</button>
          <Confirm message={RUN_CYCLE_MESSAGE} onConfirm={runCycle}>
            Run subscription cycle
          </Confirm>
          <Link to="/admin/organizations/new" className="btn btn-primary">Add organization</Link>
        </>} />

      {error && (
        <div className="mb-4 rounded-card border border-[#F2DCA8] bg-sun-100 px-4 py-3 text-sm text-[#7A5A05]" role="alert">
          {stats ? `Could not refresh the statistics: ${error} Showing the last figures loaded.` : `The system statistics could not be loaded: ${error}`}
          <button type="button" className="btn btn-ghost btn-sm ml-3" onClick={load} disabled={loading}>Try again</button>
        </div>
      )}

      {!stats && !error ? <Skeleton rows={4} /> : (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {cards.map(([label, value, hint]) => <Stat key={label} label={label} value={value} hint={hint} />)}
        </div>
      )}

      {isCount(pendingCount) && pendingCount > 0 && (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-card border border-[#F2DCA8] bg-sun-100 px-4 py-3 text-sm text-[#7A5A05]">
          <span>{pendingCount} organization(s) are waiting for verification.</span>
          <Link to="/admin/organizations?status=pending" className="btn btn-accent">Review registrations</Link>
        </div>
      )}

      {stats && (
        <div className="mt-6 grid gap-4 lg:grid-cols-2">
          <BarChart title="Organizations per plan" series={planSeries(o?.byPlan) || {}} />
          <BarChart title="Subscription status" color="#E0A21A"
            series={o?.bySubscriptionStatus ? Object.fromEntries(Object.entries(o.bySubscriptionStatus).map(([k, v]) => [titleCase(k), v])) : {}} />
          <BarChart title="Verified online revenue per plan (₱, PayMongo)" color="#12664A" empty="No verified online payments yet."
            series={planSeries(stats.payments?.onlineVerifiedByPlan) || {}} />
          <YouthRecordsPerOrganization rows={stats.youthPerOrganization} />
        </div>
      )}

      <Section title="Recent activity across organizations">
        <div className="mt-2">
          {recent === null && <p className="mt-3 text-sm text-[#7A889B]">Loading activity…</p>}
          {recent === false && <p className="mt-3 text-sm text-[#7A889B]">Recent activity could not be loaded.</p>}
          {Array.isArray(recent) && recent.length === 0 && <p className="mt-3 text-sm text-[#7A889B]">No activity recorded yet.</p>}
          {(recent || []).map((log) => (
            <div key={log.id} className="mt-3 border-b border-[#EEF1F4] pb-2 last:border-0">
              <p className="text-sm text-navy-900">{log.description}</p>
              <p className="text-xs text-[#8391A4]">{log.user} · {log.at}</p>
            </div>
          ))}
        </div>
      </Section>
    </>
  );
}

export function Organizations() {
  const { approveOrg, dataVersion } = useStore();
  const [search, setSearch] = useState('');
  const query = useDebouncedValue(search.trim(), 300);
  const [searchParams] = useSearchParams();
  const [status, setStatus] = useState(searchParams.get('status') || '');

  // Keep the filter in step when the dashboard links here with ?status=pending.
  useEffect(() => { setStatus(searchParams.get('status') || ''); }, [searchParams]);

  // GET /admin/organizations: search, status and paging on the server, in the
  // console's usual order (pending first, then by name).
  const list = usePagedList('/admin/organizations', {
    q: query || undefined,
    status: status || undefined,
    sort: 'status',
  }, { perPage: 25, enabled: dataVersion > 0, refreshKey: dataVersion, errorMessage: 'Unable to load organizations.' });
  const pending = list.data?.counts?.pending;
  const filtered = Boolean(query || status);
  const reset = () => { setSearch(''); setStatus(''); };

  return (
    <>
      <PageHeader title="SK organizations"
        subtitle={list.data ? `${list.total.toLocaleString()} organization(s) · ${showCount(pending)} awaiting verification` : 'Loading organizations…'}
        actions={<Link to="/admin/organizations/new" className="btn btn-primary">Add organization</Link>} />

      <div className="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="sm:col-span-2">
          <Field label="Search"><input className="field" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name, barangay, municipality, or chairperson" /></Field>
        </div>
        <Field label="Status">
          <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All</option>
            {['pending', 'active', 'suspended', 'rejected'].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
          </select>
        </Field>
        <div className="flex items-end"><button type="button" className="btn btn-ghost w-full" onClick={reset}>Reset filters</button></div>
      </div>

      {list.error ? (
        <Empty title="Organizations could not be loaded" body={list.error}>
          <button type="button" className="btn btn-primary" onClick={list.reload}>Retry</button>
        </Empty>
      ) : !list.data ? (
        <Skeleton rows={6} />
      ) : list.rows.length === 0 ? (
        filtered
          ? <Empty title="No results found" body="No organizations match this search or status."><button type="button" className="btn btn-ghost" onClick={reset}>Reset filters</button></Empty>
          : <Empty title="No organizations yet" body="Organizations appear here once they register or are added." />
      ) : (
        <>
          {list.loading && <p className="mb-2 text-sm text-[#5A6C82]">Loading organizations…</p>}
          <Table head={['Organization', 'Chairperson', 'Plan', 'Subscription', 'Users', 'Status', '']} minWidth={860}>
            {list.rows.map((org) => (
              <tr key={org.id} className="hover:bg-shell">
                <td className="td">
                  <span className="font-medium text-navy-900">{org.name}</span>
                  <span className="block text-xs text-[#8391A4]">{org.email}</span>
                </td>
                <td className="td">{org.chairperson}</td>
                <td className="td">{planByCode(org.plan).name}</td>
                <td className="td">
                  <Badge value={subLabel(org)} />
                  <span className="block text-xs text-[#8391A4]">{remainingText(org)}</span>
                </td>
                <td className="td">{showCount(org.userCount)}</td>
                <td className="td"><Badge value={org.status} /></td>
                <td className="td">
                  <div className="flex gap-2">
                    <Link to={`/admin/organizations/${org.id}`} className="btn btn-ghost btn-sm">Open</Link>
                    {org.status === 'pending' && (
                      <Confirm className="btn btn-accent btn-sm" message="Approve this organization and start its 7-day Premium trial?"
                        onConfirm={() => approveOrg(org.id)}>Approve</Confirm>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </Table>
          <Pagination page={list.page} pageSize={list.perPage} total={list.total} totalPages={list.totalPages}
            onPage={list.setPage} loading={list.loading} noun="organizations"
            pageSizes={pageSizesFor(25)} onPageSize={list.setPerPage} />
        </>
      )}
    </>
  );
}

/**
 * One organization by id: GET /admin/organizations/{id} (with its team) plus
 * the newest page of its activity, rather than filtering console-wide copies
 * of every user and log entry. Online (PayMongo) payments are on the Payments page.
 */
export function OrganizationDetail() {
  const { id } = useParams();
  const store = useStore();
  if (typeof store.orgDetail !== 'function') return <SnapshotOrganizationDetail id={id} />;
  return <LiveOrganizationDetail id={id} />;
}

const ORG_ACTIVITY_PAGE = 50;

function LiveOrganizationDetail({ id }) {
  const { orgDetail, orgActivity, dataVersion, approveOrg, setOrgStatus } = useStore();
  const [state, setState] = useState({ id: null, org: null, activity: null, error: null, missing: false });
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    if (dataVersion === 0) return undefined;
    let cancelled = false;
    Promise.allSettled([
      orgDetail(id),
      orgActivity(id, { page: 1, perPage: ORG_ACTIVITY_PAGE }),
    ]).then(([orgResult, activityResult]) => {
      if (cancelled) return;
      if (orgResult.status === 'rejected') {
        const err = orgResult.reason;
        setState({ id, org: null, activity: null, missing: err?.status === 404,
          error: friendlyApiMessage(err, 'Unable to load this organization.') });
        return;
      }
      setState({
        id,
        org: orgResult.value,
        activity: activityResult.status === 'fulfilled' ? activityResult.value : null,
        error: null,
        missing: false,
      });
    });
    return () => { cancelled = true; };
  }, [id, dataVersion, attempt, orgDetail, orgActivity]);

  if (state.id !== id || (!state.org && !state.error)) return <Skeleton rows={6} />;
  if (state.missing) return <Empty title="Organization not found" body="That organization is not on this deployment." />;
  if (!state.org) {
    return (
      <div className="card p-5 text-sm text-[#7A5A05]" role="alert">
        {state.error}
        <button type="button" className="btn btn-ghost btn-sm ml-3" onClick={() => setAttempt((n) => n + 1)}>Try again</button>
      </div>
    );
  }

  const listOf = (payload) => (payload ? itemsOf(payload) : null);
  return (
    <OrganizationDetailView
      org={state.org}
      team={Array.isArray(state.org.team) ? state.org.team : []}
      activity={listOf(state.activity)}
      activityTotal={state.activity?.total}
      approveOrg={approveOrg}
      setOrgStatus={setOrgStatus}
    />
  );
}

/** Offline demo store: everything is already in memory. */
function SnapshotOrganizationDetail({ id }) {
  const { orgs, users, db, approveOrg, setOrgStatus, adminLoading } = useStore();
  const org = orgs.find((o) => String(o.id) === String(id));
  if (!org && adminLoading) return <Skeleton rows={6} />;
  if (!org) return <Empty title="Organization not found" body="That organization is not on this deployment." />;
  return (
    <OrganizationDetailView
      org={org}
      team={users.filter((u) => u.orgId === org.id)}
      activity={db.activity.filter((a) => a.orgId === org.id)}
      approveOrg={approveOrg}
      setOrgStatus={setOrgStatus}
    />
  );
}

function OrganizationDetailView({ org, team, activity, activityTotal, approveOrg, setOrgStatus }) {
  const [status, setStatus] = useState(org?.status || 'active');
  const [note, setNote] = useState(org?.statusNote || '');

  useEffect(() => {
    if (!org) return;
    setStatus(org.status || 'active');
    setNote(org.statusNote || '');
  }, [org]);

  return (
    <>
      <PageHeader title={org.name} subtitle={`${org.province} · ${org.planName || planByCode(org.plan).name} plan`}
        actions={<>
          {org.status === 'pending' && (
            <Confirm className="btn btn-accent" message="Approve this organization and start its 7-day Premium trial?"
              onConfirm={() => approveOrg(org.id)}>Approve organization</Confirm>
          )}
          <Link to="/admin/organizations" className="btn btn-ghost">Back</Link>
        </>} />

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Section title="Organization details" action={<Badge value={org.status} />}>
            <dl className="mt-4 grid gap-3 sm:grid-cols-2">
              {[['Barangay', org.barangay], ['Municipality', org.municipality], ['Chairperson', org.chairperson],
                ['Official email', org.email], ['Contact number', org.contact],
                ['Youth records', Number.isFinite(org.youthCount) ? org.youthCount.toLocaleString() : '']].map(([label, value]) => (
                <div key={label}><dt className="text-xs text-[#7A889B]">{label}</dt><dd className="text-sm text-navy-900">{value || '—'}</dd></div>
              ))}
            </dl>
            {org.statusNote && <p className="mt-4 rounded-lg bg-shell px-3 py-2 text-sm text-[#5A6C82]">Note: {org.statusNote}</p>}
          </Section>

          <Section title="Users">
            <div className="mt-3 divide-y divide-line">
              {team.map((user) => (
                <div key={user.id} className="flex items-center justify-between py-2">
                  <div>
                    <p className="text-sm font-medium text-navy-900">{user.name} {user.owner && <span className="badge bg-[#EEF1F5] text-[#4A5B70]">Owner</span>}</p>
                    <p className="text-xs text-[#8391A4]">{user.email} · last login {user.lastLogin}</p>
                  </div>
                  <Badge value={user.active ? 'active' : 'suspended'} />
                </div>
              ))}
            </div>
          </Section>

          <Section title="Activity">
            {activity === null && <p className="mt-3 text-sm text-[#7A889B]">The activity log could not be loaded.</p>}
            {activity !== null && activityTotal > activity.length && (
              <p className="mt-3 text-xs text-[#8391A4]">Newest {activity.length} of {activityTotal.toLocaleString()} entries. <Link to="/admin/monitoring" className="underline">Audit logs</Link> has the full trail.</p>
            )}
            {activity === null ? null : activity.length === 0 ? <p className="mt-3 text-sm text-[#7A889B]">No activity recorded.</p> : activity.map((log) => (
              <div key={log.id} className="mt-3 border-b border-[#EEF1F4] pb-2 last:border-0">
                <p className="text-sm text-navy-900">{log.description}</p>
                <p className="text-xs text-[#8391A4]">{log.user} · {log.at}</p>
              </div>
            ))}
          </Section>
        </div>

        <aside className="space-y-4">
          <Section title="Subscription" action={<Badge value={subLabel(org) === 'expiring soon' ? 'expiring soon' : (org.subStatusLabel || org.subStatus || 'free')} />}>
            <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
              <div><dt className="text-xs text-[#7A889B]">Plan</dt><dd className="font-semibold text-navy-900">{org.planName || planByCode(org.plan).name}</dd></div>
              <div><dt className="text-xs text-[#7A889B]">Cycle</dt><dd className="capitalize text-navy-900">{!org.cycle || org.cycle === 'none' ? '—' : org.cycle}</dd></div>
              <div><dt className="text-xs text-[#7A889B]">Started</dt><dd className="text-navy-900">{org.startedOn || '—'}</dd></div>
              <div><dt className="text-xs text-[#7A889B]">Ends</dt><dd className="text-navy-900">{org.expiresAt || '—'}</dd></div>
              <div className="col-span-2"><dt className="text-xs text-[#7A889B]">Remaining</dt><dd className="text-navy-900">{remainingText(org)}</dd></div>
            </dl>
            {org.restrictedToFree && <p className="mt-3 text-xs text-danger">Free plan limits apply while the subscription is {String(org.subStatusLabel || org.subStatus).toLowerCase()}.</p>}
            <AdminSubscriptionUsage org={org} />
            <AdminSubscriptionActions org={org} />
          </Section>

          <form className="card space-y-4 p-5" onSubmit={(e) => { e.preventDefault(); setOrgStatus(org.id, status, note); }}>
            <h2 className="text-sm font-bold text-navy-900">Change account status</h2>
            <Field label="Status">
              <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
                {['active', 'suspended', 'rejected'].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
              </select>
            </Field>
            <Field label="Note (optional)"><textarea className="field" rows="3" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
            <button type="submit" className="btn btn-primary w-full">Update status</button>
          </form>

        </aside>
      </div>
    </>
  );
}

const EMPTY_ORG = {
  barangay: '',
  municipality: '',
  province: 'Laguna',
  chairperson: '',
  email: '',
  contact: '',
  ownerName: '',
  ownerEmail: '',
  ownerPassword: '',
};

export function OrganizationForm() {
  const navigate = useNavigate();
  const { createOrg, mutating } = useStore();
  const [values, setValues] = useState(EMPTY_ORG);
  const [errors, setErrors] = useState({});

  const set = (key) => (event) => {
    const raw = event.target.value;
    const value = key === 'contact' ? digitsOnly(raw) : raw;
    setValues((current) => ({ ...current, [key]: value }));
    setErrors((current) => (current[key] ? { ...current, [key]: undefined } : current));
  };

  const submit = async (event) => {
    event.preventDefault();
    setErrors({});
    try {
      await createOrg({
        barangay: values.barangay,
        municipality: values.municipality,
        province: values.province,
        chairperson: values.chairperson,
        email: values.email,
        contact: values.contact,
        ownerName: values.ownerName,
        ownerEmail: values.ownerEmail,
        ownerPassword: values.ownerPassword,
      });
      navigate('/admin/organizations');
    } catch (err) {
      setErrors(err?.fields || { form: err?.message || 'Unable to create the organization.' });
    }
  };

  return (
    <>
      <PageHeader title="Add an SK organization" />
      <form className="card space-y-5 p-5" onSubmit={submit} noValidate>
        <FormErrors errors={errors} />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Barangay" error={errors.barangay}>
            <input className="field" value={values.barangay} onChange={set('barangay')} required />
          </Field>
          <Field label="Municipality" error={errors.municipality}>
            <input className="field" list="admin-municipalities" value={values.municipality} onChange={set('municipality')} required />
            <datalist id="admin-municipalities">{MUNICIPALITIES.map((m) => <option key={m} value={m} />)}</datalist>
          </Field>
          <Field label="Province" error={errors.province}>
            <input className="field" value={values.province} onChange={set('province')} required />
          </Field>
          <Field label="SK chairperson" error={errors.chairperson}>
            <input className="field" value={values.chairperson} onChange={set('chairperson')} required />
          </Field>
          <Field label="Official email" error={errors.email}>
            <input className="field" type="email" value={values.email} onChange={set('email')} required />
          </Field>
          <Field label="Contact number" error={errors.contact} hint={`${CONTACT_LENGTH} digits.`}>
            <input className="field" type="tel" inputMode="numeric" maxLength={CONTACT_LENGTH}
              placeholder="09171234567" value={values.contact} onChange={set('contact')} required />
          </Field>
        </div>
        <fieldset className="border-t border-line pt-5">
          <legend className="text-sm font-bold text-navy-900">Owner account</legend>
          <p className="mt-1 text-xs text-[#7A889B]">The organization is created as active and its 7-day Premium trial starts immediately. Leave the password blank to issue a temporary one.</p>
          <div className="mt-4 grid gap-4 sm:grid-cols-3">
            <Field label="Full name" error={errors.ownerName}>
              <input className="field" value={values.ownerName} onChange={set('ownerName')} required />
            </Field>
            <Field label="Email" error={errors.ownerEmail}>
              <input className="field" type="email" value={values.ownerEmail} onChange={set('ownerEmail')} required />
            </Field>
            <Field label="Password" error={errors.ownerPassword}>
              <input className="field" type="password" value={values.ownerPassword} onChange={set('ownerPassword')} minLength={8} />
            </Field>
          </div>
        </fieldset>
        <div className="flex gap-3">
          <button type="submit" className="btn btn-primary" disabled={mutating}>
            {mutating ? 'Creating…' : 'Create organization'}
          </button>
          <button type="button" className="btn btn-ghost" onClick={() => navigate('/admin/organizations')}>Cancel</button>
        </div>
      </form>
    </>
  );
}

/** The subscription board works over every organization, so it loads the whole list when it opens. */
export function Subscriptions() {
  return <NeedsStoreData keys={['orgs']} rows={8}><SubscriptionBoard /></NeedsStoreData>;
}

const RUN_CYCLE_MESSAGE = 'Run the subscription cycle now? Every subscription whose end date has passed is marked Expired '
  + '(Free plan limits apply, records are kept). Nothing else changes and nothing is charged.';

/** "12 day(s)", "Ends today", "Expired", "No expiration" from the server's shared calculation. */
const remainingText = (org) => {
  if (org.daysRemaining !== null && org.daysRemaining !== undefined) {
    return org.daysRemaining === 0 ? 'Ends today' : `${org.daysRemaining} day(s)`;
  }
  if (org.subStatus === 'expired') return org.expiresAt ? `Expired ${org.expiresAt}` : 'Expired';
  if (org.subStatus === 'cancelled') return 'Cancelled';
  return 'No expiration';
};

const RESOURCE_LABELS = {
  youth: 'Youth records', accounts: 'SK Official accounts', programs: 'Active programs & events',
  assistance: 'Active assistance programs', qr: 'QR scans used (lifetime)',
};
const limitText = (value) => (value === null || value === undefined ? 'Unlimited' : Number(value).toLocaleString());

/**
 * Change plan / Extend / Cancel for one organization. Each dialog first reads
 * GET /admin/organizations/{id}/subscription (current plan, usage, catalog),
 * submits to the admin endpoint and shows the server's own error on failure.
 */
function SubscriptionActions({ orgId, orgName, compact = false }) {
  const [dialog, setDialog] = useState(null);
  const size = compact ? 'btn-sm' : '';
  return (
    <>
      <div className={`flex flex-wrap gap-2 ${compact ? '' : 'mt-4'}`}>
        <button type="button" className={`btn btn-ghost ${size}`} onClick={() => setDialog('plan')}>Change plan</button>
        <button type="button" className={`btn btn-ghost ${size}`} onClick={() => setDialog('extend')}>Extend</button>
        <button type="button" className={`btn btn-danger ${size}`} onClick={() => setDialog('cancel')}>Cancel</button>
      </div>
      {dialog && <SubscriptionDialog kind={dialog} orgId={orgId} orgName={orgName} onClose={() => setDialog(null)} />}
    </>
  );
}

function SubscriptionDialog({ kind, orgId, orgName, onClose }) {
  const { orgSubscription, setOrgPlan, extendOrg, cancelOrgSub } = useStore();
  const [data, setData] = useState(null);
  const [loadError, setLoadError] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [plan, setPlan] = useState('');
  const [cycle, setCycle] = useState('monthly');
  const [mode, setMode] = useState('days');
  const [days, setDays] = useState('30');
  const [date, setDate] = useState('');
  const [reason, setReason] = useState('');

  useEffect(() => {
    let cancelled = false;
    orgSubscription(orgId).then((result) => {
      if (cancelled) return;
      setData(result);
      const sub = result.subscription;
      setPlan(sub.plan);
      setCycle(sub.cycle === 'yearly' ? 'yearly' : 'monthly');
    }).catch((err) => { if (!cancelled) setLoadError(friendlyApiMessage(err, 'Unable to load this subscription.')); });
    return () => { cancelled = true; };
  }, [orgId, orgSubscription]);

  const sub = data?.subscription;
  const target = sub?.plans?.find((p) => p.code === plan);
  const impact = sub && target
    ? Object.keys(RESOURCE_LABELS).map((key) => {
      const used = sub.usage?.[key] ?? 0;
      const limit = target.limits?.[key] ?? null;
      return { key, used, limit, over: limit !== null && used > limit, at: limit !== null && used === limit };
    })
    : [];
  const over = impact.filter((row) => row.over);
  const unchanged = sub && plan === sub.plan && (plan === 'free'
    ? sub.status === 'free'
    : sub.status === 'active' && cycle === sub.cycle);
  const extendBlocked = sub && (sub.plan === 'free' || sub.status === 'free'
    ? 'The Free plan does not expire, so there is nothing to extend. Use Change plan to start a paid term.'
    : sub.status === 'cancelled' ? 'This subscription was cancelled. Use Change plan to start a new term.' : null);
  const cancelBlocked = sub && (sub.plan === 'free' || sub.status === 'free'
    ? 'The Free plan has no subscription to cancel.'
    : sub.status === 'cancelled' ? 'This subscription is already cancelled.' : null);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      if (kind === 'plan') await setOrgPlan(orgId, plan, plan === 'free' ? undefined : cycle);
      if (kind === 'extend') await extendOrg(orgId, mode === 'days' ? { days: Number(days) } : { expiresAt: date });
      if (kind === 'cancel') await cancelOrgSub(orgId, reason.trim());
      onClose();
    } catch (err) {
      const fields = err?.fields || err?.details?.fields;
      const fieldText = fields && typeof fields === 'object' ? Object.values(fields).filter((v) => typeof v === 'string' && v.length > 12)[0] : null;
      setError(friendlyApiMessage(err, 'The server did not accept this change.') + (fieldText && !String(err?.message).includes(fieldText) ? ` ${fieldText}` : ''));
    } finally {
      setBusy(false);
    }
  };

  const titles = { plan: 'Change plan', extend: 'Extend subscription', cancel: 'Cancel subscription' };
  const submitLabel = { plan: 'Change plan', extend: 'Extend', cancel: 'Cancel subscription' }[kind];
  const blocked = kind === 'extend' ? extendBlocked : kind === 'cancel' ? cancelBlocked : null;
  const disabled = busy || !sub || Boolean(blocked) || (kind === 'plan' && (!plan || unchanged))
    || (kind === 'extend' && (mode === 'days' ? !(Number(days) >= 1 && Number(days) <= 365) : !date));

  return (
    <Modal open title={`${titles[kind]} — ${orgName}`} onClose={busy ? () => {} : onClose}
      footer={<>
        <button type="button" className="btn btn-ghost" onClick={onClose} disabled={busy}>Close</button>
        {sub && (
          <button type="submit" form={`sub-dialog-${kind}`} className={kind === 'cancel' ? 'btn btn-danger' : 'btn btn-primary'} disabled={disabled}>
            {busy ? 'Saving…' : submitLabel}
          </button>
        )}
      </>}>
      {loadError && <p className="text-sm text-danger" role="alert">{loadError}</p>}
      {!sub && !loadError && <Skeleton rows={3} />}
      {sub && (
        <form id={`sub-dialog-${kind}`} className="space-y-4" onSubmit={submit}>
          <dl className="grid grid-cols-2 gap-3 rounded-lg bg-shell p-3 text-sm">
            <div><dt className="text-xs text-[#7A889B]">Current plan</dt><dd className="font-semibold text-navy-900">{sub.planName}{sub.restrictedToFree ? ' (Free limits apply)' : ''}</dd></div>
            <div><dt className="text-xs text-[#7A889B]">Status</dt><dd className="font-semibold text-navy-900">{sub.statusLabel}</dd></div>
            <div><dt className="text-xs text-[#7A889B]">Cycle</dt><dd className="font-semibold capitalize text-navy-900">{sub.cycle === 'none' ? '—' : sub.cycle}</dd></div>
            <div><dt className="text-xs text-[#7A889B]">Expires</dt><dd className="font-semibold text-navy-900">{sub.expiresAt || '—'}{sub.daysRemaining !== null ? ` (${sub.daysRemaining} day(s) left)` : ''}</dd></div>
          </dl>

          {kind === 'plan' && (
            <>
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label="New plan">
                  <select className="field" value={plan} onChange={(e) => setPlan(e.target.value)}>
                    {sub.plans.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
                  </select>
                </Field>
                {plan !== 'free' && (
                  <Field label="Billing cycle">
                    <select className="field" value={cycle} onChange={(e) => setCycle(e.target.value)}>
                      <option value="monthly">Monthly (1 month)</option>
                      <option value="yearly">Yearly (12 months)</option>
                    </select>
                  </Field>
                )}
              </div>
              <p className="text-xs text-[#5A6C82]">
                {plan === 'free'
                  ? 'Free has no end date. Status becomes Free.'
                  : `Status becomes Active and a new ${cycle === 'yearly' ? '12-month' : '1-month'} term starts today.`}
                {sub.status === 'trial' && plan !== 'free' ? ' The trial ends.' : ''}
                {' '}No payment is taken; online payments are on the Payments page.
              </p>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr><th className="th">Limit impact</th><th className="th">In use</th><th className="th">{target?.name || 'New'} limit</th></tr></thead>
                  <tbody>
                    {impact.map((row) => (
                      <tr key={row.key} className={row.over ? 'bg-danger-bg' : ''}>
                        <td className="td">{RESOURCE_LABELS[row.key]}</td>
                        <td className="td">{row.used.toLocaleString()}</td>
                        <td className={`td ${row.over ? 'font-semibold text-danger' : ''}`}>{limitText(row.limit)}{row.over ? ' — over limit' : row.at ? ' — at limit' : ''}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {over.length > 0 && (
                <p className="rounded-lg border border-warning-border bg-warning-bg px-3 py-2 text-sm text-warning" role="alert">
                  This organization will be over the {target?.name} limits for {over.map((row) => RESOURCE_LABELS[row.key].toLowerCase()).join(', ')}.
                  Nothing is deleted or archived; new records of those kinds will be blocked until usage is below the limit.
                </p>
              )}
              {unchanged && <p className="text-sm text-[#5A6C82]">This is already the organization&apos;s plan and cycle. Use Extend to add time.</p>}
            </>
          )}

          {kind === 'extend' && (
            blocked ? <p className="text-sm text-danger">{blocked}</p> : (
              <>
                <div className="flex gap-2">
                  {['days', 'date'].map((m) => (
                    <button key={m} type="button" onClick={() => setMode(m)}
                      className={`btn btn-sm ${mode === m ? 'btn-primary' : 'btn-ghost'}`}>{m === 'days' ? 'Add days' : 'Set end date'}</button>
                  ))}
                </div>
                {mode === 'days' ? (
                  <Field label="Days to add (1–365)">
                    <input type="number" min="1" max="365" step="1" className="field" value={days} onChange={(e) => setDays(e.target.value)} />
                  </Field>
                ) : (
                  <Field label="New end date">
                    <input type="date" className="field" value={date} onChange={(e) => setDate(e.target.value)} />
                  </Field>
                )}
                <p className="text-xs text-[#5A6C82]">
                  {['active', 'trial'].includes(sub.status)
                    ? `Time is added from the current end date (${sub.expiresAt}).`
                    : 'The term has ended, so time is added from today.'}
                  {sub.status === 'trial' ? ' The trial stays a trial.' : ''}
                  {['expired', 'payment_failed'].includes(sub.status) ? ' The subscription becomes Active again.' : ''}
                  {' '}Up to 365 days per extension.
                </p>
              </>
            )
          )}

          {kind === 'cancel' && (
            blocked ? <p className="text-sm text-danger">{blocked}</p> : (
              <>
                <ul className="list-disc space-y-1 pl-5 text-sm text-[#4A5B70]">
                  <li>Takes effect immediately: Free plan limits apply from now on.</li>
                  <li>No records are deleted or archived. Creating more than the Free limits is blocked.</li>
                  <li>The current end date{sub.expiresAt ? ` (${sub.expiresAt})` : ''} stays on record.</li>
                  <li>No payment is refunded or cancelled; YouthSync has no payment provider.</li>
                  <li>To restore a paid plan later, use Change plan.</li>
                </ul>
                <Field label="Reason (optional, saved in the audit log)">
                  <input className="field" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
                </Field>
              </>
            )
          )}

          {error && <p className="rounded-lg bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">{error}</p>}
        </form>
      )}
    </Modal>
  );
}

/**
 * Compact usage vs effective limit per resource for the organization detail
 * page. Reads GET /admin/organizations/{id}/subscription (the same counts the
 * limits are enforced with) and reloads after a plan/extend/cancel change.
 */
function AdminSubscriptionUsage({ org }) {
  const { orgSubscription } = useStore();
  const [sub, setSub] = useState(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (typeof orgSubscription !== 'function') return undefined;
    let cancelled = false;
    setFailed(false);
    orgSubscription(org.id)
      .then((result) => { if (!cancelled) setSub(result.subscription); })
      .catch(() => { if (!cancelled) setFailed(true); });
    return () => { cancelled = true; };
  }, [orgSubscription, org.id, org.plan, org.subStatus, org.cycle, org.expiresAt]);

  if (typeof orgSubscription !== 'function') return null;
  if (failed) return <p className="mt-3 text-xs text-danger">Plan usage could not be loaded.</p>;
  if (!sub) return <div className="mt-3"><Skeleton rows={2} /></div>;
  const over = Object.keys(RESOURCE_LABELS).filter((key) => sub.limits?.[key] != null && (sub.usage?.[key] ?? 0) > sub.limits[key]);
  return (
    <div className="mt-4" data-testid="admin-plan-usage">
      <p className="text-xs font-semibold uppercase tracking-wide text-[#7A889B]">Usage ({sub.effectivePlan?.name || sub.planName} limits)</p>
      <ul className="mt-2 space-y-1 text-sm">
        {Object.keys(RESOURCE_LABELS).map((key) => {
          const used = sub.usage?.[key] ?? 0;
          const limit = sub.limits?.[key] ?? null;
          const isOver = limit !== null && used > limit;
          const isAt = limit !== null && used === limit;
          return (
            <li key={key} className={`flex items-center justify-between gap-2 rounded px-2 py-1 ${isOver ? 'bg-danger-bg' : ''}`}>
              <span className="text-[#4A5B70]">{RESOURCE_LABELS[key]}</span>
              <span className={`whitespace-nowrap font-semibold ${isOver ? 'text-danger' : isAt ? 'text-warning' : 'text-navy-900'}`}>
                {used.toLocaleString()} / {limitText(limit)}{isOver ? ' · over limit' : isAt ? ' · at limit' : ''}
              </span>
            </li>
          );
        })}
      </ul>
      {over.length > 0 && (
        <p className="mt-2 text-xs text-danger">Over the limit: records are kept; new ones of these kinds are blocked.</p>
      )}
    </div>
  );
}

/** Live console only: the offline demo store has no subscription endpoints. */
function AdminSubscriptionActions({ org }) {
  const { orgSubscription } = useStore();
  if (typeof orgSubscription !== 'function') return null;
  return <SubscriptionActions orgId={org.id} orgName={org.name} />;
}

function SubscriptionBoard() {
  const { orgs, runCycle } = useStore();
  const [status, setStatus] = useState('');

  const rows = orgs.filter((o) => {
    if (!status) return true;
    if (status === 'expiring') return subLabel(o) === 'expiring soon';
    return o.subStatus === status;
  });

  return (
    <>
      <PageHeader title="Subscriptions" subtitle={`${rows.length} subscription(s)`}
        actions={<Confirm message={RUN_CYCLE_MESSAGE} onConfirm={runCycle}>Run subscription cycle</Confirm>} />

      <div className="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Status">
          <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All</option>
            {['free', 'trial', 'active', 'expiring', 'expired', 'payment_failed', 'cancelled'].map((s) => (
              <option key={s} value={s}>{SUBSCRIPTION_STATUSES[s] || 'Expiring soon'}</option>
            ))}
          </select>
        </Field>
        <div className="flex items-end"><button type="button" className="btn btn-ghost w-full" onClick={() => setStatus('')}>Reset filters</button></div>
      </div>

      <Table head={['Organization', 'Plan', 'Cycle', 'Started', 'Ends', 'Remaining', 'Status', 'Actions']} minWidth={1080}>
        {rows.map((org) => (
          <tr key={org.id} className="hover:bg-shell">
            <td className="td"><Link to={`/admin/organizations/${org.id}`} className="font-medium text-navy-900 underline">{org.name}</Link></td>
            <td className="td">{org.planName || planByCode(org.plan).name}{org.restrictedToFree ? <span className="block text-xs text-danger">Free limits apply</span> : null}</td>
            <td className="td capitalize">{org.cycle === 'none' ? '—' : org.cycle}</td>
            <td className="td">{org.startedOn || '—'}</td>
            <td className="td">{org.expiresAt || '—'}</td>
            <td className="td">{remainingText(org)}</td>
            <td className="td"><Badge value={subLabel(org) === 'expiring soon' ? 'expiring soon' : (org.subStatusLabel || org.subStatus)} /></td>
            <td className="td"><SubscriptionActions orgId={org.id} orgName={org.name} compact /></td>
          </tr>
        ))}
      </Table>
    </>
  );
}

export function Payments() {
  return (
    <>
      <PageHeader title="Payments"
        subtitle="Online subscription payments, confirmed by PayMongo and verified on the server." />

      <OnlinePaymentTransactions />
    </>
  );
}

/**
 * POST /admin/payment-transactions/{id}/reconcile: asks the SERVER to re-verify with PayMongo.
 * There is deliberately no action that marks a payment paid or verified; only the server-side
 * verification service can, after fetching the checkout and payment from PayMongo.
 */
function ReconcileButton({ tx, onDone }) {
  const [state, setState] = useState({ busy: false, message: '', tone: '' });
  const run = async () => {
    setState({ busy: true, message: '', tone: '' });
    try {
      const out = await api.post(`/admin/payment-transactions/${tx.id}/reconcile`, {});
      setState({ busy: false, message: out?.message || 'Checked with PayMongo.', tone: out?.result === 'applied' || out?.result === 'already_applied' ? 'ok' : 'info' });
      onDone();
    } catch (err) {
      setState({ busy: false, message: friendlyApiMessage(err, 'PayMongo could not be reached. Try again later.'), tone: 'error' });
    }
  };
  return (
    <div className="max-w-[220px]">
      <button type="button" className="btn btn-ghost text-xs" disabled={state.busy} onClick={run} data-testid={`reconcile-${tx.id}`}>
        {state.busy ? 'Checking…' : 'Re-check with PayMongo'}
      </button>
      {state.message && (
        <span className={`mt-1 block text-xs ${state.tone === 'error' ? 'text-[#96201A]' : state.tone === 'ok' ? 'text-[#12664A]' : 'text-[#5A6C82]'}`}>{state.message}</span>
      )}
    </div>
  );
}

/**
 * GET /admin/payment-transactions: online (PayMongo) checkouts with their verification state.
 * The only action is "Re-check with PayMongo" (server-side verification, audited).
 */
function OnlinePaymentTransactions() {
  const { dataVersion } = useStore();
  const [status, setStatus] = useState('');
  const [verification, setVerification] = useState('');
  const [search, setSearch] = useState('');
  const query = useDebouncedValue(search.trim(), 300);
  const list = usePagedList('/admin/payment-transactions', {
    q: query || undefined,
    status: status || undefined,
    verification: verification || undefined,
  }, { perPage: 25, enabled: dataVersion > 0, refreshKey: dataVersion, errorMessage: 'Unable to load online payments.' });
  const totals = list.data?.totals || {};
  const gateway = list.data?.gateway;
  const filtered = Boolean(query || status || verification);

  return (
    <section data-testid="admin-online-payments">
      <h2 className="t-section mb-1">Online payments (PayMongo)</h2>
      <p className="mb-3 text-sm text-[#5A6C82]">
        {gateway
          ? (gateway.enabled
            ? `Gateway configured in ${gateway.mode === 'live' ? 'LIVE' : 'TEST'} mode. `
            : `Gateway not active: ${gateway.message} `)
          : ''}
        A plan is activated only after the server verifies the payment with PayMongo (signed webhook or "Re-check with PayMongo"):
        checkout, payment id, amount in centavos, currency, organization, transaction and test/live mode must all match.
        Rows marked Needs review were not activated. Refunds are recorded and flagged for review; the plan is never downgraded automatically.
      </p>
      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Verified by PayMongo (gross)" value={pesoAmount(totals.collected)} hint={`${showCount(totals.verified)} verified · ${pesoAmount(totals.refunded)} refunded`} />
        <Stat label="Awaiting payment / verification" value={showCount(totals.pending)} hint={`${showCount(totals.awaitingVerification)} paid at PayMongo, awaiting verification`} />
        <Stat label="Needs review" value={showCount(totals.review)} />
        <Stat label="Not paid (failed / cancelled / expired)" value={showCount(totals.notPaid)} />
      </div>
      <div className="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="sm:col-span-2"><Field label="Search"><input className="field" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Reference, organization or PayMongo checkout id" /></Field></div>
        <Field label="Status">
          <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All</option>
            {[['pending', 'Awaiting payment'], ['paid', 'Paid'], ['review', 'Needs review'], ['failed', 'Failed'], ['cancelled', 'Cancelled'], ['expired', 'Expired'],
              ['refunded', 'Refunded'], ['partially_refunded', 'Partially refunded']]
              .map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </Field>
        <Field label="Verification">
          <select className="field" value={verification} onChange={(e) => setVerification(e.target.value)}>
            <option value="">All</option>
            {[['provider_verified', 'Verified by PayMongo'], ['awaiting_verification', 'Awaiting verification'], ['unverified', 'Not verified'],
              ['mismatch', 'Verification failed'], ['needs_review', 'Needs review (incl. refunds)']]
              .map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </Field>
        <div className="flex items-end"><button type="button" className="btn btn-ghost w-full" onClick={() => { setSearch(''); setStatus(''); setVerification(''); }}>Reset filters</button></div>
      </div>
      {list.error ? (
        <Empty title="Online payments could not be loaded" body={list.error}>
          <button type="button" className="btn btn-primary" onClick={list.reload}>Retry</button>
        </Empty>
      ) : !list.data ? (
        <Skeleton rows={3} />
      ) : (
        <>
          {list.loading && <p className="mb-2 text-sm text-[#5A6C82]">Loading online payments…</p>}
          <Table head={['ID / reference', 'Organization', 'Plan', 'Amount', 'Method', 'PayMongo refs', 'Provider status', 'Status', 'Verification', 'Source', 'Created', 'Paid', 'Actions']} minWidth={1500}>
            {list.rows.length === 0 ? (
              <tr><td className="td text-[#7A889B]" colSpan={13}>{filtered ? 'No online payments match this search or filter.' : 'No online payments yet.'}</td></tr>
            ) : list.rows.map((tx) => (
              <tr key={tx.id} className="hover:bg-shell">
                <td className="td font-mono text-xs"><span className="block text-[#8391A4]">#{tx.id}</span>{tx.reference}{tx.mode === 'test' ? <span className="ml-1 font-sans text-warning">(test)</span> : null}</td>
                <td className="td">{tx.organizationId ? <Link to={`/admin/organizations/${tx.organizationId}`} className="underline">{tx.organizationName || `#${tx.organizationId}`}</Link> : '—'}</td>
                <td className="td">{tx.planName} · <span className="capitalize">{tx.cycle}</span></td>
                <td className="td">{pesoAmount(tx.amount)} {tx.currency}</td>
                <td className="td">{methodText(tx)}</td>
                <td className="td max-w-[200px] break-all font-mono text-[11px] text-[#5A6C82]">
                  {tx.providerReference || '—'}
                  {tx.providerPaymentId && <span className="block">{tx.providerPaymentId}</span>}
                </td>
                <td className="td text-xs">{tx.providerStatus || '—'}</td>
                <td className="td">
                  <PaymentStatusBadge status={tx.status} />
                  {tx.failureMessage && tx.status !== 'paid' && <span className="mt-1 block max-w-[220px] text-xs text-[#96201A]">{tx.failureMessage}</span>}
                  {tx.activationNote && tx.status === 'paid' && <span className="mt-1 block max-w-[220px] text-xs text-[#12664A]">{tx.activationNote}</span>}
                  {tx.refundedAmount > 0 && <span className="mt-1 block text-xs text-[#96201A]">Refunded {pesoAmount(tx.refundedAmount)}{tx.refundedAt ? ` on ${tx.refundedAt}` : ''}</span>}
                  {tx.reviewRequired && tx.reviewReason && tx.status !== 'review' && <span className="mt-1 block max-w-[220px] text-xs text-[#96201A]">Needs admin review: {tx.reviewReason}</span>}
                </td>
                <td className="td">
                  <VerificationBadge verification={tx.verification} label={tx.verificationLabel} />
                  {tx.verifiedAt && <span className="mt-1 block text-xs text-[#5A6C82]">{tx.verifiedAt}{tx.verifiedVia ? ` · ${tx.verifiedVia}` : ''}</span>}
                </td>
                <td className="td text-xs">PayMongo</td>
                <td className="td text-xs">{tx.createdAt || '—'}</td>
                <td className="td text-xs">{tx.paidAt || '—'}</td>
                <td className="td">{tx.canReconcile ? <ReconcileButton tx={tx} onDone={list.reload} /> : <span className="text-xs text-[#8391A4]">Not sent to PayMongo</span>}</td>
              </tr>
            ))}
          </Table>
          <Pagination page={list.page} pageSize={list.perPage} total={list.total} totalPages={list.totalPages}
            onPage={list.setPage} loading={list.loading} noun="online payments"
            pageSizes={pageSizesFor(25)} onPageSize={list.setPerPage} />
        </>
      )}
    </section>
  );
}

export function Users() {
  const { orgs = [], toggleUser, resetUserPassword, user: me, mutating, dataVersion } = useStore();
  const [search, setSearch] = useState('');
  const query = useDebouncedValue(search.trim(), 300);
  const [role, setRole] = useState('');
  const [status, setStatus] = useState('');
  const [busyId, setBusyId] = useState(null);
  const [issued, setIssued] = useState(null);

  // GET /admin/users: search, role, status and paging on the server.
  const list = usePagedList('/admin/users', {
    q: query || undefined,
    role: role || undefined,
    status: status || undefined,
  }, { perPage: 50, enabled: dataVersion > 0, refreshKey: dataVersion, errorMessage: 'Unable to load users.' });
  const rows = list.rows;
  const filtered = Boolean(query || role || status);
  const reset = () => { setSearch(''); setRole(''); setStatus(''); };

  const changeStatus = async (account) => {
    if (busyId || mutating) return;
    setBusyId(account.id);
    await toggleUser(account.id, account.active);
    setBusyId(null);
  };

  const resetPassword = async (account) => {
    if (busyId || mutating || typeof resetUserPassword !== 'function') return;
    setBusyId(account.id);
    setIssued(null);
    const saved = await resetUserPassword(account.id);
    setBusyId(null);
    if (saved?.temporaryPassword) {
      setIssued({ name: account.name, email: account.email, password: saved.temporaryPassword });
    }
  };

  return (
    <>
      <PageHeader title="Users" subtitle={list.data ? `${list.total.toLocaleString()} account(s)${filtered ? ' match these filters' : ' across all organizations'}` : 'Loading accounts…'} />
      <div className="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="sm:col-span-2"><Field label="Search"><input className="field" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name or email" /></Field></div>
        <Field label="Role">
          <select className="field" value={role} onChange={(e) => setRole(e.target.value)}>
            <option value="">All</option>
            <option value="system_admin">System Administrator</option>
            <option value="sk_official">SK User</option>
            <option value="youth">Youth</option>
          </select>
        </Field>
        <Field label="Status">
          <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </Field>
        <div className="flex items-end"><button type="button" className="btn btn-ghost w-full" onClick={reset}>Reset filters</button></div>
      </div>

      {list.error && (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-card border border-[#F0D3D1] bg-[#FCF3F2] px-4 py-3 text-sm text-[#96201A]">
          <span>{list.error}</span>
          <button type="button" className="btn btn-ghost btn-sm" onClick={list.reload}>Retry</button>
        </div>
      )}
      {issued && (
        <div className="mb-4 rounded-card border border-[#D6E4F5] bg-[#F4F8FC] px-4 py-3 text-sm text-navy-900">
          <p className="font-semibold">Temporary password for {issued.name}</p>
          <p className="mt-1">Email: {issued.email}</p>
          <p className="mt-2 font-mono text-base">{issued.password}</p>
          <p className="mt-2 text-[#5A6C82]">This password is shown only once. The user must change it the next time they sign in.</p>
          <button type="button" className="btn btn-ghost btn-sm mt-3" onClick={() => setIssued(null)}>Dismiss</button>
        </div>
      )}
      {list.error ? null : !list.data ? <Skeleton rows={6} /> : rows.length === 0 ? (
        filtered
          ? <Empty title="No results found" body="No accounts match this search or these filters."><button type="button" className="btn btn-ghost" onClick={reset}>Reset filters</button></Empty>
          : <Empty title="No accounts yet" />
      ) : (
        <>
        {list.loading && <p className="mb-2 text-sm text-[#5A6C82]">Loading accounts…</p>}
        <Table head={['Name', 'Email', 'Organization', 'Role', 'Last login', 'Status', '']} minWidth={820}>
          {rows.map((user) => (
            <tr key={user.id} className="hover:bg-shell">
              <td className="td font-medium text-navy-900">{user.name}</td>
              <td className="td">{user.email}</td>
              <td className="td">{user.organizationName || orgs.find((o) => o.id === user.orgId)?.name || '—'}</td>
              <td className="td">
                {user.role === 'system_admin' ? 'System Administrator' : user.role === 'youth' ? 'Youth' : 'SK User'}
              </td>
              <td className="td">{user.lastLogin}</td>
              <td className="td"><Badge value={user.active ? 'active' : 'suspended'} /></td>
              <td className="td">
                {user.id === me.id || user.role === 'system_admin' ? <span className="text-xs text-[#8391A4]">{user.id === me.id ? 'You' : 'Protected'}</span> : (
                  <div className="flex flex-wrap gap-2">
                    <Confirm className="btn btn-ghost btn-sm" disabled={Boolean(busyId) || mutating} message="Change this account's active status?" onConfirm={() => changeStatus(user)}>
                      {busyId === user.id ? 'Saving…' : user.active ? 'Deactivate' : 'Activate'}
                    </Confirm>
                    <Confirm className="btn btn-ghost btn-sm" disabled={Boolean(busyId) || mutating} message={`Reset the password for ${user.email}? A temporary password will be generated and their current password will stop working.`} onConfirm={() => resetPassword(user)}>
                      {busyId === user.id ? 'Saving…' : 'Reset password'}
                    </Confirm>
                  </div>
                )}
              </td>
            </tr>
          ))}
        </Table>
        <Pagination page={list.page} pageSize={list.perPage} total={list.total} totalPages={list.totalPages}
          onPage={list.setPage} loading={list.loading} noun="accounts"
          pageSizes={pageSizesFor(50)} onPageSize={list.setPerPage} />
        </>
      )}
    </>
  );
}

export function Monitoring() {
  const { orgs = [], orgCounts, dataVersion } = useStore();
  const [action, setAction] = useState('');
  const [backups, setBackups] = useState([]);
  const [backupError, setBackupError] = useState('');
  const [backingUp, setBackingUp] = useState(false);
  const [errorLog, setErrorLog] = useState(null);
  const [errorLogError, setErrorLogError] = useState('');
  const list = usePagedList('/admin/activity', { action: action || undefined },
    { perPage: 25, enabled: dataVersion > 0, refreshKey: dataVersion, errorMessage: 'Unable to load the activity log.' });
  useNotificationPoll(dataVersion > 0, () => list.reload());
  const activity = list.rows;
  const actions = Array.isArray(list.data?.filters?.actions) ? list.data.filters.actions : [];

  const loadBackups = useCallback(() => {
    api.get('/admin/backups').then((result) => {
      setBackups(Array.isArray(result?.items) ? result.items : []);
      setBackupError('');
    }).catch((err) => setBackupError(friendlyApiMessage(err, 'Unable to load backups.')));
  }, []);
  const loadErrorLog = useCallback(() => {
    api.get('/admin/errors').then((result) => {
      setErrorLog(Array.isArray(result?.items) ? result.items : []);
      setErrorLogError('');
    }).catch((err) => setErrorLogError(friendlyApiMessage(err, 'Unable to load the error log.')));
  }, []);
  useEffect(() => {
    if (dataVersion > 0) {
      loadBackups();
      loadErrorLog();
    }
  }, [dataVersion, loadBackups, loadErrorLog]);

  const runBackup = async () => {
    setBackingUp(true);
    setBackupError('');
    try {
      await api.post('/admin/backups', {});
      loadBackups();
    } catch (err) {
      setBackupError(friendlyApiMessage(err, 'The database backup failed.'));
    } finally {
      setBackingUp(false);
    }
  };

  const downloadBackup = async (file) => {
    try {
      const { blob, filename } = await apiDownload('/admin/backups/download', { query: { file } });
      saveBlob(blob, filename || file);
    } catch (err) {
      setBackupError(friendlyApiMessage(err, 'The backup file could not be downloaded.'));
    }
  };

  return (
    <>
      <PageHeader title="System monitoring" subtitle="Activity across organizations and the local application error log." />

      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <Stat label="Data source" value="Live API" hint="The activity log is stored in the database. The error log is the local app.log file." />
        <Stat label="Organizations tracked (live)" value={orgCounts ? showCount(orgCounts.all) : orgs.length.toLocaleString()} />
        <Stat label="Stored backups" value={backups.length.toLocaleString()} hint="mysqldump files kept on the server. The 14 newest are retained." />
      </div>

      <Section title="Database backups"
        action={(
          <button type="button" className="btn btn-primary btn-sm" disabled={backingUp} onClick={runBackup}>
            {backingUp ? 'Creating…' : 'Create backup now'}
          </button>
        )}>
        <p className="mt-2 text-sm text-[#5A6C82]">A backup is a full copy of the YouthSync database. The 14 newest files are kept.</p>
        {backupError && <p className="mt-3 text-sm text-[#96201A]" role="alert">{backupError}</p>}
        {backups.length === 0 ? <p className="mt-4 text-sm text-[#7A889B]">No backup file is stored yet.</p> : (
          <div className="mt-3 max-h-52 overflow-auto rounded-lg border border-[#EEF1F4]">
            <table className="w-full text-sm">
              <thead className="sticky top-0 bg-white">
                <tr>
                  <th className="th">File</th>
                  <th className="th">Created</th>
                  <th className="th">Size</th>
                  <th className="th"><span className="sr-only">Download</span></th>
                </tr>
              </thead>
              <tbody>
                {backups.map((item) => {
                  const created = new Date(item.createdAt);
                  const when = Number.isNaN(created.getTime())
                    ? item.createdAt
                    : created.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
                  const bytes = Number(item.bytes) || 0;
                  const size = bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
                  return (
                    <tr key={item.file}>
                      <td className="td font-mono text-xs">{item.file}</td>
                      <td className="td whitespace-nowrap text-[#5A6C82]">{when}</td>
                      <td className="td whitespace-nowrap text-[#5A6C82]">{size}</td>
                      <td className="td text-right">
                        <button type="button" className="btn btn-ghost btn-sm" onClick={() => downloadBackup(item.file)}>Download</button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Section>

      <div className="grid gap-4 lg:grid-cols-2">
        <Section title="Activity log (live)">
          <p className="mt-2 text-xs text-[#8391A4]">Newest first, from the server's activity log.</p>
          <select className="field mt-3" value={action} disabled={!list.data} onChange={(e) => setAction(e.target.value)}>
            <option value="">All actions</option>
            {actions.map((a) => <option key={a} value={a}>{a}</option>)}
          </select>
          {list.error ? (
            <div className="mt-3 rounded-card border border-[#F0D3D1] bg-[#FCF3F2] px-4 py-3 text-sm text-[#96201A]">
              <p>{list.error}</p>
              <button type="button" className="btn btn-ghost btn-sm mt-2" onClick={list.reload}>Retry</button>
            </div>
          ) : !list.data ? (
            <div className="mt-3"><Skeleton rows={5} /></div>
          ) : activity.length === 0 ? (
            <p className="mt-3 text-sm text-[#7A889B]">{action ? 'No results found for this action.' : 'No activity recorded yet.'}</p>
          ) : (
            <div className={`mt-3 max-h-96 space-y-2 overflow-y-auto ${list.loading ? 'opacity-60' : ''}`}>
              {activity.map((log) => (
                <div key={log.id} className="border-b border-[#EEF1F4] pb-2 last:border-0">
                  <p className="text-sm text-navy-900">{log.description}</p>
                  <p className="text-xs text-[#8391A4]">{log.user} · {log.at} · {log.ip}</p>
                </div>
              ))}
            </div>
          )}
          <Pagination page={list.page} pageSize={list.perPage} total={list.total} totalPages={list.totalPages}
            onPage={list.setPage} loading={list.loading} noun="entries" />
        </Section>

        <Section title="Error log (live)">
          <p className="mt-2 text-xs text-[#8391A4]">Newest lines from api/logs/app.log. Secrets are hidden. This file stays on the server.</p>
          {errorLogError ? (
            <div className="mt-3 rounded-card border border-[#F0D3D1] bg-[#FCF3F2] px-4 py-3 text-sm text-[#96201A]">
              <p>{errorLogError}</p>
              <button type="button" className="btn btn-ghost btn-sm mt-2" onClick={loadErrorLog}>Retry</button>
            </div>
          ) : errorLog === null ? (
            <div className="mt-3"><Skeleton rows={4} /></div>
          ) : errorLog.length === 0 ? (
            <p className="mt-3 text-sm text-[#7A889B]">No error has been recorded yet.</p>
          ) : (
            <div className="mt-3 max-h-96 space-y-2 overflow-y-auto">
              {errorLog.map((entry, index) => (
                <div key={`${entry.at}-${index}`} className="border-b border-[#EEF1F4] pb-2 last:border-0">
                  <p className="break-words text-sm text-navy-900">{entry.message}</p>
                  <p className="text-xs text-[#8391A4]">{entry.at}</p>
                </div>
              ))}
            </div>
          )}
        </Section>
      </div>
    </>
  );
}
