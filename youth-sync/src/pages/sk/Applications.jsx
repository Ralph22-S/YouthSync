import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader } from '../../components/layouts.jsx';
import { Badge, ConfirmButton, Empty, Field, Modal, Section, Stat, Table, Tabs } from '../../components/ui.jsx';
import { FilterBar, SORT_NEWEST, sortRows } from '../../components/filters.jsx';
import { age, fullName, APPLICATION_STATUSES, REQUIREMENT_STATUSES } from '../../data/mock.js';

export function ApplicationQueue() {
  const { scoped, db, targetOf } = useStore();
  // The sidebar links here with ?tab=scholarship / ?tab=assistance, so the kind
  // filter reads from the URL and stays in step with navigation.
  const [params, setParams] = useSearchParams();
  const kind = params.get('tab') || 'all';
  const [filter, setFilter] = useState('all');
  const [query, setQuery] = useState('');
  const [sort, setSort] = useState('newest');

  const all = scoped('applications').filter((a) => {
    if (kind === 'all') return true;
    if (kind === 'assistance') return a.targetType === 'assistance' && targetOf(a)?.category !== 'scholarship';
    if (kind === 'scholarship') return targetOf(a)?.category === 'scholarship';
    return true;
  });

  const filtered = all
    .filter((a) => (filter === 'all' ? true : a.status === filter))
    .filter((a) => {
      if (!query) return true;
      const youth = db.youth.find((y) => y.id === a.youthId);
      const target = targetOf(a);
      return `${youth ? fullName(youth) : ''} ${target?.name || ''}`.toLowerCase().includes(query.toLowerCase());
    });

  const rows = sortRows(filtered, sort, (a) => {
    const youth = db.youth.find((y) => y.id === a.youthId);
    return youth ? youth.lastName : '';
  });

  const count = (key) => (key === 'all' ? all.length : all.filter((a) => a.status === key).length);
  const kindLabel = { all: 'Applications', scholarship: 'Scholarship applications', assistance: 'Assistance applications' }[kind];

  return (
    <>
      <PageHeader title={kindLabel}
        subtitle={`${all.length} application(s)${kind === 'all' ? ' across events, programs, scholarships and assistance' : ''}`} />

      <div className="mb-4 flex flex-wrap gap-2">
        {[['all', 'All'], ['scholarship', 'Scholarships'], ['assistance', 'Assistance']].map(([key, label]) => (
          <button key={key} type="button"
            onClick={() => setParams(key === 'all' ? {} : { tab: key })}
            className={`rounded-lg border px-3 py-1.5 text-sm font-medium ${
              kind === key ? 'border-navy-900 bg-navy-900 text-white' : 'border-line bg-white text-[#4A5B70] hover:bg-shell'}`}>
            {label}
          </button>
        ))}
      </div>

      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Awaiting review" value={count('pending')} />
        <Stat label="Approved" value={count('approved')} />
        <Stat label="Needs resubmission" value={count('needs_resubmission')} />
        <Stat label="Rejected" value={count('rejected')} />
      </div>

      <Tabs active={filter} onSelect={setFilter} items={[
        { key: 'all', label: 'All', count: count('all') },
        ...Object.entries(APPLICATION_STATUSES).map(([key, label]) => ({ key, label, count: count(key) })),
      ]} />

      <FilterBar search={query} onSearch={setQuery}
        placeholder="Youth name or opportunity"
        sort={{ value: sort, onChange: setSort, options: SORT_NEWEST }}
        onReset={() => { setQuery(''); setFilter('all'); setSort('newest'); }} />

      {rows.length === 0 ? (
        <Empty title="No applications in this tab" body="Applications appear here as soon as youth apply from their accounts." />
      ) : (
        <Table head={['Youth', 'Applied for', 'Type', 'Requirements', 'Submitted', 'Status', '']} minWidth={880}>
          {rows.map((application) => {
            const youth = db.youth.find((y) => y.id === application.youthId);
            const target = targetOf(application);
            const reqs = db.requirements.filter((r) => r.targetType === application.targetType && r.targetId === application.targetId);
            const subs = db.submissions.filter((s) => s.applicationId === application.id);
            return (
              <tr key={application.id} className="hover:bg-shell">
                <td className="td">
                  <span className="font-medium text-navy-900">{youth ? fullName(youth) : '—'}</span>
                  {youth && <span className="block text-xs text-[#8391A4]">{age(youth.birthDate)} years old · {youth.level} priority</span>}
                </td>
                <td className="td">{target?.name || '—'}</td>
                <td className="td">{application.targetType === 'assistance' ? 'Assistance' : 'Event / programme'}</td>
                <td className="td">{subs.length} / {reqs.length}</td>
                <td className="td">{application.submittedAt}</td>
                <td className="td"><Badge value={APPLICATION_STATUSES[application.status]} /></td>
                <td className="td"><Link to={`/sk/applications/${application.id}`} className="btn btn-ghost btn-sm">Review</Link></td>
              </tr>
            );
          })}
        </Table>
      )}
    </>
  );
}

