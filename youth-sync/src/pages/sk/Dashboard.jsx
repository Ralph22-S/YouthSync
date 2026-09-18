import { Link } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Stat, BarChart } from '../../components/ui.jsx';
import { age, fullName } from '../../data/mock.js';

const groupCount = (rows, key) => rows.reduce((acc, row) => {
  const k = typeof key === 'function' ? key(row) : row[key];
  acc[k] = (acc[k] || 0) + 1;
  return acc;
}, {});

export default function Dashboard() {
  const { user, org, scoped, db, dashboard } = useStore();
  const youth = scoped('youth').filter((y) => !y.archived);
  const programs = scoped('programs');
  const registrations = scoped('registrations');
  const beneficiaries = scoped('beneficiaries');
  const assistance = scoped('assistance');
  const activity = scoped('activity');
  const highlights = dashboard?.highlights;
  const summary = dashboard?.summary;

  const approved = beneficiaries.filter((b) => ['approved', 'released'].includes(b.status));
  const categoryOf = (b) => assistance.find((a) => a.id === b.programId)?.category;
  const upcoming = programs
    .filter((p) => ['published', 'ongoing'].includes(p.status) && new Date(p.scheduledOn) >= new Date())
    .sort((a, b) => a.scheduledOn.localeCompare(b.scheduledOn));

  const stats = [
    ['Total youth', summary?.youth?.active ?? youth.length],
    ['Total families', highlights?.families ?? new Set(youth.map((y) => y.guardianName)).size],
    ['Unemployed youth', highlights?.unemployedYouth ?? youth.filter((y) => y.employment === 'Unemployed').length],
    ['Unemployed parents', highlights?.unemployedParents ?? youth.filter((y) => y.guardianEmployment === 'Unemployed').length],
    ['Youth needing assistance', highlights?.highPriorityYouth ?? youth.filter((y) => y.level === 'High').length, 'High priority recommendation'],
    ['Scholarship beneficiaries', highlights?.scholarshipBeneficiaries ?? approved.filter((b) => categoryOf(b) === 'scholarship').length],
    ['Upcoming programs', highlights?.upcomingPrograms ?? upcoming.length],
    ['Active assistance', highlights?.activeAssistance ?? assistance.filter((a) => ['draft', 'open', 'full'].includes(a.status)).length],
  ];

  return (
    <>
      <PageHeader
        title={`Welcome, ${user.name}`}
        subtitle={`Here is the current picture for ${org.name}.`}
        actions={<>
          <Link to="/sk/youth/new" className="btn btn-primary">Add youth record</Link>
          <Link to="/sk/programs/new" className="btn btn-ghost">New activity</Link>
        </>}
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {stats.map(([label, value, hint]) => <Stat key={label} label={label} value={value.toLocaleString()} hint={hint} />)}
      </div>

      <h2 className="mb-3 mt-8 text-sm font-bold text-navy-900">Youth demographics</h2>
      <div className="grid gap-4 lg:grid-cols-2">
        <BarChart title="Age distribution" empty="Add youth records to see this."
          series={groupCount(youth, (y) => (age(y.birthDate) <= 17 ? '15-17' : age(y.birthDate) <= 24 ? '18-24' : '25-30'))} />
        <BarChart title="Gender" series={groupCount(youth, 'gender')} />
        <BarChart title="Educational level" series={groupCount(youth, 'education')} />
        <BarChart title="Employment status" series={groupCount(youth, 'employment')} />
      </div>

      <h2 className="mb-3 mt-8 text-sm font-bold text-navy-900">Assistance overview</h2>
      <div className="grid gap-4 lg:grid-cols-2">
        <BarChart title="Beneficiaries by category" color="#12664A" series={{
          Scholarship: approved.filter((b) => categoryOf(b) === 'scholarship').length,
          Financial: approved.filter((b) => categoryOf(b) === 'financial').length,
          Other: approved.filter((b) => categoryOf(b) === 'other').length,
        }} />
        <BarChart title="Youth by priority level" color="#E0A21A" series={groupCount(youth, 'level')} />
      </div>

      <h2 className="mb-3 mt-8 text-sm font-bold text-navy-900">Program overview</h2>
      <div className="grid gap-4 lg:grid-cols-3">
        <Stat label="Registered participants" value={(summary?.attendance?.total ?? registrations.filter((r) => r.status === 'registered').length).toLocaleString()} />
        <Stat label="Recorded attendance" value={(summary?.attendance?.present ?? registrations.filter((r) => r.attendance === 'present').length).toLocaleString()} />
        <Stat label="Upcoming activities" value={upcoming.length.toLocaleString()} />
      </div>

      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <div className="card p-4">
          <h3 className="text-sm font-semibold text-navy-800">Upcoming activities</h3>
          {upcoming.length === 0 ? (
            <p className="mt-3 text-sm text-[#7A889B]">Nothing scheduled. <Link to="/sk/programs/new" className="underline">Create an activity</Link>.</p>
          ) : upcoming.slice(0, 5).map((program) => (
            <Link key={program.id} to={`/sk/programs/${program.id}`} className="mt-3 flex items-center justify-between rounded-lg border border-line px-3 py-2 hover:bg-shell">
              <span>
                <span className="block text-sm font-medium text-navy-900">{program.name}</span>
                <span className="block text-xs text-[#8391A4]">{new Date(program.scheduledOn).toDateString()} · {program.location}</span>
              </span>
              <span className="text-xs font-semibold text-navy-700">
                {registrations.filter((r) => r.programId === program.id && r.status === 'registered').length} / {program.maxParticipants || '∞'}
              </span>
            </Link>
          ))}
        </div>
        <div className="card p-4">
          <h3 className="text-sm font-semibold text-navy-800">Recent activity</h3>
          {activity.slice(0, 6).map((log) => (
            <div key={log.id} className="mt-3 border-b border-[#EEF1F4] pb-2 last:border-0">
              <p className="text-sm text-navy-900">{log.description}</p>
              <p className="text-xs text-[#8391A4]">{log.user} · {log.at}</p>
            </div>
          ))}
        </div>
      </div>

      <p className="mt-6 text-xs text-[#8391A4]">
        Newest youth on file: {youth.slice(0, 3).map(fullName).join(', ') || 'none yet'} ·{' '}
        {db.notifications.filter((n) => n.orgId === org.id && !n.read).length} unread notification(s)
      </p>
    </>
  );
}
