import { useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Badge, Confirm, Empty, Field, FormErrors, Section, Stat, Table } from '../../components/ui.jsx';
import { validateProgram, hasErrors } from '../../lib/validation.js';
import { age, fullName, PROGRAM_CATEGORIES, APPLICATION_STATUSES, makeProgramCode } from '../../data/mock.js';
import RequirementsEditor from '../../components/requirements.jsx';
import EligibilityFields from '../../components/eligibility.jsx';
import { FilterBar, SORT_NEWEST } from '../../components/filters.jsx';
import SelectYouthPanel from '../../components/selection.jsx';
import { QrImage } from '../../components/qr.jsx';

const timeRange = (p) => (p.startsAt ? `${p.startsAt}${p.endsAt ? ` – ${p.endsAt}` : ''}` : 'Whole day');

export function ProgramList() {
  const { scoped, db, deleteProgram } = useStore();
  const [query, setQuery] = useState('');
  const [kind, setKind] = useState('');
  const [status, setStatus] = useState('');
  const [sort, setSort] = useState('newest');

  const rows = scoped('programs')
    .filter((p) => (kind ? p.kind === kind : true))
    .filter((p) => (status ? p.status === status : true))
    .filter((p) => p.name.toLowerCase().includes(query.toLowerCase()))
    .sort((a, b) => {
      if (sort === 'name') return a.name.localeCompare(b.name);
      if (sort === 'oldest') return (a.createdAt || '').localeCompare(b.createdAt || '') || a.id - b.id;
      return (b.createdAt || '').localeCompare(a.createdAt || '') || b.id - a.id;
    });

  return (
    <>
      <PageHeader title="Programs & events" subtitle={`${rows.length} record(s)`}
        actions={<>
          <Link to="/sk/programs/new?kind=event" className="btn btn-primary">Add event</Link>
          <Link to="/sk/programs/new?kind=program" className="btn btn-ghost">Add program</Link>
        </>} />

      <FilterBar search={query} onSearch={setQuery} placeholder="Activity name"
        sort={{ value: sort, onChange: setSort, options: SORT_NEWEST }}
        onReset={() => { setQuery(''); setKind(''); setStatus(''); setSort('newest'); }}>
        <Field label="Type">
          <select className="field" value={kind} onChange={(e) => setKind(e.target.value)}>
            <option value="">All types</option>
            <option value="program">Programs</option>
            <option value="event">Events</option>
          </select>
        </Field>
        <Field label="Status">
          <select className="field" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All statuses</option>
            {['draft', 'published', 'ongoing', 'completed', 'archived'].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
          </select>
        </Field>
      </FilterBar>

      {rows.length === 0 ? (
        <Empty title="No programs or events yet" body="Create an activity, publish it, then register participants and record attendance on the day.">
          <Link to="/sk/programs/new" className="btn btn-primary">Add activity</Link>
        </Empty>
      ) : (
        <Table head={['Activity', 'Date', 'Location', 'Registered', 'Status', '']}>
          {rows.map((program) => (
            <tr key={program.id} className="hover:bg-shell">
              <td className="td">
                <span className="font-medium text-navy-900">{program.name}</span>
                <span className="block text-xs text-[#8391A4]">{program.kind[0].toUpperCase() + program.kind.slice(1)} · {program.category || 'Uncategorised'}</span>
              </td>
              <td className="td">{new Date(program.scheduledOn).toDateString()}<span className="block text-xs text-[#8391A4]">{timeRange(program)}</span></td>
              <td className="td">{program.location || '—'}</td>
              <td className="td">{db.registrations.filter((r) => r.programId === program.id && r.status === 'registered').length} / {program.maxParticipants || '∞'}</td>
              <td className="td"><Badge value={program.status} /></td>
              <td className="td">
                <div className="flex flex-wrap gap-1.5">
                  <Link to={`/sk/programs/${program.id}`} className="btn btn-ghost btn-sm">Open</Link>
                  <Confirm className="btn btn-danger btn-sm" message={`Delete "${program.name}" and its registrations?`}
                    onConfirm={() => deleteProgram(program.id)}>Delete</Confirm>
                </div>
              </td>
            </tr>
          ))}
        </Table>
      )}
    </>
  );
}

export function ProgramDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { db, scoped, register, markAttendance, setProgramStatus, targetOf } = useStore();
  const [query, setQuery] = useState('');
  const [youthId, setYouthId] = useState('');

  const program = db.programs.find((p) => p.id === Number(id));
  if (!program) return <Empty title="Activity not found" body="That program or event does not exist in this organization." />;

  const registrations = db.registrations.filter((r) => r.programId === program.id);
  const registered = registrations.filter((r) => r.status === 'registered').length;
  const attended = registrations.filter((r) => r.attendance === 'present').length;
  const taken = new Set(registrations.map((r) => r.youthId));
  const available = scoped('youth').filter((y) => !y.archived && !taken.has(y.id));
  const applications = db.applications.filter((a) => a.targetType === 'program' && a.targetId === program.id);

  const visible = registrations
    .map((r) => ({ ...r, youth: db.youth.find((y) => y.id === r.youthId) }))
    .filter((r) => r.youth && fullName(r.youth).toLowerCase().includes(query.toLowerCase()))
    .sort((a, b) => a.youth.lastName.localeCompare(b.youth.lastName));

  return (
    <>
      <PageHeader title={program.name}
        subtitle={`${new Date(program.scheduledOn).toDateString()} · ${timeRange(program)} · ${program.location || 'No location set'}`}
        actions={<>
          {program.status === 'published' ? (
            <Confirm message="Move this activity back to draft?" onConfirm={() => setProgramStatus(program.id, 'draft')}>Unpublish</Confirm>
          ) : (
            <Confirm className="btn btn-accent" message="Publish this activity?" onConfirm={() => setProgramStatus(program.id, 'published')}>Publish</Confirm>
          )}
          <Link to="/sk/attendance" className="btn btn-ghost">Scan QR</Link>
          <Link to={`/sk/programs/${program.id}/edit`} className="btn btn-ghost">Edit</Link>
          <Confirm className="btn btn-danger" message="Archive this activity?"
            onConfirm={() => { setProgramStatus(program.id, 'archived'); navigate('/sk/programs'); }}>Archive</Confirm>
        </>} />

      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Registered" value={`${registered} / ${program.maxParticipants || '∞'}`} />
        <Stat label="Attended" value={`${attended} / ${registered}`} />
        <Stat label="Status" value={program.status[0].toUpperCase() + program.status.slice(1)} />
        <Stat label="Registration closes" value={program.registrationDeadline || 'Open'} />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <Section title="Registered participants"
            action={<button type="button" className="btn btn-ghost btn-sm" onClick={() => window.print()}>Print list</button>}>
            <input className="field mt-4" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search participants by name" />
            {visible.length === 0 ? (
              <p className="mt-4 text-sm text-[#7A889B]">No participants registered yet. Register a youth from the panel on the right.</p>
            ) : (
              <div className="mt-4 overflow-x-auto">
                <table className="w-full" style={{ minWidth: 640 }}>
                  <thead><tr><th className="th">Name</th><th className="th">Age</th><th className="th">Contact</th><th className="th">Status</th><th className="th">Attendance</th></tr></thead>
                  <tbody>
                    {visible.map((r) => (
                      <tr key={r.id}>
                        <td className="td"><Link to={`/sk/youth/${r.youth.id}`} className="font-medium text-navy-900 underline">{fullName(r.youth)}</Link></td>
                        <td className="td">{age(r.youth.birthDate)}</td>
                        <td className="td">{r.youth.contact || '—'}</td>
                        <td className="td"><Badge value={r.status} /></td>
                        <td className="td">{r.selected ? <Badge value="selected" /> : <span className="text-xs text-[#8391A4]">—</span>}</td>
                        <td className="td">
                          <select className="field !py-1 !text-xs" value={r.attendance || ''} onChange={(e) => markAttendance(r.id, e.target.value)}>
                            <option value="">Not marked</option>
                            {['present', 'absent', 'excused'].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
                          </select>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Section>
        </div>

        <aside className="space-y-4">
          <SelectYouthPanel targetType="program" targetId={program.id} label={program.name}
            alreadySelected={registrations.filter((r) => r.selected).map((r) => r.youthId)} />

          <div className="card p-5 text-center">
            <h2 className="text-sm font-bold text-navy-900">Registration QR</h2>
            <p className="mt-1 text-xs text-[#7A889B]">Print this on the poster. Youth scan it to register themselves.</p>
            <div className="mt-3 flex justify-center"><QrImage value={makeProgramCode(program.id)} size={170} /></div>
            <p className="mt-2 font-mono text-xs text-[#5A6C82]">{makeProgramCode(program.id)}</p>
          </div>

          <form className="card space-y-4 p-5" onSubmit={(e) => { e.preventDefault(); if (!youthId) return; register(program.id, Number(youthId)); setYouthId(''); }}>
            <h2 className="text-sm font-bold text-navy-900">Register a participant</h2>
            <Field label="Youth">
              <select className="field" value={youthId} onChange={(e) => setYouthId(e.target.value)} required>
                <option value="">Select a youth record</option>
                {available.map((y) => <option key={y.id} value={y.id}>{fullName(y)} ({age(y.birthDate)})</option>)}
              </select>
            </Field>
            <button type="submit" className="btn btn-primary w-full">Register participant</button>
            {program.maxParticipants && registered >= program.maxParticipants && (
              <p className="rounded-lg bg-sun-100 px-3 py-2 text-xs text-[#7A5A05]">This activity is full. New registrations go to the waitlist.</p>
            )}
          </form>

          {program.description && (
            <Section title="About this activity">
              <p className="mt-2 text-sm leading-relaxed text-[#4A5B70]">{program.description}</p>
            </Section>
          )}
        </aside>

        <div className="lg:col-span-2">
          <Section title="Applications for this activity"
            action={<Link to="/sk/applications" className="btn btn-ghost btn-sm">Open the review queue</Link>}>
            {applications.length === 0 ? (
              <p className="mt-3 text-sm text-[#7A889B]">No youth have applied through their accounts yet.</p>
            ) : (
              <div className="mt-3 space-y-2">
                {applications.map((application) => {
                  const youth = db.youth.find((y) => y.id === application.youthId);
                  const submitted = db.submissions.filter((s) => s.applicationId === application.id).length;
                  const required = db.requirements.filter((r) => r.targetType === 'program' && r.targetId === program.id).length;
                  return (
                    <Link key={application.id} to={`/sk/applications/${application.id}`}
                      className="flex items-center justify-between rounded-lg border border-line px-3 py-2 hover:bg-shell">
                      <span>
                        <span className="block text-sm font-medium text-navy-900">{youth ? fullName(youth) : '—'}</span>
                        <span className="block text-xs text-[#8391A4]">{submitted} / {required} requirement(s) submitted</span>
                      </span>
                      <Badge value={APPLICATION_STATUSES[application.status]} />
                    </Link>
                  );
                })}
              </div>
            )}
          </Section>
        </div>

        <div className="lg:col-span-2">
          <RequirementsEditor targetType="program" targetId={program.id} />
        </div>
      </div>
    </>
  );
}

const EMPTY = {
  kind: 'event', name: '', category: '', scheduledOn: '', startsAt: '08:00', endsAt: '15:00',
  location: '', description: '', maxParticipants: '', registrationDeadline: '', status: 'draft',
};

export function ProgramForm() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { db, saveProgram } = useStore();
  const existing = id ? db.programs.find((p) => p.id === Number(id)) : null;
  const [searchParams] = useSearchParams();
  const kindFromQuery = searchParams.get('kind');
  const [values, setValues] = useState(existing ? { ...existing } : { ...EMPTY, kind: kindFromQuery || 'event' });
  const [errors, setErrors] = useState({});

  const set = (key) => (e) => {
    setValues((v) => ({ ...v, [key]: e.target.value }));
    setErrors((x) => (x[key] ? { ...x, [key]: undefined } : x));
  };

  return (
    <>
      <PageHeader title={existing ? `Edit ${existing.name}` : 'New activity'} />
      <form className="card space-y-4 p-5" noValidate onSubmit={async (e) => {
        e.preventDefault();
        const found = validateProgram(values);
        if (hasErrors(found)) { setErrors(found); return; }
        const savedId = await saveProgram(values);
        if (savedId) navigate(`/sk/programs/${savedId}`);
      }}>
        <FormErrors errors={errors} />
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Type *">
            <select className="field" value={values.kind} onChange={set('kind')}>
              <option value="event">Event</option><option value="program">Program</option>
            </select>
          </Field>
          <div className="sm:col-span-2">
            <Field label="Name *" error={errors.name}><input className="field" value={values.name} onChange={set('name')} /></Field>
          </div>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Category">
            <input className="field" list="program-categories" value={values.category} onChange={set('category')} />
            <datalist id="program-categories">{PROGRAM_CATEGORIES.map((c) => <option key={c} value={c} />)}</datalist>
          </Field>
          <Field label="Location"><input className="field" value={values.location} onChange={set('location')} /></Field>
        </div>
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Date *" error={errors.scheduledOn}>
            <input className="field" type="date" value={values.scheduledOn} onChange={set('scheduledOn')} />
          </Field>
          <Field label="Start time"><input className="field" type="time" value={values.startsAt} onChange={set('startsAt')} /></Field>
          <Field label="End time" error={errors.endsAt}><input className="field" type="time" value={values.endsAt} onChange={set('endsAt')} /></Field>
        </div>
        <Field label="Description"><textarea className="field" rows="3" value={values.description} onChange={set('description')} /></Field>
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Maximum participants"><input className="field" type="number" min="1" value={values.maxParticipants} onChange={set('maxParticipants')} /></Field>
          <Field label="Registration deadline" error={errors.registrationDeadline}>
            <input className="field" type="date" value={values.registrationDeadline} onChange={set('registrationDeadline')} />
          </Field>
          <Field label="Status *">
            <select className="field" value={values.status} onChange={set('status')}>
              {['draft', 'published', 'ongoing', 'completed', 'archived'].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
            </select>
          </Field>
        </div>
        <EligibilityFields values={values} onChange={setValues} />

        <div className="flex gap-3 pt-1">
          <button type="submit" className="btn btn-primary">{existing ? 'Save changes' : 'Create activity'}</button>
          <button type="button" className="btn btn-ghost" onClick={() => navigate('/sk/programs')}>Cancel</button>
        </div>
      </form>
    </>
  );
}
