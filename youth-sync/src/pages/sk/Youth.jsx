import { useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Badge, Confirm, Empty, Field, FormErrors, Section, Stat, Table } from '../../components/ui.jsx';
import { age, fullName, youthCode, ACCOUNT_STATUSES, PRIORITY_DISCLAIMER, INTEREST_OPTIONS, SKILL_OPTIONS, ACTIVITY_OPTIONS } from '../../data/mock.js';
import EducationFields from '../../components/education.jsx';
import { FilterBar, SORT_NEWEST } from '../../components/filters.jsx';
import { TagInput, CheckboxGroup } from '../../components/tags.jsx';
import Upload from '../../components/upload.jsx';
import PhotoField from '../../components/photo.jsx';
import { validateYouth, digitsOnly, hasErrors, CONTACT_LENGTH } from '../../lib/validation.js';

export function YouthList() {
  const { scoped, remaining, limits, plan, deleteYouth, accountStatusOf, skLoading, skError, retrySk } = useStore();
  const [query, setQuery] = useState('');
  const [priority, setPriority] = useState('');
  const [employment, setEmployment] = useState('');
  const [archived, setArchived] = useState(false);
  const [sort, setSort] = useState('newest');
  const [page, setPage] = useState(1);

  const rows = useMemo(() => scoped('youth').filter((y) => {
    if (y.archived !== archived) return false;
    if (priority && y.level !== priority) return false;
    if (employment && y.employment !== employment) return false;
    if (!query) return true;
    const term = query.toLowerCase();
    return [y.firstName, y.lastName, y.school, y.address, y.contact].join(' ').toLowerCase().includes(term);
  }).sort((a, b) => {
    // Newest first is the default. Records added today sort above the seeded ones,
    // and ties fall back to id so the most recent insert always wins.
    if (sort === 'name') return a.lastName.localeCompare(b.lastName);
    if (sort === 'oldest') return (a.createdAt || '').localeCompare(b.createdAt || '') || a.id - b.id;
    return (b.createdAt || '').localeCompare(a.createdAt || '') || b.id - a.id;
  }), [scoped, query, priority, employment, archived, sort]);

  const perPage = 15;
  const pages = Math.max(1, Math.ceil(rows.length / perPage));
  const current = rows.slice((page - 1) * perPage, page * perPage);

  return (
    <>
      <PageHeader
        title={archived ? 'Archived youth records' : 'Youth records'}
        subtitle={`${rows.length} record(s) · ${limits.youth === null ? 'unlimited capacity' : `${remaining('youth').toLocaleString()} slot(s) left on the ${plan.name} plan`}`}
        actions={<>
          <button type="button" className="btn btn-ghost" onClick={() => { setArchived((v) => !v); setPage(1); }}>
            {archived ? 'Back to active' : 'Archived'}
          </button>
          <Link to="/sk/youth/import" className="btn btn-ghost">Import CSV</Link>
          <Link to="/sk/youth/new" className="btn btn-primary">Add youth</Link>
        </>}
      />

      <FilterBar search={query} onSearch={(v) => { setQuery(v); setPage(1); }}
        placeholder="Name, school, address, or contact"
        sort={{ value: sort, onChange: (v) => { setSort(v); setPage(1); }, options: SORT_NEWEST }}
        onReset={() => { setQuery(''); setPriority(''); setEmployment(''); setSort('newest'); setPage(1); }}>
        <Field label="Priority">
          <select className="field" value={priority} onChange={(e) => { setPriority(e.target.value); setPage(1); }}>
            <option value="">All priorities</option>
            <option>High</option><option>Medium</option>
          </select>
        </Field>
        <Field label="Employment">
          <select className="field" value={employment} onChange={(e) => { setEmployment(e.target.value); setPage(1); }}>
            <option value="">All employment</option>
            {['Employed', 'Unemployed', 'Self-employed', 'Student'].map((s) => <option key={s}>{s}</option>)}
          </select>
        </Field>
      </FilterBar>

      {skLoading ? (
        <Empty title="Loading youth records" body="Fetching the latest records from your organization." />
      ) : skError ? (
        <Empty title="Youth records could not be loaded" body={skError}>
          <button type="button" className="btn btn-primary" onClick={() => retrySk()}>Retry</button>
        </Empty>
      ) : rows.length === 0 ? (
        <Empty title="No youth records here yet" body="Add your first youth record, or import an existing list from a CSV file.">
          <Link to="/sk/youth/new" className="btn btn-primary">Add youth record</Link>
        </Empty>
      ) : (
        <>
          <Table head={['Name', 'Age', 'Education', 'Employment', 'Priority', 'Account', 'Actions']} minWidth={880}>
            {current.map((y) => (
              <tr key={y.id} className="hover:bg-shell">
                <td className="td">
                  <span className="font-medium text-navy-900">{fullName(y)}</span>
                  <span className="block text-xs text-[#8391A4]">{y.address}</span>
                </td>
                <td className="td">{age(y.birthDate)}</td>
                <td className="td">{y.education}<span className="block text-xs text-[#8391A4]">{y.school || '—'}</span></td>
                <td className="td">{y.employment}</td>
                <td className="td"><Badge value={y.level} /></td>
                <td className="td"><Badge value={ACCOUNT_STATUSES[accountStatusOf(y)]} /></td>
                <td className="td">
                  <div className="flex flex-wrap gap-1.5">
                    <Link to={`/sk/youth/${y.id}`} className="btn btn-ghost btn-sm">View</Link>
                    <Link to={`/sk/youth/${y.id}/edit`} className="btn btn-ghost btn-sm">Edit</Link>
                    <Confirm className="btn btn-danger btn-sm"
                      message={`Delete ${fullName(y)} permanently? This also removes their registrations and applications.`}
                      onConfirm={() => deleteYouth(y.id)}>Delete</Confirm>
                  </div>
                </td>
              </tr>
            ))}
          </Table>
          {pages > 1 && (
            <div className="mt-4 flex items-center gap-2">
              <button type="button" className="btn btn-ghost btn-sm" disabled={page === 1} onClick={() => setPage((p) => p - 1)}>Previous</button>
              <span className="text-sm text-[#5A6C82]">Page {page} of {pages}</span>
              <button type="button" className="btn btn-ghost btn-sm" disabled={page === pages} onClick={() => setPage((p) => p + 1)}>Next</button>
            </div>
          )}
        </>
      )}
    </>
  );
}

