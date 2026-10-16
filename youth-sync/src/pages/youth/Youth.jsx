import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useStore } from '../../store.jsx';
import { PageHeader, youthInbox } from '../../components/layouts.jsx';
import { Badge, Confirm, ConfirmButton, Empty, Field, FormErrors, Section, Skeleton, Stat, Table, Tabs } from '../../components/ui.jsx';
import Upload from '../../components/upload.jsx';
import { ProfilePhoto } from '../../components/photo.jsx';
import { apiBlob, friendlyApiMessage, mediaSrc } from '../../lib/api.js';
import EducationFields from '../../components/education.jsx';
import { TagInput, CheckboxGroup } from '../../components/tags.jsx';
import { QrImage, QrScanner } from '../../components/qr.jsx';
import { digitsOnly, hasErrors, validateYouth } from '../../lib/validation.js';
import {
  age, fullName, youthCode, makeYouthToken, APPLICATION_STATUSES, REQUIREMENT_STATUSES,
  INTEREST_OPTIONS, SKILL_OPTIONS, ACTIVITY_OPTIONS,
} from '../../data/mock.js';

function apiErrorText(err) {
  const fields = err?.fields && typeof err.fields === 'object' ? Object.values(err.fields).filter(Boolean) : [];
  if (fields.length) return fields.join(' ');
  return err?.message || 'The request could not be completed.';
}

function openProtectedFile(path, filename, download) {
  return apiBlob(path).then((blob) => {
    const url = URL.createObjectURL(blob);
    if (download) {
      const link = document.createElement('a');
      link.href = url;
      link.download = filename || 'document';
      link.click();
    } else {
      window.open(url, '_blank', 'noopener');
    }
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  });
}

function PortalNotice({ loading, error }) {
  if (loading) return <Skeleton rows={4} />;
  if (error) {
    return <p className="mb-4 rounded-card border border-[#F0D3D1] bg-[#FCF3F2] px-4 py-3 text-sm text-[#96201A]">{error}</p>;
  }
  return null;
}

const KIND = {
  event: { label: 'Events', route: 'events', targetType: 'program' },
  program: { label: 'Programs', route: 'programs', targetType: 'program' },
  assistance: { label: 'Assistance', route: 'assistance', targetType: 'assistance' },
};

