import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Badge, Confirm, Empty, Field, FormErrors, NeedsStoreData, Pagination, Section, Table, Tabs } from '../../components/ui.jsx';
import { fullName, PRIORITY_DISCLAIMER, APPLICATION_STATUSES } from '../../data/mock.js';
import RequirementsEditor from '../../components/requirements.jsx';
import EligibilityFields from '../../components/eligibility.jsx';
import SelectYouthPanel from '../../components/selection.jsx';
import { mapAssistance } from '../../lib/mappers.js';
import { pageSizesFor, useDebouncedValue, usePagedList } from '../../lib/usePagedList.js';

const CATEGORY_LABEL = { scholarship: 'Scholarship', financial: 'Financial assistance', other: 'Other assistance' };

export function AssistanceList() {
  const { org, db, dataVersion } = useStore();
  const [tab, setTab] = useState('all');
  const [search, setSearch] = useState('');
  const query = useDebouncedValue(search.trim(), 300);

  // GET /sk/assistance: category tab, search (name, description) and paging on
  // the server. `counts` gives every tab's total for the organization.
  const list = usePagedList('/sk/assistance', {
    category: tab === 'all' ? undefined : tab,
    q: query || undefined,
  }, { perPage: 25, enabled: dataVersion > 0, refreshKey: dataVersion, errorMessage: 'Unable to load assistance programs.' });
  const rows = list.rows.map((row) => mapAssistance(row, org?.id));
  const counts = list.data?.counts || {};
  const count = (key) => (list.data ? Number(counts[key]) || 0 : undefined);

  return (
    <>
      <PageHeader title="Assistance management"
        subtitle="Scholarships, financial assistance, and your own assistance types."
        actions={<Link to="/sk/assistance/new" className="btn btn-primary">New assistance program</Link>} />

      <Tabs active={tab} onSelect={setTab} items={[
        { key: 'all', label: 'All assistance', count: count('all') },
        { key: 'scholarship', label: 'Scholarship', count: count('scholarship') },
        { key: 'financial', label: 'Financial assistance', count: count('financial') },
        { key: 'other', label: 'Other assistance', count: count('other') },
      ]} />

      <div className="card mb-4 p-4">
        <Field label="Search programs"><input className="field" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name or description" /></Field>
      </div>

      {list.error ? (
        <Empty title="Assistance programs could not be loaded" body={list.error}>
          <button type="button" className="btn btn-primary" onClick={list.reload}>Retry</button>
        </Empty>
      ) : !list.data ? (
        <Empty title="Loading assistance programs" body="Fetching the latest programs from your organization." />
      ) : rows.length === 0 ? (
        query ? (
          <Empty title="No results found" body="No assistance programs in this tab match your search.">
            <button type="button" className="btn btn-ghost" onClick={() => setSearch('')}>Clear search</button>
          </Empty>
        ) : (
          <Empty title="No assistance programs in this tab"
            body="Create a scholarship, financial assistance, or a custom assistance program to start tracking beneficiaries.">
            <Link to="/sk/assistance/new" className="btn btn-primary">New assistance program</Link>
          </Empty>
        )
      ) : (
        <>
          {list.loading && <p className="mb-2 text-sm text-[#5A6C82]">Loading assistance programs…</p>}
          <div className="grid gap-4 lg:grid-cols-2">
            {rows.map((program) => {
              // The list row carries this program's beneficiaries (GET /sk/assistance).
              const beneficiaries = Array.isArray(program.beneficiaries)
                ? program.beneficiaries
                : db.beneficiaries.filter((b) => b.programId === program.id);
              const approved = beneficiaries.filter((b) => ['approved', 'released'].includes(b.status)).length;
              return (
                <Link key={program.id} to={`/sk/assistance/${program.id}`} className="card block p-5 hover:border-navy-600">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <h3 className="text-sm font-semibold text-navy-900">{program.name}</h3>
                      <p className="text-xs text-[#8391A4]">{CATEGORY_LABEL[program.category]}</p>
                    </div>
                    <Badge value={program.status} />
                  </div>
                  <p className="mt-3 line-clamp-2 text-sm text-[#5A6C82]">{program.description || 'No description.'}</p>
                  <dl className="mt-4 grid grid-cols-3 gap-2 text-xs">
                    <div><dt className="text-[#8391A4]">Slots filled</dt><dd className="font-semibold text-navy-900">{approved} / {program.slots || '∞'}</dd></div>
                    <div><dt className="text-[#8391A4]">Deadline</dt><dd className="font-semibold text-navy-900">{program.deadline || '—'}</dd></div>
                    <div><dt className="text-[#8391A4]">Applicants</dt><dd className="font-semibold text-navy-900">{beneficiaries.length}</dd></div>
                  </dl>
                </Link>
              );
            })}
          </div>
          <Pagination page={list.page} pageSize={list.perPage} total={list.total} totalPages={list.totalPages}
            onPage={list.setPage} loading={list.loading} noun="assistance programs"
            pageSizes={pageSizesFor(25)} onPageSize={list.setPerPage} />
        </>
      )}
    </>
  );
}