export function YouthDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { db, scoped, archiveYouth, restoreYouth, deleteYouth, targetOf } = useStore();
  const youth = db.youth.find((y) => y.id === Number(id));
  if (!youth) return <Empty title="Record not found" body="That youth record does not exist in this organization." />;

  const assistance = scoped('assistance');
  const programs = scoped('programs');
  const records = db.beneficiaries.filter((b) => b.youthId === youth.id);
  const joined = db.registrations.filter((r) => r.youthId === youth.id);

  const groups = [
    ['Personal information', [['Address', youth.address], ['Contact number', youth.contact], ['Email', youth.email],
      ['Date of birth', new Date(youth.birthDate).toDateString()], ['Civil status', youth.civilStatus]]],
    ['Education', [['Education status', youth.educationStatus], ['Educational level', youth.education],
      ['School', youth.school], ['Course / Program', youth.course],
      ['Year / Grade level', youth.yearLevel], ['Strand', youth.strand]]],
    ['Employment', [['Employment status', youth.employment], ['Occupation', youth.occupation]]],
    ['Family', [['Parent or guardian', youth.guardianName], ['Parent employment', youth.guardianEmployment],
      ['Parent occupation', youth.guardianOccupation],
      ['Monthly family income', youth.familyIncome ? `₱${Number(youth.familyIncome).toLocaleString()}` : ''],
      ['Family members', youth.familyMembers]]],
    ['Skills and interests', [
      ['Skills', (youth.skills || []).join(', ')],
      ['Interests', (youth.interests || []).join(', ')],
      ['Preferred activities', (youth.preferredActivities || []).join(', ')],
    ]],
  ];

  return (
    <>
      <PageHeader
        title={fullName(youth)}
        subtitle={`${age(youth.birthDate)} years old · ${youth.gender} · added ${youth.createdAt || 'earlier'}`}
        actions={<>
          <Link to={`/sk/youth/${youth.id}/edit`} className="btn btn-ghost">Edit</Link>
          {youth.archived ? (
            <Confirm message="Restore this youth record?" onConfirm={() => restoreYouth(youth.id)}>Restore</Confirm>
          ) : (
            <Confirm className="btn btn-danger" message="Archive this youth record? It can be restored later."
              onConfirm={async () => { await archiveYouth(youth.id); navigate('/sk/youth'); }}>Archive</Confirm>
          )}
          <Confirm className="btn btn-danger"
            message={`Delete ${fullName(youth)} permanently? This cannot be undone.`}
            onConfirm={() => { deleteYouth(youth.id); navigate('/sk/youth'); }}>Delete</Confirm>
          <Link to="/sk/youth" className="btn btn-ghost">Back to list</Link>
        </>}
      />

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          {groups.map(([title, fields]) => (
            <Section key={title} title={title}>
              <dl className="mt-3 grid gap-3 sm:grid-cols-2">
                {fields.map(([label, value]) => (
                  <div key={label}>
                    <dt className="text-xs font-medium text-[#7A889B]">{label}</dt>
                    <dd className="text-sm text-navy-900">{value || '—'}</dd>
                  </div>
                ))}
              </dl>
            </Section>
          ))}

          <Section title="Assistance received">
            {records.length === 0 ? <p className="mt-3 text-sm text-[#7A889B]">No assistance records yet.</p> : records.map((record) => {
              const program = assistance.find((a) => a.id === record.programId);
              return (
                <div key={record.id} className="mt-3 flex items-center justify-between border-b border-[#EEF1F4] pb-2 last:border-0">
                  <div>
                    <p className="text-sm font-medium text-navy-900">{program?.name}</p>
                    <p className="text-xs text-[#8391A4]">{program?.category} · {record.awardedOn || 'Not yet awarded'}</p>
                  </div>
                  <Badge value={record.status} />
                </div>
              );
            })}
          </Section>

          <Section title="Applications">
            {db.applications.filter((a) => a.youthId === youth.id).length === 0
              ? <p className="mt-3 text-sm text-[#7A889B]">This youth has not applied for anything yet.</p>
              : db.applications.filter((a) => a.youthId === youth.id).map((application) => (
                <Link key={application.id} to={`/sk/applications/${application.id}`}
                  className="mt-3 flex items-center justify-between border-b border-[#EEF1F4] pb-2 last:border-0">
                  <div>
                    <p className="text-sm font-medium text-navy-900">{targetOf(application)?.name}</p>
                    <p className="text-xs text-[#8391A4]">
                      {db.submissions.filter((s) => s.applicationId === application.id).length} requirement(s) submitted
                    </p>
                  </div>
                  <Badge value={application.status.replace(/_/g, ' ')} />
                </Link>
              ))}
          </Section>

          <Section title="Program participation">
            {joined.length === 0 ? <p className="mt-3 text-sm text-[#7A889B]">Has not joined any activity yet.</p> : joined.map((registration) => {
              const program = programs.find((p) => p.id === registration.programId);
              return (
                <div key={registration.id} className="mt-3 flex items-center justify-between border-b border-[#EEF1F4] pb-2 last:border-0">
                  <div>
                    <p className="text-sm font-medium text-navy-900">{program?.name}</p>
                    <p className="text-xs text-[#8391A4]">{program && new Date(program.scheduledOn).toDateString()}</p>
                  </div>
                  <Badge value={registration.status} />
                </div>
              );
            })}
          </Section>
        </div>

        <aside className="space-y-4">
          {youth.photo?.dataUrl && (
            <Section title="Profile photo">
              <img src={youth.photo.dataUrl} alt={fullName(youth)} className="mt-3 h-40 w-full rounded-lg border border-line object-cover" />
            </Section>
          )}
          <Section title="Priority recommendation">
            <p className={`mt-3 text-3xl font-bold ${youth.level === 'High' ? 'text-[#B3261E]' : 'text-[#B98407]'}`}>{(youth.level || '—').toString().toUpperCase()}</p>
            <p className="text-xs text-[#8391A4]">Screening score {youth.score}</p>
            <h3 className="mt-4 text-xs font-semibold text-[#5A6C82]">Reasons</h3>
            <ul className="mt-1.5 space-y-1 text-sm text-[#4A5B70]">
              {(youth.reasons || []).map((reason) => <li key={reason}>· {reason}</li>)}
            </ul>
            <p className="mt-4 rounded-lg bg-sun-100 px-3 py-2 text-xs leading-relaxed text-[#7A5A05]">{PRIORITY_DISCLAIMER}</p>
          </Section>
        </aside>
      </div>
    </>
  );
}