export function YouthDashboard() {
  const { user, db, youthRecord, org, targetOf, matchProfile, sessionKind, youthPortalLoading, youthPortalError, loadYouthProfile } = useStore();
  const portal = sessionKind === 'youth';
  const photoSrc = mediaSrc(youthRecord?.photo);
  useEffect(() => {
    if (!portal || !loadYouthProfile) return undefined;
    loadYouthProfile().catch(() => {});
    return undefined;
  }, [portal, loadYouthProfile]);
  const mine = db.applications.filter((a) => a.youthId === user.youthId);
  const openItems = db.programs
    .filter((p) => p.orgId === user.orgId && p.status === 'published')
    .sort((a, b) => Number(b.id) - Number(a.id));
  const openAssistance = db.assistance
    .filter((a) => a.orgId === user.orgId && a.status === 'open')
    .sort((a, b) => Number(b.id) - Number(a.id));
  const matchesYouth = (item) => Boolean(matchProfile?.(youthRecord, item)?.matched);
  const forYou = openItems.filter(matchesYouth);
  const forYouAssistance = openAssistance.filter(matchesYouth);
  const approved = mine.filter((a) => a.status === 'approved');
  const needsAction = mine.filter((a) => ['pending', 'needs_resubmission'].includes(a.status));
  const attendance = db.registrations.filter((r) => r.youthId === user.youthId && r.attendance === 'present');

  return (
    <>
      <PageHeader title={`Hello, ${youthRecord?.firstName || user.name}`}
        subtitle={org ? `Your SK is ${org.name}.` : 'Your account is not linked to a barangay yet.'}
        actions={<Link to="/youth/events" className="btn btn-primary">Browse events</Link>} />

      {portal && <PortalNotice loading={youthPortalLoading} error={youthPortalError} />}

      {photoSrc && (
        <div className="mb-4 max-w-sm">
          <Section title="Profile photo">
            <ProfilePhoto src={photoSrc} alt={fullName(youthRecord)} className="mt-3" />
          </Section>
        </div>
      )}

      {needsAction.length > 0 && (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-card border border-[#F2DCA8] bg-sun-100 px-4 py-3 text-sm text-[#7A5A05]">
          <span>{needsAction.length} application(s) still need your requirements.</span>
          <Link to="/youth/applications" className="btn btn-accent">Finish them</Link>
        </div>
      )}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="My applications" value={mine.length} />
        <Stat label="Approved" value={approved.length} />
        <Stat label="Open opportunities" value={openItems.length + openAssistance.length} />
        <Stat label="Events attended" value={attendance.length} />
      </div>

      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <Section title="Open to you right now">
          {forYou.length + forYouAssistance.length === 0 ? (
            <p className="mt-3 text-sm text-[#7A889B]">
              Nothing open matches your interests or skills yet.{' '}
              <Link to="/youth/profile" className="font-semibold text-navy-900 underline">Update your profile</Link>
              {' '}or{' '}
              <Link to="/youth/events" className="font-semibold text-navy-900 underline">browse every program and event</Link>.
            </p>
          ) : (
            <div className="mt-3 space-y-2">
              {forYou.map((p) => (
                <Link key={p.id} to={`/youth/${p.kind === 'event' ? 'events' : 'programs'}/${p.id}`}
                  className="flex items-center justify-between rounded-lg border border-line px-3 py-2 hover:bg-shell">
                  <span>
                    <span className="block text-sm font-medium text-navy-900">{p.name}</span>
                    <span className="block text-xs text-[#8391A4]">{new Date(p.scheduledOn).toDateString()} · {p.location}</span>
                  </span>
                  <span className="text-xs font-semibold text-navy-700">Open</span>
                </Link>
              ))}
              {forYouAssistance.map((a) => (
                <Link key={a.id} to={`/youth/assistance/${a.id}`} className="flex items-center justify-between rounded-lg border border-line px-3 py-2 hover:bg-shell">
                  <span>
                    <span className="block text-sm font-medium text-navy-900">{a.name}</span>
                    <span className="block text-xs text-[#8391A4]">Deadline {a.deadline}</span>
                  </span>
                  <span className="text-xs font-semibold text-navy-700">Open</span>
                </Link>
              ))}
            </div>
          )}
        </Section>

        <Section title="My latest applications">
          {mine.length === 0 ? (
            <p className="mt-3 text-sm text-[#7A889B]">You have not applied for anything yet.</p>
          ) : mine.slice(0, 5).map((application) => (
            <Link key={application.id} to={`/youth/applications/${application.id}`}
              className="mt-3 flex items-center justify-between border-b border-[#EEF1F4] pb-2 last:border-0">
              <span className="text-sm text-navy-900">{targetOf(application)?.name || 'Unknown'}</span>
              <Badge value={APPLICATION_STATUSES[application.status]} />
            </Link>
          ))}
        </Section>
      </div>
    </>
  );
}

export function YouthProfile() {
  const { youthRecord, user, updateYouthProfile, loadYouthProfile, mutating } = useStore();
  const [values, setValues] = useState({ ...youthRecord });
  const [errors, setErrors] = useState({});
  useEffect(() => {
    let live = true;
    loadYouthProfile?.().then((data) => {
      if (!live || !data?.youth) return;
      setValues({ ...data.youth, photo: data.youth.photo || null });
    }).catch(() => {});
    return () => { live = false; };
  }, [loadYouthProfile]);
  const set = (key) => (e) => {
    const t = e.target;
    setValues((v) => ({ ...v, [key]: t.type === 'checkbox' ? t.checked : t.value }));
  };
  const setField = (key, value) => setValues((v) => ({ ...v, [key]: value }));

  if (!youthRecord) return <Empty title="No youth record linked" body="Contact your SK office so they can link your account to a youth record." />;

  return (
    <>
      <PageHeader title="My profile" subtitle="Keep this accurate — your SK uses it to check if you qualify." />
      <div className="grid gap-4 lg:grid-cols-3">
        <form className="space-y-4 lg:col-span-2" onSubmit={async (e) => {
          e.preventDefault();
          const found = validateYouth(values);
          setErrors(found);
          if (hasErrors(found)) return;
          const result = await updateYouthProfile(values);
          if (result?.fields) setErrors(result.fields);
          if (result?.youth) setValues({ ...result.youth, photo: result.youth.photo || null });
        }}>
          <FormErrors errors={errors} />
          <Section title="Personal information">
            <div className="mt-4 grid gap-4 sm:grid-cols-3">
              <Field label="First name"><input className="field" value={values.firstName} onChange={set('firstName')} required /></Field>
              <Field label="Middle name"><input className="field" value={values.middleName || ''} onChange={set('middleName')} /></Field>
              <Field label="Last name"><input className="field" value={values.lastName} onChange={set('lastName')} required /></Field>
              <Field label="Date of birth"><input className="field" type="date" value={values.birthDate} onChange={set('birthDate')} required /></Field>
              <Field label="Gender">
                <select className="field" value={values.gender} onChange={set('gender')}>
                  {['Male', 'Female', 'Prefer not to say'].map((g) => <option key={g}>{g}</option>)}
                </select>
              </Field>
              <Field label="Mobile number *" error={errors.contact}
                hint="Exactly 11 digits, numbers only. SK updates are sent here by SMS.">
                <input className="field" type="tel" inputMode="numeric" maxLength={11}
                  value={values.contact || ''}
                  onChange={(e) => setField('contact', digitsOnly(e.target.value))} />
              </Field>
              <Field label="Email address" error={errors.email}>
                <input className="field" type="email" value={values.email || ''} onChange={set('email')} />
              </Field>
              <div className="sm:col-span-3"><Field label="Address"><input className="field" value={values.address} onChange={set('address')} required /></Field></div>
            </div>
          </Section>

          <Section title="Education">
            <div className="mt-4">
              <EducationFields values={values} onChange={setValues} errors={errors} />
            </div>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <Field label="Employment status">
                <select className="field" value={values.employment} onChange={set('employment')}>
                  {['Student', 'Employed', 'Unemployed', 'Self-employed'].map((e) => <option key={e}>{e}</option>)}
                </select>
              </Field>
            </div>
          </Section>

          <Section title="Skills, interests and preferred activities">
            <div className="mt-4 space-y-5">
              <TagInput label="Skills" values={values.skills || []} options={SKILL_OPTIONS}
                onChange={(v) => setField('skills', v)} addLabel="Add Skill"
                placeholder="No skills added yet." hint="Add them one at a time." />
              <TagInput label="Interests" required error={errors.interests} addLabel="Add Interest"
                values={values.interests || []} options={INTEREST_OPTIONS}
                onChange={(v) => setField('interests', v)}
                placeholder="No interests added yet." hint="At least one interest is required." />
              <CheckboxGroup label="Preferred activities" options={ACTIVITY_OPTIONS}
                values={values.preferredActivities || []}
                onChange={(v) => setField('preferredActivities', v)}
                hint="Used to recommend programs and events that fit you." />
            </div>
          </Section>

          <Section title="Household">
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <Field label="Parent or guardian"><input className="field" value={values.guardianName || ''} onChange={set('guardianName')} /></Field>
              <Field label="Parent employment status">
                <select className="field" value={values.guardianEmployment || ''} onChange={set('guardianEmployment')}>
                  <option value="">Not recorded</option>
                  {['Employed', 'Unemployed', 'Self-employed', 'Retired'].map((e) => <option key={e}>{e}</option>)}
                </select>
              </Field>
              <Field label="Monthly family income (₱)"><input className="field" type="number" min="0" value={values.familyIncome || ''} onChange={set('familyIncome')} /></Field>
              <Field label="Number of family members"><input className="field" type="number" min="1" max="30" value={values.familyMembers || ''} onChange={set('familyMembers')} /></Field>
            </div>
          </Section>

          <button type="submit" className="btn btn-primary" disabled={mutating}>{mutating ? 'Saving…' : 'Save my profile'}</button>
        </form>

        <aside className="space-y-4">
          <Section title="Profile photo">
            <div className="mt-3">
              <Upload accepts="image/*" profile label="Upload profile photo"
                value={values.photo}
                onChange={(file) => { setValues((v) => ({ ...v, photo: file })); }}
                onRemove={() => setValues((v) => ({ ...v, photo: null }))} />
            </div>
            <p className="mt-2 text-xs text-[#8391A4]">Save the form to keep the photo.</p>
          </Section>

          <Section title="My QR" action={<Link to="/youth/qr" className="btn btn-ghost btn-sm">Open</Link>}>
            <p className="mt-2 text-sm text-[#5A6C82]">
              Your Youth QR identifies you at activities. The SK scans it to record attendance.
            </p>
          </Section>

          <Section title="Account">
            <dl className="mt-3 space-y-2 text-sm">
              <div className="flex justify-between"><dt className="text-[#5A6C82]">Youth ID</dt><dd className="font-mono font-medium text-navy-900">{youthCode(youthRecord)}</dd></div>
              <div className="flex justify-between"><dt className="text-[#5A6C82]">Email</dt><dd className="font-medium text-navy-900">{user.email}</dd></div>
              <div className="flex justify-between"><dt className="text-[#5A6C82]">Verified</dt><dd><Badge value={user.verified ? 'active' : 'pending'} /></dd></div>
              <div className="flex justify-between"><dt className="text-[#5A6C82]">Age</dt><dd className="font-medium text-navy-900">{age(values.birthDate)}</dd></div>
            </dl>
          </Section>
        </aside>
      </div>
    </>
  );
}

/** Recommendation banner. It never gates the Apply button — the SK still decides. */
function MatchNote({ youth, target }) {
  const { matchProfile } = useStore();
  const { matched, reasons, blockers, configured } = matchProfile(youth, target);

  if (!configured) return null;

  return (
    <div className="mt-3 space-y-2">
      {matched && (
        <p className="rounded-lg border border-[#BFE3D0] bg-[#F1FAF5] px-3 py-2 text-xs text-[#12664A]">
          ✓ Matches your profile — {reasons.join(' · ')}
        </p>
      )}
      {blockers.map((blocker) => (
        <p key={blocker} className="rounded-lg border border-[#F2DCA8] bg-sun-100 px-3 py-2 text-xs text-[#7A5A05]">
          {blocker} You can still apply, and the SK will decide.
        </p>
      ))}
    </div>
  );
}

export function YouthOpportunities({ kind }) {
  const { user, db, requirementsFor, youthRecord, matchProfile, sessionKind, youthPortalLoading, youthPortalError } = useStore();
  const meta = KIND[kind];
  const portal = sessionKind === 'youth';

  const showActivities = kind === 'event';
  const rows = kind === 'assistance'
    ? db.assistance.filter((a) => a.orgId === user.orgId && ['open', 'full'].includes(a.status))
    : db.programs.filter((p) => p.orgId === user.orgId
      && (showActivities || p.kind === kind)
      && ['published', 'ongoing'].includes(p.status));

  const mine = db.applications.filter((a) => a.youthId === user.youthId);

  return (
    <>
      <PageHeader title={showActivities ? 'Available Programs' : meta.label}
        subtitle={showActivities ? 'Open programs and events from your SK.' : `Open ${meta.label.toLowerCase()} from your SK.`} />
      {portal && (youthPortalLoading || youthPortalError) ? <PortalNotice loading={youthPortalLoading} error={youthPortalError} /> : rows.length === 0 ? (
        <Empty title={showActivities ? 'No programs or events open right now' : `No ${meta.label.toLowerCase()} open right now`} body="When your SK publishes something, it will appear here and you will get a notification." />
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          {rows.map((item) => {
            const application = mine.find((a) => a.targetType === meta.targetType && a.targetId === item.id);
            const reqs = requirementsFor(meta.targetType, item.id);
            return (
              <Link key={item.id} to={`/youth/${item.kind === 'program' ? 'programs' : meta.route}/${item.id}`} className="card block p-5 hover:border-navy-600">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <h3 className="text-sm font-semibold text-navy-900">{item.name}</h3>
                    <p className="text-xs text-[#8391A4]">
                      {kind === 'assistance'
                        ? `Deadline ${item.deadline || 'open'}`
                        : `${item.kind === 'event' ? 'Event' : 'Program'} · ${new Date(item.scheduledOn).toDateString()} · ${item.location}`}
                    </p>
                  </div>
                  {application ? <Badge value={APPLICATION_STATUSES[application.status]} /> : <Badge value="open" />}
                </div>
                <p className="mt-3 line-clamp-2 text-sm text-[#5A6C82]">{item.description || 'No description provided.'}</p>
                {matchProfile(youthRecord, item).matched && (
                  <p className="mt-2 inline-block rounded-full bg-[#F1FAF5] px-2.5 py-1 text-xs font-semibold text-[#12664A]">
                    ✓ Matches your profile
                  </p>
                )}
                <p className="mt-3 text-xs text-[#8391A4]">{reqs.length} requirement(s) to submit</p>
              </Link>
            );
          })}
        </div>
      )}
    </>
  );
}

export function YouthOpportunityDetail({ kind }) {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user, db, requirementsFor, apply, respondInvitation, respondProgramInvitation, youthRecord, sessionKind, youthPortalLoading, youthPortalError, mutating } = useStore();
  const [applyError, setApplyError] = useState('');
  const meta = KIND[kind];
  const portal = sessionKind === 'youth';

  const item = kind === 'assistance'
    ? db.assistance.find((a) => a.id === Number(id))
    : db.programs.find((p) => p.id === Number(id));

  if (portal && youthPortalLoading) return <Skeleton rows={6} />;
  if (portal && youthPortalError) return <PortalNotice error={youthPortalError} />;
  if (!item) return <Empty title="Not found" body="That opportunity is no longer available." />;

  const reqs = requirementsFor(meta.targetType, item.id);
  const application = db.applications.find((a) => a.youthId === user.youthId && a.targetType === meta.targetType && a.targetId === item.id);
  const invitation = kind === 'assistance'
    ? (db.beneficiaries || []).find((row) => Number(row.programId) === Number(item.id))
    : (db.registrations || []).find((row) => Number(row.programId) === Number(item.id) && row.source === 'invite');
  const answerInvitation = kind === 'assistance' ? respondInvitation : respondProgramInvitation;
  const joined = invitation?.status === 'confirmed' || invitation?.status === 'registered';
  const registered = item.registeredCount != null
    ? Number(item.registeredCount)
    : db.registrations.filter((r) => r.programId === item.id && r.status === 'registered').length;
  const full = kind !== 'assistance' && item.maxParticipants && registered >= item.maxParticipants;

  return (
    <>
      <PageHeader title={item.name}
        subtitle={kind === 'assistance'
          ? `${item.category} · deadline ${item.deadline || 'open'}`
          : `${new Date(item.scheduledOn).toDateString()} · ${item.startsAt}–${item.endsAt} · ${item.location}`}
        actions={<Link to={`/youth/${meta.route}`} className="btn btn-ghost">Back</Link>} />

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <Section title="About">
            <p className="mt-3 text-sm leading-relaxed text-[#4A5B70]">{item.description || 'No description provided.'}</p>
            <MatchNote youth={youthRecord} target={item} />
            <dl className="mt-4 grid gap-3 sm:grid-cols-3">
              {kind === 'assistance' ? (
                <>
                  <div><dt className="text-xs text-[#7A889B]">Slots</dt><dd className="text-sm text-navy-900">{item.slots || 'Unlimited'}</dd></div>
                  <div><dt className="text-xs text-[#7A889B]">Amount</dt><dd className="text-sm text-navy-900">{item.amount ? `₱${Number(item.amount).toLocaleString()}` : '—'}</dd></div>
                  <div><dt className="text-xs text-[#7A889B]">Deadline</dt><dd className="text-sm text-navy-900">{item.deadline || 'Open'}</dd></div>
                </>
              ) : (
                <>
                  <div><dt className="text-xs text-[#7A889B]">Participants</dt><dd className="text-sm text-navy-900">{registered} / {item.maxParticipants || '∞'}</dd></div>
                  <div><dt className="text-xs text-[#7A889B]">Registration closes</dt><dd className="text-sm text-navy-900">{item.registrationDeadline || 'Open'}</dd></div>
                  <div><dt className="text-xs text-[#7A889B]">Category</dt><dd className="text-sm text-navy-900">{item.category || '—'}</dd></div>
                </>
              )}
            </dl>
          </Section>

          <Section title="Requirements you will need">
            {reqs.length === 0 ? (
              <p className="mt-3 text-sm text-[#7A889B]">No requirements — you can apply directly.</p>
            ) : (
              <ul className="mt-3 space-y-2">
                {reqs.map((r) => (
                  <li key={r.id} className="rounded-lg border border-line px-3 py-2">
                    <p className="text-sm font-medium text-navy-900">
                      {r.name} {r.required ? <span className="text-xs text-[#B3261E]">Required</span> : <span className="text-xs text-[#8391A4]">Optional</span>}
                    </p>
                    <p className="text-xs text-[#5A6C82]">{r.description}</p>
                  </li>
                ))}
              </ul>
            )}
          </Section>
        </div>

        <aside>
          {invitation?.status === 'invited' && (
            <Section title="Invitation">
              <p className="mt-3 text-sm text-[#5A6C82]">Your SK invited you by email. Confirm here if you will join.</p>
              <div className="mt-4 flex flex-col gap-2">
                <button type="button" className="btn btn-primary" disabled={mutating} onClick={() => answerInvitation?.(item.id, 'join')}>I will join</button>
                <button type="button" className="btn btn-ghost" disabled={mutating} onClick={() => answerInvitation?.(item.id, 'decline')}>I will not join</button>
              </div>
            </Section>
          )}
          {joined && (
            <Section title="Invitation">
              <p className="mt-3 text-sm text-[#5A6C82]">You confirmed that you will join this program.</p>
            </Section>
          )}
          {invitation?.status === 'declined' && (
            <Section title="Invitation">
              <p className="mt-3 text-sm text-[#5A6C82]">You declined this invitation.</p>
            </Section>
          )}
          <Section title="Apply">
            {application ? (
              <>
                <p className="mt-3 text-sm text-[#5A6C82]">
                  {application.status === 'rejected'
                    ? 'Your last application was rejected. Update the requirements, then reapply.'
                    : 'You already applied. Current status:'}
                </p>
                <p className="mt-2"><Badge value={APPLICATION_STATUSES[application.status]} /></p>
                <Link to={`/youth/applications/${application.id}`} className="btn btn-primary mt-4 w-full">
                  {application.status === 'rejected' ? 'Reapply' : 'Open my application'}
                </Link>
              </>
            ) : full ? (
              <p className="mt-3 text-sm text-[#7A5A05]">This activity is already full. Watch your notifications in case slots open.</p>
            ) : (
              <>
                {portal && kind !== 'assistance' && (
                  <p className="mt-3 text-sm text-[#5A6C82]">Registering does not mark attendance. Check in later with the attendance code, or let your SK scan your Youth QR.</p>
                )}
                <p className="mt-3 text-sm text-[#5A6C82]">
                  Applying creates an application record. You then upload {reqs.length} requirement(s) and submit it for review.
                </p>
                {applyError && <p className="mt-3 text-sm text-[#96201A]">{applyError}</p>}
                <button type="button" className="btn btn-primary mt-4 w-full" disabled={mutating}
                  onClick={async () => {
                    setApplyError('');
                    try {
                      const newId = await apply(meta.targetType, item.id);
                      if (newId) navigate(`/youth/applications/${newId}`);
                    } catch (err) {
                      setApplyError(apiErrorText(err));
                    }
                  }}>
                  {mutating ? 'Submitting…' : 'Apply now'}
                </button>
              </>
            )}
          </Section>
        </aside>
      </div>
    </>
  );
}

