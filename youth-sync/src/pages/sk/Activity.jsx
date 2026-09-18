import { useState } from 'react';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Empty, Field, Tabs } from '../../components/ui.jsx';

const ACTIONS = {
  all: 'All',
  application: 'Applications',
  requirement: 'Requirements',
  attendance: 'Attendance',
  register: 'Registrations',
  program: 'Programs & events',
  youth: 'Youth records',
  user: 'User accounts',
  subscription: 'Subscription',
};

/** Append-only audit trail for the organization. Newest entry first. */
export default function ActivityLogs() {
  const { scoped, allows, plan } = useStore();
  const [filter, setFilter] = useState('all');
  const [query, setQuery] = useState('');

  const all = scoped('activityLogs');
  const rows = all
    .filter((log) => (filter === 'all' ? true : log.action === filter))
    .filter((log) => `${log.description} ${log.actor}`.toLowerCase().includes(query.toLowerCase()));

  if (!allows('activity_logs')) {
    return (
      <>
        <PageHeader title="Activity logs" />
        <Empty title={`Activity logs are not included in the ${plan.name} plan`}
          body="Upgrade to Basic or Premium to keep an audit trail of what your council did and when." />
      </>
    );
  }

  return (
    <>
      <PageHeader title="Activity logs" subtitle={`${all.length} entry(s) recorded for this organization`} />

      <Tabs active={filter} onSelect={setFilter} items={Object.entries(ACTIONS)
        .map(([key, label]) => ({ key, label, count: key === 'all' ? all.length : all.filter((l) => l.action === key).length }))
        .filter((tab) => tab.key === 'all' || tab.count > 0)} />

      <div className="card mb-4 p-4">
        <Field label="Search"><input className="field" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Description or person" /></Field>
      </div>

      {rows.length === 0 ? (
        <Empty title="Nothing logged yet" body="Approvals, attendance, registrations and record changes are written here as they happen." />
      ) : (
        <div className="card divide-y divide-line">
          {rows.map((log) => (
            <div key={log.id} className="flex flex-wrap items-start justify-between gap-3 p-4">
              <div className="min-w-0">
                <p className="text-sm text-navy-900">{log.description}</p>
                <p className="mt-0.5 text-xs text-[#8391A4]">
                  {log.actor} · {log.role.replace(/_/g, ' ')} · {log.at}
                </p>
              </div>
              <span className="badge bg-shell text-[#4A5B70]">{ACTIONS[log.action] || log.action}</span>
            </div>
          ))}
        </div>
      )}
    </>
  );
}