export function ApplicationReview() {
  const { id } = useParams();
  const {
    db, targetOf, requirementsFor, submissionsFor, reviewRequirement,
    approveApplication, rejectApplication, requestResubmission,
  } = useStore();
  const [notes, setNotes] = useState({});
  const [rejecting, setRejecting] = useState(false);
  const [resubmitting, setResubmitting] = useState(false);
  const [reason, setReason] = useState('');
  const [note, setNote] = useState('');
  const [picked, setPicked] = useState([]);

  const application = db.applications.find((a) => a.id === Number(id));
  if (!application) return <Empty title="Application not found" body="That application is not on file for your organization." />;

  const youth = db.youth.find((y) => y.id === application.youthId);
  const target = targetOf(application);
  const reqs = requirementsFor(application.targetType, application.targetId);
  const subs = submissionsFor(application.id);

  return (
    <>
      <PageHeader title={youth ? fullName(youth) : 'Application'}
        subtitle={`${target?.name} · applied ${application.submittedAt}`}
        actions={<Link to="/sk/applications" className="btn btn-ghost">Back to queue</Link>} />

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Section title="Youth information"
            action={youth && <Link to={`/sk/youth/${youth.id}`} className="btn btn-ghost btn-sm">Open full record</Link>}>
            {youth ? (
              <dl className="mt-4 grid gap-3 sm:grid-cols-3">
                {[['Age', age(youth.birthDate)], ['Gender', youth.gender], ['Contact', youth.contact],
                  ['Address', youth.address], ['Education', youth.education],
                  ['Employment', youth.employment],
                  ['Family income', youth.familyIncome ? `₱${Number(youth.familyIncome).toLocaleString()}` : '—'],
                  ['Household size', youth.familyMembers || '—'],
                  ['Priority', `${youth.level} (score ${youth.score})`]].map(([label, value]) => (
                  <div key={label}><dt className="text-xs text-[#7A889B]">{label}</dt><dd className="text-sm text-navy-900">{value || '—'}</dd></div>
                ))}
              </dl>
            ) : <p className="mt-3 text-sm text-[#7A889B]">Youth record not found.</p>}
          </Section>

          <Section title="Submitted requirements" action={<span className="text-xs text-[#8391A4]">{subs.length} of {reqs.length}</span>}>
            {reqs.length === 0 ? (
              <p className="mt-3 text-sm text-[#7A889B]">This opportunity has no configured requirements.</p>
            ) : (
              <div className="mt-4 space-y-4">
                {reqs.map((requirement) => {
                  const submission = subs.find((s) => s.requirementId === requirement.id);
                  const status = submission?.status || 'missing';
                  return (
                    <div key={requirement.id} className="rounded-lg border border-line p-4">
                      <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                          <p className="text-sm font-semibold text-navy-900">
                            {requirement.name} {requirement.required && <span className="text-xs font-medium text-[#B3261E]">Required</span>}
                          </p>
                          <p className="text-xs text-[#5A6C82]">{requirement.description}</p>
                        </div>
                        <Badge value={REQUIREMENT_STATUSES[status]} />
                      </div>

                      {!submission ? (
                        <p className="mt-3 text-sm text-[#7A889B]">Not submitted yet.</p>
                      ) : (
                        <>
                          <div className="mt-3 flex flex-wrap items-center gap-3 rounded-lg border border-line bg-shell p-3">
                            {submission.dataUrl && submission.fileType?.startsWith('image/') ? (
                              <a href={submission.dataUrl} target="_blank" rel="noreferrer">
                                <img src={submission.dataUrl} alt={submission.fileName} className="h-16 w-16 rounded-lg border border-line object-cover" />
                              </a>
                            ) : (
                              <span className="grid h-16 w-16 place-items-center rounded-lg border border-line bg-white text-[10px] font-semibold text-[#5A6C82]">
                                {submission.dataUrl ? 'FILE' : 'NO PREVIEW'}
                              </span>
                            )}
                            <div className="min-w-0 flex-1">
                              <p className="truncate text-sm font-medium text-navy-900">{submission.fileName}</p>
                              <p className="text-xs text-[#8391A4]">Submitted {submission.submittedAt}</p>
                            </div>
                          </div>

                          <div className="mt-3 flex flex-wrap items-end gap-2">
                            <div className="min-w-[200px] flex-1">
                              <input className="field" placeholder="Note to the youth (used when rejecting)"
                                value={notes[submission.id] || ''} onChange={(e) => setNotes({ ...notes, [submission.id]: e.target.value })} />
                            </div>
                            <button type="button" className="btn btn-ghost btn-sm" onClick={() => reviewRequirement(submission.id, 'verified')}>Verify</button>
                            <button type="button" className="btn btn-danger btn-sm"
                              onClick={() => reviewRequirement(submission.id, 'needs_resubmission', notes[submission.id] || '')}>Needs resubmission</button>
                          </div>
                        </>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </Section>
        </div>

        <aside className="space-y-4">
          <Section title="Decision">
            <p className="mt-3"><Badge value={APPLICATION_STATUSES[application.status]} /></p>
            {application.remarks && (
              <p className="mt-2 rounded-lg bg-shell px-3 py-2 text-xs text-[#5A6C82]">Note on file: {application.remarks}</p>
            )}

            <div className="mt-4 grid gap-2">
              <ConfirmButton className="btn btn-accent w-full" tone="btn btn-accent"
                label="Approve" title="Approve this application?"
                description={`${youth ? fullName(youth) : 'This youth'} will be notified that ${target?.name} was approved.`}
                confirmLabel="Confirm approval"
                onConfirm={() => approveApplication(application.id)} />

              <button type="button" className="btn btn-danger w-full" onClick={() => setRejecting(true)}>Reject</button>
              <button type="button" className="btn btn-ghost w-full" onClick={() => setResubmitting(true)}>Request resubmission</button>
            </div>

            <p className="mt-4 text-xs leading-relaxed text-[#8391A4]">
              Nothing is sent until you confirm. The youth is notified only after the decision is saved.
            </p>

            {application.targetType === 'program' && (
              <p className="mt-3 rounded-lg bg-shell px-3 py-2 text-xs leading-relaxed text-[#5A6C82]">
                This youth registered when they applied. Approving confirms their slot —
                only scanning their Youth QR at the activity marks them present.
              </p>
            )}
          </Section>
        </aside>
      </div>

      <Modal open={rejecting} title="Reject application"
        description="The reason is required and is shown to the youth."
        onClose={() => setRejecting(false)}
        footer={<>
          <button type="button" className="btn btn-ghost" onClick={() => setRejecting(false)}>Cancel</button>
          <button type="button" className="btn btn-danger"
            onClick={() => { if (rejectApplication(application.id, reason)) { setRejecting(false); setReason(''); } }}>
            Confirm rejection
          </button>
        </>}>
        <Field label="Reason" error={reason.trim() ? null : undefined}>
          <textarea className="field" rows="4" value={reason} autoFocus
            onChange={(e) => setReason(e.target.value)}
            placeholder="e.g. Required proof of residency was not submitted." />
        </Field>
      </Modal>

      <Modal open={resubmitting} title="Request resubmission"
        description="Pick what needs replacing and explain why. Only this youth is notified."
        onClose={() => setResubmitting(false)}
        footer={<>
          <button type="button" className="btn btn-ghost" onClick={() => setResubmitting(false)}>Cancel</button>
          <button type="button" className="btn btn-primary"
            onClick={() => {
              if (requestResubmission(application.id, picked, note)) {
                setResubmitting(false); setPicked([]); setNote('');
              }
            }}>
            Request resubmission
          </button>
        </>}>
        <p className="label">Missing or incorrect requirements</p>
        {reqs.length === 0 ? (
          <p className="text-sm text-[#7A889B]">This opportunity has no requirements configured.</p>
        ) : (
          <div className="space-y-2">
            {reqs.map((requirement) => {
              const submission = subs.find((s) => s.requirementId === requirement.id);
              return (
                <label key={requirement.id}
                  className={`flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2.5 text-sm ${
                    picked.includes(requirement.id) ? 'border-navy-600 bg-[#F4F8FD]' : 'border-line'}`}>
                  <input type="checkbox" className="mt-0.5 h-4 w-4 rounded border-[#C7D0DA]"
                    checked={picked.includes(requirement.id)}
                    onChange={() => setPicked((v) => (v.includes(requirement.id)
                      ? v.filter((id) => id !== requirement.id) : [...v, requirement.id]))} />
                  <span>
                    <span className="block font-medium text-navy-900">{requirement.name}</span>
                    <span className="block text-xs text-[#8391A4]">
                      {REQUIREMENT_STATUSES[submission?.status || 'missing']}
                    </span>
                  </span>
                </label>
              );
            })}
          </div>
        )}

        <div className="mt-4">
          <Field label="Reason / note">
            <textarea className="field" rows="3" value={note} onChange={(e) => setNote(e.target.value)}
              placeholder="e.g. Please upload a clearer copy of your School ID." />
          </Field>
        </div>
      </Modal>
    </>
  );
}