export function YouthApplications() {
  const { user, db, targetOf, sessionKind, youthPortalLoading, youthPortalError } = useStore();
  const portal = sessionKind === 'youth';
  const [filter, setFilter] = useState('all');
  const mine = db.applications.filter((a) => a.youthId === user.youthId);
  const rows = filter === 'all' ? mine : mine.filter((a) => a.status === filter);

  return (
    <>
      <PageHeader title="My applications" subtitle={`${mine.length} application(s)`} />
      {portal && (youthPortalLoading || youthPortalError) ? <PortalNotice loading={youthPortalLoading} error={youthPortalError} /> : (
      <>
      <Tabs active={filter} onSelect={setFilter} items={[
        { key: 'all', label: 'All', count: mine.length },
        ...Object.entries(APPLICATION_STATUSES)
          .map(([key, label]) => ({ key, label, count: mine.filter((a) => a.status === key).length }))
          .filter((t) => t.count > 0),
      ]} />

      {rows.length === 0 ? (
        <Empty title="Nothing here yet" body="Apply for an event, program, or assistance and it will show up here with its status.">
          <Link to="/youth/events" className="btn btn-primary">Browse events</Link>
        </Empty>
      ) : (
        <div className="card divide-y divide-line">
          {rows.map((application) => {
            const target = targetOf(application);
            return (
              <Link key={application.id} to={`/youth/applications/${application.id}`} className="flex items-center justify-between gap-3 p-4 hover:bg-shell">
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-navy-900">{target?.name || application.assistanceName || 'Application'}</p>
                  <p className="text-xs text-[#8391A4]">
                    {application.targetType === 'assistance' ? 'Assistance' : 'Event / programme'} · submitted {application.submittedAt}
                  </p>
                  {application.remarks && <p className="mt-1 text-xs text-[#96201A]">{application.remarks}</p>}
                </div>
                <Badge value={APPLICATION_STATUSES[application.status]} />
              </Link>
            );
          })}
        </div>
      )}
      </>
      )}
    </>
  );
}

