import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useStore, useStoreData } from '../../store.jsx';
import { api, apiDownload, friendlyApiMessage, saveBlob, totalPagesOf } from '../../lib/api.js';
import { uniqueById, useNotificationPoll } from '../../lib/notificationPoll.js';
import { PageHeader, subLabel } from '../../components/layouts.jsx';
import { Badge, BarChart, Confirm, Empty, Field, Pagination, Section, Skeleton, Stat, Tabs } from '../../components/ui.jsx';
import { FilterBar } from '../../components/filters.jsx';
import { DeliveryHistory, formatDateTime } from '../../components/deliveries.jsx';
import { PaymentStatusBadge, pesoAmount, usePaymentOptions, VerificationBadge } from '../../components/payments.jsx';

const CATEGORIES = {
  scholarship: 'Scholarship', assistance: 'Assistance', programs: 'Programs & events',
  applications: 'Application updates', subscription: 'Subscription', system: 'System',
};

const NOTIFICATION_PAGE_SIZE = 20;

const NOTIFICATION_TABS = [
  { key: 'inapp', label: 'In-App' },
  { key: 'sms', label: 'SMS History' },
  { key: 'email', label: 'Email History' },
];

/**
 * SK Notifications: in-app inbox (notifications table) plus read-only SMS and
 * email delivery history (sms_deliveries / email_deliveries). Delivery rows are
 * never mixed into the in-app list.
 */
export function Notifications() {
  const [params, setParams] = useSearchParams();
  const requested = params.get('tab');
  const tab = requested === 'sms' || requested === 'email' ? requested : 'inapp';
  const setTab = (value) => setParams(value === 'inapp' ? {} : { tab: value });

  return (
    <>
      <PageHeader title="Notifications"
        subtitle="In-app notices for your SK account, and the SMS and email delivery history recorded by the server." />
      <Tabs active={tab} onSelect={setTab} items={NOTIFICATION_TABS} />
      {tab === 'inapp' && <InAppNotifications />}
      {tab === 'sms' && <DeliveryHistory channel="sms" />}
      {tab === 'email' && <DeliveryHistory channel="email" />}
    </>
  );
}