const EMPTY = {
  firstName: '', middleName: '', lastName: '', birthDate: '', gender: 'Male', address: '', contact: '', email: '',
  civilStatus: 'Single', educationStatus: 'Currently Studying', education: 'Senior High School',
  school: '', course: '', yearLevel: '', strand: '', studying: true,
  employment: 'Student', occupation: '', guardianName: '', guardianEmployment: '', guardianOccupation: '',
  familyIncome: '', familyMembers: '', skills: [], interests: [], preferredActivities: [],
  previousScholarship: false, previousAssistance: false, previousParticipation: false, photo: null,
};

export function YouthForm() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { db, saveYouth, saveYouthWithAccount, canAdd, limitMessage, notify, plan, usage, limits, mutating } = useStore();
  const [withAccount, setWithAccount] = useState(false);
  const [accountEmail, setAccountEmail] = useState('');
  const [issued, setIssued] = useState(null);
  const existing = id ? db.youth.find((y) => y.id === Number(id)) : null;
  // Checked on render as well as on submit, so opening the route directly
  // cannot get around the plan limit.
  const blocked = !existing && !canAdd('youth');
  const [values, setValues] = useState(existing ? { ...EMPTY, ...existing } : EMPTY);
  const [errors, setErrors] = useState({});

  const set = (key) => (event) => {
    const target = event.target;
    const value = key === 'contact'
      ? digitsOnly(target.value)                    // numbers only, capped at 11
      : target.type === 'checkbox' ? target.checked : target.value;

    setValues((v) => ({ ...v, [key]: value }));
    // Clear the message as soon as the field is touched again.
    setErrors((e) => (e[key] ? { ...e, [key]: undefined } : e));
  };

  const submit = async (event) => {
    event.preventDefault();
    if (!existing && !canAdd('youth')) { notify(limitMessage('youth'), 'warning'); return; }

    const found = validateYouth(values);
    if (hasErrors(found)) {
      setErrors(found);
      notify('Some required fields still need attention.', 'warning');
      return;
    }

    if (withAccount && !existing) {
      const result = await saveYouthWithAccount(values, { email: accountEmail });
      if (!result?.id) return;
      if (result.password) { setIssued(result); return; }
      navigate(`/sk/youth/${result.id}`);
      return;
    }

    const savedId = await saveYouth(values);
    if (savedId) navigate(`/sk/youth/${savedId}`);
  };

  if (issued) {
    return (
      <>
        <PageHeader title="Youth account created"
          breadcrumb={[{ label: 'Youth Records', to: '/sk/youth' }, { label: 'Account created' }]} />
        <div className="card card-pad max-w-lg">
          <p className="t-body">Give these sign-in details to the youth. The temporary password is shown once.</p>
          <dl className="mt-4 space-y-2.5 border-t border-line pt-4 text-sm">
            <div className="flex justify-between gap-4"><dt className="text-ink-muted">Youth ID</dt><dd className="font-mono font-semibold">{issued.code}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-ink-muted">Email</dt><dd className="font-semibold">{accountEmail}</dd></div>
            <div className="flex justify-between gap-4"><dt className="text-ink-muted">Temporary password</dt><dd className="font-mono text-base font-bold">{issued.password}</dd></div>
          </dl>
          <p className="mt-4 rounded-lg bg-info-bg px-3 py-2 text-xs text-info">
            The youth is required to set their own password at first sign-in.
          </p>
          <div className="mt-5 flex gap-2">
            <button type="button" className="btn btn-primary" onClick={() => navigate(`/sk/youth/${issued.id}`)}>View record</button>
            <Link to="/sk/youth" className="btn btn-secondary">Back to Youth Records</Link>
          </div>
        </div>
      </>
    );
  }

  if (blocked) {
    return (
      <>
        <PageHeader title="Add youth record"
          actions={<Link to="/sk/youth" className="btn btn-ghost">Back to youth</Link>} />
        <Empty title="Youth limit reached — upgrade your plan to add more Youth"
          body={`You are using ${usage.youth} of ${limits.youth} youth records on the ${plan.name} plan. Existing records are untouched.`}>
          <Link to="/sk/subscription/plans" className="btn btn-primary">Upgrade plan</Link>
        </Empty>
      </>
    );
  }

  return (
    <>
      <PageHeader title={existing ? `Edit ${fullName(existing)}` : 'Add youth record'} subtitle="Fields marked with * are required." />
      <form onSubmit={submit} className="space-y-5" noValidate>
        <FormErrors errors={errors} />
        <Section title="Personal information">
          <div className="mt-4 grid gap-4 sm:grid-cols-3">
            <Field label="First name *" error={errors.firstName}>
              <input className="field" value={values.firstName} onChange={set('firstName')} />
            </Field>
            <Field label="Middle name"><input className="field" value={values.middleName} onChange={set('middleName')} /></Field>
            <Field label="Last name *" error={errors.lastName}>
              <input className="field" value={values.lastName} onChange={set('lastName')} />
            </Field>
            <Field label="Date of birth *" error={errors.birthDate}>
              <input className="field" type="date" value={values.birthDate} onChange={set('birthDate')} />
            </Field>
            <Field label="Gender *">
              <select className="field" value={values.gender} onChange={set('gender')}>
                {['Male', 'Female', 'Prefer not to say'].map((g) => <option key={g}>{g}</option>)}
              </select>
            </Field>
            <Field label="Civil status *">
              <select className="field" value={values.civilStatus} onChange={set('civilStatus')}>
                {['Single', 'Married', 'Widowed', 'Separated'].map((s) => <option key={s}>{s}</option>)}
              </select>
            </Field>
            <div className="sm:col-span-2">
              <Field label="Address *" error={errors.address}>
                <input className="field" value={values.address} onChange={set('address')} />
              </Field>
            </div>
            <Field label="Contact number *" error={errors.contact}
              hint={`Exactly ${CONTACT_LENGTH} digits, numbers only. Simulated SMS is sent here automatically.`}>
              <input className="field" type="tel" inputMode="numeric" maxLength={CONTACT_LENGTH}
                placeholder="09171234567" value={values.contact} onChange={set('contact')} />
            </Field>
            <Field label="Email address" error={errors.email} hint="Used for the simulated email notifications.">
              <input className="field" type="email" placeholder="name@example.com" value={values.email} onChange={set('email')} />
            </Field>
          </div>
        </Section>

        <Section title="Education">
          <div className="mt-4">
            <EducationFields values={values} onChange={setValues} errors={errors} />
          </div>
        </Section>

        {!existing && (
          <Section title="Youth account">
            <p className="mt-1 t-help">
              A youth record can exist without a login. Create an account only if this youth will sign in.
            </p>
            <label className="mt-4 flex items-start gap-2.5 text-sm text-ink-muted">
              <input type="checkbox" className="mt-0.5 h-4 w-4 rounded border-line-strong"
                checked={withAccount} onChange={(e) => setWithAccount(e.target.checked)} />
              Create a youth account for this record
            </label>
            {withAccount && (
              <div className="mt-4 max-w-md">
                <Field label={<>Account email <span className="required">*</span></>}
                  hint="Used to sign in. A temporary password is issued once the record is saved.">
                  <input className="field" type="email" value={accountEmail}
                    onChange={(e) => setAccountEmail(e.target.value)} required={withAccount} />
                </Field>
              </div>
            )}
          </Section>
        )}

        <Section title="Profile photo and documents">
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <PhotoField value={values.photo}
              onChange={(file) => setValues((v) => ({ ...v, photo: file }))}
              onRemove={() => setValues((v) => ({ ...v, photo: null }))} />
            <div>
              <p className="label">Supporting document</p>
              <Upload label="Upload document" value={values.document}
                onChange={(file) => setValues((v) => ({ ...v, document: file }))}
                onRemove={() => setValues((v) => ({ ...v, document: null }))} />
              <p className="mt-1 text-xs text-[#8391A4]">Optional. Per-opportunity requirements are uploaded by the youth on their application.</p>
            </div>
          </div>
        </Section>

        <Section title="Employment">
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <Field label="Employment status *">
              <select className="field" value={values.employment} onChange={set('employment')}>
                {['Student', 'Employed', 'Unemployed', 'Self-employed'].map((e) => <option key={e}>{e}</option>)}
              </select>
            </Field>
            <Field label="Occupation"><input className="field" value={values.occupation} onChange={set('occupation')} /></Field>
          </div>
        </Section>

        <Section title="Family">
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <Field label="Parent or guardian"><input className="field" value={values.guardianName} onChange={set('guardianName')} /></Field>
            <Field label="Parent employment status">
              <select className="field" value={values.guardianEmployment} onChange={set('guardianEmployment')}>
                <option value="">Not recorded</option>
                {['Employed', 'Unemployed', 'Self-employed', 'Retired'].map((e) => <option key={e}>{e}</option>)}
              </select>
            </Field>
            <Field label="Parent occupation"><input className="field" value={values.guardianOccupation} onChange={set('guardianOccupation')} /></Field>
            <Field label="Monthly family income (₱)"><input className="field" type="number" min="0" value={values.familyIncome} onChange={set('familyIncome')} /></Field>
            <Field label="Number of family members"><input className="field" type="number" min="1" max="30" value={values.familyMembers} onChange={set('familyMembers')} /></Field>
          </div>
          <p className="mt-3 text-xs text-[#8391A4]">Income and household size feed the priority recommendation. {PRIORITY_DISCLAIMER}</p>
        </Section>

        <Section title="Skills, interests and preferred activities">
          <div className="mt-4 space-y-5">
            <TagInput label="Skills" values={values.skills || []} options={SKILL_OPTIONS}
              onChange={(v) => setValues((x) => ({ ...x, skills: v }))} addLabel="Add Skill"
              placeholder="No skills added yet." hint="One entry per skill." />
            <TagInput label="Interests" required error={errors.interests} addLabel="Add Interest"
              values={values.interests || []} options={INTEREST_OPTIONS}
              onChange={(v) => setValues((x) => ({ ...x, interests: v }))}
              placeholder="No interests added yet." hint="At least one interest is required." />
            <CheckboxGroup label="Preferred activities" options={ACTIVITY_OPTIONS}
              values={values.preferredActivities || []}
              onChange={(v) => setValues((x) => ({ ...x, preferredActivities: v }))}
              hint="Used to match this youth with suitable programs and events." />
          </div>
        </Section>

        <Section title="Assistance history">
          <div className="mt-4 space-y-2 text-sm text-[#4A5B70]">
            {[['previousScholarship', 'Previously received a scholarship'],
              ['previousAssistance', 'Previously received financial assistance'],
              ['previousParticipation', 'Previously joined an SK program']].map(([key, label]) => (
              <label key={key} className="flex items-center gap-2">
                <input type="checkbox" className="h-4 w-4 rounded border-[#C7D0DA]" checked={values[key]} onChange={set(key)} /> {label}
              </label>
            ))}
          </div>
        </Section>

        <div className="flex gap-3">
          <button type="submit" className="btn btn-primary" disabled={mutating}>{existing ? 'Save changes' : 'Add youth record'}</button>
          <button type="button" className="btn btn-ghost" onClick={() => navigate(-1)}>Cancel</button>
        </div>
      </form>
    </>
  );
}