export function YouthApplicationDetail() {
  const { id } = useParams();
  const { db, requirementsFor, submissionsFor, targetOf, submitRequirement, removeSubmission, submitApplication, sessionKind, youthPortalLoading, youthPortalError, mutating } = useStore();
  const [submitError, setSubmitError] = useState('');
  const [picked, setPicked] = useState({});
  const [uploadingId, setUploadingId] = useState(0);
  const [uploadError, setUploadError] = useState({});
  const [fileError, setFileError] = useState('');
  const portal = sessionKind === 'youth';
  const application = db.applications.find((a) => a.id === Number(id));

  if (portal && youthPortalLoading) return <Skeleton rows={6} />;
  if (portal && youthPortalError) return <PortalNotice error={youthPortalError} />;
  if (!application) return <Empty title="Application not found" body="That application is no longer on file." />;

  const target = targetOf(application);
  const reqs = requirementsFor(application.targetType, application.targetId);
  const subs = submissionsFor(application.id);
  const locked = ['approved', 'not_qualified'].includes(application.status);
  const done = reqs.filter((r) => r.required).every((r) => subs.some((s) => s.requirementId === r.id));

  return (
    <>
      <PageHeader title={target?.name || application.assistanceName || 'Application'}
        subtitle={`Applied ${application.submittedAt}${application.reviewedAt ? ` · reviewed ${application.reviewedAt}` : ''}`}
        actions={<Link to="/youth/applications" className="btn btn-ghost">Back</Link>} />

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-card border border-line bg-white px-4 py-3">
        <div>
          <p className="text-xs text-[#7A889B]">Application status</p>
          <p className="mt-1"><Badge value={APPLICATION_STATUSES[application.status]} /></p>
        </div>
        {application.remarks && <p className="max-w-lg text-sm text-[#96201A]">{application.remarks}</p>}
        {application.status === 'approved' && application.targetType === 'program' && (
          <Link to="/youth/qr" className="btn btn-accent">Show my Youth QR</Link>
        )}
      </div>

      <Section title="Requirements"
        action={!locked && <span className="text-xs text-[#8391A4]">{subs.length} of {reqs.length} submitted</span>}>
        {reqs.length === 0 ? (
          <p className="mt-3 text-sm text-[#7A889B]">This opportunity has no requirements.</p>
        ) : (
          <div className="mt-4 space-y-4">
            {reqs.map((requirement) => {
              const submission = subs.find((s) => s.requirementId === requirement.id);
              const status = submission?.status || 'missing';
              const replaceable = !locked && (
                ['missing', 'needs_resubmission', 'submitted'].includes(status)
                || (application.status === 'rejected' && status === 'verified')
              );

              return (
                <div key={requirement.id} className="rounded-lg border border-line p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p className="text-sm font-semibold text-navy-900">
                        {requirement.name} {requirement.required
                          ? <span className="text-xs font-medium text-[#B3261E]">Required</span>
                          : <span className="text-xs font-medium text-[#8391A4]">Optional</span>}
                      </p>
                      <p className="text-xs text-[#5A6C82]">{requirement.description}</p>
                    </div>
                    <Badge value={REQUIREMENT_STATUSES[status]} />
                  </div>

                  {submission?.remarks && (
                    <p className="mt-2 rounded-lg bg-[#FCF3F2] px-3 py-2 text-xs text-[#96201A]">SK note: {submission.remarks}</p>
                  )}

                  <div className="mt-3">
                    {portal ? (
                      <div className="space-y-2">
                        {submission?.fileName ? (
                          <div className="flex flex-wrap items-center gap-3 rounded-lg border border-line bg-shell p-3">
                            <span className="text-sm" aria-hidden="true">📎</span>
                            <div className="min-w-0 flex-1">
                              <p className="truncate text-sm font-medium text-navy-900">{submission.fileName}</p>
                              <p className="text-xs text-[#8391A4]">{REQUIREMENT_STATUSES[submission.status] || submission.status}</p>
                            </div>
                            <button type="button" className="btn btn-ghost btn-sm" disabled={uploadingId === requirement.id}
                              onClick={async () => {
                                setFileError('');
                                try {
                                  await openProtectedFile(
                                    `/youth/applications/${application.id}/requirements/${requirement.id}/file`,
                                    submission.fileName,
                                    false
                                  );
                                } catch (err) {
                                  setFileError(apiErrorText(err));
                                }
                              }}>
                              View
                            </button>
                          </div>
                        ) : (
                          <p className="text-sm text-[#5A6C82]">No document uploaded yet.</p>
                        )}
                        {replaceable && (
                          <div className="flex flex-wrap items-center gap-2">
                            <input
                              type="file"
                              accept={requirement.accepts || 'image/*,application/pdf'}
                              disabled={uploadingId === requirement.id}
                              onChange={(event) => {
                                const file = event.target.files?.[0] || null;
                                setPicked((current) => ({ ...current, [requirement.id]: file }));
                                setUploadError((current) => ({ ...current, [requirement.id]: '' }));
                              }}
                            />
                            <button type="button" className="btn btn-primary btn-sm"
                              disabled={uploadingId === requirement.id || !picked[requirement.id]}
                              onClick={async () => {
                                const file = picked[requirement.id];
                                if (!file) return;
                                setUploadingId(requirement.id);
                                setUploadError((current) => ({ ...current, [requirement.id]: '' }));
                                try {
                                  await submitRequirement(application.id, requirement.id, file);
                                  setPicked((current) => ({ ...current, [requirement.id]: null }));
                                } catch (err) {
                                  setUploadError((current) => ({ ...current, [requirement.id]: apiErrorText(err) }));
                                } finally {
                                  setUploadingId(0);
                                }
                              }}>
                              {uploadingId === requirement.id ? 'Uploading...' : 'Upload'}
                            </button>
                            {submission?.fileName && (
                              <button type="button" className="btn btn-danger btn-sm"
                                disabled={uploadingId === requirement.id}
                                onClick={async () => {
                                  setUploadError((current) => ({ ...current, [requirement.id]: '' }));
                                  try {
                                    await removeSubmission(application.id, requirement.id);
                                  } catch (err) {
                                    setUploadError((current) => ({ ...current, [requirement.id]: apiErrorText(err) }));
                                  }
                                }}>
                                Remove
                              </button>
                            )}
                          </div>
                        )}
                        {uploadError[requirement.id] && (
                          <p className="text-sm text-[#96201A]">{uploadError[requirement.id]}</p>
                        )}
                        {fileError && <p className="text-sm text-[#96201A]">{fileError}</p>}
                      </div>
                    ) : (
                      <Upload accepts={requirement.accepts}
                        label={submission ? 'Replace file' : 'Upload photo or document'}
                        disabled={!replaceable}
                        value={submission ? { name: submission.fileName, type: submission.fileType, size: submission.size, dataUrl: submission.dataUrl } : null}
                        onChange={(file) => submitRequirement(application.id, requirement.id, file)}
                        onRemove={replaceable ? () => removeSubmission(submission.id) : undefined} />
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}

        {portal && ['needs_resubmission', 'rejected'].includes(application.status) && (
          <div className="mt-5 flex flex-wrap items-center gap-3 border-t border-line pt-4">
            {submitError && <p className="w-full text-sm text-[#96201A]">{submitError}</p>}
            <button type="button" className="btn btn-primary" disabled={mutating}
              onClick={async () => {
                setSubmitError('');
                try {
                  await submitApplication(application.id);
                } catch (err) {
                  setSubmitError(apiErrorText(err));
                }
              }}>
              {mutating ? 'Submitting…' : (application.status === 'rejected' ? 'Reapply' : 'Resubmit application')}
            </button>
          </div>
        )}

        {!portal && !locked && reqs.length > 0 && (
          <div className="mt-5 flex flex-wrap items-center gap-3 border-t border-line pt-4">
            <Confirm className="btn btn-primary" message="Submit this application for SK review?"
              onConfirm={() => submitApplication(application.id)}>Submit for review</Confirm>
            {!done && <p className="text-xs text-[#7A5A05]">Upload every required item first.</p>}
          </div>
        )}
      </Section>
    </>
  );
}

export function YouthNotifications() {
  const { user, db, readNotification, readAllNotifications, deleteNotification, sessionKind, youthPortalLoading, youthPortalError } = useStore();
  const portal = sessionKind === 'youth';
  const rows = db.notifications.filter((n) => youthInbox(n, user.orgId, user.youthId));
  const unread = rows.filter((n) => !n.read).length;

  return (
    <>
      <PageHeader title="Notifications" subtitle={`${unread} unread of ${rows.length}`}
        actions={unread > 0 && (
          <Confirm message="Mark everything as read?"
            onConfirm={() => readAllNotifications((n) => youthInbox(n, user.orgId, user.youthId))}>Mark all as read</Confirm>
        )} />

      {portal && (youthPortalLoading || youthPortalError) ? <PortalNotice loading={youthPortalLoading} error={youthPortalError} /> : rows.length === 0 ? (
        <Empty title="Nothing here" body="Updates about your applications, new opportunities, and attendance will appear here." />
      ) : (
        <div className="card divide-y divide-line">
          {rows.map((n) => (
            <div key={n.id} className={`flex flex-wrap items-start gap-3 p-4 ${n.read ? '' : 'bg-[#F8FAFD]'}`}>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  {!n.read && <span className="h-2 w-2 rounded-full bg-navy-600" />}
                  <p className="text-sm font-semibold text-navy-900">{n.title}</p>
                </div>
                <p className="mt-1 text-sm text-[#5A6C82]">{n.body}</p>
                <p className="mt-1 text-xs text-[#8391A4]">{n.createdAt}</p>
              </div>
              <div className="flex gap-2">
                {n.link && n.link.startsWith('/youth')
                  ? <Link to={n.link} onClick={() => readNotification(n.id)} className="btn btn-ghost btn-sm">Open</Link>
                  : !n.read && <button type="button" className="btn btn-ghost btn-sm" onClick={() => readNotification(n.id)}>Mark as read</button>}
                <Confirm className="btn btn-danger btn-sm" message="Delete this notification?" onConfirm={() => deleteNotification(n.id)}>Delete</Confirm>
              </div>
            </div>
          ))}
        </div>
      )}
    </>
  );
}

export function YouthQr() {
  const { user, db, youthRecord } = useStore();
  if (!youthRecord) return <Empty title="No youth record linked" body="Contact your SK office so they can link your account." />;

  const token = makeYouthToken(youthRecord);
  const upcoming = db.registrations
    .filter((r) => r.youthId === user.youthId && r.status !== 'cancelled')
    .map((r) => ({ ...r, program: db.programs.find((p) => p.id === r.programId) }))
    .filter((r) => r.program && r.attendance !== 'present');

  return (
    <>
      <PageHeader title="My QR" subtitle="Show this to the SK at an activity so they can record your attendance." />

      <div className="grid gap-4 lg:grid-cols-2">
        <div className="card p-6 text-center">
          <p className="text-xs font-semibold uppercase tracking-wide text-[#8391A4]">Youth QR</p>
          <div className="mt-4 flex justify-center"><QrImage value={token} size={240} /></div>
          <p className="mt-4 text-lg font-bold text-navy-900">{fullName(youthRecord)}</p>
          <p className="text-sm text-[#5A6C82]">Youth ID: <span className="font-mono">{youthCode(youthRecord)}</span></p>
          <p className="mt-3 font-mono text-xs text-[#8391A4]">{token}</p>
          <p className="mt-2 text-xs text-[#8391A4]">
            If the camera cannot read it, the SK can type this code instead.
          </p>
        </div>

        <Section title="Where this works">
          <p className="mt-3 text-sm leading-relaxed text-[#4A5B70]">
            This code identifies you — it is not tied to one activity. The SK picks the activity first,
            scans your QR, checks that you are registered, then confirms your attendance.
          </p>
          <p className="mt-3 rounded-lg bg-sun-100 px-3 py-2 text-xs leading-relaxed text-[#7A5A05]">
            Registering for an activity is not the same as attending it. You are only marked present
            after the SK scans this code at the activity itself.
          </p>

          <h3 className="mt-5 text-xs font-semibold text-[#5A6C82]">Registered, waiting for attendance</h3>
          {upcoming.length === 0 ? (
            <p className="mt-2 text-sm text-[#7A889B]">Nothing pending. Register for an activity first.</p>
          ) : (
            <ul className="mt-2 space-y-2">
              {upcoming.map((r) => (
                <li key={r.id} className="flex items-center justify-between rounded-lg border border-line px-3 py-2">
                  <span>
                    <span className="block text-sm font-medium text-navy-900">{r.program.name}</span>
                    <span className="block text-xs text-[#8391A4]">{new Date(r.program.scheduledOn).toDateString()}</span>
                  </span>
                  <Badge value="registered" />
                </li>
              ))}
            </ul>
          )}

          <Link to="/youth/attendance" className="btn btn-ghost mt-4 w-full">View my attendance history</Link>
        </Section>
      </div>
    </>
  );
}

/** Registrations the youth made, separate from whether they turned up. */
export function YouthRegistrations() {
  const { user, db } = useStore();
  const rows = db.registrations
    .filter((r) => r.youthId === user.youthId)
    .map((r) => ({ ...r, program: db.programs.find((p) => p.id === r.programId) }))
    .filter((r) => r.program);

  return (
    <>
      <PageHeader title="My registrations" subtitle={`${rows.length} activity registration(s)`}
        actions={<Link to="/youth/events" className="btn btn-primary">Browse activities</Link>} />

      {rows.length === 0 ? (
        <Empty title="No registrations yet" body="Scan an activity QR or open Programs & Events to register.">
          <Link to="/youth/scan" className="btn btn-primary">Scan activity QR</Link>
        </Empty>
      ) : (
        <Table head={['Activity', 'Date', 'Registration', 'Attendance']} minWidth={560}>
          {rows.map((r) => (
            <tr key={r.id}>
              <td className="td">
                <Link to={`/youth/${r.program.kind === 'event' ? 'events' : 'programs'}/${r.program.id}`}
                  className="font-medium text-navy-900 underline">{r.program.name}</Link>
                <span className="block text-xs text-[#8391A4]">{r.program.location}</span>
              </td>
              <td className="td">{new Date(r.program.scheduledOn).toDateString()}</td>
              <td className="td"><Badge value={r.status} /></td>
              <td className="td">
                <Badge value={r.attendance === 'present' ? 'present' : 'not yet attended'} />
                {r.scannedAt && <span className="block text-xs text-[#8391A4]">{r.scannedAt}</span>}
              </td>
            </tr>
          ))}
        </Table>
      )}
    </>
  );
}

export function YouthAttendance() {
  const { user, db, sessionKind, youthPortalLoading, checkInAttendance } = useStore();
  const live = sessionKind === 'youth';
  const [mode, setMode] = useState('');
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState(null);
  const rows = db.registrations
    .filter((r) => r.youthId === user.youthId)
    .map((r) => ({ ...r, program: r.program || db.programs.find((p) => p.id === r.programId) }))
    .filter((r) => r.program);
  const present = rows.filter((r) => r.attendance === 'present');
  const statusLabel = (row) => row.attendance || (row.status === 'pending' ? 'pending' : row.status) || 'pending';
  const kindLabel = (program) => (program?.kind === 'event' ? 'Event' : 'Program');

  const submitCode = async (value) => {
    const next = String(value || '').trim();
    if (!next || busy || !checkInAttendance) return;
    setBusy(true);
    setNotice(null);
    try {
      const result = await checkInAttendance(next);
      setNotice({ ok: true, message: result?.message || 'Attendance recorded successfully.' });
      setCode('');
      setMode('');
    } catch (err) {
      const already = err?.code === 'ATTENDANCE_ALREADY_RECORDED' || err?.status === 409;
      setNotice({
        ok: false,
        message: already ? 'Attendance already recorded.' : friendlyApiMessage(err, 'Check-in failed.'),
      });
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHeader title="My attendance" subtitle="Check in with the activity code, or wait for the SK to scan your Youth QR."
        actions={<Link to="/youth/qr" className="btn btn-primary">Show my QR</Link>} />

      {live && (
        <Section title="Attendance Check-In">
          <p className="mt-2 text-sm text-[#4A5B70]">
            Scan the attendance QR displayed by the SK, or type the attendance code.
          </p>
          {mode === '' && (
            <div className="mt-3 flex flex-wrap gap-2">
              <button type="button" className="btn btn-primary" disabled={busy} onClick={() => { setNotice(null); setMode('scan'); }}>Scan QR</button>
              <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => { setNotice(null); setMode('code'); }}>Enter Code</button>
            </div>
          )}
          {mode === 'scan' && (
            <div className="mt-3">
              <QrScanner
                startOpen
                showManual={false}
                openLabel="Scan QR"
                deniedMessage="Camera access is unavailable. Enter the attendance code manually."
                placeholder="ATT-XXXXXXXX"
                onScan={submitCode}
              />
              <button type="button" className="btn btn-ghost mt-3" disabled={busy} onClick={() => setMode('code')}>Enter Code</button>
            </div>
          )}
          {mode === 'code' && (
            <form className="mt-3 max-w-md" onSubmit={(e) => { e.preventDefault(); submitCode(code); }}>
              <Field label="Attendance Code">
                <input className="field font-mono" value={code} onChange={(e) => setCode(e.target.value)}
                  placeholder="ATT-XXXXXXXX" disabled={busy} autoComplete="off" />
              </Field>
              <div className="mt-3 flex gap-2">
                <button type="submit" className="btn btn-primary" disabled={busy || !code.trim()}>
                  {busy ? 'Checking in...' : 'Check In'}
                </button>
                <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => setMode('scan')}>Scan QR</button>
              </div>
            </form>
          )}
          {busy && mode === 'scan' && <p className="mt-2 text-sm text-[#5A6C82]">Checking in...</p>}
          {notice && (
            <p className={`mt-3 rounded-card border px-4 py-3 text-sm ${
              notice.ok ? 'border-[#BFE3D0] bg-[#F1FAF5] text-[#12664A]' : 'border-[#F0D3D1] bg-[#FCF3F2] text-[#96201A]'
            }`}>{notice.message}</p>
          )}
        </Section>
      )}

      {live && youthPortalLoading ? (
        <p className="mt-4 text-sm text-[#5A6C82]">Loading attendance…</p>
      ) : (
        <>
          <div className="mb-4 mt-4 grid grid-cols-3 gap-3">
            <Stat label="Records" value={rows.length} />
            <Stat label="Present" value={present.length} />
            <Stat label="Not present" value={rows.length - present.length} />
          </div>

          {rows.length === 0 ? (
            <Empty title="No attendance records yet." body="Attendance appears here after you check in or the SK records it." />
          ) : (
            <Table head={['Activity', 'Type', 'Date', 'Location', 'Status']} minWidth={640}>
              {rows.map((r) => (
                <tr key={r.id}>
                  <td className="td font-medium text-navy-900">{r.program.name}</td>
                  <td className="td">{kindLabel(r.program)}</td>
                  <td className="td">{r.program.scheduledOn ? new Date(r.program.scheduledOn).toDateString() : '—'}</td>
                  <td className="td">{r.program.location || '—'}</td>
                  <td className="td"><Badge value={statusLabel(r)} /></td>
                </tr>
              ))}
            </Table>
          )}
        </>
      )}
    </>
  );
}
