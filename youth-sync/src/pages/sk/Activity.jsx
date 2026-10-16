import { useEffect, useState } from 'react';
import { useStore } from '../../store.jsx';
import { api, friendlyApiMessage, totalPagesOf } from '../../lib/api.js';
import { PageHeader } from '../../components/layouts.jsx';
import { Empty, Field, Pagination } from '../../components/ui.jsx';
import { FilterBar } from '../../components/filters.jsx';
import { formatDateTime } from '../../components/deliveries.jsx';

const PAGE_SIZE = 25;

/** Friendly names for categories stored in activity_logs. Unknown values are shown as stored. */
const CATEGORY_LABELS = {
  auth: 'Sign-in & security',
  application: 'Applications',
  requirement: 'Requirements',
  attendance: 'Attendance',
  qr: 'QR scans',
  program: 'Programs & events',
  assistance: 'Assistance',
  youth: 'Youth records',
  user: 'User accounts',
  notification: 'Notifications',
  organization: 'Organization',
  general: 'General',
};

const categoryLabel = (value) => CATEGORY_LABELS[value] || value;
const roleLabel = (value) => String(value || 'system').replace(/_/g, ' ').toLowerCase();

/**
 * Append-only audit trail for the signed-in SK organization, newest first.
 * Rows come from GET /sk/activity; the organization is taken from the session on the server.
 */
export default function ActivityLogs() {
  const { allows, plan } = useStore();
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [action, setAction] = useState('');
  const [category, setCategory] = useState('');
  const [page, setPage] = useState(1);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [reloadKey, setReloadKey] = useState(0);
  const enabled = allows('activity_logs');

  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery(search.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    if (!enabled) return undefined;
    let cancelled = false;
    setLoading(true);
    setError(null);
    api.get('/sk/activity', {
      query: {
        page,
        perPage: PAGE_SIZE,
        q: query || undefined,
        action: action || undefined,
        category: category || undefined,
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
        setData(result || { items: [], total: 0 });
      })
      .catch((err) => {
        if (!cancelled && err?.name !== 'AbortError') {
          setError(friendlyApiMessage(err, 'Unable to load activity logs.'));
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, [enabled, page, query, action, category, reloadKey]);

  if (!enabled) {
    return (
      <>
        <PageHeader title="Activity logs" />
        <Empty title={`Activity logs are not included in the ${plan.name} plan`}
          body="Upgrade to Basic or Premium to keep an audit trail of what your council did and when." />
      </>
    );
  }

  const rows = Array.isArray(data?.items) ? data.items : [];
  const total = Number(data?.total) || 0;
  const actions = Array.isArray(data?.filters?.actions) ? data.filters.actions : [];
  const categories = Array.isArray(data?.filters?.categories) ? data.filters.categories : [];
  const filtered = Boolean(query || action || category);
  const reset = () => {
    setSearch('');
    setQuery('');
    setAction('');
    setCategory('');
    setPage(1);
  };
  const pick = (setter) => (e) => {
    setter(e.target.value);
    setPage(1);
  };

  return (
    <>
      <PageHeader
        title="Activity logs"
        subtitle={data ? `${total.toLocaleString()} entr${total === 1 ? 'y' : 'ies'}${filtered ? ' match these filters' : ' recorded for this organization'}` : 'Audit trail recorded by the server'}
        actions={(
          <button type="button" className="btn btn-ghost" disabled={loading} onClick={() => setReloadKey((k) => k + 1)}>
            {loading ? 'Loading...' : 'Refresh'}
          </button>
        )}
      />

      <FilterBar search={search} onSearch={setSearch} placeholder="Description or person" onReset={filtered ? reset : undefined}>
        <Field label="Category">
          <select className="field" value={category} onChange={pick(setCategory)}>
            <option value="">All categories</option>
            {categories.map((value) => <option key={value} value={value}>{categoryLabel(value)}</option>)}
            {category && !categories.includes(category) && <option value={category}>{categoryLabel(category)}</option>}
          </select>
        </Field>
        <Field label="Action">
          <select className="field" value={action} onChange={pick(setAction)}>
            <option value="">All actions</option>
            {actions.map((value) => <option key={value} value={value}>{value}</option>)}
            {action && !actions.includes(action) && <option value={action}>{action}</option>}
          </select>
        </Field>
      </FilterBar>

      {error ? (
        <div className="card border-[#F0D3D1] bg-[#FCF3F2] px-6 py-8 text-center">
          <p className="text-sm font-semibold text-[#96201A]">{error}</p>
          <button type="button" className="btn btn-ghost mt-4" onClick={() => setReloadKey((k) => k + 1)}>Retry</button>
        </div>
      ) : loading && !data ? (
        <Empty title="Loading activity..." body="Fetching the organization audit trail from the server." />
      ) : rows.length === 0 ? (
        filtered
          ? <Empty title="No activity matches these filters." body="Clear the search or filters to see every entry." />
          : <Empty title="Nothing logged yet" body="Sign-ins, approvals, attendance, registrations and record changes are written here as they happen." />
      ) : (
        <>
          {loading && <p className="mb-2 text-sm text-ink-muted">Loading activity...</p>}
          <div className="card divide-y divide-line">
            {rows.map((log) => (
              <div key={log.id} className="flex flex-wrap items-start justify-between gap-3 p-4">
                <div className="min-w-0">
                  <p className="text-sm text-navy-900">{log.description}</p>
                  <p className="mt-0.5 text-xs text-[#8391A4]">
                    {log.actor || log.user || 'System'} · {roleLabel(log.actorRole || log.role)} · {formatDateTime(log.createdAt || log.at) || 'time not recorded'}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-1">
                  <span className="badge bg-shell text-[#4A5B70]">{categoryLabel(log.category || 'general')}</span>
                  <span className="badge bg-white text-[#4A5B70] ring-1 ring-line">{log.action}</span>
                </div>
              </div>
            ))}
          </div>
          <Pagination page={page} pageSize={PAGE_SIZE} total={total} totalPages={totalPagesOf(data)}
            onPage={setPage} loading={loading} noun="entries" />
        </>
      )}
    </>
  );
}