const REQUIRED = ['first_name', 'last_name', 'birth_date', 'gender', 'address'];
const SAMPLE_CSV = `first_name,middle_name,last_name,birth_date,gender,address,contact_number,education,school,family_income,family_members
Juan,Reyes,Dela Cruz,2005-03-14,Male,"Purok 2, Barangay Ibaba",09171234567,Senior High School,Paete National High School,9000,6
Angelica,Cruz,Bagsic,2007-08-02,Female,"Purok 4, Barangay Ibaba",09181234567,Junior High School,Paete National High School,15000,5
,,Torres,2006-01-01,Male,"Purok 1",,,,,`;

export function YouthImport() {
  const { allows, importYouth, scoped, plan } = useStore();
  const [raw, setRaw] = useState('');
  const [preview, setPreview] = useState(null);

  const parse = (text) => {
    const lines = text.trim().split(/\r?\n/).filter(Boolean);
    if (lines.length < 2) return { error: 'The file needs a header row and at least one data row.' };
    const header = lines[0].split(',').map((h) => h.trim().toLowerCase().replace(/\s+/g, '_'));
    const missing = REQUIRED.filter((r) => !header.includes(r));
    if (missing.length) return { error: `Missing required column(s): ${missing.join(', ')}.` };

    const existing = new Set(scoped('youth').map((y) => `${y.firstName}|${y.lastName}|${y.birthDate}`.toLowerCase()));
    const seen = new Set();
    const valid = []; const invalid = []; const duplicates = [];

    lines.slice(1).forEach((line, index) => {
      const cells = line.match(/("[^"]*"|[^,]*)/g).filter((_, i) => i % 2 === 0).map((c) => c.replace(/^"|"$/g, '').trim());
      const row = Object.fromEntries(header.map((h, i) => [h, cells[i] ?? '']));
      const errors = REQUIRED.filter((r) => !row[r]).map((r) => `Missing ${r.replace(/_/g, ' ')}`);
      if (row.birth_date && Number.isNaN(Date.parse(row.birth_date))) errors.push('Birth date is not a valid date (use YYYY-MM-DD)');

      const name = `${row.first_name} ${row.last_name}`.trim();
      if (errors.length) { invalid.push({ row: index + 2, name, errors }); return; }

      const key = `${row.first_name}|${row.last_name}|${row.birth_date}`.toLowerCase();
      if (existing.has(key) || seen.has(key)) { duplicates.push({ row: index + 2, name, errors: ['Already exists in your youth records'] }); return; }
      seen.add(key);

      valid.push({
        firstName: row.first_name, middleName: row.middle_name || '', lastName: row.last_name,
        birthDate: row.birth_date, gender: row.gender || 'Male', address: row.address,
        contact: row.contact_number || '', civilStatus: 'Single',
        educationStatus: 'Currently Studying',
        education: row.education || 'Senior High School', school: row.school || '',
        course: '', yearLevel: '', strand: '', studying: true, employment: 'Student', occupation: '',
        guardianName: '', guardianEmployment: '', guardianOccupation: '',
        familyIncome: Number(row.family_income) || 0, familyMembers: Number(row.family_members) || 0,
        skills: [], interests: row.interest ? [row.interest] : ['Community service'], preferredActivities: [],
        previousScholarship: false, previousAssistance: false, previousParticipation: false,
      });
    });

    return { total: lines.length - 1, valid, invalid, duplicates };
  };

  if (!allows('csv_import')) {
    return (
      <>
        <PageHeader title="Import youth records from CSV" />
        <Empty title={`CSV import is not included in the ${plan.name} plan`}
          body="Upgrade to Basic or Premium to import an existing youth list. You can still add records one at a time.">
          <Link to="/sk/subscription/plans" className="btn btn-primary">View plans</Link>
        </Empty>
      </>
    );
  }

  return (
    <>
      <PageHeader title="Import youth records from CSV"
        subtitle="Paste your CSV, review the preview, then confirm. Nothing is saved until you confirm."
        actions={<button type="button" className="btn btn-ghost" onClick={() => setRaw(SAMPLE_CSV)}>Load sample CSV</button>} />

      {!preview ? (
        <Section title="Step 1 — Paste the file contents">
          <p className="mt-1 text-sm text-[#5A6C82]">
            Required columns: first_name, last_name, birth_date (YYYY-MM-DD), gender, address.
            Optional: middle_name, contact_number, education, school, family_income, family_members.
          </p>
          <textarea className="field mt-4 font-mono text-xs" rows="10" value={raw} onChange={(e) => setRaw(e.target.value)}
            placeholder="first_name,last_name,birth_date,gender,address" />
          <button type="button" className="btn btn-primary mt-4" onClick={() => {
            const result = parse(raw);
            if (result.error) { window.alert(result.error); return; }
            setPreview(result);
          }}>Validate and preview</button>
        </Section>
      ) : (
        <Section title="Step 2 — Review the preview">
          <p className="mt-1 text-sm text-[#5A6C82]">{preview.total} record(s) detected.</p>
          <div className="mt-4 grid grid-cols-3 gap-3">
            <Stat label="Valid" value={preview.valid.length} />
            <Stat label="Duplicates" value={preview.duplicates.length} />
            <Stat label="Invalid" value={preview.invalid.length} />
          </div>

          {[...preview.invalid, ...preview.duplicates].length > 0 && (
            <>
              <h3 className="mt-6 text-sm font-semibold text-navy-900">Rows that will be skipped</h3>
              <div className="mt-2 max-h-64 overflow-y-auto rounded-lg border border-line">
                <table className="w-full">
                  <thead><tr><th className="th">Row</th><th className="th">Name</th><th className="th">Problem</th></tr></thead>
                  <tbody>
                    {[...preview.invalid, ...preview.duplicates].map((row) => (
                      <tr key={`${row.row}-${row.name}`}>
                        <td className="td">{row.row}</td>
                        <td className="td">{row.name || '—'}</td>
                        <td className="td text-[#96201A]">{row.errors.join('; ')}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}

          {preview.valid.length > 0 && (
            <>
              <h3 className="mt-6 text-sm font-semibold text-navy-900">First rows that will be imported</h3>
              <div className="mt-2 overflow-x-auto rounded-lg border border-line">
                <table className="w-full" style={{ minWidth: 560 }}>
                  <thead><tr><th className="th">Name</th><th className="th">Birth date</th><th className="th">Gender</th><th className="th">Address</th></tr></thead>
                  <tbody>
                    {preview.valid.slice(0, 8).map((row, i) => (
                      <tr key={i}>
                        <td className="td">{row.firstName} {row.lastName}</td>
                        <td className="td">{row.birthDate}</td>
                        <td className="td">{row.gender}</td>
                        <td className="td">{row.address}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}

          <div className="mt-6 flex flex-wrap gap-3">
            {preview.valid.length > 0 && (
              <Confirm className="btn btn-primary" message={`Import ${preview.valid.length} youth records?`}
                onConfirm={() => { importYouth(preview.valid); setPreview(null); setRaw(''); }}>
                Confirm import of {preview.valid.length} records
              </Confirm>
            )}
            <button type="button" className="btn btn-ghost" onClick={() => setPreview(null)}>Cancel and paste another file</button>
          </div>
        </Section>
      )}
    </>
  );
}