// Beneficiaries, requirements, applications and the youth picker.
const ASSISTANCE_DETAIL_DATA = ['assistance', 'applications', 'youth'];

export function AssistanceDetail() {
  return <NeedsStoreData keys={ASSISTANCE_DETAIL_DATA}><AssistanceDetailView /></NeedsStoreData>;
}

function AssistanceDetailView() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { db, scoped, saveBeneficiary, removeBeneficiary, archiveAssistance, closeAssistance, mutating } = useStore();
  const program = db.assistance.find((a) => a.id === Number(id));
  const [form, setForm] = useState({ youthId: '', status: 'applied', remarks: '' });

  if (!program) return <Empty title="Program not found" body="That assistance program does not exist in this organization." />;

  const youthOf = (youthId) => db.youth.find((y) => y.id === youthId);
  const byNeed = (leftId, rightId) => {
    const left = youthOf(leftId);
    const right = youthOf(rightId);
    const rank = (youth) => (youth?.level === 'High' ? 0 : youth?.level === 'Medium' ? 1 : 2);
    const income = (youth) => (Number(youth?.familyIncome) > 0 ? Number(youth.familyIncome) : Number.MAX_SAFE_INTEGER);
    return rank(left) - rank(right) || income(left) - income(right) || leftId - rightId;
  };
  const beneficiaries = db.beneficiaries
    .filter((b) => b.programId === program.id)
    .sort((a, b) => byNeed(a.youthId, b.youthId));
  const applications = db.applications
    .filter((a) => a.targetType === 'assistance' && a.targetId === program.id)
    .sort((a, b) => byNeed(a.youthId, b.youthId));
  const approved = beneficiaries.filter((b) => ['approved', 'released'].includes(b.status)).length;
  const taken = new Set(beneficiaries.map((b) => b.youthId));
  const candidates = scoped('youth')
    .filter((y) => !y.archived && !taken.has(y.id))
    .sort((a, b) => (a.level === b.level ? b.score - a.score : a.level === 'High' ? -1 : 1))
    .slice(0, 20);

  return (
    <>
      <PageHeader title={program.name}
        subtitle={`${CATEGORY_LABEL[program.category]} · ${approved} / ${program.slots || '∞'} slot(s) filled`}
        actions={<>
          <Link to={`/sk/assistance/${program.id}/edit`} className="btn btn-ghost">Edit</Link>
          {!['closed', 'archived'].includes(program.status) && (
            <Confirm disabled={mutating} className="btn btn-accent" message="Mark this assistance program as done? Youth will no longer see it as open."
              onConfirm={() => closeAssistance(program.id)}>
              {mutating ? 'Saving...' : 'Mark as done'}
            </Confirm>
          )}
          <Confirm disabled={mutating} className="btn btn-danger" message="Archive this assistance program?"
            onConfirm={() => { archiveAssistance(program.id); navigate('/sk/assistance'); }}>
            {mutating ? 'Saving...' : 'Archive'}
          </Confirm>
          <Link to="/sk/assistance" className="btn btn-ghost">Back</Link>
        </>} />

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Section title="Program details" action={<Badge value={program.status} />}>
            <dl className="mt-4 grid gap-3 sm:grid-cols-3">
              <div><dt className="text-xs text-[#7A889B]">Deadline</dt><dd className="text-sm text-navy-900">{program.deadline || '—'}</dd></div>
              <div><dt className="text-xs text-[#7A889B]">Available slots</dt><dd className="text-sm text-navy-900">{program.slots || 'Unlimited'}</dd></div>
              <div><dt className="text-xs text-[#7A889B]">Amount</dt><dd className="text-sm text-navy-900">{program.amount ? `₱${Number(program.amount).toLocaleString()}` : '—'}</dd></div>
            </dl>
            {program.description && <p className="mt-4 text-sm leading-relaxed text-[#4A5B70]">{program.description}</p>}
            {program.requirements && (
              <>
                <h3 className="mt-4 text-xs font-semibold text-[#5A6C82]">Requirements</h3>
                <ul className="mt-1.5 space-y-1 text-sm text-[#4A5B70]">
                  {program.requirements.split('\n').filter(Boolean).map((line) => <li key={line}>· {line}</li>)}
                </ul>
              </>
            )}
          </Section>

          <Section title="Applications for this program"
            action={<Link to={`/sk/applications?tab=${program.category === 'scholarship' ? 'scholarship' : 'assistance'}`} className="btn btn-ghost btn-sm">Open the review queue</Link>}>
            {applications.length === 0 ? (
              <p className="mt-3 text-sm text-[#7A889B]">No youth have applied through their accounts yet.</p>
            ) : (
              <div className="mt-3 space-y-2">
                {applications.map((application) => {
                  const youth = youthOf(application.youthId);
                  const submitted = db.submissions.filter((s) => s.applicationId === application.id).length;
                  const required = db.requirements.filter((r) => r.targetType === 'assistance' && r.targetId === program.id).length;
                  return (
                    <Link key={application.id} to={`/sk/applications/${application.id}`}
                      className="flex items-center justify-between rounded-lg border border-line px-3 py-2 hover:bg-shell">
                      <span>
                        <span className="block text-sm font-medium text-navy-900">{youth ? fullName(youth) : '—'}</span>
                        <span className="block text-xs text-[#8391A4]">
                          {youth?.familyIncome ? `₱${Number(youth.familyIncome).toLocaleString()}/month · ` : ''}
                          {submitted} / {required} requirement(s) submitted
                        </span>
                      </span>
                      <Badge value={APPLICATION_STATUSES[application.status]} />
                    </Link>
                  );
                })}
              </div>
            )}
          </Section>

          <RequirementsEditor targetType="assistance" targetId={program.id} />

          <Section title="Beneficiaries">
            {beneficiaries.length === 0 ? (
              <p className="mt-3 text-sm text-[#7A889B]">No one recorded yet. Add a beneficiary from the panel on the right.</p>
            ) : (
              <div className="mt-3 overflow-x-auto">
                <table className="w-full" style={{ minWidth: 520 }}>
                  <thead><tr><th className="th">Name</th><th className="th">Priority</th><th className="th">Status</th><th className="th">Awarded</th><th className="th" /></tr></thead>
                  <tbody>
                    {beneficiaries.map((record) => {
                      const youth = youthOf(record.youthId);
                      return (
                        <tr key={record.id}>
                          <td className="td"><Link to={`/sk/youth/${youth?.id}`} className="font-medium text-navy-900 underline">{youth ? fullName(youth) : '—'}</Link></td>
                          <td className="td">{youth && <Badge value={youth.level} />}</td>
                          <td className="td"><Badge value={record.status} /></td>
                          <td className="td">{record.awardedOn || '—'}</td>
                          <td className="td">
                            <Confirm className="btn btn-danger btn-sm" message="Remove this beneficiary from the program?"
                              onConfirm={() => removeBeneficiary(record.id)}>Remove</Confirm>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </Section>
        </div>

        <aside className="space-y-4">
          <SelectYouthPanel targetType="assistance" targetId={program.id} label={program.name} verb="approved" orderByIncome
            alreadySelected={beneficiaries.filter((b) => ['approved', 'released'].includes(b.status)).map((b) => b.youthId)} />

          <form className="card space-y-4 p-5" onSubmit={(e) => {
            e.preventDefault();
            if (!form.youthId) return;
            saveBeneficiary(program.id, Number(form.youthId), form.status, form.remarks);
            setForm({ youthId: '', status: 'applied', remarks: '' });
          }}>
            <h2 className="text-sm font-bold text-navy-900">Record a beneficiary</h2>
            <Field label="Youth" hint="Sorted by priority recommendation.">
              <select className="field" value={form.youthId} onChange={(e) => setForm({ ...form, youthId: e.target.value })} required>
                <option value="">Select a youth record</option>
                {candidates.map((y) => <option key={y.id} value={y.id}>{fullName(y)} — {y.level} priority</option>)}
              </select>
            </Field>
            <Field label="Status">
              <select className="field" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                {['applied', 'approved', 'released', 'rejected'].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
              </select>
            </Field>
            <Field label="Remarks"><input className="field" value={form.remarks} onChange={(e) => setForm({ ...form, remarks: e.target.value })} /></Field>
            <button type="submit" className="btn btn-primary w-full">Save beneficiary</button>
            <p className="rounded-lg bg-sun-100 px-3 py-2 text-xs leading-relaxed text-[#7A5A05]">{PRIORITY_DISCLAIMER}</p>
          </form>
        </aside>
      </div>
    </>
  );
}

const EMPTY = { name: '', category: 'scholarship', typeId: '', description: '', requirements: '', deadline: '', slots: '', amount: '', status: 'draft' };
const BLANK_REQUIREMENT = { name: '', description: '', required: true, accepts: 'image/*,application/pdf' };

export function AssistanceForm() {
  const { id } = useParams();
  return <NeedsStoreData keys={id ? ['types', 'assistance'] : ['types']}><AssistanceFormView /></NeedsStoreData>;
}

function AssistanceFormView() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { db, types, saveAssistance, addType, mutating } = useStore();
  const existing = id ? db.assistance.find((a) => a.id === Number(id)) : null;
  const [values, setValues] = useState(existing ? { ...existing } : EMPTY);
  const [newType, setNewType] = useState({ name: '', category: 'other', requirements: [{ ...BLANK_REQUIREMENT }] });
  const [errors, setErrors] = useState({});

  const set = (key) => (e) => setValues((v) => ({ ...v, [key]: e.target.value }));
  const selectedType = types.find((x) => x.id === Number(values.typeId));
  const presetCount = selectedType ? (selectedType.requirements?.length ?? 0) : null;

  const setTypeRequirement = (index, patch) => setNewType((s) => ({
    ...s, requirements: s.requirements.map((r, i) => (i === index ? { ...r, ...patch } : r)),
  }));

  return (
    <>
      <PageHeader title={existing ? `Edit ${existing.name}` : 'New assistance program'} />
      <div className="grid gap-4 lg:grid-cols-3">
        <form className="card space-y-4 p-5 lg:col-span-2" onSubmit={async (e) => {
          e.preventDefault();
          if (mutating) return;
          setErrors({});
          try {
            const savedId = await saveAssistance(values);
            if (savedId) navigate(`/sk/assistance/${savedId}`);
          } catch (err) {
            const fields = err?.fields && typeof err.fields === 'object' ? err.fields : {};
            setErrors({ ...fields, form: err?.message || 'Could not save this assistance program.' });
          }
        }}>
          <FormErrors errors={errors} />
          <Field label="Program name *" error={errors.name}><input className="field" value={values.name} onChange={set('name')} required /></Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Category *">
              <select className="field" value={values.category} onChange={set('category')}>
                {Object.entries(CATEGORY_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </Field>
            <Field label="Assistance type" hint={presetCount === null
              ? 'Pick a type to pull in its standard requirements.'
              : presetCount > 0
                ? `${presetCount} requirement(s) come with this type and are applied automatically.`
                : 'This type has no preset requirements — you add them by hand after saving.'}>
              <select className="field" value={values.typeId} onChange={set('typeId')}>
                <option value="">Not specified</option>
                {types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
            </Field>
          </div>

          {presetCount !== null && (
            <div className="rounded-lg bg-shell px-3 py-2.5 text-xs leading-relaxed text-[#5A6C82]">
              {presetCount > 0 ? (
                <>
                  <span className="font-semibold text-navy-900">Requirements applied automatically:</span>{' '}
                  {selectedType.requirements.map(([name]) => name).join(', ')}.
                  Youth applying for this program submit exactly these. You can still add or remove items afterwards.
                </>
              ) : (
                <>
                  <span className="font-semibold text-navy-900">No preset requirements for this type.</span>{' '}
                  Save the program, then add the requirements youth must submit.
                </>
              )}
            </div>
          )}
          <Field label="Description"><textarea className="field" rows="3" value={values.description} onChange={set('description')} /></Field>
          <Field label="Requirements" hint="One requirement per line.">
            <textarea className="field" rows="4" value={values.requirements} onChange={set('requirements')} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Deadline"><input className="field" type="date" value={values.deadline} onChange={set('deadline')} /></Field>
            <Field label="Available slots"><input className="field" type="number" min="1" value={values.slots} onChange={set('slots')} /></Field>
            <Field label="Amount per beneficiary (₱)"><input className="field" type="number" min="0" value={values.amount} onChange={set('amount')} /></Field>
          </div>
          <Field label="Status *">
            <select className="field" value={values.status} onChange={set('status')}>
              {['draft', 'open', 'full', 'closed', 'archived'].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
            </select>
          </Field>
          <div className="pt-1"><EligibilityFields values={values} onChange={setValues} /></div>

          <div className="flex gap-3 pt-1">
            <button type="submit" className="btn btn-primary" disabled={mutating}>
              {mutating ? (existing ? 'Saving...' : 'Creating...') : (existing ? 'Save changes' : 'Create program')}
            </button>
            <button type="button" className="btn btn-ghost" onClick={() => navigate('/sk/assistance')}>Cancel</button>
          </div>
        </form>

        <form className="card h-fit space-y-4 p-5" onSubmit={(e) => {
          e.preventDefault();
          if (!newType.name.trim()) return;
          addType(newType);
          setNewType({ name: '', category: 'other', requirements: [{ ...BLANK_REQUIREMENT }] });
        }}>
          <h2 className="text-sm font-bold text-navy-900">Add a custom assistance type</h2>
          <p className="text-xs text-[#7A889B]">
            Your own types appear in the dropdown for this organization only. Give the type its requirements
            here and every program using it gets them automatically.
          </p>
          <Field label="Type name">
            <input className="field" placeholder="e.g. Transportation Assistance" value={newType.name}
              onChange={(e) => setNewType({ ...newType, name: e.target.value })} />
          </Field>
          <Field label="Belongs to">
            <select className="field" value={newType.category} onChange={(e) => setNewType({ ...newType, category: e.target.value })}>
              {Object.entries(CATEGORY_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </Field>

          <div className="border-t border-line pt-3">
            <p className="label">Requirements for this type</p>
            <div className="space-y-3">
              {newType.requirements.map((requirement, index) => (
                <div key={index} className="rounded-lg border border-line p-3">
                  <input className="field" placeholder="Requirement name" value={requirement.name}
                    onChange={(e) => setTypeRequirement(index, { name: e.target.value })} />
                  <input className="field mt-2" placeholder="Short description shown to the youth" value={requirement.description}
                    onChange={(e) => setTypeRequirement(index, { description: e.target.value })} />
                  <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <select className="field !w-auto !py-1 !text-xs" value={requirement.accepts}
                      onChange={(e) => setTypeRequirement(index, { accepts: e.target.value })}>
                      <option value="image/*,application/pdf">Photo or PDF</option>
                      <option value="image/*">Photo only</option>
                      <option value="application/pdf">PDF only</option>
                    </select>
                    <label className="flex items-center gap-1.5 text-xs text-[#4A5B70]">
                      <input type="checkbox" className="h-4 w-4 rounded border-[#C7D0DA]" checked={requirement.required}
                        onChange={(e) => setTypeRequirement(index, { required: e.target.checked })} /> Required
                    </label>
                    {newType.requirements.length > 1 && (
                      <button type="button" className="btn btn-danger btn-sm"
                        onClick={() => setNewType((s) => ({ ...s, requirements: s.requirements.filter((_, i) => i !== index) }))}>
                        Remove
                      </button>
                    )}
                  </div>
                </div>
              ))}
            </div>
            <button type="button" className="btn btn-ghost btn-sm mt-3"
              onClick={() => setNewType((s) => ({ ...s, requirements: [...s.requirements, { ...BLANK_REQUIREMENT }] }))}>
              Add another requirement
            </button>
          </div>

          <button type="submit" className="btn btn-primary w-full">Save assistance type</button>
        </form>
      </div>
    </>
  );
}
