import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Stat, BarChart, Skeleton } from '../../components/ui.jsx';
import { formatDateTime } from '../../components/deliveries.jsx';
import { api, friendlyApiMessage } from '../../lib/api.js';

/**
 * SK dashboard. Every figure on this page comes from GET /sk/dashboard, which
 * the server scopes to the signed-in SK Official's organization. A value the
 * API did not return is shown as "-", never as a number computed in the browser.
 */

const isCount = (value) => typeof value === 'number' && Number.isFinite(value);
const show = (value) => (isCount(value) ? value.toLocaleString() : '-');

const label = (key) => {
  const text = String(key).replace(/_/g, ' ');
  return text.charAt(0).toUpperCase() + text.slice(1);
};

/** Re-key a { key: count } breakdown with display labels; null stays null. */
const relabel = (series, names = {}) => {
  if (!series || typeof series !== 'object') return null;
  return Object.fromEntries(Object.entries(series).map(([key, value]) => [names[key] || label(key), value]));
};

const AGE_LABELS = { '15-17': '15-17', '18-24': '18-24', '25-30': '25-30', other: 'Outside 15-30' };

const formatDay = (value) => {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
  if (!match) return String(value || '');
  return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])).toDateString();
};

function Chart({ title, series, color, empty }) {
  if (series === null) {
    return <BarChart title={title} series={{}} color={color} empty="Not available from the server." />;
  }
  return <BarChart title={title} series={series} color={color} empty={empty} />;
}