function InAppNotifications() {
  const { readNotification, readAllNotifications, deleteNotification } = useStore();
  const navigate = useNavigate();
  const [filter, setFilter] = useState('all');
  const [category, setCategory] = useState('');
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState({});
  const [reloadKey, setReloadKey] = useState(0);
  const hasRows = useRef(false);
  useNotificationPoll(true, () => setReloadKey((key) => key + 1));

  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery(search.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    let cancelled = false;
    if (!hasRows.current) setLoading(true);
    setError(null);
    api.get('/sk/notifications', {
      query: {
        page,
        perPage: NOTIFICATION_PAGE_SIZE,
        unreadOnly: filter === 'unread' ? 1 : undefined,
        readOnly: filter === 'read' ? 1 : undefined,
        type: category || undefined,
        q: query || undefined,
      },
    })
      .then((result) => {
        if (cancelled) return;
        // The API returns an empty page past the end; if rows were removed
        // meanwhile, step back to the last real page instead of showing it.
        const last = totalPagesOf(result);
        if (page > last) {
          setPage(last);
          return;
        }
        const items = uniqueById(Array.isArray(result?.items) ? result.items : []);
        hasRows.current = true;
        setData({ ...(result || { total: 0, unreadCount: 0 }), items });
      })
      .catch((err) => {
        if (!cancelled && err?.name !== 'AbortError' && !hasRows.current) {
          setError(friendlyApiMessage(err, 'Unable to load notifications.'));
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, [page, filter, category, query, reloadKey]);

  const reload = () => setReloadKey((k) => k + 1);
  const setRowBusy = (id, state) => setBusy((current) => {
    const next = { ...current };
    if (state) next[id] = state; else delete next[id];
    return next;
  });

  const markRead = async (n) => {
    setRowBusy(n.id, 'saving');
    const ok = await readNotification(n.id);
    setRowBusy(n.id, null);
    if (ok) reload();
    return Boolean(ok);
  };

  const markAll = async () => {
    setRowBusy('all', 'saving');
    const ok = await readAllNotifications();
    setRowBusy('all', null);
    if (ok) reload();
  };

  const remove = async (n) => {
    setRowBusy(n.id, 'deleting');
    const ok = await deleteNotification(n.id);
    setRowBusy(n.id, null);
    if (ok) reload();
  };

  const open = async (n) => {
    if (!n.isRead && !(await markRead(n))) return;
    navigate(n.link);
  };

  const rows = Array.isArray(data?.items) ? data.items : [];
  const total = Number(data?.total) || 0;
  const unread = Number(data?.unreadCount) || 0;
  const filtered = filter !== 'all' || category !== '' || query !== '';

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-ink-muted">
          {data ? `${unread} unread` : '\u00a0'}
        </p>
        <div className="flex flex-wrap gap-2">
          <button type="button" className="btn btn-ghost" disabled={loading} onClick={reload}>
            {loading ? 'Loading...' : 'Refresh'}
          </button>
          {unread > 0 && (
            <Confirm message="Mark every notification as read?" disabled={Boolean(busy.all)} onConfirm={markAll}>
              {busy.all ? 'Saving...' : 'Mark all as read'}
            </Confirm>
          )}
        </div>
      </div>

      <FilterBar search={search} onSearch={setSearch} placeholder="Title or message"
        onReset={() => { setSearch(''); setQuery(''); setFilter('all'); setCategory(''); setPage(1); }}>
        <Field label="Show">
          <select className="field" value={filter} onChange={(e) => { setFilter(e.target.value); setPage(1); }}>
            <option value="all">All</option>
            <option value="unread">Unread</option>
            <option value="read">Read</option>
          </select>
        </Field>
        <Field label="Category">
          <select className="field" value={category} onChange={(e) => { setCategory(e.target.value); setPage(1); }}>
            <option value="">All categories</option>
            {Object.entries(CATEGORIES).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </Field>
      </FilterBar>

      {error ? (
        <div className="card border-[#F0D3D1] bg-[#FCF3F2] px-6 py-8 text-center">
          <p className="text-sm font-semibold text-[#96201A]">{error}</p>
          <button type="button" className="btn btn-ghost mt-4" onClick={reload}>Retry</button>
        </div>
      ) : loading && !data ? (
        <Empty title="Loading notifications..." />
      ) : rows.length === 0 ? (
        filtered
          ? <Empty title="No notifications match these filters." body="Reset the filters to see every notification." />
          : <Empty title="No notifications yet." body="Notices about applications and other SK events will appear here." />
      ) : (
        <>
          {loading && <p className="mb-2 text-sm text-ink-muted">Loading notifications...</p>}
          <div className="card divide-y divide-line">
            {rows.map((n) => {
              const state = busy[n.id];
              const categoryLabel = CATEGORIES[n.type] || n.type || 'System';
              const canOpen = typeof n.link === 'string' && n.link.startsWith('/sk/');
              return (
                <div key={n.id} className={`flex flex-wrap items-start gap-3 p-4 ${n.isRead ? '' : 'border-l-4 border-navy-600 bg-[#F8FAFD]'}`}>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      {!n.isRead && <span className="h-2 w-2 rounded-full bg-navy-600" aria-hidden="true" />}
                      <p className={`text-sm text-navy-900 ${n.isRead ? 'font-medium' : 'font-semibold'}`}>{n.title}</p>
                      <span className="badge bg-[#EEF1F5] text-[#4A5B70]">{categoryLabel}</span>
                      <Badge value={n.isRead ? 'Read' : 'Unread'} tone={n.isRead ? 'neutral' : 'info'} />
                    </div>
                    {n.message && <p className="mt-1 text-sm text-[#5A6C82]">{n.message}</p>}
                    <p className="mt-1 text-xs text-[#8391A4]">
                      {formatDateTime(n.createdAt)}
                      {n.isRead && n.readAt ? ` · Read ${formatDateTime(n.readAt)}` : ''}
                      {n.userId ? ' · Addressed to you' : ''}
                    </p>
                    {(n.related || n.relatedEntityType) && (
                      <p className="mt-0.5 text-xs text-[#8391A4]">
                        Related: {n.related || ''}
                        {n.relatedEntityType ? `${n.related ? ' · ' : ''}${n.relatedEntityType}${n.relatedEntityId ? ` #${n.relatedEntityId}` : ''}` : ''}
                      </p>
                    )}
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {canOpen && (
                      <button type="button" className="btn btn-ghost btn-sm" disabled={Boolean(state)} onClick={() => open(n)}>Open</button>
                    )}
                    {!n.isRead && (
                      <button type="button" className="btn btn-ghost btn-sm" disabled={Boolean(state)} onClick={() => markRead(n)}>
                        {state === 'saving' ? 'Saving...' : 'Mark as read'}
                      </button>
                    )}
                    <Confirm className="btn btn-danger btn-sm" message="Delete this notification?" disabled={Boolean(state)}
                      onConfirm={() => remove(n)}>
                      {state === 'deleting' ? 'Deleting...' : 'Delete'}
                    </Confirm>
                  </div>
                </div>
              );
            })}
          </div>
          <Pagination page={page} pageSize={NOTIFICATION_PAGE_SIZE} total={total} totalPages={totalPagesOf(data)}
            onPage={setPage} loading={loading} noun="notifications" />
        </>
      )}
    </div>
  );
}

const REPORT_TABS = [
  { key: 'youth', label: 'Youth report' },
  { key: 'programs', label: 'Programs & events' },
  { key: 'assistance', label: 'Assistance report' },
  { key: 'applications', label: 'Applications' },
  { key: 'attendance', label: 'Attendance' },
  { key: 'subscription', label: 'Subscription' },
];

/** Filters each report type accepts (mirrors DashboardService::REPORT_FILTERS). */
const REPORT_FILTERS = {
  youth: { dateLabel: 'Date added', status: ['active', 'archived'] },
  programs: { dateLabel: 'Scheduled on', status: ['draft', 'published', 'ongoing', 'completed', 'archived'], kind: ['program', 'event'] },
  assistance: { dateLabel: 'Created on', status: ['draft', 'open', 'full', 'closed', 'archived'], category: ['scholarship', 'financial', 'other'] },
  applications: { dateLabel: 'Submitted on', status: ['pending', 'approved', 'rejected', 'withdrawn', 'needs_resubmission'], kind: ['program', 'event', 'assistance'] },
  attendance: { dateLabel: 'Attendance date', status: ['pending', 'present', 'absent', 'excused'], kind: ['program', 'event'] },
};

const EMPTY_FILTERS = { from: '', to: '', status: '', kind: '', category: '' };
const label = (value) => String(value).replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
const num = (value) => Number(value || 0).toLocaleString();

export function Reports() {
  const { org, allows, notify } = useStore();
  const [type, setType] = useState('youth');
  const [filters, setFilters] = useState(EMPTY_FILTERS);
  const [reloadKey, setReloadKey] = useState(0);
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [loadedAt, setLoadedAt] = useState(null);
  const [exporting, setExporting] = useState(null);
  const [exportError, setExportError] = useState(null);

  const isSubscription = type === 'subscription';
  const spec = REPORT_FILTERS[type];
  const query = useMemo(() => {
    if (!spec) return {};
    const q = { from: filters.from, to: filters.to, status: filters.status };
    if (spec.kind) q.kind = filters.kind;
    if (spec.category) q.category = filters.category;
    return q;
  }, [spec, filters]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    (async () => {
      try {
        const data = isSubscription
          ? await api.get('/sk/subscription')
          : await api.get(`/sk/reports/${type}`, { query });
        if (!cancelled) {
          setReport({ type, data });
          setLoadedAt(new Date());
        }
      } catch (err) {
        if (!cancelled) {
          setReport(null);
          setError(friendlyApiMessage(err, 'Unable to load report data from the API.'));
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [type, query, isSubscription, reloadKey]);

  const selectType = (next) => {
    if (next === type) return;
    setType(next);
    setFilters(EMPTY_FILTERS);
    setReport(null);
    setExportError(null);
  };
  const setFilter = (key, value) => setFilters((prev) => ({ ...prev, [key]: value }));
  const canExcel = allows('excel_export');

  const download = async (format) => {
    if (isSubscription || exporting) return;
    setExporting(format);
    setExportError(null);
    try {
      const { blob, filename } = await apiDownload(`/sk/reports/${type}/${format}`, { query });
      const name = filename || `${type}_report.${format}`;
      saveBlob(blob, name);
      notify(`Downloaded ${name}.`);
    } catch (err) {
      setExportError(friendlyApiMessage(err, `Unable to export the ${format.toUpperCase()} file.`));
    } finally {
      setExporting(null);
    }
  };

  const data = report && report.type === type ? report.data : null;
  const rowCount = !isSubscription && data ? Number(data.export?.rowCount ?? data.totals?.total ?? 0) : 0;
  const hasFilters = Object.values(filters).some(Boolean);

  return (
    <>
      <PageHeader title="Reports"
        subtitle={loadedAt ? `${org?.name || 'Organization'} · live data loaded ${loadedAt.toLocaleString()}` : `${org?.name || 'Organization'} · live data`}
        actions={<>
          <button type="button" className="btn btn-ghost" onClick={() => setReloadKey((k) => k + 1)} disabled={loading}>
            {loading ? 'Loading…' : 'Refresh'}
          </button>
          <button type="button" className="btn btn-ghost" onClick={() => window.print()}>Print</button>
          {!isSubscription && (
            <>
              <button type="button" className="btn btn-ghost" onClick={() => download('csv')} disabled={Boolean(exporting) || loading || Boolean(error)}>
                {exporting === 'csv' ? 'Exporting…' : 'Export CSV'}
              </button>
              <button type="button" className="btn btn-ghost" onClick={() => download('xlsx')}
                disabled={!canExcel || Boolean(exporting) || loading || Boolean(error)}
                title={canExcel ? undefined : 'Excel export is included in the Basic and Premium plans.'}>
                {exporting === 'xlsx' ? 'Exporting…' : 'Export Excel'}
              </button>
            </>
          )}
        </>} />

      <Tabs active={type} onSelect={selectType} items={REPORT_TABS} />

      {!isSubscription && !canExcel && (
        <p className="mb-4 rounded-card border border-[#F2DCA8] bg-sun-100 px-4 py-2.5 text-sm text-[#7A5A05]">
          Excel export is included in the Basic and Premium plans. CSV export and Print are available on your plan.{' '}
          <Link to="/sk/subscription/plans" className="font-semibold underline">View plans</Link>
        </p>
      )}

      {spec && (
        <FilterBar onReset={hasFilters ? () => setFilters(EMPTY_FILTERS) : undefined}>
          <Field label={`${spec.dateLabel} from`}>
            <input type="date" className="field" value={filters.from} max={filters.to || undefined}
              onChange={(e) => setFilter('from', e.target.value)} />
          </Field>
          <Field label={`${spec.dateLabel} to`}>
            <input type="date" className="field" value={filters.to} min={filters.from || undefined}
              onChange={(e) => setFilter('to', e.target.value)} />
          </Field>
          <Field label="Status">
            <select className="field" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
              <option value="">All statuses</option>
              {spec.status.map((s) => <option key={s} value={s}>{label(s)}</option>)}
            </select>
          </Field>
          {spec.kind && (
            <Field label={type === 'applications' ? 'Applied for' : 'Kind'}>
              <select className="field" value={filters.kind} onChange={(e) => setFilter('kind', e.target.value)}>
                <option value="">All</option>
                {spec.kind.map((k) => <option key={k} value={k}>{label(k)}</option>)}
              </select>
            </Field>
          )}
          {spec.category && (
            <Field label="Category">
              <select className="field" value={filters.category} onChange={(e) => setFilter('category', e.target.value)}>
                <option value="">All categories</option>
                {spec.category.map((c) => <option key={c} value={c}>{label(c)}</option>)}
              </select>
            </Field>
          )}
        </FilterBar>
      )}

      {exportError && (
        <p className="mb-4 rounded-card border border-[#F0D3D1] bg-[#FCF3F2] px-4 py-2.5 text-sm text-[#96201A]" role="alert">{exportError}</p>
      )}

      {loading && !data ? (
        <Skeleton rows={4} />
      ) : error ? (
        <div className="card px-6 py-8 text-center" role="alert">
          <p className="text-sm font-semibold text-[#96201A]">{error}</p>
          <button type="button" className="btn btn-ghost mt-4" onClick={() => setReloadKey((k) => k + 1)}>Try again</button>
        </div>
      ) : !data ? null : isSubscription ? (
        <SubscriptionReport data={data} />
      ) : (
        <>
          <p className="mb-3 text-xs text-[#8391A4]" aria-live="polite">
            {loading ? 'Refreshing… ' : ''}
            {num(rowCount)} record{rowCount === 1 ? '' : 's'} in this report{hasFilters ? ' for the selected filters' : ''}.
            {' '}CSV and Excel exports contain exactly these {num(rowCount)} row{rowCount === 1 ? '' : 's'}
            {data.export?.columns?.length ? ` (${data.export.columns.length} columns)` : ''}.
          </p>
          {rowCount === 0 ? (
            <Empty title="No records match this report."
              body={hasFilters ? 'Try widening the date range or clearing the filters.' : 'Records will appear here once they are added for your organization.'}>
              {hasFilters && <button type="button" className="btn btn-ghost" onClick={() => setFilters(EMPTY_FILTERS)}>Clear filters</button>}
            </Empty>
          ) : (
            <ReportBody type={type} data={data} />
          )}
        </>
      )}
    </>
  );
}

function ReportBody({ type, data }) {
  const t = data.totals || {};
  const s = data.series || {};
  if (type === 'youth') {
    return (
      <>
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Stat label="Total youth" value={num(t.total)} />
          <Stat label="Active" value={num(t.active)} />
          <Stat label="Archived" value={num(t.archived)} />
          <Stat label="High priority" value={num(s.priority?.High)} />
        </div>
        <div className="mt-4 grid gap-4 lg:grid-cols-3">
          <BarChart title="Age groups" series={s.ageGroups} />
          <BarChart title="Gender" series={s.gender} />
          <BarChart title="Education" series={s.education} />
          <BarChart title="Employment" series={s.employment} />
          <BarChart title="Priority distribution" color="#E0A21A" series={s.priority} />
          <Section title="High priority youth">
            {(data.highPriorityYouth || []).length === 0 ? (
              <p className="mt-2 text-sm text-[#7A889B]">No High priority youth in this report.</p>
            ) : (
              <ul className="mt-3 max-h-56 space-y-1.5 overflow-y-auto text-sm text-[#4A5B70]">
                {data.highPriorityYouth.map((y) => (
                  <li key={y.id}>· <Link to={`/sk/youth/${y.id}`} className="underline">{`${y.firstName} ${y.lastName}`}</Link>{y.firstReason ? ` — ${y.firstReason}` : ''}</li>
                ))}
              </ul>
            )}
          </Section>
        </div>
      </>
    );
  }
  if (type === 'programs') {
    return (
      <>
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
          <Stat label="Total" value={num(t.total)} />
          <Stat label="Programs" value={num(t.programs)} />
          <Stat label="Events" value={num(t.events)} />
          <Stat label="Registrations" value={num(t.registrations)} hint="Not rejected or withdrawn" />
          <Stat label="Unique participants" value={num(t.participants)} />
          <Stat label="Present (attendance)" value={num(t.presentAttendance)} />
        </div>
        <div className="mt-4 grid gap-4 lg:grid-cols-2">
          <BarChart title="By status" series={s.byStatus} />
          <BarChart title="Programs vs events" series={s.byKind} />
        </div>
      </>
    );
  }
  if (type === 'assistance') {
    return (
      <>
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
          <Stat label="Assistance programs" value={num(t.total)} />
          <Stat label="Beneficiaries" value={num(t.beneficiaries)} hint="All statuses" />
          <Stat label="Awarded" value={num(t.awarded)} hint="Approved + released" />
        </div>
        <div className="mt-4 grid gap-4 lg:grid-cols-2">
          <BarChart title="Programs by status" series={s.byStatus} />
          <BarChart title="Programs by category" series={s.byCategory} />
          <BarChart title="Beneficiaries by status" series={s.beneficiariesByStatus} />
          <BarChart title="Awarded beneficiaries by category" color="#E0A21A" series={s.awardedByCategory} />
        </div>
        <div className="card mt-4 overflow-x-auto">
          <table className="w-full" style={{ minWidth: 600 }}>
            <thead><tr>{['Program', 'Category', 'Status', 'Slots', 'Awarded', 'Available'].map((h) => <th key={h} className="th">{h}</th>)}</tr></thead>
            <tbody>
              {(data.slots || []).map((row) => (
                <tr key={row.id}>
                  <td className="td">{row.name}</td>
                  <td className="td">{label(row.category)}</td>
                  <td className="td"><Badge value={row.status} /></td>
                  <td className="td">{row.slots === null ? 'Unlimited' : num(row.slots)}</td>
                  <td className="td">{num(row.filled)}</td>
                  <td className="td">{row.available === null ? '—' : num(row.available)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </>
    );
  }
  if (type === 'applications') {
    const byStatus = s.byStatus || {};
    const byTarget = s.byTarget || {};
    return (
      <>
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Stat label="Applications" value={num(t.total)} />
          <Stat label="Pending" value={num(byStatus.pending)} />
          <Stat label="Approved" value={num(byStatus.approved)} />
          <Stat label="Needs resubmission" value={num(byStatus.needs_resubmission)} />
        </div>
        <div className="mt-4 grid gap-4 lg:grid-cols-2">
          <BarChart title="By status" series={byStatus} />
          <BarChart title="Program / event / assistance" series={byTarget} />
        </div>
      </>
    );
  }
  const byStatus = s.byStatus || {};
  return (
    <>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <Stat label="Attendance records" value={num(t.total)} />
        <Stat label="Present" value={num(t.present)} />
        <Stat label="Absent" value={num(byStatus.absent)} />
        <Stat label="Excused" value={num(byStatus.excused)} />
        <Stat label="Pending" value={num(byStatus.pending)} />
      </div>
      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <BarChart title="By status" series={byStatus} />
        <BarChart title="Programs vs events" series={s.byKind} />
      </div>
    </>
  );
}

function SubscriptionReport({ data }) {
  const usage = data.usage || {};
  const limits = data.limits || {};
  const rows = [
    ['youth', 'Youth records'],
    ['accounts', 'Staff accounts'],
    ['programs', 'Active programs and events'],
    ['assistance', 'Active assistance programs'],
    ['qr', 'QR attendance scans'],
  ];
  return (
    <>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="Current plan" value={data.effectivePlan?.name || data.planName || '—'} />
        <Stat label="Status" value={label(data.status || '—')} />
        <Stat label="Billing cycle" value={label(data.cycle || 'none')} />
        <Stat label="Days remaining" value={data.daysRemaining !== null && data.daysRemaining !== undefined
          ? num(data.daysRemaining)
          : (data.expiresAt ? label(data.status || '—') : 'No expiration')} />
      </div>
      <div className="card mt-4 overflow-x-auto">
        <table className="w-full" style={{ minWidth: 520 }}>
          <thead><tr>{['Usage', 'Used', 'Plan limit', 'Remaining'].map((h) => <th key={h} className="th">{h}</th>)}</tr></thead>
          <tbody>
            {rows.map(([key, name]) => (
              <tr key={key}>
                <td className="td">{name}</td>
                <td className="td">{num(usage[key])}</td>
                <td className="td">{limits[key] === null || limits[key] === undefined ? 'Unlimited' : num(limits[key])}</td>
                <td className="td">{data.remaining?.[key] === null || data.remaining?.[key] === undefined ? 'Unlimited' : num(data.remaining[key])}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="mt-3 text-xs text-[#8391A4]">
        Live plan and usage from GET /sk/subscription. Plan details and upgrades are on the <Link to="/sk/subscription" className="underline">Subscription</Link> page.
      </p>
    </>
  );
}

const USAGE_ROWS = [
  ['youth', 'Youth records'], ['accounts', 'SK Official accounts'], ['programs', 'Active programs & events'],
  ['assistance', 'Active assistance programs'], ['qr', 'QR scans used (lifetime)'],
];

const daysText = (org) => {
  if (org.daysRemaining !== null && org.daysRemaining !== undefined) {
    return org.daysRemaining === 0 ? 'Ends today' : `${org.daysRemaining} day(s)`;
  }
  if (org.subStatus === 'expired') return 'Expired';
  if (org.subStatus === 'cancelled') return 'Cancelled';
  return 'No expiration';
};

/**
 * Live plan, status and usage from GET /sk/subscription. Payment history lists
 * online (PayMongo) checkouts from GET /sk/subscription/checkouts.
 */
/** Latest online (PayMongo) transactions for this organization, from GET /sk/subscription/checkouts. */
function useOnlinePayments() {
  const [state, setState] = useState({ loading: true, error: null, items: [], total: 0 });
  useEffect(() => {
    let alive = true;
    api.get('/sk/subscription/checkouts?page=1&perPage=5')
      .then((data) => {
        if (!alive) return;
        const items = Array.isArray(data?.items) ? data.items : [];
        setState({ loading: false, error: null, items, total: Number(data?.total ?? items.length) });
      })
      .catch((err) => {
        if (alive) setState({ loading: false, error: friendlyApiMessage(err, 'Unable to load online payments.'), items: [], total: 0 });
      });
    return () => { alive = false; };
  }, []);
  return state;
}

export function Subscription() {
  const { org, plan, storedPlan, planName, usage, limits, subscription } = useStore();
  const online = useOnlinePayments();
  const reached = subscription?.reached || {};
  const over = subscription?.overLimit || {};
  const overKeys = Object.keys(over);

  return (
    <>
      <PageHeader title="Subscription" subtitle="One subscription covers your whole organization."
        actions={<Link to="/sk/subscription/plans" className="btn btn-primary">View plans</Link>} />

      {org.restrictedToFree && (
        <div className="mb-4 rounded-lg border border-danger-border bg-danger-bg px-4 py-3 text-sm text-danger">
          Your {storedPlan?.name || ''} subscription is {org.subStatusLabel?.toLowerCase() || org.subStatus}
          {org.expiresAt ? ` (term ${org.subStatus === 'cancelled' ? 'was until' : 'ended'} ${org.expiresAt})` : ''}.
          Free plan limits apply now. All records are kept; adding more than the Free limits is blocked until the
          System Administrator renews or changes your plan.
        </div>
      )}
      {overKeys.length > 0 && (
        <div className="mb-4 rounded-lg border border-warning-border bg-warning-bg px-4 py-3 text-sm text-warning">
          You are over your current plan limits ({overKeys.map((k) => `${k} ${over[k].usage}/${over[k].limit}`).join(', ')}).
          Existing records are kept, but new ones of those kinds cannot be added.
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <section className="card p-5 lg:col-span-2">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="text-xs text-[#7A889B]">Current plan</p>
              <h2 className="text-2xl font-bold text-navy-900">{storedPlan?.name || planName}</h2>
              <p className="text-sm text-[#5A6C82]">{storedPlan?.tagline}</p>
              {org.restrictedToFree && <p className="mt-1 text-xs text-danger">Limits in effect: {planName}</p>}
            </div>
            <Badge value={subLabel(org)} />
          </div>

          <dl className="mt-5 grid gap-4 sm:grid-cols-4">
            <div><dt className="text-xs text-[#7A889B]">Status</dt><dd className="text-sm font-medium text-navy-900">{org.subStatusLabel || org.subStatus}</dd></div>
            <div><dt className="text-xs text-[#7A889B]">Billing cycle</dt><dd className="text-sm font-medium capitalize text-navy-900">{org.cycle === 'none' ? '—' : org.cycle}</dd></div>
            <div><dt className="text-xs text-[#7A889B]">Expires</dt><dd className="text-sm font-medium text-navy-900">{org.expiresAt || '—'}</dd></div>
            <div><dt className="text-xs text-[#7A889B]">Days remaining</dt><dd className="text-sm font-medium text-navy-900">{daysText(org)}</dd></div>
          </dl>

          <h3 className="mt-6 text-sm font-bold text-navy-900">Usage against your plan</h3>
          <div className="mt-3 space-y-3">
            {USAGE_ROWS.map(([key, label]) => {
              const limit = limits[key] ?? null;
              const used = usage[key] ?? 0;
              const pct = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
              return (
                <div key={key}>
                  <div className="flex justify-between text-xs text-[#5A6C82]">
                    <span>{label}{reached[key] ? <span className="ml-2 font-semibold text-danger">Limit reached</span> : null}</span>
                    <span className="font-semibold text-navy-900">{used.toLocaleString()} / {limit === null ? 'Unlimited' : limit.toLocaleString()}</span>
                  </div>
                  <div className="mt-1 h-2 rounded-full bg-[#EDF0F4]">
                    <div className="h-2 rounded-full" style={{ width: `${limit ? Math.max(2, pct) : 4}%`, background: pct >= 100 ? '#B3261E' : pct >= 80 ? '#E0A21A' : '#22568C' }} />
                  </div>
                </div>
              );
            })}
          </div>

          <p className="mt-6 border-t border-line pt-4 text-xs text-[#8391A4]">
            Plan changes, renewals and cancellations are made by the System Administrator. Records are never
            deleted when a plan changes; you simply cannot add new ones beyond the limits of the plan in effect.
          </p>
        </section>

        <aside className="space-y-4">
          <Section title={`Included in ${planName}`}>
            <ul className="mt-3 space-y-1.5 text-sm text-navy-900">
              {(plan.bullets || []).map((line) => (
                <li key={line} className="flex items-start gap-2"><span>✓</span>{line}</li>
              ))}
              {(plan.bullets || []).length === 0 && <li className="text-[#7A889B]">Plan details are unavailable right now.</li>}
            </ul>
          </Section>

          <Section title="Payment history">
            <h4 className="mt-3 text-xs font-semibold uppercase tracking-wide text-[#7A889B]">Online payments (PayMongo)</h4>
            {online.loading ? <p className="mt-2 text-sm text-[#7A889B]">Loading online payments…</p>
              : online.error ? <p className="mt-2 text-sm text-danger">{online.error}</p>
              : online.items.length === 0 ? <p className="mt-2 text-sm text-[#7A889B]">No online payments yet.</p>
              : online.items.map((tx) => (
                <div key={`online-${tx.id}`} className="mt-2 border-b border-[#EEF1F4] pb-2 text-sm last:border-0">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium text-navy-900">{pesoAmount(tx.amount)}</span>
                    <PaymentStatusBadge status={tx.status} />
                  </div>
                  <p className="text-xs text-[#5A6C82]">Online · {tx.planName} · <span className="capitalize">{tx.cycle}</span>{tx.mode === 'test' ? ' · Test mode' : ''}</p>
                  <p className="text-xs text-[#8391A4]">{tx.reference} · Started {formatDateTime(tx.createdAt)}{tx.paidAt ? ` · Paid ${formatDateTime(tx.paidAt)}` : ''}</p>
                  {tx.status === 'pending' && tx.failureMessage && <p className="text-xs text-danger">Last attempt failed: {tx.failureMessage}</p>}
                  {tx.verification && tx.verification !== 'unverified' && <div className="mt-1"><VerificationBadge verification={tx.verification} label={tx.verificationLabel} /></div>}
                </div>
              ))}
            {online.total > online.items.length && (
              <Link to="/sk/billing" className="mt-2 inline-block text-xs underline">View all {online.total} online payments</Link>
            )}
          </Section>
        </aside>
      </div>
    </>
  );
}

export function Plans() {
  const { plans, org, subscription } = useStore();
  const [cycle, setCycle] = useState('monthly');
  const navigate = useNavigate();
  const payment = usePaymentOptions();
  const online = payment.options?.enabled === true;

  return (
    <>
      <PageHeader title="Plans" subtitle={online
        ? 'Pay online with PayMongo, or ask your System Administrator to apply a plan.'
        : 'Reference list prices. Your System Administrator applies plan changes.'} />

      <p className="mb-4 rounded-lg bg-shell px-4 py-3 text-sm text-[#4A5B70]">
        {online
          ? `Online payment is available${payment.options.mode === 'test' ? ' in TEST mode (no real money moves)' : ''}. Your plan changes only after PayMongo confirms the payment to YouthSync.`
          : (subscription?.billing?.note || 'Online payment is not configured. Your System Administrator applies plan changes.')}
      </p>
      {!online && payment.options && payment.options.reason !== 'not_configured' && (
        <p className="mb-4 rounded-lg border border-warning-border bg-warning-bg px-4 py-3 text-sm text-warning">{payment.options.message}</p>
      )}

      <div className="mb-5 inline-flex rounded-lg border border-line bg-white p-1">
        {['monthly', 'yearly'].map((option) => (
          <button key={option} type="button" onClick={() => setCycle(option)}
            className={`rounded-md px-4 py-1.5 text-sm font-semibold ${cycle === option ? 'bg-navy-900 text-white' : 'text-[#4A5B70]'}`}>
            {option === 'monthly' ? 'Monthly' : 'Yearly'}
          </button>
        ))}
      </div>

      {plans.length === 0 && <Empty title="Plans are unavailable right now." body="Reload the page to try again." />}

      <div className="grid gap-5 lg:grid-cols-3">
        {plans.map((plan) => {
          const isCurrent = org.plan === plan.code && !org.restrictedToFree;
          const price = cycle === 'yearly' ? plan.priceYearly : plan.priceMonthly;
          const saving = plan.priceMonthly * 12 - plan.priceYearly;
          return (
            <div key={plan.code} className={`card flex flex-col p-6 ${plan.popular ? 'border-navy-900 ring-1 ring-navy-900' : ''}`}>
              {plan.popular && <span className="badge mb-3 self-start bg-navy-900 text-white">Most popular</span>}
              <h3 className="text-lg font-bold text-navy-900">{plan.name}</h3>
              <p className="mt-1 text-sm text-[#5A6C82]">{plan.tagline}</p>
              <p className="mt-4 text-3xl font-bold text-navy-900">₱{price.toLocaleString()}</p>
              <p className="text-sm text-[#7A889B]">{price > 0 ? `per ${cycle === 'yearly' ? 'year' : 'month'} (list price)` : 'Permanent. Does not expire.'}</p>
              {cycle === 'yearly' && saving > 0 && <p className="mt-1 text-sm text-[#12664A]">₱{saving.toLocaleString()} less than 12 monthly payments</p>}

              <ul className="mt-5 flex-1 space-y-1.5 text-sm text-[#4A5B70]">
                {plan.bullets.map((line) => <li key={line}>{line}</li>)}
              </ul>

              {isCurrent ? (
                <span className="btn btn-ghost mt-6 opacity-70">Your current plan</span>
              ) : (
                <div className="mt-6 space-y-2">
                  {online && plan.code !== 'free' ? (
                    <button type="button" className="btn btn-primary w-full"
                      onClick={() => navigate(`/sk/subscription/checkout?plan=${plan.code}&cycle=${cycle}`)}>
                      Pay online for {plan.name}
                    </button>
                  ) : (
                    <>
                      <p className="text-xs text-[#5A6C82]">Ask your System Administrator to move you to {plan.name}.</p>
                      {plan.code !== 'free' && !payment.loading && payment.options?.reason === 'not_configured' && (
                        <button type="button" className="btn btn-ghost w-full"
                          onClick={() => navigate(`/sk/subscription/checkout?plan=${plan.code}&cycle=${cycle}`)}>
                          Preview checkout (simulation)
                        </button>
                      )}
                    </>
                  )}
                </div>
              )}
            </div>
          );
        })}
      </div>
    </>
  );
}

/**
 * Checkout entry. When the server reports PayMongo as configured this is the
 * real hosted checkout (review, then redirect to PayMongo). Only when online
 * payment is NOT configured does it fall back to the clearly labelled simulation.
 */
export function Checkout() {
  const [params] = useSearchParams();
  const { plans } = useStore();
  const payment = usePaymentOptions();
  const code = params.get('plan') || 'basic';
  const cycle = params.get('cycle') === 'yearly' ? 'yearly' : 'monthly';
  const plan = plans.find((p) => p.code === code && p.code !== 'free') || plans.find((p) => p.code === 'basic');

  if (payment.loading) {
    return (<><PageHeader title="Checkout" /><Skeleton rows={4} /></>);
  }
  if (payment.error) {
    return (
      <>
        <PageHeader title="Checkout" />
        <Empty title="Online payment status could not be loaded" body={payment.error}>
          <button type="button" className="btn btn-primary" onClick={payment.reload}>Retry</button>
        </Empty>
      </>
    );
  }
  if (payment.options?.enabled) {
    if (!plan) {
      return (<><PageHeader title="Checkout" /><Empty title="Plans are unavailable right now." body="Reload the page to try again." /></>);
    }
    return <OnlineCheckout plan={plan} cycle={cycle} options={payment.options} />;
  }
  if (payment.options?.reason !== 'not_configured') {
    return (
      <>
        <PageHeader title="Checkout" />
        <Empty title="Online payment is unavailable" body={payment.options?.message || 'Online payment is not available right now.'}>
          <Link to="/sk/subscription/plans" className="btn btn-ghost">Back to plans</Link>
        </Empty>
      </>
    );
  }
  return <SimulatedCheckout />;
}

function OnlineCheckout({ plan, cycle, options }) {
  const navigate = useNavigate();
  const { org } = useStore();
  const [method, setMethod] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [pendingTx, setPendingTx] = useState(null);
  const submitting = useRef(false);
  const amount = cycle === 'yearly' ? plan.priceYearly : plan.priceMonthly;
  const renewing = org.plan === plan.code && org.subStatus === 'active' && !org.restrictedToFree;

  const start = async (event) => {
    event.preventDefault();
    if (submitting.current) return;
    submitting.current = true;
    setBusy(true);
    setError(null);
    setPendingTx(null);
    try {
      const data = await api.post('/sk/subscription/checkout', { plan: plan.code, cycle, ...(method ? { method } : {}) });
      if (typeof data?.checkoutUrl === 'string' && data.checkoutUrl.startsWith('https://')) {
        window.location.assign(data.checkoutUrl);
        return; // keep the button disabled while the browser leaves
      }
      setError('The payment provider did not return a checkout page. Nothing was charged.');
    } catch (err) {
      if (err?.code === 'CHECKOUT_IN_PROGRESS') setPendingTx(err?.details?.error?.transactionId || null);
      setError(friendlyApiMessage(err, 'Checkout could not be started. Nothing was charged.'));
    }
    submitting.current = false;
    setBusy(false);
  };

  return (
    <>
      <PageHeader title="Checkout" subtitle="Review your plan, then continue to PayMongo's secure payment page." />
      {options.mode === 'test' && (
        <div className="mb-4 rounded-lg border border-warning-border bg-warning-bg px-4 py-3 text-sm text-warning" data-testid="paymongo-test-mode">
          TEST MODE. Use PayMongo test cards or test e-wallet pages only; no real money moves.
        </div>
      )}
      <div className="grid gap-4 lg:grid-cols-3">
        <section className="card p-5 lg:col-span-2">
          <ol className="flex flex-wrap gap-4 border-b border-line pb-4 text-xs">
            {['Plan', 'Billing cycle', 'Review', 'Pay on PayMongo', 'Confirmation'].map((step, index) => (
              <li key={step} className={`flex items-center gap-1.5 ${index <= 2 ? 'font-semibold text-navy-900' : 'text-[#9AA7B6]'}`}>
                <span className={`grid h-5 w-5 place-items-center rounded-full ${index <= 2 ? 'bg-navy-900 text-white' : 'bg-[#EDF0F4]'}`}>{index + 1}</span>{step}
              </li>
            ))}
          </ol>

          <dl className="mt-5 space-y-3 text-sm">
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Plan</dt><dd className="font-semibold text-navy-900">{plan.name}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Billing cycle</dt><dd className="font-semibold capitalize text-navy-900">{cycle}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Term</dt>
              <dd className="text-right font-semibold text-navy-900">
                {cycle === 'yearly' ? '12 months' : '1 month'}{renewing ? ', added to your current term' : ', starting when payment is confirmed'}
              </dd>
            </div>
            <div className="flex justify-between border-t border-line pt-3 text-base">
              <dt className="font-semibold text-navy-900">Total</dt><dd className="font-bold text-navy-900">{pesoAmount(amount)} PHP</dd>
            </div>
          </dl>

          <form className="mt-6 space-y-4 border-t border-line pt-5" onSubmit={start}>
            {options.methods.length === 0 ? (
              <p className="text-sm text-danger">No payment methods are enabled. Ask your System Administrator.</p>
            ) : (
              <Field label="Payment method" hint="You will choose and complete the payment on PayMongo's page. YouthSync never sees your card or e-wallet details.">
                <select className="field" value={method} onChange={(e) => setMethod(e.target.value)} disabled={busy}>
                  <option value="">Any available method</option>
                  {options.methods.map((m) => <option key={m.code} value={m.code}>{m.label}</option>)}
                </select>
              </Field>
            )}
            {error && (
              <div className="rounded-lg border border-danger-border bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">
                {error}
                {pendingTx && (
                  <> <Link className="underline" to={`/sk/subscription/payment/success?tx=${pendingTx}`}>Check that payment</Link></>
                )}
              </div>
            )}
            <button type="submit" className="btn btn-primary w-full" disabled={busy || options.methods.length === 0}>
              {busy ? 'Redirecting to PayMongo…' : 'Continue to Payment'}
            </button>
            <button type="button" className="btn btn-ghost w-full" onClick={() => navigate('/sk/subscription/plans')} disabled={busy}>Back to plans</button>
          </form>
        </section>

        <aside className="card h-fit p-5">
          <h2 className="text-sm font-bold text-navy-900">Included in {plan.name}</h2>
          <ul className="mt-3 space-y-1.5 text-sm text-[#4A5B70]">
            {(plan.bullets || []).map((line) => <li key={line}>{line}</li>)}
          </ul>
          <p className="mt-4 rounded-lg bg-shell px-3 py-2 text-xs leading-relaxed text-[#5A6C82]">
            Your plan changes only after PayMongo confirms the payment to YouthSync. Coming back to this site does not by
            itself activate anything. Payments are processed by PayMongo.
          </p>
        </aside>
      </div>
    </>
  );
}

const RETURN_POLL_MS = 3000;
const RETURN_POLL_MAX = 60;

/**
 * PayMongo success/cancel return page. It only polls the protected
 * GET /sk/subscription/checkout/{id}; "Payment successful" appears only when the
 * server says the transaction is paid.
 */
export function PaymentReturn({ kind = 'success' }) {
  const [params] = useSearchParams();
  const id = Number.parseInt(params.get('tx') || '', 10);
  const { refreshSubscription } = useStore();
  const [tx, setTx] = useState(null);
  const [subscription, setSubscription] = useState(null);
  const [error, setError] = useState(null);
  const [polls, setPolls] = useState(0);
  const [cancelling, setCancelling] = useState(false);
  const refreshed = useRef(false);
  const status = tx?.status;
  const done = status && status !== 'pending';
  const timedOut = !done && polls >= RETURN_POLL_MAX;

  useEffect(() => {
    if (!Number.isInteger(id) || id < 1 || done || timedOut) return undefined;
    let alive = true;
    const timer = setTimeout(async () => {
      try {
        const data = await api.get(`/sk/subscription/checkout/${id}`);
        if (!alive) return;
        setTx(data.transaction);
        setSubscription(data.subscription || null);
        setError(null);
      } catch (err) {
        if (!alive) return;
        if (err?.status === 404) {
          setError({ fatal: true, message: 'That payment was not found for your organization.' });
          return;
        }
        setError({ fatal: false, message: friendlyApiMessage(err, 'The server could not be reached. Retrying…') });
      }
      if (alive) setPolls((n) => n + 1);
    }, polls === 0 ? 0 : RETURN_POLL_MS);
    return () => { alive = false; clearTimeout(timer); };
  }, [id, polls, done, timedOut]);

  useEffect(() => {
    if (status === 'paid' && !refreshed.current) {
      refreshed.current = true;
      refreshSubscription?.();
    }
  }, [status, refreshSubscription]);

  const cancel = async () => {
    if (cancelling) return;
    setCancelling(true);
    try {
      const data = await api.post(`/sk/subscription/checkout/${id}/cancel`, {});
      setTx(data.transaction);
    } catch (err) {
      setError({ fatal: false, message: friendlyApiMessage(err, 'The checkout could not be cancelled right now.') });
      setPolls(0);
    }
    setCancelling(false);
  };

  if (!Number.isInteger(id) || id < 1 || error?.fatal) {
    return (
      <>
        <PageHeader title="Payment" />
        <Empty title="Payment not found" body={error?.message || 'This page needs a payment reference from PayMongo.'}>
          <Link to="/sk/billing" className="btn btn-ghost">Go to Billing</Link>
        </Empty>
      </>
    );
  }

  let title = 'Payment processing';
  let body = 'Waiting for PayMongo to confirm your payment. This page updates automatically.';
  let tone = 'info';
  if (!tx) {
    body = 'Checking your payment…';
  } else if (status === 'paid') {
    title = 'Payment successful';
    body = `${tx.planName} (${tx.cycle}) is active${subscription?.expiresAt ? ` until ${subscription.expiresAt}` : ''}. Reference ${tx.reference}.`;
    tone = 'success';
  } else if (status === 'failed') {
    title = 'Payment could not be started';
    body = `${tx.failureMessage || 'The payment provider was unavailable.'} Nothing was charged and your plan did not change.`;
    tone = 'danger';
  } else if (status === 'cancelled') {
    title = 'Checkout cancelled';
    body = 'Nothing was charged and your plan did not change.';
    tone = 'neutral';
  } else if (status === 'expired') {
    title = 'Checkout expired';
    body = 'This checkout can no longer be paid. Nothing was charged; start a new checkout from Plans.';
    tone = 'neutral';
  } else if (status === 'review') {
    title = 'Payment needs review';
    body = `PayMongo reported a payment that did not match this checkout, so your plan was not changed. Contact your System Administrator with reference ${tx.reference}.`;
    tone = 'danger';
  } else if (kind === 'cancel') {
    title = 'Payment not completed';
    body = 'You left the PayMongo page before paying. If you did not pay, you can cancel this checkout. If you did pay, wait here for confirmation.';
    tone = 'warning';
  } else if (tx.providerStatus === 'payment_failed') {
    body = `The last payment attempt failed${tx.failureMessage ? ` (${tx.failureMessage})` : ''}. You can try again on the PayMongo page or cancel this checkout.`;
    tone = 'warning';
  } else if (tx.providerStatus === 'paid') {
    body = 'PayMongo shows a payment. Waiting for its confirmation to reach YouthSync before your plan changes.';
  }
  const toneClass = {
    success: 'border-line bg-success-bg text-success', danger: 'border-danger-border bg-danger-bg text-danger',
    warning: 'border-warning-border bg-warning-bg text-warning', neutral: 'border-line bg-shell text-[#4A5B70]', info: 'border-line bg-info-bg text-info',
  }[tone];

  return (
    <>
      <PageHeader title={title} subtitle={tx ? `Reference ${tx.reference}` : undefined} />
      <div className="card mx-auto max-w-xl p-6" data-testid="payment-return" data-status={status || 'loading'}>
        <div className={`rounded-lg border px-4 py-3 text-sm ${toneClass}`}>{body}</div>
        {timedOut && (
          <p className="mt-3 text-sm text-[#5A6C82]">
            Still waiting. Confirmation can take a few minutes. You can leave this page; the result will appear on Billing.
            {' '}<button type="button" className="underline" onClick={() => setPolls(0)}>Check again</button>
          </p>
        )}
        {error && !error.fatal && <p className="mt-3 text-sm text-warning">{error.message}</p>}
        {tx && (
          <dl className="mt-5 space-y-2.5 border-t border-line pt-5 text-sm">
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Plan</dt><dd className="font-semibold text-navy-900">{tx.planName} · <span className="capitalize">{tx.cycle}</span></dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Amount</dt><dd className="font-semibold text-navy-900">{pesoAmount(tx.amount)} {tx.currency}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Status</dt><dd><PaymentStatusBadge status={tx.status} /></dd></div>
            {tx.verification && <div className="flex justify-between"><dt className="text-[#5A6C82]">Verification</dt><dd><VerificationBadge verification={tx.verification} label={tx.verificationLabel} /></dd></div>}
            {tx.methodLabel && <div className="flex justify-between"><dt className="text-[#5A6C82]">Method</dt><dd className="font-semibold text-navy-900">{tx.methodLabel}</dd></div>}
            {tx.mode === 'test' && <div className="flex justify-between"><dt className="text-[#5A6C82]">Mode</dt><dd className="font-semibold text-warning">Test</dd></div>}
          </dl>
        )}
        <div className="mt-6 flex flex-wrap gap-3">
          {status === 'paid' && <Link to="/sk/subscription" className="btn btn-primary">View subscription</Link>}
          {status === 'pending' && (
            <button type="button" className="btn btn-ghost" onClick={cancel} disabled={cancelling}>
              {cancelling ? 'Cancelling…' : 'Cancel this checkout'}
            </button>
          )}
          {status && status !== 'pending' && status !== 'paid' && <Link to="/sk/subscription/plans" className="btn btn-primary">Back to plans</Link>}
          <Link to="/sk/billing" className="btn btn-ghost">Billing</Link>
        </div>
      </div>
    </>
  );
}

function SimulatedCheckout() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const { plans, payAndActivate } = useStore();
  const [method, setMethod] = useState('ewallet');
  const [confirmed, setConfirmed] = useState(false);
  const [outcome, setOutcome] = useState('success');
  const [receipt, setReceipt] = useState(null);

  const code = params.get('plan') || 'basic';
  const cycle = params.get('cycle') || 'monthly';
  const plan = plans.find((p) => p.code === code) || plans.find((p) => p.code === 'basic') || plans[0];
  if (!plan) {
    return (
      <>
        <PageHeader title="Checkout (simulation)" />
        <Empty title="Plans are unavailable right now." body="Reload the page to try again." />
      </>
    );
  }
  const amount = cycle === 'yearly' ? plan.priceYearly : plan.priceMonthly;
  const expires = new Date();
  expires.setDate(expires.getDate() + (cycle === 'yearly' ? 365 : 30));

  if (receipt && receipt.status === 'failed') {
    return (
      <>
        <PageHeader title="Payment failed" subtitle="Step 5 of 5" />
        <div className="card mx-auto max-w-xl p-6">
          <span className="grid h-12 w-12 place-items-center rounded-full bg-[#FCEDEC] text-lg">!</span>
          <h2 className="mt-4 text-lg font-bold text-navy-900">Your payment did not go through</h2>
          <p className="mt-1 text-sm text-[#5A6C82]">
            Reference {receipt.reference}. Your subscription was not activated and nothing was charged.
            Your existing records are untouched.
          </p>
          <div className="mt-6 flex gap-3">
            <button type="button" className="btn btn-primary" onClick={() => { setReceipt(null); setOutcome('success'); }}>Retry payment</button>
            <Link to="/sk/subscription/plans" className="btn btn-ghost">Back to plans</Link>
          </div>
        </div>
      </>
    );
  }

  if (receipt) {
    return (
      <>
        <PageHeader title="Subscription confirmed" subtitle="Step 5 of 5" />
        <div className="card mx-auto max-w-xl p-6">
          <span className="grid h-12 w-12 place-items-center rounded-full bg-[#E7F5EE] text-lg">✓</span>
          <h2 className="mt-4 text-lg font-bold text-navy-900">{plan.name} plan is active</h2>
          <p className="mt-1 text-sm text-[#5A6C82]">
            Payment confirmed automatically — no admin approval was needed. Your subscription runs until {expires.toDateString()}.
          </p>
          <dl className="mt-5 space-y-2.5 border-t border-line pt-5 text-sm">
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Reference number</dt><dd className="font-mono text-xs font-semibold text-navy-900">{receipt.reference}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Amount</dt><dd className="font-semibold text-navy-900">₱{receipt.amount.toLocaleString()}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Billing cycle</dt><dd className="font-semibold text-navy-900">{receipt.cycle}</dd></div>
            <div className="flex justify-between"><dt className="text-[#5A6C82]">Payment status</dt><dd><Badge value={receipt.status} /></dd></div>
          </dl>
          <p className="mt-4 rounded-lg bg-sun-100 px-3 py-2 text-xs leading-relaxed text-[#7A5A05]">
            This is a payment record. No payment gateway is connected to this preview.
          </p>
          <div className="mt-6 flex gap-3">
            <Link to="/sk/subscription" className="btn btn-primary">View subscription</Link>
            <Link to="/sk" className="btn btn-ghost">Back to dashboard</Link>
          </div>
        </div>
      </>
    );
  }

  return (
    <>
      <PageHeader title="Checkout (simulation)" subtitle="Preview only — no payment is processed and your plan does not change." />
      <div className="mb-4 rounded-lg border border-warning-border bg-warning-bg px-4 py-3 text-sm text-warning">
        Simulation. YouthSync has no payment gateway: nothing is charged and confirming here does not change your
        subscription. To change plans, pay through your usual arrangement and ask the System Administrator to apply the plan.
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <section className="card p-5 lg:col-span-2">
          <ol className="flex flex-wrap gap-4 border-b border-line pb-4 text-xs">
            {['Select plan', 'Billing cycle', 'Review', 'Payment', 'Confirmation'].map((step, index) => (
              <li key={step} className={`flex items-center gap-1.5 ${index <= 2 ? 'font-semibold text-navy-900' : 'text-[#9AA7B6]'}`}>
                <span className={`grid h-5 w-5 place-items-center rounded-full ${index <= 2 ? 'bg-navy-900 text-white' : 'bg-[#EDF0F4]'}`}>{index + 1}</span>{step}
              </li>
            ))}
          </ol>

          <dl className="mt-5 space-y-3 text-sm">
            {[['Plan', plan.name], ['Billing cycle', cycle], ['Price', `₱${amount.toLocaleString()}`],
              ['Start date', new Date().toDateString()], ['Expiration date', expires.toDateString()]].map(([label, value]) => (
              <div key={label} className="flex justify-between"><dt className="text-[#5A6C82]">{label}</dt><dd className="font-semibold text-navy-900">{value}</dd></div>
            ))}
            <div className="flex justify-between border-t border-line pt-3 text-base">
              <dt className="font-semibold text-navy-900">Total</dt><dd className="font-bold text-navy-900">₱{amount.toLocaleString()}</dd>
            </div>
          </dl>

          <form className="mt-6 space-y-4 border-t border-line pt-5" onSubmit={async (e) => {
            e.preventDefault();
            setReceipt(await payAndActivate(plan.code, cycle, outcome));
          }}>
            <Field label="Payment method">
              <select className="field" value={method} onChange={(e) => setMethod(e.target.value)}>
                <option value="ewallet">E-wallet</option>
                <option value="bank_transfer">Bank transfer</option>
              </select>
            </Field>
            <Field label="Simulate the gateway result" hint="There is no real gateway here, so you choose what it returns.">
              <select className="field" value={outcome} onChange={(e) => setOutcome(e.target.value)}>
                <option value="success">Payment succeeds → subscription activates automatically</option>
                <option value="fail">Payment fails → subscription stays inactive, retry allowed</option>
              </select>
            </Field>
            <label className="flex items-start gap-2 text-sm text-[#4A5B70]">
              <input type="checkbox" className="mt-0.5 h-4 w-4 rounded border-[#C7D0DA]" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} required />
              I understand no real payment gateway is connected and this checkout is only a simulation.
            </label>
            <button type="submit" className="btn btn-primary w-full" disabled={!confirmed}>Confirm subscription</button>
            <button type="button" className="btn btn-ghost w-full" onClick={() => navigate('/sk/subscription/plans')}>Back to plans</button>
          </form>
        </section>

        <aside className="card h-fit p-5">
          <h2 className="text-sm font-bold text-navy-900">Included in {plan.name}</h2>
          <ul className="mt-3 space-y-1.5 text-sm text-[#4A5B70]">
            {(plan.bullets || []).map((line) => <li key={line}>{line}</li>)}
          </ul>
          <p className="mt-4 rounded-lg bg-shell px-3 py-2 text-xs leading-relaxed text-[#5A6C82]">
            Prices are reference list prices. A plan becomes active only when the System Administrator applies it.
            When a term ends the status changes to Expired and Free plan limits apply; records are kept.
          </p>
        </aside>
      </div>
    </>
  );
}

export function Settings() {
  const { org, user, users, usage, limits, plan, allows, scoped, updateOrg, saveSkAccount, saveStaff, removeStaff, resetDemoData, mutating } = useStore();
  const [details, setDetails] = useState({
    barangay: org?.barangay || '', municipality: org?.municipality || '', province: org?.province || '',
    chairperson: org?.chairperson || '', email: org?.email || '', contact: org?.contact || '',
  });
  const [orgErrors, setOrgErrors] = useState({});
  const [account, setAccount] = useState({ name: user?.name || '', email: user?.email || '' });
  const [accountErrors, setAccountErrors] = useState({});
  const [passwords, setPasswords] = useState({ currentPassword: '', newPassword: '', confirmPassword: '' });
  const [accountSaving, setAccountSaving] = useState(false);
  const [staff, setStaff] = useState({ name: '', email: '' });
  // The staff list below reads the store's SK accounts; ask for them here.
  useStoreData('users');
  const team = users.filter((u) => u.orgId === org?.id);
  const logs = scoped('activity');

  useEffect(() => {
    if (!org) return;
    setDetails({
      barangay: org.barangay || '',
      municipality: org.municipality || '',
      province: org.province || '',
      chairperson: org.chairperson || '',
      email: org.email || '',
      contact: org.contact || '',
    });
  }, [org]);

  useEffect(() => {
    setAccount({ name: user?.name || '', email: user?.email || '' });
  }, [user?.name, user?.email]);

  const saveAccount = async (event) => {
    event.preventDefault();
    if (accountSaving) return;
    setAccountErrors({});
    setAccountSaving(true);
    try {
      await saveSkAccount({ ...account, ...passwords });
      setPasswords({ currentPassword: '', newPassword: '', confirmPassword: '' });
    } catch (err) {
      setAccountErrors(err?.fields && typeof err.fields === 'object' ? err.fields : { form: err?.message || 'Unable to save your account.' });
    } finally {
      setAccountSaving(false);
    }
  };

  const saveOrganization = async (event) => {
    event.preventDefault();
    if (mutating) return;
    setOrgErrors({});
    try {
      await updateOrg(details);
    } catch (err) {
      setOrgErrors(err?.fields && typeof err.fields === 'object' ? err.fields : {});
    }
  };

  return (
    <>
      <PageHeader title="Settings" subtitle="Organization details, your account, and staff access." />
      <div className="grid gap-4 lg:grid-cols-2">
        <form className="card space-y-4 p-5" onSubmit={saveOrganization}>
          <h2 className="text-sm font-bold text-navy-900">Organization</h2>
          {orgErrors.form && <p className="text-sm text-[#96201A]">{orgErrors.form}</p>}
          <div className="grid gap-4 sm:grid-cols-2">
            {[['barangay', 'Barangay'], ['municipality', 'Municipality'], ['province', 'Province'],
              ['chairperson', 'SK chairperson'], ['email', 'Official email'], ['contact', 'Contact number']].map(([key, label]) => (
              <Field key={key} label={label} error={orgErrors[key]}>
                <input className="field" value={details[key] || ''} disabled={mutating}
                  onChange={(e) => setDetails({ ...details, [key]: e.target.value })} required />
              </Field>
            ))}
          </div>
          <button type="submit" className="btn btn-primary" disabled={mutating}>
            {mutating ? 'Saving...' : 'Save organization details'}
          </button>
        </form>

        <form className="card space-y-4 p-5" onSubmit={saveAccount}>
          <h2 className="text-sm font-bold text-navy-900">Your account</h2>
          {accountErrors.form && <p className="text-sm text-[#96201A]">{accountErrors.form}</p>}
          <Field label="Full name" error={accountErrors.name}>
            <input className="field" value={account.name} disabled={accountSaving} required
              onChange={(e) => setAccount({ ...account, name: e.target.value })} />
          </Field>
          <Field label="Email" error={accountErrors.email}>
            <input className="field" type="email" value={account.email} disabled={accountSaving} required
              onChange={(e) => setAccount({ ...account, email: e.target.value })} />
          </Field>
          <p className="text-xs text-[#7A889B]">Leave the password fields blank to keep your current password.</p>
          <Field label="Current password" error={accountErrors.currentPassword}>
            <input className="field" type="password" autoComplete="current-password" disabled={accountSaving}
              value={passwords.currentPassword} onChange={(e) => setPasswords({ ...passwords, currentPassword: e.target.value })} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="New password" error={accountErrors.newPassword}>
              <input className="field" type="password" autoComplete="new-password" disabled={accountSaving}
                value={passwords.newPassword} onChange={(e) => setPasswords({ ...passwords, newPassword: e.target.value })} />
            </Field>
            <Field label="Confirm" error={accountErrors.confirmPassword}>
              <input className="field" type="password" autoComplete="new-password" disabled={accountSaving}
                value={passwords.confirmPassword} onChange={(e) => setPasswords({ ...passwords, confirmPassword: e.target.value })} />
            </Field>
          </div>
          <button type="submit" className="btn btn-primary" disabled={accountSaving}>
            {accountSaving ? 'Saving...' : 'Save account'}
          </button>
        </form>

        <Section title="Staff accounts">
          <p className="mt-1 text-xs text-[#7A889B]">
            {usage.accounts} of {limits.accounts === null ? 'unlimited' : limits.accounts} allowed on the {plan.name} plan.
          </p>
          <div className="mt-3 divide-y divide-line">
            {team.map((member) => (
              <div key={member.id} className="flex items-center justify-between py-2">
                <div>
                  <p className="text-sm font-medium text-navy-900">
                    {member.name} {member.owner && <span className="badge bg-[#EEF1F5] text-[#4A5B70]">Owner</span>}
                  </p>
                  <p className="text-xs text-[#8391A4]">{member.email}</p>
                </div>
                {!member.owner && (
                  <Confirm className="btn btn-danger btn-sm" message="Remove this staff account?" onConfirm={() => removeStaff(member.id)}>Remove</Confirm>
                )}
              </div>
            ))}
          </div>
          <form className="mt-4 space-y-3 border-t border-line pt-4" onSubmit={(e) => {
            e.preventDefault();
            if (!staff.name || !staff.email) return;
            saveStaff(staff);
            setStaff({ name: '', email: '' });
          }}>
            <div className="grid gap-3 sm:grid-cols-3">
              <input className="field" placeholder="Full name" value={staff.name} onChange={(e) => setStaff({ ...staff, name: e.target.value })} />
              <input className="field" type="email" placeholder="Email" value={staff.email} onChange={(e) => setStaff({ ...staff, email: e.target.value })} />
              <input className="field" type="password" placeholder="Password" />
            </div>
            <button type="submit" className="btn btn-ghost">Add staff account</button>
          </form>
        </Section>

        <Section title="System data">
          <p className="mt-2 text-sm text-[#5A6C82]">
            Everything you add is saved in this browser&apos;s localStorage, so a refresh keeps it.
            Resetting restores the starting records and signs you out.
          </p>
          <Confirm className="btn btn-danger mt-3"
            message="Reset all records? Everything added in this browser will be lost."
            onConfirm={resetDemoData}>Reset records</Confirm>
        </Section>

        <Section title="Activity log">
          {!allows('activity_logs') ? (
            <p className="mt-3 text-sm text-[#7A889B]">Activity logs are a Basic and Premium feature.</p>
          ) : (
            <div className="mt-3 max-h-80 space-y-2 overflow-y-auto">
              {logs.map((log) => (
                <div key={log.id} className="border-b border-[#EEF1F4] pb-2 last:border-0">
                  <p className="text-sm text-navy-900">{log.description}</p>
                  <p className="text-xs text-[#8391A4]">{log.user} · {log.at} · {log.ip}</p>
                </div>
              ))}
            </div>
          )}
        </Section>
      </div>
    </>
  );
}
