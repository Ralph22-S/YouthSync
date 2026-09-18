import { useEffect, useMemo, useState } from 'react';
import { StoreContext } from '../store-context.js';
import {
  ORGANIZATIONS, USERS, PLANS, ASSISTANCE_TYPES, buildData, buildYouthAccounts, planByCode,
  evaluatePriority, newId, today, parseYouthToken, parseProgramCode, youthCode,
  temporaryPassword, MAX_UPLOAD_BYTES,
} from '../data/mock.js';
import { loadState, saveState, clearState } from '../lib/storage.js';

const addDays = (n) => { const d = new Date(today); d.setDate(d.getDate() + n); return d; };
const iso = (d) => d.toISOString().slice(0, 10);
const stamp = () => new Date().toISOString().slice(0, 16).replace('T', ' ');

function seed() {
  const db = buildData();
  return {
    db,
    orgs: ORGANIZATIONS,
    users: [...USERS, ...buildYouthAccounts(db.youth)],
    types: ASSISTANCE_TYPES,
  };
}

export function MockStoreProvider({ children }) {
  // Restored from localStorage when present, so a refresh keeps the records.
  const [data, setData] = useState(() => loadState() ?? seed());
  const [userId, setUserId] = useState(null);
  const [signup, setSignup] = useState(null);
  const [flash, setFlash] = useState(null);

  const { db, orgs, users, types } = data;

  useEffect(() => { saveState(data); }, [data]);

  const user = users.find((u) => u.id === userId) ?? null;
  const org = user?.orgId ? orgs.find((o) => o.id === user.orgId) : null;
  const youthRecord = user?.role === 'youth' ? db.youth.find((y) => y.id === user.youthId) : null;

  const plan = org ? planByCode(org.plan) : PLANS[0];
  const effectivePlan = org && ['expired', 'cancelled', 'payment_failed'].includes(org.subStatus)
    ? planByCode('free') : plan;

  const scoped = (key) => (db[key] ?? []).filter((row) => row.orgId === org?.id);

  const usage = useMemo(() => ({
    youth: db.youth.filter((y) => y.orgId === org?.id && !y.archived).length,
    accounts: users.filter((u) => u.orgId === org?.id && u.role === 'sk_official').length,
    programs: db.programs.filter((p) => p.orgId === org?.id && ['draft', 'published', 'ongoing'].includes(p.status)).length,
    assistance: db.assistance.filter((a) => a.orgId === org?.id && ['draft', 'open', 'full'].includes(a.status)).length,
  }), [db, users, org]);

  const limits = effectivePlan.limits;
  const qrUses = org?.qrUses ?? 0;
  const qrLimit = limits.qr ?? null;
  const qrRemaining = qrLimit === null ? null : Math.max(0, qrLimit - qrUses);
  const canScan = () => qrLimit === null || qrUses < qrLimit;
  const qrLimitMessage = () =>
    `QR scan limit reached — the ${effectivePlan.name} plan includes ${qrLimit} scan${qrLimit === 1 ? '' : 's'}. `
    + 'Upgrade your plan for more QR scans.';
  const remaining = (key) => (limits[key] === null ? null : Math.max(0, limits[key] - usage[key]));
  const canAdd = (key, count = 1) => limits[key] === null || usage[key] + count <= limits[key];
  const allows = (feature) => Boolean(effectivePlan.features[feature]);
  const limitMessage = (key) => {
    const labels = { youth: 'youth records', accounts: 'staff accounts', programs: 'active programs and events', assistance: 'active assistance programs' };
    return `You have reached the ${effectivePlan.name} plan limit of ${limits[key]?.toLocaleString()} ${labels[key]}. `
      + 'Your existing data is untouched — upgrade your plan to add more.';
  };

  const notify = (message, tone = 'success') => setFlash({ message, tone });

  const consumeQr = () => setOrgs((rows) => rows.map((o) => (o.id === org.id ? { ...o, qrUses: (o.qrUses ?? 0) + 1 } : o)));

  /** Newest first: every insert goes to index 0. */
  const prepend = (key, row) => setData((d) => ({ ...d, db: { ...d.db, [key]: [row, ...d.db[key]] } }));
  const patch = (key, updater) => setData((d) => ({ ...d, db: { ...d.db, [key]: updater(d.db[key]) } }));
  const setUsers = (updater) => setData((d) => ({ ...d, users: updater(d.users) }));
  const setOrgs = (updater) => setData((d) => ({ ...d, orgs: updater(d.orgs) }));
  const setTypes = (updater) => setData((d) => ({ ...d, types: updater(d.types) }));

  const requirementsFor = (targetType, targetId) =>
    db.requirements.filter((r) => r.targetType === targetType && r.targetId === Number(targetId));
  const submissionsFor = (applicationId) => db.submissions.filter((s) => s.applicationId === applicationId);
  const targetOf = (application) => (application.targetType === 'program'
    ? db.programs.find((p) => p.id === application.targetId)
    : db.assistance.find((a) => a.id === application.targetId));

  const youthById = (id) => db.youth.find((y) => y.id === id);

  /** A youth record only has an account if a user row points at it. */
  const accountFor = (youth) => users.find((u) => u.role === 'youth' && u.youthId === youth?.id);
  const accountStatusOf = (youth) => {
    const account = accountFor(youth);
    if (!account) return 'not_registered';
    return account.active ? 'active' : 'inactive';
  };

  /**
   * Compares a youth profile against an opportunity's eligibility settings.
   * It recommends only — the SK still decides. Nothing here approves anybody.
   */
  const matchProfile = (youth, target) => {
    if (!youth || !target) return { matched: false, reasons: [], blockers: [] };

    const reasons = [];
    const blockers = [];
    const overlap = (a = [], b = []) => a.filter((v) => b.includes(v));

    const tags = [...(target.tagInterests || []), ...(target.tagSkills || []), ...(target.tagActivities || [])];

    overlap(youth.interests || [], target.tagInterests || []).forEach((v) => reasons.push(`Interest: ${v}`));
    overlap(youth.skills || [], target.tagSkills || []).forEach((v) => reasons.push(`Skill: ${v}`));
    overlap(youth.preferredActivities || [], target.tagActivities || []).forEach((v) => reasons.push(`Preferred activity: ${v}`));

    if (target.requiresStudying && youth.educationStatus !== 'Currently Studying') {
      blockers.push('You may not meet the education requirement for this opportunity — it is for youth who are currently studying.');
    }

    const years = youth.birthDate ? Math.floor((Date.now() - new Date(youth.birthDate)) / 31557600000) : null;
    if (target.minAge && years !== null && years < target.minAge) blockers.push(`Minimum age is ${target.minAge}.`);
    if (target.maxAge && years !== null && years > target.maxAge) blockers.push(`Maximum age is ${target.maxAge}.`);

    return { matched: reasons.length > 0, reasons, blockers, configured: tags.length > 0 || Boolean(target.requiresStudying) };
  };

  /**
   * The one place a youth gets told something.
   * In-app notification + simulated SMS + simulated email, all newest-first.
   * Nothing here sends anything for real — SMS and email are system records only.
   */
  const dispatchToYouth = (youth, { category, title, body, link = null, related = null, email = true }) => {
    if (!youth) return;

    const createdAt = stamp();

    prepend('notifications', {
      id: newId(), orgId: youth.orgId, youthId: youth.id, category, title, body, link,
      related, createdAt: iso(today), sentAt: createdAt, read: false,
    });

    if (youth.contact) {
      const text = `YouthSync: ${body}`;
      prepend('sms', {
        id: newId(), orgId: youth.orgId, youthId: youth.id,
        name: `${youth.firstName} ${youth.lastName}`, mobile: youth.contact,
        type: category, related, text, characters: text.length,
        status: 'Simulated Sent', sentAt: createdAt,
      });
    }

    if (email && youth.email) {
      prepend('emails', {
        id: newId(), orgId: youth.orgId, youthId: youth.id,
        name: `${youth.firstName} ${youth.lastName}`, email: youth.email,
        subject: title, body, type: category, related,
        status: 'Simulated Sent', sentAt: createdAt,
      });
    }
  };

  /** Append-only audit trail. Newest first, like everything else. */
  const log = (action, description, orgId = org?.id ?? null) => {
    prepend('activityLogs', {
      id: newId(), orgId, action, description,
      actor: user ? user.name : 'System', role: user?.role ?? 'system',
      at: stamp(),
    });
  };

  const actions = {
    // ---- session -------------------------------------------------------
    login(email) {
      const found = users.find((u) => u.email.toLowerCase() === String(email).trim().toLowerCase());
      if (!found) return { error: 'Those credentials do not match our records.' };
      if (!found.active) return { error: 'This account has been deactivated.' };
      if (found.role === 'youth' && !found.verified) {
        return { error: 'This account is not verified yet. Finish OTP verification to continue.' };
      }
      if (found.role === 'sk_official') {
        return { error: 'Those credentials do not match our records.' };
      }
      setUserId(found.id);
      return { user: found };
    },
    logout: () => setUserId(null),

    resetDemoData() {
      clearState();
      setData(seed());
      setUserId(null);
      notify('Records reset to its starting state.');
    },

    // ---- youth sign-up + OTP -------------------------------------------
    startSignup(payload) {
      if (users.some((u) => u.email.toLowerCase() === payload.email.toLowerCase())) {
        return { error: 'An account with that email already exists. Log in instead.' };
      }
      const code = String(Math.floor(100000 + Math.random() * 900000));
      setSignup({ ...payload, code, expiresAt: Date.now() + 300000, attempts: 0 });
      return { code };
    },
    resendOtp() {
      if (!signup) return { error: 'Start the sign-up again.' };
      const code = String(Math.floor(100000 + Math.random() * 900000));
      setSignup((s) => ({ ...s, code, expiresAt: Date.now() + 300000, attempts: 0 }));
      return { code };
    },
    verifyOtp(entered) {
      if (!signup) return { error: 'Start the sign-up again.' };
      if (Date.now() > signup.expiresAt) return { error: 'That code has expired. Tap Resend code to get a new one.' };
      if (signup.attempts >= 4) return { error: 'Too many incorrect attempts. Resend the code to try again.' };
      if (String(entered).trim() !== signup.code) {
        setSignup((s) => ({ ...s, attempts: s.attempts + 1 }));
        return { error: `Incorrect code. ${4 - signup.attempts} attempt(s) left.` };
      }

      const youthId = newId();
      const base = {
        id: youthId, orgId: Number(signup.orgId),
        firstName: signup.firstName, middleName: signup.middleName || '', lastName: signup.lastName,
        birthDate: signup.birthDate, gender: signup.gender, address: signup.address,
        contact: signup.mobile, email: signup.email, civilStatus: 'Single',
        education: signup.education || 'Senior High School', school: '', studying: true, courseStrand: '',
        employment: 'Student', occupation: '', guardianName: '', guardianEmployment: '',
        guardianOccupation: '', familyIncome: 0, familyMembers: 0, skills: '',
        interests: signup.interests || 'Community service', preferredActivities: '',
        previousScholarship: false, previousAssistance: false, previousParticipation: false,
        archived: false, photo: signup.photo ?? null, createdAt: iso(today), selfRegistered: true,
      };
      prepend('youth', { ...base, ...evaluatePriority(base) });

      const account = {
        id: newId(), name: `${signup.firstName} ${signup.lastName}`, email: signup.email,
        mobile: signup.mobile, role: 'youth', orgId: Number(signup.orgId), youthId,
        verified: true, active: true, lastLogin: 'Today', createdAt: stamp(),
      };
      setUsers((rows) => [account, ...rows]);

      dispatchToYouth({ ...base }, {
        category: 'system', title: 'Welcome to YouthSync',
        body: 'Your account is verified. Browse events, programs, and assistance to start applying.',
        link: '/youth',
      });

      prepend('notifications', {
        id: newId(), orgId: Number(signup.orgId), youthId: null, category: 'system',
        title: 'New youth account registered', body: `${account.name} signed up and verified their account.`,
        link: '/sk/youth', createdAt: iso(today), sentAt: stamp(), read: false,
      });

      setSignup(null);
      setUserId(account.id);
      return { user: account };
    },
    cancelSignup: () => setSignup(null),

    // ---- youth CRUD ----------------------------------------------------
    /**
     * Create or update a youth record. Passing `account` also opens a login for
     * them, reusing the same Youth ID — it never creates a second record.
     */
    saveYouthWithAccount(values, account) {
      const id = actions.saveYouth(values);
      if (!id || !account?.email) return { id };

      if (users.some((u) => u.email.toLowerCase() === account.email.trim().toLowerCase())) {
        notify('That email already has an account. The youth record was saved without one.', 'warning');
        return { id };
      }
      if (!canAdd('accounts')) { notify(limitMessage('accounts'), 'warning'); return { id }; }

      const password = temporaryPassword();
      const saved = db.youth.find((y) => y.id === id) || values;

      setUsers((rows) => [{
        id: newId(),
        name: `${values.firstName} ${values.lastName}`,
        email: account.email.trim(),
        mobile: values.contact,
        role: 'youth',
        orgId: org.id,
        youthId: id,
        active: true,
        // Verified by the SK in person, so no OTP step — the temporary password
        // is handed over at the counter instead.
        verified: true,
        mustChangePassword: true,
        temporaryPassword: password,
        createdAt: iso(today),
        lastLogin: 'Never',
      }, ...rows]);

      log('youth', `Created a youth account for ${values.firstName} ${values.lastName}.`);
      notify('Youth record and account created successfully.');
      return { id, password, code: youthCode(saved) };
    },

    /** First login after the SK hands over a temporary password. */
    changePassword(newPassword) {
      if (String(newPassword || '').length < 8) {
        notify('Use a password of at least 8 characters.', 'warning');
        return false;
      }
      // `user` is derived from `users`, so updating the list is enough.
      setUsers((rows) => rows.map((u) => (u.id === user.id
        ? { ...u, mustChangePassword: false, temporaryPassword: null } : u)));
      notify('Password changed successfully.');
      return true;
    },

    saveYouth(values) {
      const evaluated = evaluatePriority(values);

      if (values.id) {
        patch('youth', (rows) => rows.map((y) => (y.id === values.id ? { ...y, ...values, ...evaluated } : y)));
        setUsers((rows) => rows.map((u) => (u.youthId === values.id
          ? { ...u, name: `${values.firstName} ${values.lastName}`, mobile: values.contact } : u)));
        notify('Youth record updated.');
        return values.id;
      }

      if (!canAdd('youth')) { notify(limitMessage('youth'), 'warning'); return null; }

      const id = newId();
      prepend('youth', {
        ...values, id, orgId: org.id, archived: false,
        createdAt: iso(today), addedAt: stamp(), ...evaluated,
      });
      notify('Youth record added. It is now at the top of your list.');
      return id;
    },
    archiveYouth(id) {
      patch('youth', (rows) => rows.map((y) => (y.id === id ? { ...y, archived: true } : y)));
      notify('Youth record archived. It can be restored anytime.');
    },
    restoreYouth(id) {
      if (!canAdd('youth')) { notify(limitMessage('youth'), 'warning'); return; }
      patch('youth', (rows) => rows.map((y) => (y.id === id ? { ...y, archived: false } : y)));
      notify('Youth record restored.');
    },
    deleteYouth(id) {
      const youth = youthById(id);
      patch('youth', (rows) => rows.filter((y) => y.id !== id));
      patch('registrations', (rows) => rows.filter((r) => r.youthId !== id));
      patch('applications', (rows) => rows.filter((a) => a.youthId !== id));
      patch('beneficiaries', (rows) => rows.filter((b) => b.youthId !== id));
      setUsers((rows) => rows.filter((u) => u.youthId !== id));
      notify(`${youth ? `${youth.firstName} ${youth.lastName}` : 'The record'} was deleted permanently.`);
    },
    importYouth(rows) {
      if (!canAdd('youth', rows.length)) {
        notify(`This import contains ${rows.length} records but only ${remaining('youth')} slots remain on the ${effectivePlan.name} plan.`, 'warning');
        return 0;
      }
      const prepared = rows.map((r) => ({
        ...r, id: newId(), orgId: org.id, archived: false,
        createdAt: iso(today), addedAt: stamp(), ...evaluatePriority(r),
      })).reverse();
      patch('youth', (list) => [...prepared, ...list]);
      notify(`${prepared.length} youth records successfully imported.`);
      return prepared.length;
    },
    updateYouthProfile(values) {
      patch('youth', (rows) => rows.map((y) => (y.id === user.youthId ? { ...y, ...values, ...evaluatePriority({ ...y, ...values }) } : y)));
      setUsers((rows) => rows.map((u) => (u.id === user.id
        ? { ...u, name: `${values.firstName} ${values.lastName}`, mobile: values.contact } : u)));
      notify('Your profile was saved.');
    },

    // ---- user management -----------------------------------------------
    saveUser(values) {
      if (values.id) {
        setUsers((rows) => rows.map((u) => (u.id === values.id ? { ...u, ...values } : u)));
        notify('User account updated.');
        return values.id;
      }
      if (values.role === 'sk_official' && !canAdd('accounts')) {
        notify(limitMessage('accounts'), 'warning');
        return null;
      }
      const id = newId();
      setUsers((rows) => [{
        ...values, id, orgId: org.id, active: true, verified: true,
        lastLogin: 'Never', createdAt: stamp(),
      }, ...rows]);
      notify(`${values.name} was added and is at the top of the list.`);
      return id;
    },
    toggleUserActive(id) {
      if (id === user.id) { notify('You cannot deactivate your own account.', 'warning'); return; }
      setUsers((rows) => rows.map((u) => (u.id === id ? { ...u, active: !u.active } : u)));
      notify('Account status updated.');
    },
    deleteUser(id) {
      const target = users.find((u) => u.id === id);
      if (target?.owner) { notify('The owner account cannot be removed.', 'warning'); return; }
      if (id === user.id) { notify('You cannot delete your own account.', 'warning'); return; }
      setUsers((rows) => rows.filter((u) => u.id !== id));
      notify('User account deleted.');
    },
    toggleUser(id) { actions.toggleUserActive(id); },
    saveStaff(values) { actions.saveUser({ ...values, role: 'sk_official' }); },
    removeStaff(id) { actions.deleteUser(id); },

    // ---- requirements ---------------------------------------------------
    addRequirement(targetType, targetId, values) {
      prepend('requirements', { ...values, id: newId(), orgId: org.id, targetType, targetId });
      notify(`Requirement "${values.name}" added.`);
    },
    updateRequirement(id, values) {
      patch('requirements', (rows) => rows.map((r) => (r.id === id ? { ...r, ...values } : r)));
      notify('Requirement updated.');
    },
    removeRequirement(id) {
      patch('requirements', (rows) => rows.filter((r) => r.id !== id));
      notify('Requirement removed.');
    },

    // ---- applications ---------------------------------------------------
    apply(targetType, targetId) {
      const existing = db.applications.find((a) => a.youthId === user.youthId
        && a.targetType === targetType && a.targetId === targetId);
      if (existing) { notify('You already have an application for this. Open it to continue.', 'warning'); return existing.id; }

      const target = targetType === 'program'
        ? db.programs.find((p) => p.id === targetId)
        : db.assistance.find((a) => a.id === targetId);

      const id = newId();
      let registrationId = null;

      if (targetType === 'program') {
        const existingReg = db.registrations.find((r) => r.programId === targetId && r.youthId === user.youthId);
        if (existingReg) {
          registrationId = existingReg.id;
        } else {
          registrationId = newId();
          const taken = db.registrations.filter((r) => r.programId === targetId && r.status === 'registered').length;
          const full = target.maxParticipants && taken >= target.maxParticipants;
          prepend('registrations', {
            id: registrationId, orgId: target.orgId, programId: targetId, youthId: user.youthId,
            status: full ? 'waitlisted' : 'registered', selected: false,
            registeredAt: stamp(), attendance: null, scannedAt: null,
          });
        }
      }

      prepend('applications', {
        id, orgId: target.orgId, youthId: user.youthId, targetType, targetId,
        status: 'pending', submittedAt: iso(today), createdAt: stamp(),
        reviewedAt: null, remarks: '', registrationId,
      });

      dispatchToYouth(youthById(user.youthId), {
        category: 'applications',
        title: targetType === 'program' ? 'You are registered' : 'Application received',
        body: targetType === 'program'
          ? `You are registered for ${target.name}. Being registered is not attendance — the SK will scan your Youth QR at the activity.`
          : `Your application for ${target.name} was received. Upload the requirements to complete it.`,
        link: `/youth/applications/${id}`, related: target.name, email: false,
      });

      prepend('notifications', {
        id: newId(), orgId: target.orgId, youthId: null, category: 'applications',
        title: 'New application received',
        body: `${youthRecord?.firstName} ${youthRecord?.lastName} registered for "${target.name}".`,
        link: `/sk/applications/${id}`, createdAt: iso(today), sentAt: stamp(), read: false,
      });

      log('register', `${youthRecord?.firstName} ${youthRecord?.lastName} registered for "${target.name}".`, target.orgId);

      notify(targetType === 'program'
        ? 'Registration submitted successfully.'
        : 'Application created successfully.');
      return id;
    },

    /** Youth scans the code printed on the activity poster. */
    registerByProgramCode(code) {
      const programId = parseProgramCode(code);
      if (!programId) return { ok: false, message: 'Invalid QR code.' };

      const program = db.programs.find((p) => p.id === programId);
      if (!program || program.orgId !== user.orgId) return { ok: false, message: 'Invalid QR code.' };

      if (!['published', 'ongoing'].includes(program.status)) {
        return { ok: false, message: 'This program is currently inactive and cannot accept registrations.' };
      }
      if (program.registrationDeadline && program.registrationDeadline < iso(today)) {
        return { ok: false, message: 'Registration for this activity has closed.' };
      }
      if (db.registrations.some((r) => r.programId === programId && r.youthId === user.youthId && r.status !== 'cancelled')) {
        return { ok: false, message: 'You are already registered for this program.' };
      }

      const applicationId = actions.apply('program', programId);
      setOrgs((rows) => rows.map((o) => (o.id === program.orgId ? { ...o, qrUses: (o.qrUses ?? 0) + 1 } : o)));
      return { ok: true, message: `Registration completed successfully — you are registered for ${program.name}.`, programId, applicationId };
    },

    submitRequirement(applicationId, requirementId, file) {
      const application = db.applications.find((a) => a.id === applicationId);
      const requirement = db.requirements.find((r) => r.id === requirementId);

      patch('submissions', (rows) => {
        const existing = rows.find((s) => s.applicationId === applicationId && s.requirementId === requirementId);
        const record = {
          id: existing?.id ?? newId(), applicationId, requirementId,
          fileName: file.name, fileType: file.type, dataUrl: file.dataUrl, size: file.size,
          status: 'submitted', remarks: '', submittedAt: iso(today), createdAt: stamp(),
        };
        return existing ? rows.map((s) => (s.id === existing.id ? record : s)) : [record, ...rows];
      });

      if (application.status === 'needs_resubmission') {
        patch('applications', (rows) => rows.map((a) => (a.id === applicationId ? { ...a, status: 'pending' } : a)));
      }

      prepend('notifications', {
        id: newId(), orgId: application.orgId, youthId: null, category: 'applications',
        title: 'Requirement submitted',
        body: `${youthRecord?.firstName} ${youthRecord?.lastName} submitted "${requirement.name}".`,
        link: `/sk/applications/${applicationId}`, createdAt: iso(today), sentAt: stamp(), read: false,
      });

      notify(`"${requirement.name}" submitted.`);
    },
    removeSubmission(id) {
      patch('submissions', (rows) => rows.filter((s) => s.id !== id));
      notify('Upload removed. You can submit a replacement.');
    },
    submitApplication(applicationId) {
      const application = db.applications.find((a) => a.id === applicationId);
      const required = requirementsFor(application.targetType, application.targetId).filter((r) => r.required);
      const done = submissionsFor(applicationId);
      const missing = required.filter((r) => !done.some((s) => s.requirementId === r.id));

      if (missing.length) { notify(`Still missing: ${missing.map((r) => r.name).join(', ')}.`, 'warning'); return; }

      patch('applications', (rows) => rows.map((a) => (a.id === applicationId
        ? { ...a, status: 'pending', submittedAt: iso(today) } : a)));

      prepend('notifications', {
        id: newId(), orgId: application.orgId, youthId: null, category: 'applications',
        title: 'Requirements ready for review',
        body: `${youthRecord?.firstName} ${youthRecord?.lastName} completed the requirements for "${targetOf(application)?.name}".`,
        link: `/sk/applications/${applicationId}`, createdAt: iso(today), sentAt: stamp(), read: false,
      });

      notify('Application submitted for review.');
    },
    reviewRequirement(submissionId, status, remarks = '') {
      const submission = db.submissions.find((s) => s.id === submissionId);
      const requirement = db.requirements.find((r) => r.id === submission.requirementId);
      const application = db.applications.find((a) => a.id === submission.applicationId);

      patch('submissions', (rows) => rows.map((s) => (s.id === submissionId ? { ...s, status, remarks } : s)));

      if (status === 'needs_resubmission') {
        patch('applications', (rows) => rows.map((a) => (a.id === application.id ? { ...a, status: 'needs_resubmission' } : a)));
        dispatchToYouth(youthById(application.youthId), {
          category: 'applications', title: 'Requirement needs resubmission',
          body: `"${requirement.name}" needs to be resubmitted${remarks ? `: ${remarks}` : '.'} Upload a replacement to continue.`,
          link: `/youth/applications/${application.id}`, related: targetOf(application)?.name, email: false,
        });
      }

      log('requirement', `Marked "${requirement.name}" as ${status.replace(/_/g, ' ')}.`);
      notify(`"${requirement.name}" marked as ${status.replace(/_/g, ' ')} successfully.`);
    },

    /** Approve — always reached through a confirmation step in the UI. */
    approveApplication(applicationId) {
      actions.setApplicationStatus(applicationId, 'approved');
      notify('Application approved successfully.');
    },

    /** Reject — the reason is required and is shown to the youth. */
    rejectApplication(applicationId, reason) {
      if (!String(reason || '').trim()) { notify('A reason is required to reject an application.', 'warning'); return false; }
      actions.setApplicationStatus(applicationId, 'rejected', reason.trim());
      notify('Application rejected successfully.');
      return true;
    },

    /** Ask for specific requirements again, with a note explaining what to fix. */
    requestResubmission(applicationId, requirementIds, note) {
      const ids = (requirementIds || []).map(Number).filter(Boolean);
      if (!ids.length) { notify('Select at least one requirement to resubmit.', 'warning'); return false; }
      if (!String(note || '').trim()) { notify('A note is required so the youth knows what to fix.', 'warning'); return false; }

      const application = db.applications.find((a) => a.id === applicationId);
      const names = ids.map((id) => db.requirements.find((r) => r.id === id)?.name).filter(Boolean);

      patch('submissions', (rows) => rows.map((s) => (s.applicationId === applicationId && ids.includes(s.requirementId)
        ? { ...s, status: 'needs_resubmission', remarks: note.trim() } : s)));

      patch('applications', (rows) => rows.map((a) => (a.id === applicationId
        ? { ...a, status: 'needs_resubmission', remarks: note.trim(), reviewedAt: iso(today) } : a)));

      dispatchToYouth(youthById(application.youthId), {
        category: 'applications', title: 'Resubmission requested',
        body: `Please resubmit: ${names.join(', ')}. ${note.trim()}`,
        link: `/youth/applications/${applicationId}`, related: targetOf(application)?.name, email: false,
      });

      log('application', `Requested resubmission of ${names.join(', ')} from ${youthById(application.youthId)?.firstName}.`);
      notify('Resubmission request sent successfully.');
      return true;
    },

    /**
     * Final decision on one application. This is a confirmation action, so it
     * notifies — approving is what tells the youth, not ticking a box.
     */
    setApplicationStatus(applicationId, status, remarks = '') {
      const application = db.applications.find((a) => a.id === applicationId);
      const target = targetOf(application);
      const youth = youthById(application.youthId);

      if (status === 'approved' && application.targetType === 'assistance') {
        patch('beneficiaries', (rows) => {
          const existing = rows.find((b) => b.programId === application.targetId && b.youthId === application.youthId);
          if (existing) return rows.map((b) => (b.id === existing.id ? { ...b, status: 'approved' } : b));
          return [{
            id: newId(), orgId: application.orgId, programId: application.targetId,
            youthId: application.youthId, status: 'approved', awardedOn: iso(today), remarks,
          }, ...rows];
        });
      }

      if (status === 'approved' && application.registrationId) {
        patch('registrations', (rows) => rows.map((r) => (r.id === application.registrationId
          ? { ...r, selected: true } : r)));
      }

      patch('applications', (rows) => rows.map((a) => (a.id === applicationId
        ? { ...a, status, remarks, reviewedAt: iso(today) } : a)));

      const messages = {
        approved: ['Application approved', application.targetType === 'program'
          ? `Congratulations! You have been selected for ${target?.name}. Show your Youth QR at the entrance so the SK can record your attendance.`
          : `Congratulations! Your ${target?.name} application has been approved.`],
        rejected: ['Application rejected',
          `Your application for ${target?.name} was not approved${remarks ? `: ${remarks}` : '.'}`],
        needs_resubmission: ['Requirements need resubmission',
          `Some requirements for ${target?.name} need to be replaced.`],
      };

      if (messages[status] && youth) {
        dispatchToYouth(youth, {
          category: 'applications', title: messages[status][0], body: messages[status][1],
          link: `/youth/applications/${applicationId}`, related: target?.name,
          email: status === 'approved',
        });
      }

      log('application', `${youth?.firstName} ${youth?.lastName} — ${target?.name} marked ${status.replace(/_/g, ' ')}.`);
    },

    /**
     * Confirm Selection for events, programs, scholarships and assistance.
     * Only the youth in `youthIds` are told. Nothing fires on checkbox clicks —
     * the caller runs this from the confirmation step.
     */
    confirmSelection(targetType, targetId, youthIds, options = {}) {
      const ids = [...new Set(youthIds)].filter(Boolean);
      if (!ids.length) { notify('Select at least one youth first.', 'warning'); return 0; }

      const target = targetType === 'program'
        ? db.programs.find((p) => p.id === targetId)
        : db.assistance.find((a) => a.id === targetId);
      if (!target) return 0;

      const verb = options.verb ?? 'selected';
      const label = options.label ?? target.name;

      if (targetType === 'program') {
        patch('registrations', (rows) => {
          const updated = rows.map((r) => (r.programId === targetId && ids.includes(r.youthId)
            ? { ...r, selected: true, status: r.status === 'cancelled' ? 'registered' : r.status } : r));

          const missing = ids
            .filter((id) => !rows.some((r) => r.programId === targetId && r.youthId === id))
            .map((id) => ({
              id: newId(), orgId: target.orgId, programId: targetId, youthId: id,
              status: 'registered', selected: true, registeredAt: stamp(), attendance: null, scannedAt: null,
            }));

          return [...missing.reverse(), ...updated];
        });
      } else {
        patch('beneficiaries', (rows) => {
          const updated = rows.map((b) => (b.programId === targetId && ids.includes(b.youthId)
            ? { ...b, status: 'approved', awardedOn: iso(today) } : b));

          const missing = ids
            .filter((id) => !rows.some((b) => b.programId === targetId && b.youthId === id))
            .map((id) => ({
              id: newId(), orgId: target.orgId, programId: targetId, youthId: id,
              status: 'approved', awardedOn: iso(today), remarks: '',
            }));

          return [...missing.reverse(), ...updated];
        });
      }

      patch('selections', (rows) => [{
        id: newId(), orgId: target.orgId, targetType, targetId, label,
        youthIds: ids, count: ids.length, verb, at: stamp(),
      }, ...rows]);

      ids.forEach((id) => dispatchToYouth(youthById(id), {
        category: targetType === 'program' ? 'programs' : 'assistance',
        title: `You have been ${verb} for ${label}`,
        body: `Congratulations! You have been ${verb} for ${label}. Please check YouthSync for more details.`,
        link: targetType === 'program' ? '/youth/events' : '/youth/assistance',
        related: label,
      }));

      notify(`${ids.length} youth ${verb}. In-app notifications, SMS and email were created for them only.`);
      return ids.length;
    },

    // ---- programs and attendance ----------------------------------------
    saveProgram(values) {
      if (values.id) {
        patch('programs', (rows) => rows.map((p) => (p.id === values.id ? { ...p, ...values } : p)));
        notify('Changes saved.');
        return values.id;
      }
      if (!canAdd('programs')) { notify(limitMessage('programs'), 'warning'); return null; }
      const id = newId();
      prepend('programs', { ...values, id, orgId: org.id, createdAt: stamp() });
      notify(`${values.kind === 'event' ? 'Event' : 'Program'} created and placed at the top of the list.`);
      return id;
    },
    setProgramStatus(id, status) {
      const program = db.programs.find((p) => p.id === id);
      patch('programs', (rows) => rows.map((p) => (p.id === id ? { ...p, status } : p)));

      if (status === 'published') {
        // Every notification has one recipient. Announcements are fanned out per youth
        // rather than left unaddressed, so nobody reads someone else's inbox.
        const recipients = db.youth.filter((y) => y.orgId === org.id && !y.archived);
        const rows = recipients.map((y) => ({
          id: newId(), orgId: org.id, youthId: y.id, recipientUserId: y.id, category: 'programs',
          title: `New ${program.kind}: ${program.name}`,
          body: `Registration is open until ${program.registrationDeadline || program.scheduledOn}. Scan the activity QR or open it in Programs & Events to register.`,
          link: `/youth/${program.kind === 'event' ? 'events' : 'programs'}/${program.id}`,
          related: program.name, createdAt: iso(today), sentAt: stamp(), read: false,
        }));
        patch('notifications', (existing) => [...rows, ...existing]);
        log('program', `Published "${program.name}" and notified ${rows.length} youth.`);
      }

      notify(status === 'published' ? 'Published successfully. Youth in your barangay were notified.'
        : status === 'archived' ? 'Archived successfully.' : 'Moved back to draft successfully.');
    },
    deleteProgram(id) {
      patch('programs', (rows) => rows.filter((p) => p.id !== id));
      patch('registrations', (rows) => rows.filter((r) => r.programId !== id));
      notify('Activity deleted.');
    },
    register(programId, youthId) {
      if (db.registrations.some((r) => r.programId === programId && r.youthId === youthId && r.status !== 'cancelled')) {
        notify('This youth is already registered for this program.', 'warning');
        return;
      }
      const program = db.programs.find((p) => p.id === programId);
      const count = db.registrations.filter((r) => r.programId === programId && r.status === 'registered').length;
      const full = program.maxParticipants && count >= program.maxParticipants;

      prepend('registrations', {
        id: newId(), orgId: org.id, programId, youthId,
        status: full ? 'waitlisted' : 'registered', selected: false,
        registeredAt: stamp(), attendance: null, scannedAt: null,
      });
      notify(full ? 'The activity is full — the participant was added to the waitlist.' : 'Participant registered.');
    },
    markAttendance(registrationId, state) {
      patch('registrations', (rows) => rows.map((r) => (r.id === registrationId
        ? { ...r, attendance: state || null, scannedAt: state ? stamp() : null } : r)));
    },
    cancelRegistration(id) {
      patch('registrations', (rows) => rows.map((r) => (r.id === id ? { ...r, status: 'cancelled' } : r)));
      notify('Registration cancelled.');
    },

    /**
     * Attendance. The SK picks the activity first, then scans the youth's own QR.
     * Registration is checked here — being registered is not the same as present.
     */
    scanYouthQr(token, programId) {
      if (!canScan()) return { ok: false, message: qrLimitMessage() };

      const code = parseYouthToken(token);
      if (!code) return { ok: false, message: 'That is not a Youth QR code.' };

      const youth = db.youth.find((y) => youthCode(y) === code && y.orgId === org.id);
      if (!youth) return { ok: false, message: `No youth in your barangay matches ${code}.` };

      const program = db.programs.find((p) => p.id === Number(programId));
      if (!program) return { ok: false, message: 'Select an activity first.' };

      const registration = db.registrations.find((r) => r.programId === Number(programId)
        && r.youthId === youth.id && r.status !== 'cancelled');

      if (!registration) {
        consumeQr();
        return {
          ok: false, youth,
          message: `Attendance cannot be recorded. ${youth.firstName} ${youth.lastName} is not registered for ${program.name}.`,
        };
      }

      if (registration.attendance === 'present') {
        consumeQr();
        return {
          ok: false, youth,
          message: `Attendance already recorded for ${youth.firstName} ${youth.lastName} at ${registration.scannedAt}.`,
        };
      }

      // Identified and registered — the UI confirms before this becomes Present.
      consumeQr();
      return {
        ok: true, pending: true, youth, registration, program,
        message: `${youth.firstName} ${youth.lastName} (${code}) is registered for ${program.name}.`,
      };
    },

    /** Second half of the scan: the SK confirms, and only then is it attendance. */
    confirmAttendance(registrationId) {
      const registration = db.registrations.find((r) => r.id === registrationId);
      if (!registration) return { ok: false, message: 'That registration no longer exists.' };
      if (registration.attendance === 'present') {
        return { ok: false, message: `Attendance already recorded at ${registration.scannedAt}.` };
      }

      const scannedAt = stamp();
      const youth = youthById(registration.youthId);
      const program = db.programs.find((p) => p.id === registration.programId);

      patch('registrations', (rows) => rows.map((r) => (r.id === registrationId
        ? { ...r, attendance: 'present', scannedAt } : r)));

      dispatchToYouth(youth, {
        category: 'programs', title: 'Attendance recorded',
        body: `You were marked present at ${program?.name} on ${scannedAt}.`,
        link: '/youth/attendance', related: program?.name, email: false,
      });

      log('attendance', `${youth?.firstName} ${youth?.lastName} marked present at ${program?.name}.`);
      notify('Attendance recorded successfully.');

      return { ok: true, message: `${youth?.firstName} ${youth?.lastName} marked present at ${scannedAt}.`, youth, scannedAt };
    },

    // ---- assistance -----------------------------------------------------
    saveAssistance(values) {
      const type = types.find((x) => x.id === Number(values.typeId));
      const preset = type?.requirements ?? [];

      const applyPreset = (assistanceId) => {
        patch('requirements', (rows) => {
          const kept = rows.filter((r) => !(r.targetType === 'assistance' && r.targetId === assistanceId && r.fromType));
          const added = preset.map(([name, description, required, accepts]) => ({
            id: newId(), orgId: org.id, targetType: 'assistance', targetId: assistanceId,
            name, description, required, accepts, fromType: true,
          }));
          return [...added, ...kept];
        });
      };

      if (values.id) {
        const changed = db.assistance.find((a) => a.id === values.id)?.typeId !== values.typeId;
        patch('assistance', (rows) => rows.map((a) => (a.id === values.id ? { ...a, ...values } : a)));
        if (changed) applyPreset(values.id);
        notify(changed && preset.length
          ? `Assistance program updated. ${preset.length} requirement(s) from the assistance type were applied.`
          : 'Assistance program updated.');
        return values.id;
      }

      if (!canAdd('assistance')) { notify(limitMessage('assistance'), 'warning'); return null; }

      const id = newId();
      prepend('assistance', { ...values, id, orgId: org.id, createdAt: stamp() });
      applyPreset(id);
      notify(preset.length
        ? `Assistance program created with ${preset.length} requirement(s) from the assistance type.`
        : 'Assistance program created. This type has no preset requirements — add them below.');
      return id;
    },
    archiveAssistance(id) {
      patch('assistance', (rows) => rows.map((a) => (a.id === id ? { ...a, status: 'archived' } : a)));
      notify('Assistance program archived.');
    },
    deleteAssistance(id) {
      patch('assistance', (rows) => rows.filter((a) => a.id !== id));
      patch('beneficiaries', (rows) => rows.filter((b) => b.programId !== id));
      notify('Assistance program deleted.');
    },
    addType(values) {
      const requirements = (values.requirements || [])
        .filter((r) => r.name.trim())
        .map((r) => [r.name.trim(), r.description.trim(), r.required, r.accepts]);

      setTypes((rows) => [{
        id: newId(), name: values.name, category: values.category,
        system: false, orgId: org.id, requirements,
      }, ...rows]);

      notify(requirements.length
        ? `Assistance type "${values.name}" added with ${requirements.length} requirement(s). Programs using it get them automatically.`
        : `Assistance type "${values.name}" added. Add its requirements on each program that uses it.`);
    },
    saveBeneficiary(programId, youthId, status, remarks) {
      const program = db.assistance.find((a) => a.id === programId);
      const approved = db.beneficiaries.filter((b) => b.programId === programId && ['approved', 'released'].includes(b.status)).length;
      if (program.slots && status !== 'applied' && approved >= program.slots) {
        notify('All available slots for this program are already filled.', 'warning');
        return;
      }
      patch('beneficiaries', (rows) => {
        const existing = rows.find((b) => b.programId === programId && b.youthId === youthId);
        if (existing) return rows.map((b) => (b.id === existing.id ? { ...b, status, remarks } : b));
        return [{
          id: newId(), orgId: org.id, programId, youthId, status, remarks,
          awardedOn: ['approved', 'released'].includes(status) ? iso(today) : null,
        }, ...rows];
      });
      notify('Beneficiary record saved.');
    },
    removeBeneficiary(id) {
      patch('beneficiaries', (rows) => rows.filter((b) => b.id !== id));
      notify('Beneficiary removed from this program.');
    },

    // ---- notifications and outboxes --------------------------------------
    readNotification(id) {
      patch('notifications', (rows) => rows.map((n) => (n.id === id ? { ...n, read: true } : n)));
    },
    readAllNotifications(inbox) {
      patch('notifications', (rows) => rows.map((n) => (inbox(n) ? { ...n, read: true } : n)));
      notify('All notifications marked as read.');
    },
    deleteNotification(id) {
      patch('notifications', (rows) => rows.filter((n) => n.id !== id));
      notify('Notification deleted.');
    },
    clearOutbox(key) {
      patch(key, (rows) => rows.filter((row) => row.orgId !== org.id));
      notify(key === 'sms' ? 'SMS outbox cleared.' : 'Email outbox cleared.');
    },

    // ---- subscription ----------------------------------------------------
    payAndActivate(planCode, cycle, outcome = 'success') {
      const chosen = planByCode(planCode);
      const days = cycle === 'yearly' ? 365 : 30;
      const amount = cycle === 'yearly' ? chosen.priceYearly : chosen.priceMonthly;
      const reference = `PAY-${iso(today).replace(/-/g, '')}-${Math.random().toString(36).slice(2, 8).toUpperCase()}`;

      if (outcome === 'fail') {
        const payment = { id: newId(), orgId: org.id, planCode, cycle, amount, method: 'ewallet', reference, status: 'failed', paidAt: null };
        prepend('payments', payment);
        setOrgs((rows) => rows.map((o) => (o.id === org.id ? { ...o, subStatus: 'payment_failed' } : o)));
        notify('Payment failed. Your subscription was not activated — you can retry.', 'error');
        return payment;
      }

      const payment = { id: newId(), orgId: org.id, planCode, cycle, amount, method: 'ewallet', reference, status: 'paid', paidAt: iso(today) };
      prepend('payments', payment);
      setOrgs((rows) => rows.map((o) => (o.id === org.id
        ? { ...o, plan: planCode, subStatus: 'active', cycle, expiresIn: days, startedOn: iso(today) } : o)));
      notify(`Payment confirmed. ${chosen.name} activated automatically until ${addDays(days).toDateString()}.`);
      return payment;
    },
    downgrade(planCode) {
      const target = planByCode(planCode);
      setOrgs((rows) => rows.map((o) => (o.id === org.id
        ? { ...o, plan: planCode, subStatus: planCode === 'free' ? 'free' : 'active',
            cycle: planCode === 'free' ? 'none' : o.cycle, expiresIn: planCode === 'free' ? null : 30 } : o)));
      const over = target.limits.youth !== null && usage.youth > target.limits.youth;
      notify(over
        ? `Your plan is now ${target.name}. All ${usage.youth.toLocaleString()} youth records were kept — you cannot add new ones until your count is within the limit.`
        : `Your plan is now ${target.name}. No records were deleted.`, over ? 'warning' : 'success');
    },

    // ---- organization and admin ------------------------------------------
    updateOrg(values) {
      setOrgs((rows) => rows.map((o) => (o.id === (values.id ?? org.id)
        ? { ...o, ...values, name: `SK ${values.barangay}, ${values.municipality}` } : o)));
      notify('Organization details saved.');
    },
    approveOrg(id) {
      setOrgs((rows) => rows.map((o) => (o.id === id
        ? { ...o, status: 'active', plan: 'premium', subStatus: 'trial', cycle: 'trial', expiresIn: 7 } : o)));
      notify('Organization approved. Their 7-day Premium trial has started.');
    },
    setOrgStatus(id, status, note) {
      setOrgs((rows) => rows.map((o) => (o.id === id ? { ...o, status, statusNote: note } : o)));
      notify(`Status updated to ${status}.`);
    },
    setOrgPlan(id, planCode, cycle) {
      setOrgs((rows) => rows.map((o) => (o.id === id
        ? { ...o, plan: planCode, cycle, subStatus: planCode === 'free' ? 'free' : 'active',
            expiresIn: planCode === 'free' ? null : (cycle === 'yearly' ? 365 : 30) } : o)));
      notify('Plan updated.');
    },
    extendOrg(id, days) {
      setOrgs((rows) => rows.map((o) => (o.id === id
        ? { ...o, subStatus: 'active', expiresIn: Math.max(o.expiresIn ?? 0, 0) + Number(days) } : o)));
      notify(`Extended by ${days} days.`);
    },
    cancelOrgSub(id) {
      setOrgs((rows) => rows.map((o) => (o.id === id ? { ...o, subStatus: 'cancelled' } : o)));
      notify('Subscription cancelled. Data was kept.');
    },
    runCycle() {
      let warned = 0;
      let expired = 0;
      setOrgs((rows) => rows.map((o) => {
        if (o.expiresIn === null || !['active', 'trial'].includes(o.subStatus)) return o;
        if (o.expiresIn <= 0) {
          expired += 1;
          return o.subStatus === 'trial'
            ? { ...o, plan: 'free', subStatus: 'free', cycle: 'none', expiresIn: null }
            : { ...o, subStatus: 'expired' };
        }
        if (o.expiresIn <= 3) warned += 1;
        return { ...o, expiresIn: o.expiresIn - 1 };
      }));
      notify(`Cycle finished — ${warned} warning(s) sent, ${expired} subscription(s) expired.`);
    },

    clearFlash: () => setFlash(null),
    notify,
  };

  const value = {
    user, org, orgs, users, types, db, signup, youthRecord,
    plan: effectivePlan, planName: plan.name, plans: PLANS,
    usage, limits, remaining, canAdd, allows, limitMessage,
    qrUses, qrLimit, qrRemaining, canScan, qrLimitMessage,
    scoped, requirementsFor, submissionsFor, targetOf, youthById, matchProfile,
    accountFor, accountStatusOf,
    flash, MAX_UPLOAD_BYTES, ...actions,
  };

  return <StoreContext.Provider value={value}>{children}</StoreContext.Provider>;
}