export default function Dashboard() {
  const { user, org } = useStore();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [loadedAt, setLoadedAt] = useState(null);
  const request = useRef(null);

  const load = useCallback(async () => {
    request.current?.abort();
    const controller = new AbortController();
    request.current = controller;
    setLoading(true);
    setError(null);
    try {
      const payload = await api.get('/sk/dashboard', { signal: controller.signal });
      if (controller.signal.aborted) return;
      setData(payload && typeof payload === 'object' ? payload : null);
      setLoadedAt(new Date());
    } catch (err) {
      if (err?.name === 'AbortError' || controller.signal.aborted) return;
      setError(friendlyApiMessage(err, 'Unable to load the dashboard statistics.'));
    } finally {
      if (request.current === controller) setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
    return () => request.current?.abort();
  }, [load]);

  const summary = data?.summary;
  const highlights = data?.highlights;
  const upcoming = Array.isArray(data?.programs?.upcoming) ? data.programs.upcoming : [];
  const recent = Array.isArray(data?.recent) ? data.recent : [];
  const newest = Array.isArray(data?.newestYouth) ? data.newestYouth : [];

  const header = (
    <PageHeader
      title={`Welcome, ${user.name}`}
      subtitle={`Here is the current picture for ${org.name}.`}
      actions={<>
        <button type="button" className="btn btn-ghost" onClick={load} disabled={loading}>
          {loading ? 'Refreshing...' : 'Refresh'}
        </button>
        <Link to="/sk/youth/new" className="btn btn-primary">Add youth record</Link>
        <Link to="/sk/programs/new" className="btn btn-ghost">New activity</Link>
      </>}
    />
  );

  if (!data) {
    return (
      <>
        {header}
        {error ? (
          <div className="card px-6 py-10 text-center" role="alert">
            <p className="text-sm font-semibold text-ink">The dashboard statistics could not be loaded.</p>
            <p className="mx-auto mt-1.5 max-w-md t-muted">{error}</p>
            <button type="button" className="btn btn-primary mt-4" onClick={load} disabled={loading}>Try again</button>
          </div>
        ) : (
          <Skeleton rows={4} />
        )}
      </>
    );
  }

  const stats = [
    ['Active youth', summary?.youth?.active, isCount(summary?.youth?.archived) ? `${summary.youth.archived.toLocaleString()} archived` : undefined],
    ['Total families', highlights?.families, 'Distinct guardians of active youth'],
    ['Unemployed youth', highlights?.unemployedYouth],
    ['Unemployed parents', highlights?.unemployedParents, 'Guardian marked unemployed'],
    ['Youth needing assistance', highlights?.highPriorityYouth, 'High priority recommendation'],
    ['Scholarship beneficiaries', highlights?.scholarshipBeneficiaries, 'Approved or released'],
    ['Upcoming programs & events', highlights?.upcomingPrograms, 'Published or ongoing, from today'],
    ['Active assistance', highlights?.activeAssistance, 'Draft, open, or full'],
  ];

  const programStats = [
    ['Programs', summary?.programs?.programs, isCount(summary?.programs?.published) ? `${summary.programs.published.toLocaleString()} published (programs + events)` : undefined],
    ['Events', summary?.programs?.events],
    ['Program registrations', summary?.applications?.programRegistrations, 'Excludes rejected or withdrawn'],
    ['Attendance marked present', summary?.attendance?.present, isCount(summary?.attendance?.total) ? `${summary.attendance.total.toLocaleString()} attendance record(s)` : undefined],
  ];

  const applicationStats = [
    ['Pending applications', summary?.applications?.pending],
    ['Approved applications', summary?.applications?.approved],
    ['Rejected applications', summary?.applications?.rejected],
    ['Needs resubmission', summary?.applications?.needsResubmission],
  ];

  return (
    <>
      {header}

      {error && (
        <div className="mb-4 rounded-card border border-[#F2DCA8] bg-sun-100 px-4 py-3 text-sm text-[#7A5A05]" role="alert">
          Could not refresh: {error} The figures below are from {loadedAt ? loadedAt.toLocaleTimeString() : 'the last successful load'}.
        </div>
      )}
      {loadedAt && !error && (
        <p className="-mt-3 mb-4 text-xs text-[#8391A4]">Live from the server · updated {loadedAt.toLocaleTimeString()}</p>
      )}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {stats.map(([title, value, hint]) => <Stat key={title} label={title} value={show(value)} hint={hint} />)}
      </div>

      <h2 className="mb-3 mt-8 text-sm font-bold text-navy-900">Youth demographics</h2>
      <p className="-mt-2 mb-3 text-xs text-[#8391A4]">Active (non-archived) youth records.</p>
      <div className="grid gap-4 lg:grid-cols-2">
        <Chart title="Age distribution" empty="Add youth records to see this." series={relabel(data.youth?.ageGroups, AGE_LABELS)} />
        <Chart title="Gender" series={relabel(data.youth?.gender)} />
        <Chart title="Educational level" series={relabel(data.youth?.education)} />
        <Chart title="Employment status" series={relabel(data.youth?.employment)} />
      </div>

      <h2 className="mb-3 mt-8 text-sm font-bold text-navy-900">Assistance overview</h2>
      <div className="grid gap-4 lg:grid-cols-2">
        <Chart title="Beneficiaries by category (approved or released)" color="#12664A" empty="No approved or released beneficiaries yet."
          series={relabel(data.assistance?.awardedBeneficiariesByCategory)} />
        <Chart title="Youth by priority level" color="#E0A21A" series={relabel(data.youth?.priority)} />
      </div>

      <h2 className="mb-3 mt-8 text-sm font-bold text-navy-900">Program overview</h2>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {programStats.map(([title, value, hint]) => <Stat key={title} label={title} value={show(value)} hint={hint} />)}
      </div>
      <div className="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
        {applicationStats.map(([title, value, hint]) => <Stat key={title} label={title} value={show(value)} hint={hint} />)}
      </div>

      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <div className="card p-4">
          <h3 className="text-sm font-semibold text-navy-800">Upcoming activities</h3>
          {upcoming.length === 0 ? (
            <p className="mt-3 text-sm text-[#7A889B]">Nothing scheduled. <Link to="/sk/programs/new" className="underline">Create an activity</Link>.</p>
          ) : upcoming.map((program) => (
            <Link key={program.id} to={`/sk/programs/${program.id}`} className="mt-3 flex items-center justify-between rounded-lg border border-line px-3 py-2 hover:bg-shell">
              <span>
                <span className="block text-sm font-medium text-navy-900">{program.name}</span>
                <span className="block text-xs text-[#8391A4]">
                  {label(program.kind || 'program')} · {formatDay(program.scheduledOn)}{program.location ? ` · ${program.location}` : ''}
                </span>
              </span>
              <span className="text-right text-xs font-semibold text-navy-700" title="Registrations / capacity">
                {show(program.registeredCount)} / {program.maxParticipants || '∞'}
                <span className="block font-normal text-[#8391A4]">registered</span>
              </span>
            </Link>
          ))}
        </div>
        <div className="card p-4">
          <h3 className="text-sm font-semibold text-navy-800">Recent activity</h3>
          {recent.length === 0 ? (
            <p className="mt-3 text-sm text-[#7A889B]">No activity recorded yet.</p>
          ) : recent.map((item) => (
            <div key={`${item.source}-${item.id}`} className="mt-3 border-b border-[#EEF1F4] pb-2 last:border-0">
              <p className="text-sm text-navy-900">{item.description}</p>
              <p className="text-xs text-[#8391A4]">{label(item.source)} · {formatDateTime(item.at)}</p>
            </div>
          ))}
        </div>
      </div>

      <p className="mt-6 text-xs text-[#8391A4]">
        Newest youth on file: {newest.map((y) => `${y.firstName} ${y.lastName}`.trim()).join(', ') || 'none yet'} ·{' '}
        {show(summary?.notifications?.unread)} unread notification(s)
      </p>
    </>
  );
}
