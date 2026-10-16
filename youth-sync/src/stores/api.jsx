import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { StoreContext, useStore } from '../store-context.js';
import { mapAdminUser, useAdminApiStore } from './admin.jsx';
import { MockStoreProvider } from './mock.jsx';
import { api, ApiError, fetchAllPages, friendlyApiMessage } from '../lib/api.js';
import { uniqueById } from '../lib/notificationPoll.js';
import {
  emptyDb,
  itemsOf,
  mapApplication,
  mapAssistance,
  mapAttendanceToRegistration,
  mapAuthUser,
  mapNotification,
  mapYouthSession,
  mapYouthProgram,
  mapYouthAssistance,
  mapYouthApplication,
  mapYouthNotification,
  mapOrganization,
  mapPlanCatalog,
  mapProgram,
  mapSkUser,
  mapYouth,
} from '../lib/mappers.js';

// The sidebar badges only need the totals (unreadCount, application status
// counts), so one row is enough; the list screens page on the server.
const BADGE_PAGE = { page: 1, perPage: 1 };

// Whole SK collections a screen can ask for (useStoreData). Registrations are
// built from each activity's attendance list, so they need the activities too.
const DATA_GROUPS = ['youth', 'programs', 'registrations', 'assistance', 'applications', 'users', 'types'];
const GROUP_NEEDS = { registrations: ['programs'] };
const expandGroups = (keys) => {
  const groups = new Set();
  (keys || []).forEach((key) => {
    if (!DATA_GROUPS.includes(key)) return;
    groups.add(key);
    (GROUP_NEEDS[key] || []).forEach((need) => groups.add(need));
  });
  return [...groups];
};

function youthWriteBody(values) {
  const body = { ...values };
  [
    'orgId', 'organization_id', 'organizationId', 'id', 'photo', 'document',
    'qrToken', 'accountStatus', 'level', 'score', 'reasons', 'createdAt', 'addedAt',
    'archived', 'selfRegistered', 'code', 'role', 'status', 'youthId', 'youth_id',
  ].forEach((key) => { delete body[key]; });
  return body;
}

async function syncYouthFiles(id, values) {
  if (!id) return;
  if (values.photo?.file) {
    await api.upload(`/sk/youth/${id}/photo`, values.photo.file);
  } else if (values.id && values.photo === null) {
    try {
      await api.delete(`/sk/youth/${id}/photo`);
    } catch (err) {
      if (err?.status !== 404) throw err;
    }
  }
  if (values.document?.file) {
    await api.upload(`/sk/youth/${id}/document`, values.document.file);
  } else if (values.id && values.document === null) {
    try {
      await api.delete(`/sk/youth/${id}/document`);
    } catch (err) {
      if (err?.status !== 404) throw err;
    }
  }
}

// Survives React StrictMode remount on the same page load. Not written to storage.
let pageAdminMe = null;
let sessionBootGen = 0;
let authMeInflight = null;

function authRole(me) {
  return String(me?.role || me?.user?.role || me?.data?.role || '').replace(/-/g, '_').toUpperCase();
}

function isSystemAdminMe(me) {
  return authRole(me) === 'SYSTEM_ADMIN';
}

function isYouthMe(me) {
  return authRole(me) === 'YOUTH';
}

function fetchAuthMe() {
  // Keep the same promise for StrictMode remount. Clearing it in finally()
  // started a second /auth/me that could 401 and look like logout.
  // A failed check must not stay cached, or the next attempt never reaches the API.
  if (!authMeInflight) {
    const request = api.get('/auth/me');
    authMeInflight = request;
    request.catch(() => {
      if (authMeInflight === request) authMeInflight = null;
    });
  }
  return authMeInflight;
}

function useSkApiStore(mock, setAdminSnapshot) {
  const [sessionReady, setSessionReady] = useState(() => pageAdminMe != null);
  const [skUser, setSkUser] = useState(null);
  const [youthSession, setYouthSession] = useState(null);
  const [youthPortal, setYouthPortal] = useState({
    programs: [], assistance: [], applications: [], notifications: [],
    requirements: [], submissions: [], attendance: [], loading: false, error: null,
  });
  const [attendanceSessions, setAttendanceSessions] = useState({});
  const [org, setOrg] = useState(null);
  const [db, setDb] = useState(emptyDb);
  // Server-side unread count for the SK inbox (GET /sk/notifications unreadCount).
  const [notificationUnread, setNotificationUnread] = useState(null);
  const [users, setUsers] = useState([]);
  const [types, setTypes] = useState([]);
  const [subscription, setSubscription] = useState(null);
  const [dashboard, setDashboard] = useState(null);
  const [reports, setReports] = useState({});
  const [skLoading, setSkLoading] = useState(false);
  const [skError, setSkError] = useState(null);
  // Bumped after every (re)load of the SK collections, so server-paged list
  // screens know to fetch their current page again after a save or delete.
  const [dataVersion, setDataVersion] = useState(0);
  // Collections some screen has asked for (reloaded on refresh) and the ones loaded so far.
  const wantedGroups = useRef(new Set());
  const [readyGroups, setReadyGroups] = useState(() => new Set());
  // Pending + needs-resubmission applications, from GET /sk/applications counts.
  const [applicationsPending, setApplicationsPending] = useState(null);
  const [flash, setFlash] = useState(null);
  const [mutating, setMutating] = useState(false);
  const sessionTokens = useRef({});
  const bootGen = useRef(0);
  const lastSnapshot = useRef(null);

  const notify = useCallback((message, tone = 'success') => {
    setFlash({ message, tone, at: Date.now() });
  }, []);
  const clearFlash = useCallback(() => setFlash(null), []);

  // Set below; lets fail() re-read plan usage when the server reports a plan limit.
  const refreshSubscriptionRef = useRef(null);

  const fail = useCallback((err, fallback) => {
    if (err?.name === 'AbortError') return null;
    const message = friendlyApiMessage(err, fallback);
    notify(message, err?.status === 403 ? 'warning' : 'error');
    if (err?.code === 'PLAN_LIMIT_REACHED') refreshSubscriptionRef.current?.();
    return null;
  }, [notify]);

  const scoped = useCallback((key) => (db[key] ?? []).filter((row) => row.orgId === org?.id), [db, org]);

  const applySnapshot = useCallback((snapshot, sub) => {
    const mappedOrg = mapOrganization(snapshot.organization, sub);
    setOrg(mappedOrg);
    setSkUser(mapAuthUser(snapshot));
  }, []);

  // Whole collections for pickers, detail pages and cross-links. None of them
  // is fetched at sign-in or on every screen: a screen asks for what it reads
  // (useStoreData / NeedsStoreData) and only those collections are loaded,
  // then reloaded with the rest of the SK data after a save. List screens never
  // read these copies; they ask the API for one filtered page at a time.
  const loadGroups = useCallback(async (organizationId, groups, gen) => {
    const still = () => gen === bootGen.current;
    const want = new Set(expandGroups(groups));
    if (want.size === 0) return;

    const jobs = {};
    if (want.has('youth')) {
      jobs.youthLive = fetchAllPages('/sk/youth', { archived: 0 });
      jobs.youthArchived = fetchAllPages('/sk/youth', { archived: 1 });
    }
    if (want.has('programs')) jobs.programs = fetchAllPages('/sk/programs');
    if (want.has('assistance')) jobs.assistance = fetchAllPages('/sk/assistance');
    if (want.has('applications')) jobs.applications = fetchAllPages('/sk/applications');
    if (want.has('users')) jobs.users = fetchAllPages('/sk/users');
    if (want.has('types')) jobs.types = api.get('/sk/assistance-types');
    const names = Object.keys(jobs);
    const settled = await Promise.allSettled(names.map((name) => jobs[name]));
    if (!still()) return;
    const result = Object.fromEntries(names.map((name, index) => [name, settled[index]]));
    const ok = (name) => result[name]?.status === 'fulfilled';
    const value = (name) => result[name].value;
    const failures = settled.filter((entry) => entry.status === 'rejected');

    const patch = {};
    const loaded = [];
    let programRequirements = null;
    let assistanceRequirements = null;

    if (want.has('youth') && ok('youthLive') && ok('youthArchived')) {
      patch.youth = [
        ...itemsOf(value('youthLive')).map((row) => mapYouth(row, organizationId)),
        ...itemsOf(value('youthArchived')).map((row) => mapYouth(row, organizationId)),
      ];
      loaded.push('youth');
    }

    const programRows = ok('programs') ? itemsOf(value('programs')).map((row) => mapProgram(row, organizationId)) : null;
    if (want.has('programs') && programRows) {
      patch.programs = programRows;
      programRequirements = [];
      programRows.forEach((row) => {
        (row.requirementItems || []).forEach((req) => {
          programRequirements.push({
            ...req,
            orgId: organizationId,
            targetType: req.targetType || 'program',
            targetId: req.targetId || row.id,
          });
        });
      });
      loaded.push('programs');
    }

    if (want.has('assistance') && ok('assistance')) {
      const assistanceSource = itemsOf(value('assistance'));
      const assistanceNeedingDetail = assistanceSource.filter((row) => !Array.isArray(row.requirementItems));
      const extraAssistance = assistanceNeedingDetail.length
        ? await Promise.allSettled(assistanceNeedingDetail.map((row) => api.get(`/sk/assistance/${row.id}`)))
        : [];
      if (!still()) return;
      const assistanceById = new Map(assistanceSource.map((row) => [row.id, row]));
      extraAssistance.forEach((entry, index) => {
        if (entry.status !== 'fulfilled' || !entry.value) return;
        assistanceById.set(assistanceNeedingDetail[index].id, entry.value);
      });
      const beneficiaries = [];
      assistanceRequirements = [];
      assistanceById.forEach((detail) => {
        const mapped = mapAssistance(detail, organizationId);
        (detail.requirementItems || []).forEach((req) => {
          assistanceRequirements.push({ ...req, orgId: organizationId, targetType: 'assistance', targetId: mapped.id });
        });
        (detail.beneficiaries || []).forEach((ben) => {
          beneficiaries.push({ ...ben, orgId: organizationId, programId: ben.programId || mapped.id });
        });
      });
      patch.assistance = [...assistanceById.values()].map((row) => mapAssistance(row, organizationId));
      patch.beneficiaries = beneficiaries;
      loaded.push('assistance');
    }

    if (want.has('applications') && ok('applications')) {
      const applicationSource = itemsOf(value('applications'));
      const appsNeedingDetail = applicationSource.filter((row) => !Array.isArray(row.submissions));
      const extraApps = appsNeedingDetail.length
        ? await Promise.allSettled(appsNeedingDetail.map((row) => api.get(`/sk/applications/${row.id}`)))
        : [];
      if (!still()) return;
      const applicationById = new Map(applicationSource.map((row) => [row.id, row]));
      extraApps.forEach((entry, index) => {
        if (entry.status !== 'fulfilled' || !entry.value) return;
        applicationById.set(appsNeedingDetail[index].id, entry.value);
      });
      const submissions = [];
      patch.applications = [...applicationById.values()].map((detail) => {
        (detail.submissions || []).forEach((subRow) => {
          submissions.push({ ...subRow, orgId: organizationId });
        });
        return mapApplication(detail, organizationId);
      });
      patch.submissions = submissions;
      loaded.push('applications');
    }

    // Registrations come from each activity's attendance list, so this one
    // costs a request per activity; only the attendance screen and the
    // program / youth detail pages ask for it.
    if (want.has('registrations') && programRows) {
      const attendanceLists = programRows.length
        ? await Promise.allSettled(programRows.map((program) => api.get(`/sk/programs/${program.id}/attendance`)))
        : [];
      if (!still()) return;
      const failedAttendance = attendanceLists.find((entry) => entry.status === 'rejected');
      if (failedAttendance) failures.push(failedAttendance);
      patch.registrations = attendanceLists.flatMap((entry) => {
        if (entry.status !== 'fulfilled') return [];
        return itemsOf(entry.value).map((row) => mapAttendanceToRegistration(row, organizationId));
      });
      if (!failedAttendance) loaded.push('registrations');
    }

    if (want.has('users') && ok('users')) {
      setUsers(itemsOf(value('users')).map((row) => mapSkUser(row, organizationId)));
      loaded.push('users');
    }
    if (want.has('types') && ok('types')) {
      const typeList = value('types');
      setTypes(Array.isArray(typeList) ? typeList : itemsOf(typeList));
      loaded.push('types');
    }

    setDb((current) => {
      const next = { ...current, ...patch };
      if (programRequirements || assistanceRequirements) {
        next.requirements = [
          ...(assistanceRequirements ?? current.requirements.filter((r) => r.targetType === 'assistance')),
          ...(programRequirements ?? current.requirements.filter((r) => r.targetType !== 'assistance')),
        ];
      }
      return next;
    });
    if (loaded.length) {
      setReadyGroups((current) => new Set([...current, ...loaded]));
    }
    if (failures.length) {
      setSkError(friendlyApiMessage(failures[0].reason, 'Some SK records could not be loaded.'));
    }
  }, []);

  // What every SK screen needs: the plan / usage and the two sidebar badges.
  const loadCollections = useCallback(async (organizationId, gen) => {
    const still = () => gen === bootGen.current;
    const take = (result, fallback) => (result.status === 'fulfilled' ? result.value : fallback);

    const [batch] = await Promise.all([
      Promise.allSettled([
        api.get('/sk/subscription'),
        api.get('/sk/notifications', { query: BADGE_PAGE }),
        api.get('/sk/applications', { query: BADGE_PAGE }),
      ]),
      loadGroups(organizationId, [...wantedGroups.current], gen),
    ]);
    if (!still()) return null;

    const failed = batch.find((result) => result.status === 'rejected');
    if (batch.every((result) => result.status === 'rejected')) {
      throw failed.reason;
    }

    const sub = take(batch[0], null);
    const notifications = take(batch[1], { items: [] });
    const applicationCounts = take(batch[2], null)?.counts;

    setSubscription(sub);
    setDb((current) => ({
      ...current,
      notifications: itemsOf(notifications).map((row) => mapNotification(row, organizationId)),
    }));
    setNotificationUnread(batch[1].status === 'fulfilled' && Number.isFinite(Number(notifications?.unreadCount))
      ? Number(notifications.unreadCount)
      : null);
    setApplicationsPending(applicationCounts
      ? (Number(applicationCounts.pending) || 0) + (Number(applicationCounts.needs_resubmission) || 0)
      : null);
    if (failed) {
      setSkError(friendlyApiMessage(failed.reason, 'Some SK records could not be loaded.'));
    }
    return sub;
  }, [loadGroups]);

  const ensureData = useCallback((keys) => {
    const missing = expandGroups(keys).filter((group) => !wantedGroups.current.has(group));
    if (missing.length === 0) return;
    missing.forEach((group) => wantedGroups.current.add(group));
    const organizationId = lastSnapshot.current?.organization?.id;
    // Before the session has loaded, the first bootstrap picks these up.
    if (!organizationId) return;
    loadGroups(organizationId, missing, bootGen.current).catch((err) => {
      if (err?.name !== 'AbortError') setSkError(friendlyApiMessage(err, 'Some SK records could not be loaded.'));
    });
  }, [loadGroups]);

  const dataReady = useCallback(
    (keys) => expandGroups(keys).every((group) => readyGroups.has(group)),
    [readyGroups]
  );

  const bootstrap = useCallback(async (snapshot, { quiet = false } = {}) => {
    const gen = ++bootGen.current;
    lastSnapshot.current = snapshot;
    if (!quiet) setSkLoading(true);
    setSkError(null);
    try {
      const organizationId = snapshot.organization.id;
      const sub = await loadCollections(organizationId, gen);
      if (gen !== bootGen.current) return;
      applySnapshot(snapshot, sub);
    } catch (err) {
      if (err?.name === 'AbortError') return;
      setSkError(friendlyApiMessage(err, 'Unable to load SK data.'));
      applySnapshot(snapshot, null);
      throw err;
    } finally {
      if (gen === bootGen.current) {
        setSkLoading(false);
        setSessionReady(true);
        setDataVersion((n) => n + 1);
      }
    }
  }, [applySnapshot, loadCollections]);

  const refresh = useCallback(async () => {
    const snapshot = lastSnapshot.current;
    if (!snapshot) return;
    try {
      await bootstrap(snapshot, { quiet: true });
    } catch (err) {
      fail(err, 'Unable to refresh data.');
    }
  }, [bootstrap, fail]);

  /**
   * Re-read only GET /sk/subscription (plan, status, usage). Saves and deletes
   * already reload it through refresh(); this covers QR scans and requests the
   * server refused with PLAN_LIMIT_REACHED, so the usage bars never lag.
   */
  const refreshSubscription = useCallback(async () => {
    const snapshot = lastSnapshot.current;
    if (!snapshot) return null;
    try {
      const sub = await api.get('/sk/subscription');
      setSubscription(sub);
      applySnapshot(snapshot, sub);
      return sub;
    } catch (err) {
      return null;
    }
  }, [applySnapshot]);
  refreshSubscriptionRef.current = refreshSubscription;

  useEffect(() => {
    const gen = ++sessionBootGen;
    (async () => {
      try {
        const me = await fetchAuthMe();
        const stale = gen !== sessionBootGen;
        if (isYouthMe(me)) {
          pageAdminMe = null;
          if (stale) return;
          mock.logout();
          setAdminSnapshot?.(null);
          setSkUser(null);
          setOrg(null);
          setYouthSession(mapYouthSession(me));
          setSessionReady(true);
          return;
        }
        setYouthSession(null);
        // SK /auth/me always includes an organization. SYSTEM_ADMIN does not.
        if (isSystemAdminMe(me) || (me && !me.organization)) {
          pageAdminMe = me;
          if (stale) return;
          setAdminSnapshot?.(me);
          setSkUser(null);
          setOrg(null);
          setDb(emptyDb());
          setSessionReady(true);
          return;
        }
        pageAdminMe = null;
        if (stale) return;
        setAdminSnapshot?.(null);
        await bootstrap(me);
      } catch (err) {
        const stale = gen !== sessionBootGen;
        if (stale || err?.name === 'AbortError') return;
        if (pageAdminMe && isSystemAdminMe(pageAdminMe)) {
          setAdminSnapshot?.(pageAdminMe);
          setSessionReady(true);
          return;
        }
        if (err instanceof ApiError && err.status === 401) {
          pageAdminMe = null;
          mock.logout();
          setYouthSession(null);
          setSkUser(null);
          setOrg(null);
          setDb(emptyDb());
          setAdminSnapshot?.(null);
          setSessionReady(true);
          return;
        }
        if (pageAdminMe) {
          setAdminSnapshot?.(pageAdminMe);
          setSessionReady(true);
          return;
        }
        // 500, timeout, or a down API must still leave the checking screen.
        pageAdminMe = null;
        setAdminSnapshot?.(null);
        setSessionReady(true);
        notify(friendlyApiMessage(err, 'Unable to check your session. You can still try to sign in.'), 'error');
      }
    })();
    return () => {
      if (sessionBootGen === gen) sessionBootGen += 1;
    };
  }, [bootstrap, mock, notify, setAdminSnapshot]);

  const loadYouthPortal = useCallback(async (session) => {
    const active = session || youthSession;
    if (!active?.user) return;
    const orgId = active.org?.id ?? active.user.orgId ?? null;
    setYouthPortal((current) => ({
      ...current,
      loading: current.programs.length === 0 && current.assistance.length === 0 && current.applications.length === 0,
      error: null,
    }));
    try {
      const [programs, assistance, applications, notifications, attendance] = await Promise.all([
        api.get('/youth/programs', { query: { perPage: 100 } }),
        api.get('/youth/assistance'),
        api.get('/youth/applications', { query: { perPage: 100 } }),
        api.get('/youth/notifications', { query: { perPage: 100 } }),
        api.get('/youth/attendance'),
      ]);
      const assistanceRows = itemsOf(assistance).map((row) => mapYouthAssistance(row, orgId));
      const programRows = itemsOf(programs).map((row) => mapYouthProgram(row, orgId));
      const applicationRows = itemsOf(applications);
      setYouthPortal({
        programs: programRows,
        assistance: assistanceRows,
        applications: applicationRows.map((row) => mapYouthApplication(row, orgId)),
        notifications: itemsOf(notifications).map((row) => mapYouthNotification(row, orgId)),
        requirements: [
          ...assistanceRows.flatMap((row) => (row.requirementItems || []).map((item) => ({
            ...item,
            targetType: 'assistance',
            targetId: row.id,
          }))),
          ...programRows.flatMap((row) => (row.requirementItems || []).map((item) => ({
            ...item,
            targetType: item.targetType || 'program',
            targetId: item.targetId || row.id,
          }))),
        ],
        submissions: applicationRows.flatMap((row) => (row.submissions || []).map((item) => ({
          id: item.id,
          applicationId: item.applicationId || row.id,
          requirementId: item.requirementId,
          fileName: item.fileName || '',
          fileType: item.fileType || '',
          size: item.fileSize || 0,
          status: item.status,
          remarks: item.remarks || '',
          dataUrl: '',
        }))),
        invitations: Array.isArray(assistance.invitations) ? assistance.invitations : [],
        programInvitations: Array.isArray(programs.invitations) ? programs.invitations : [],
        attendance: itemsOf(attendance).map((row) => ({
          id: row.id,
          programId: row.programId,
          youthId: row.youthId,
          attendance: ['present', 'absent', 'excused'].includes(String(row.status || '').toLowerCase())
            ? String(row.status).toLowerCase()
            : (String(row.source || '').toLowerCase() === 'self' ? 'present' : ''),
          status: String(row.status || '').toLowerCase(),
          scannedAt: row.confirmedAt || row.scannedAt || '',
          source: String(row.source || '').toLowerCase(),
          program: row.program ? {
            id: row.program.id,
            name: row.program.name,
            kind: row.program.kind || 'program',
            scheduledOn: row.program.scheduledOn,
            location: row.program.location || '',
            status: row.program.status,
          } : null,
        })),
        loading: false,
        error: null,
      });
    } catch (err) {
      setYouthPortal((current) => {
        const hasData = current.programs.length > 0 || current.assistance.length > 0 || current.applications.length > 0;
        if (hasData) {
          return { ...current, loading: false, error: friendlyApiMessage(err, 'Unable to refresh your youth portal.') };
        }
        return {
          programs: [], assistance: [], applications: [], notifications: [],
          requirements: [], submissions: [], attendance: [], invitations: [], loading: false,
          error: friendlyApiMessage(err, 'Unable to load your youth portal.'),
        };
      });
    }
  }, [youthSession]);

  useEffect(() => {
    if (!youthSession?.user) {
      setYouthPortal({
        programs: [], assistance: [], applications: [], notifications: [],
        requirements: [], submissions: [], attendance: [], loading: false, error: null,
      });
      return;
    }
    loadYouthPortal(youthSession);
  }, [youthSession, loadYouthPortal]);

  const enterSk = useCallback(async (snapshot) => {
    mock.logout();
    await bootstrap(snapshot);
    return { user: mapAuthUser(snapshot) };
  }, [bootstrap, mock]);

  const [signup, setSignup] = useState(null);

  const adoptYouth = useCallback(async (me) => {
    mock.logout();
    pageAdminMe = null;
    setSkUser(null);
    setOrg(null);
    setDb(emptyDb());
    setAdminSnapshot?.(null);
    const session = mapYouthSession(me);
    setYouthSession(session);
    setSessionReady(true);
    return session.user;
  }, [mock, setAdminSnapshot]);

  const signupOrganizations = useCallback(async () => {
    const rows = await api.get('/auth/youth-organizations');
    return Array.isArray(rows) ? rows : [];
  }, []);

  const startSignup = useCallback(async (values) => {
    try {
      const data = await api.post('/auth/youth-signup', {
        organizationId: Number(values.orgId),
        firstName: values.firstName,
        middleName: values.middleName,
        lastName: values.lastName,
        birthDate: values.birthDate,
        gender: values.gender,
        address: values.address,
        contact: values.mobile,
        email: values.email,
        password: values.password,
        educationStatus: values.educationStatus,
        education: values.education,
        school: values.school,
        course: values.course,
        yearLevel: values.yearLevel,
        strand: values.strand,
        interests: values.interests,
        skills: values.skills,
        preferredActivities: values.preferredActivities,
      });
      setSignup({ id: data.signupId, attempts: Number(data.attempts) || 0 });
      return { code: data.code || '' };
    } catch (err) {
      return { error: friendlyApiMessage(err, 'Unable to send the verification code.') };
    }
  }, []);

  const resendOtp = useCallback(async () => {
    if (!signup?.id) return { error: 'Start the sign-up again.' };
    try {
      const data = await api.post('/auth/youth-signup/resend', { signupId: signup.id });
      setSignup({ id: signup.id, attempts: Number(data.attempts) || 0 });
      return { code: data.code || '' };
    } catch (err) {
      return { error: friendlyApiMessage(err, 'Unable to resend the code.') };
    }
  }, [signup]);

  const cancelSignup = useCallback(async () => {
    const id = signup?.id;
    setSignup(null);
    if (!id) return;
    try {
      await api.post('/auth/youth-signup/cancel', { signupId: id });
    } catch {
      // The code expires on its own if cancel does not reach the server.
    }
  }, [signup]);

  const verifyOtp = useCallback(async (code) => {
    if (!signup?.id) return { error: 'Start the sign-up again.' };
    try {
      await api.post('/auth/youth-signup/verify', { signupId: signup.id, code });
      const me = await api.get('/auth/me');
      authMeInflight = Promise.resolve(me);
      const user = await adoptYouth(me);
      setSignup(null);
      return { user };
    } catch (err) {
      const attempts = Number(err?.details?.error?.attempts);
      if (Number.isFinite(attempts)) {
        setSignup((current) => current ? { ...current, attempts } : current);
      }
      return { error: friendlyApiMessage(err, 'That code could not be verified.') };
    }
  }, [adoptYouth, signup]);

  const login = useCallback(async (email, password, remember = false) => {
    try {
      const data = await api.post('/auth/login', {
        email,
        password,
        remember: Boolean(remember),
        rememberMe: Boolean(remember),
      });
      const me = await api.get('/auth/me');
      authMeInflight = Promise.resolve(me);
      if (isYouthMe(me)) {
        mock.logout();
        pageAdminMe = null;
        setSkUser(null);
        setOrg(null);
        setDb(emptyDb());
        setAdminSnapshot?.(null);
        const session = mapYouthSession(me);
        setYouthSession(session);
        setSessionReady(true);
        return { user: session.user };
      }
      if (isSystemAdminMe(me)) {
        mock.logout();
        pageAdminMe = me;
        setYouthSession(null);
        setSkUser(null);
        setOrg(null);
        setDb(emptyDb());
        setAdminSnapshot?.(me);
        setSessionReady(true);
        return { user: mapAdminUser(me) };
      }
      if (data?.role === 'SYSTEM_ADMIN') {
        return { error: 'Signed in, but this browser did not keep the session cookie. Refresh would sign you out.' };
      }
      pageAdminMe = null;
      setYouthSession(null);
      setAdminSnapshot?.(null);
      return enterSk(me || data);
    } catch (err) {
      return { error: friendlyApiMessage(err, 'Those credentials do not match our records.'), code: err.code };
    }
  }, [enterSk, mock, setAdminSnapshot]);

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout', {});
    } catch {
      // Cookie is cleared by the API; ignore network errors on logout.
    }
    sessionTokens.current = {};
    lastSnapshot.current = null;
    sessionBootGen += 1;
    authMeInflight = null;
    pageAdminMe = null;
    setYouthSession(null);
    setSkUser(null);
    setOrg(null);
    setDb(emptyDb());
    wantedGroups.current = new Set();
    setReadyGroups(new Set());
    setApplicationsPending(null);
    setNotificationUnread(null);
    setUsers([]);
    setSubscription(null);
    setDashboard(null);
    setAdminSnapshot?.(null);
    setSessionReady(true);
    mock.logout();
  }, [mock, setAdminSnapshot]);

  const run = useCallback(async (fn, successMessage) => {
    setMutating(true);
    try {
      const result = await fn();
      if (successMessage) notify(successMessage);
      return result;
    } catch (err) {
      fail(err);
      return null;
    } finally {
      setMutating(false);
    }
  }, [fail, notify]);

  const usage = subscription?.usage || {
    youth: db.youth.filter((y) => !y.archived).length,
    accounts: users.length,
    programs: db.programs.filter((p) => ['draft', 'published', 'ongoing'].includes(p.status)).length,
    assistance: db.assistance.filter((a) => ['draft', 'open', 'full'].includes(a.status)).length,
    qr: org?.qrUses ?? 0,
  };
  // Plan catalog, limits and features come from GET /sk/subscription only (the
  // backend PlanLimits config). Without it nothing is assumed client-side; the
  // server still enforces every limit.
  const plans = useMemo(() => mapPlanCatalog(subscription?.plans), [subscription]);
  const limits = subscription?.limits || {};
  const effectiveCode = subscription?.effectivePlan?.code || org?.plan || 'free';
  const catalogEntry = (code) => plans.find((p) => p.code === code) || {
    code, name: code ? code.charAt(0).toUpperCase() + code.slice(1) : '', tagline: '',
    priceMonthly: null, priceYearly: null, limits: {}, features: {}, bullets: [],
  };
  const plan = {
    ...catalogEntry(effectiveCode),
    name: subscription?.effectivePlan?.name || catalogEntry(effectiveCode).name,
    limits,
    features: {
      csv_import: Boolean(subscription?.features?.csvImport),
      excel_export: Boolean(subscription?.features?.excelExport),
      activity_logs: Boolean(subscription?.features?.activityLogs),
    },
  };
  // The stored (paid-for) plan, which differs from `plan` while expired/cancelled.
  const storedPlan = catalogEntry(subscription?.plan || org?.plan || 'free');
  const qrUses = usage.qr ?? org?.qrUses ?? 0;
  const qrLimit = limits.qr ?? null;
  const qrRemaining = qrLimit === null ? null : Math.max(0, qrLimit - qrUses);
  const canScan = () => qrLimit === null || qrUses < qrLimit;
  const qrLimitMessage = () =>
    `QR scan limit reached — the ${plan.name} plan includes ${qrLimit} scan${qrLimit === 1 ? '' : 's'}. `
    + 'Upgrade your plan for more QR scans.';
  const remaining = (key) => (limits[key] === null || limits[key] === undefined ? null : Math.max(0, limits[key] - (usage[key] || 0)));
  const canAdd = (key, count = 1) => limits[key] === null || limits[key] === undefined || (usage[key] || 0) + count <= limits[key];
  const allows = (feature) => Boolean(plan.features[feature]);
  const limitMessage = (key) => {
    const labels = {
      youth: 'youth records',
      accounts: 'staff accounts',
      programs: 'active programs and events',
      assistance: 'active assistance programs',
    };
    return `You have reached the ${plan.name} plan limit of ${limits[key]?.toLocaleString()} ${labels[key]}. `
      + 'Your existing data is untouched — upgrade your plan to add more.';
  };

  const requirementsFor = (targetType, targetId) =>
    db.requirements.filter((r) => r.targetType === targetType && r.targetId === Number(targetId));
  const submissionsFor = (applicationId) => db.submissions.filter((s) => s.applicationId === applicationId);
  const targetOf = (application) => (
    application?.targetType === 'program' || application?.programId
      ? db.programs.find((p) => p.id === (application.targetId || application.programId))
      : db.assistance.find((p) => p.id === application.targetId || p.id === application.assistanceId)
  );
  const youthById = (id) => db.youth.find((y) => y.id === id);
  const accountStatusOf = (youth) => youth?.accountStatus || 'not_registered';
  const matchProfile = mock.matchProfile;

  const saveYouth = async (values) => run(async () => {
    const body = youthWriteBody(values);
    let id = values.id;
    if (values.id) {
      const saved = await api.patch(`/sk/youth/${values.id}`, body);
      id = saved.id || values.id;
      await syncYouthFiles(id, values);
      notify('Youth record updated.');
      await refresh();
      return id;
    }
    const created = await api.post('/sk/youth', body);
    const youth = created.youth || created;
    id = youth.id;
    await syncYouthFiles(id, values);
    notify('Youth record added. It is now at the top of your list.');
    await refresh();
    return id;
  });

  const saveYouthWithAccount = async (values, account) => {
    try {
      setMutating(true);
      const created = await api.post('/sk/youth', {
        ...youthWriteBody(values),
        createAccount: true,
        accountEmail: account?.email,
      });
      const youth = created.youth || created;
      const password = created.temporaryPassword || created.temporary_password || '';
      try {
        await syncYouthFiles(youth.id, values);
      } catch (fileErr) {
        notify(friendlyApiMessage(fileErr, 'The account was created, but the file could not be stored.'), 'warning');
      }
      await refresh();
      if (created.warning) notify(created.warning, 'warning');
      else if (password) notify('Youth record and account created successfully.');
      else notify('Youth record added.');
      return { id: youth.id, password, code: youth.code, email: created.user?.email || account?.email || '' };
    } catch (err) {
      fail(err);
      return null;
    } finally {
      setMutating(false);
    }
  };

  const archiveYouth = (id) => run(async () => { await api.post(`/sk/youth/${id}/archive`, {}); await refresh(); }, 'Youth record archived. It can be restored anytime.');
  const restoreYouth = (id) => run(async () => { await api.post(`/sk/youth/${id}/restore`, {}); await refresh(); }, 'Youth record restored.');
  const deleteYouth = (id) => run(async () => { await api.delete(`/sk/youth/${id}`); await refresh(); }, 'The record was deleted permanently.');
  const loadYouthProfile = useCallback(async () => {
    const data = await api.get('/youth/profile');
    setYouthSession((current) => {
      if (!current || !data?.youth) return current;
      const mapped = mapYouthSession(data);
      return {
        ...current,
        user: {
          ...current.user,
          firstName: mapped.user.firstName,
          lastName: mapped.user.lastName,
          name: mapped.user.name,
        },
        org: mapped.org || current.org,
        youthRecord: mapped.youthRecord,
      };
    });
    return data;
  }, []);

  const updateYouthProfile = useCallback(async (values) => {
    setMutating(true);
    try {
      const saved = await api.patch('/youth/profile', youthWriteBody(values));
      let youth = { ...(saved.youth || {}) };
      if (values.photo?.file) {
        youth.photo = await api.upload('/youth/profile/photo', values.photo.file);
      } else if (values.photo === null) {
        try {
          await api.delete('/youth/profile/photo');
        } catch (err) {
          if (err?.status !== 404) throw err;
        }
        youth.photo = null;
      }
      const snapshot = { ...saved, youth };
      setYouthSession((current) => {
        if (!current) return current;
        const mapped = mapYouthSession(snapshot);
        return {
          ...current,
          user: {
            ...current.user,
            firstName: mapped.user.firstName,
            lastName: mapped.user.lastName,
            name: mapped.user.name,
          },
          org: mapped.org || current.org,
          youthRecord: mapped.youthRecord,
        };
      });
      notify('Profile saved.');
      return { youth: snapshot.youth };
    } catch (err) {
      fail(err);
      return { youth: null, fields: err?.fields || null };
    } finally {
      setMutating(false);
    }
  }, [fail, notify]);

  const importYouth = (rows) => run(async () => {
    const result = await api.post('/sk/youth/import', { rows });
    await refresh();
    const count = result.imported ?? result.created ?? rows.length;
    const unsent = Array.isArray(result.unsent) ? result.unsent.length : 0;
    const notified = Number(result.notified) || 0;
    notify(unsent
      ? `${count} youth records imported. ${unsent} Gmail notification${unsent === 1 ? '' : 's'} could not be sent.`
      : notified
        ? `${count} youth records imported. ${notified} Gmail account${notified === 1 ? '' : 's'} received a temporary password.`
        : `${count} youth records imported. No logins were created.`);
    return result;
  });

  const saveProgram = async (values) => {
    const body = { ...values };
    delete body.orgId;
    delete body.id;
    delete body.qrCode;
    delete body.createdAt;
    setMutating(true);
    try {
      if (values.id) {
        const saved = await api.patch(`/sk/programs/${values.id}`, body);
        notify('Activity updated.');
        await refresh();
        return saved.id || values.id;
      }
      const saved = await api.post('/sk/programs', body);
      notify('Activity created.');
      await refresh();
      return saved.id;
    } finally {
      setMutating(false);
    }
  };

  const deleteProgram = (id) => run(async () => { await api.delete(`/sk/programs/${id}`); await refresh(); }, 'Activity deleted.');
  const setProgramStatus = (id, status) => run(async () => {
    if (status === 'archived') await api.post(`/sk/programs/${id}/archive`, {});
    else await api.post(`/sk/programs/${id}/status`, { status });
    await refresh();
  }, status === 'published'
    ? 'Published successfully. Youth in your barangay were notified.'
    : status === 'archived' ? 'Archived successfully.' : 'Activity status updated.');

  const saveAssistance = async (values) => {
    const body = { ...values };
    delete body.orgId;
    delete body.id;
    delete body.createdAt;
    delete body.beneficiaryCount;
    delete body.slotsFilled;
    delete body.requirementItems;
    delete body.beneficiaries;
    setMutating(true);
    try {
      if (values.id) {
        const saved = await api.patch(`/sk/assistance/${values.id}`, body);
        notify('Assistance program updated.');
        await refresh();
        return saved.id || values.id;
      }
      const saved = await api.post('/sk/assistance', body);
      notify('Assistance program created.');
      await refresh();
      return saved.id;
    } finally {
      setMutating(false);
    }
  };

  const deleteAssistance = (id) => run(async () => { await api.delete(`/sk/assistance/${id}`); await refresh(); }, 'Assistance program deleted.');
  const archiveAssistance = (id) => run(async () => { await api.post(`/sk/assistance/${id}/archive`, {}); await refresh(); }, 'Assistance program archived.');
  const closeAssistance = (id) => run(async () => { await api.patch(`/sk/assistance/${id}`, { status: 'closed' }); await refresh(); }, 'Assistance program marked as done.');
  const confirmSelection = (targetType, targetId, youthIds) => run(async () => {
    const path = targetType === 'assistance'
      ? `/sk/assistance/${targetId}/invitations`
      : `/sk/programs/${targetId}/invitations`;
    const result = await api.post(path, { youthIds });
    await refresh();
    const skipped = Array.isArray(result?.skipped) ? result.skipped : [];
    const unsent = Array.isArray(result?.unsent) ? result.unsent : [];
    const invited = Number(result?.invited) || 0;
    if (invited < 1) {
      notify(skipped[0]?.reason || 'No invitation was sent.', 'warning');
      return 0;
    }
    const notes = [
      ...unsent.map((row) => `${row.name}: ${row.reason}`),
      ...skipped.map((row) => `${row.name || 'Youth'}: ${row.reason}`),
    ];
    notify(notes.length
      ? `Email invitation sent to ${invited}. ${notes.join(' ')}`
      : `Email invitation sent to ${invited}. They confirm in their YouthSync account.`);
    return invited;
  });
  const saveBeneficiary = (programId, youthId, status, remarks) => run(async () => {
    await api.post(`/sk/assistance/${programId}/beneficiaries`, { youthId, status, remarks });
    await refresh();
  }, 'Beneficiary saved.');
  const removeBeneficiary = (id) => run(async () => { await api.delete(`/sk/beneficiaries/${id}`); await refresh(); }, 'Beneficiary removed.');
  const addType = async (name) => run(async () => {
    await api.post('/sk/assistance-types', typeof name === 'string' ? { name } : name);
    await refresh();
  }, 'Assistance type added.');

  const approveApplication = (id) => run(async () => {
    await api.post(`/sk/applications/${id}/approve`, {});
    notify('Approved successfully.');
    await refresh();
  });
  const rejectApplication = async (id, reason) => {
    const result = await run(async () => {
      await api.post(`/sk/applications/${id}/reject`, { remarks: reason, reason });
      await refresh();
      return true;
    }, 'Application rejected.');
    return Boolean(result);
  };
  const requestResubmission = (id, requirementIds, note) => run(async () => {
    await api.post(`/sk/applications/${id}/status`, { status: 'needs_resubmission', remarks: note, requirementIds });
    await refresh();
  }, 'Resubmission requested.');
  const reviewRequirement = (submissionId, status, remarks = '') => run(async () => {
    await api.patch(`/sk/application-requirements/${submissionId}`, { status, remarks });
    await refresh();
  }, 'Requirement review saved.');
  const addRequirement = async (targetType, targetId, values) => {
    setMutating(true);
    try {
      const created = await api.post('/sk/requirements', {
        targetType,
        targetId,
        name: values?.name,
        description: values?.description || '',
        required: Boolean(values?.required),
        accepts: values?.accepts,
      });
      await refresh();
      notify(`Requirement "${created?.name || values?.name}" added.`);
      return created;
    } catch (err) {
      notify(friendlyApiMessage(err, 'Unable to save the requirement.'), 'error');
      throw err;
    } finally {
      setMutating(false);
    }
  };
  const updateRequirement = async (id, values) => {
    setMutating(true);
    try {
      const saved = await api.patch(`/sk/requirements/${id}`, {
        name: values?.name,
        description: values?.description,
        required: values?.required,
        accepts: values?.accepts,
      });
      await refresh();
      notify('Requirement updated.');
      return saved;
    } catch (err) {
      notify(friendlyApiMessage(err, 'Unable to update the requirement.'), 'error');
      throw err;
    } finally {
      setMutating(false);
    }
  };
  const removeRequirement = async (id) => {
    setMutating(true);
    try {
      await api.delete(`/sk/requirements/${id}`);
      await refresh();
      notify('Requirement removed.');
      return true;
    } catch (err) {
      notify(friendlyApiMessage(err, 'Unable to remove the requirement.'), 'error');
      throw err;
    } finally {
      setMutating(false);
    }
  };

  // Reloads only the SK inbox (sidebar badge + store copy), not every SK collection.
  const reloadNotifications = useCallback(async (options = {}) => {
    const silent = options === true || options?.silent === true;
    const organizationId = lastSnapshot.current?.organization?.id;
    if (!organizationId) return;
    try {
      const result = await api.get('/sk/notifications', { query: BADGE_PAGE });
      const rows = uniqueById(itemsOf(result).map((row) => mapNotification(row, organizationId)));
      setDb((current) => ({ ...current, notifications: rows }));
      setNotificationUnread(Number.isFinite(Number(result?.unreadCount)) ? Number(result.unreadCount) : null);
    } catch (err) {
      if (!silent) fail(err, 'Unable to reload notifications.');
    }
  }, [fail]);

  const pollYouthNotifications = useCallback(async () => {
    const active = youthSession;
    if (!active?.user) return;
    const orgId = active.org?.id ?? active.user.orgId ?? null;
    try {
      const result = await api.get('/youth/notifications', { query: { perPage: 100 } });
      const rows = uniqueById(itemsOf(result).map((row) => mapYouthNotification(row, orgId)));
      setYouthPortal((current) => ({ ...current, notifications: rows }));
    } catch {
      // Keep the notices already on screen. The next poll tries again.
    }
  }, [youthSession]);

  // Each returns true on success, null on failure (the API error is shown as a flash message).
  const readNotification = (id) => run(async () => {
    await api.post(`/sk/notifications/${id}/read`, {});
    await reloadNotifications();
    return true;
  });
  const readAllNotifications = () => run(async () => {
    await api.post('/sk/notifications/read-all', {});
    await reloadNotifications();
    return true;
  }, 'All notifications marked as read.');
  const deleteNotification = (id) => run(async () => {
    await api.delete(`/sk/notifications/${id}`);
    await reloadNotifications();
    return true;
  }, 'Notification deleted.');

  const saveUser = async (values) => run(async () => {
    const body = { ...values, role: 'SK_OFFICIAL' };
    delete body.orgId;
    delete body.id;
    if (values.id) {
      await api.patch(`/sk/users/${values.id}`, body);
      notify('User account updated.');
      await refresh();
      return values.id;
    }
    const created = await api.post('/sk/users', body);
    if (created.temporaryPassword) {
      notify(`Account created. Temporary password: ${created.temporaryPassword}`, 'info');
    } else {
      notify('User account created.');
    }
    await refresh();
    return created.id || created.user?.id;
  });
  const toggleUserActive = (id) => run(async () => { await api.post(`/sk/users/${id}/toggle-active`, {}); await refresh(); }, 'Account status updated.');
  const deleteUser = (id) => run(async () => { await api.delete(`/sk/users/${id}`); await refresh(); }, 'Account deleted.');

  const startAttendanceSession = async (programId) => {
    setMutating(true);
    try {
      const session = await api.post(`/sk/programs/${programId}/attendance/session`, {});
      if (session.token) sessionTokens.current[programId] = { token: session.token };
      setAttendanceSessions((current) => ({ ...current, [programId]: session }));
      return session;
    } finally {
      setMutating(false);
    }
  };

  const scanYouthQr = async (token, programId) => {
    try {
      const sessionToken = sessionTokens.current[programId]?.token;
      if (!sessionToken) {
        return { ok: false, message: 'Start the attendance session before scanning a Youth QR.' };
      }
      const result = await api.post('/sk/attendance/scan', { token, sessionToken, programId });
      refreshSubscription();
      const attendance = result.attendance || result;
      const youth = result.youth || attendance.youth || db.youth.find((y) => y.id === attendance.youthId);
      const program = result.program || attendance.program || db.programs.find((p) => p.id === Number(programId));
      return {
        pending: result.pending !== false,
        youth,
        program,
        registration: mapAttendanceToRegistration(attendance, org.id),
        message: result.message,
      };
    } catch (err) {
      if (err?.code === 'PLAN_LIMIT_REACHED') refreshSubscription();
      return { ok: false, message: friendlyApiMessage(err, 'Scan failed.') };
    }
  };

  const recordManualAttendance = async (programId, youthId) => {
    const result = await api.post('/sk/attendance/manual', { programId, youthId });
    const attendance = result.attendance || result;
    const youth = result.youth || attendance.youth || db.youth.find((y) => y.id === Number(youthId));
    const program = result.program || attendance.program || db.programs.find((p) => p.id === Number(programId));
    return {
      pending: true,
      youth,
      program,
      registration: mapAttendanceToRegistration(attendance, org.id),
      message: result.message,
    };
  };

  const registerByRegistrationCode = async (code) => {
    const result = await api.post('/youth/programs/register', { code });
    if (youthSession) await loadYouthPortal(youthSession);
    return result;
  };

  const checkInAttendance = async (code) => {
    const result = await api.post('/youth/attendance/check-in', { code });
    if (youthSession) await loadYouthPortal(youthSession);
    return result;
  };

  const confirmAttendance = async (registrationId) => {
    try {
      const saved = await api.post('/sk/attendance/confirm', { attendanceId: registrationId });
      await refresh();
      notify('Attendance confirmed.');
      return { ok: true, attendance: saved };
    } catch (err) {
      return { ok: false, message: friendlyApiMessage(err, 'Unable to confirm attendance.') };
    }
  };

  const markAttendance = (id, status) => run(async () => {
    if (!status) return;
    await api.patch(`/sk/attendance/${id}`, { status });
    await refresh();
  }, 'Attendance updated.');

  const register = () => {
    notify('Program registration is not part of the SK attendance API. Use Attendance to scan a Youth QR or record attendance.', 'warning');
  };

  const saveSkAccount = async (values) => {
    const saved = await api.patch('/sk/account', {
      name: values?.name,
      email: values?.email,
    });
    const me = await api.get('/auth/me');
    lastSnapshot.current = me;
    applySnapshot(me, subscription);
    const currentPassword = String(values?.currentPassword || '');
    const newPassword = String(values?.newPassword || '');
    const confirmPassword = String(values?.confirmPassword || '');
    if (currentPassword || newPassword || confirmPassword) {
      if (!currentPassword || !newPassword || newPassword !== confirmPassword) {
        throw new ApiError(
          newPassword !== confirmPassword ? 'The passwords do not match.' : 'Current password and new password are required.',
          {
            status: 422,
            fields: {
              ...(currentPassword ? {} : { currentPassword: 'Current password is required.' }),
              ...(newPassword ? {} : { newPassword: 'New password is required.' }),
              ...(newPassword === confirmPassword ? {} : { confirmPassword: 'The passwords do not match.' }),
            },
          }
        );
      }
      await api.post('/auth/change-password', {
        current_password: currentPassword,
        new_password: newPassword,
      });
      notify('Account details and password saved.');
      return saved;
    }
    notify('Account details saved.');
    return saved;
  };

  const changePassword = async (newPassword, currentPassword = '') => {
    try {
      await api.post('/auth/change-password', {
        current_password: currentPassword,
        new_password: newPassword,
      });
      const me = await api.get('/auth/me');
      if (isYouthMe(me)) {
        setYouthSession(mapYouthSession(me));
        notify('Password changed successfully.');
        return true;
      }
      lastSnapshot.current = me;
      applySnapshot(me, subscription);
      notify('Password changed successfully.');
      return true;
    } catch (err) {
      fail(err, 'Unable to change password.');
      return false;
    }
  };

  const payAndActivate = async () => {
    notify('There is no online payment. This checkout is a simulation; your plan does not change. Ask your System Administrator to apply a plan.', 'warning');
    return null;
  };
  const downgrade = () => notify('Plan changes are made by the System Administrator. Ask them to change your plan; your records are always kept.', 'info');
  const updateOrg = async (values) => {
    setMutating(true);
    try {
      const saved = await api.patch('/sk/organization', {
        barangay: values?.barangay,
        municipality: values?.municipality,
        province: values?.province,
        chairperson: values?.chairperson,
        email: values?.email,
        contact: values?.contact,
      });
      const me = await api.get('/auth/me');
      lastSnapshot.current = me;
      applySnapshot(me, subscription);
      notify('Organization details saved.');
      return saved;
    } catch (err) {
      fail(err, 'Unable to save organization details.');
      throw err;
    } finally {
      setMutating(false);
    }
  };
  const saveStaff = (values) => saveUser(values);
  const removeStaff = (id) => deleteUser(id);
  const applyYouth = async (targetType, targetId) => {
    setMutating(true);
    try {
      const body = targetType === 'assistance'
        ? { assistanceId: targetId }
        : { programId: targetId };
      const created = await api.post('/youth/applications', body);
      await loadYouthPortal(youthSession);
      notify('Application submitted.');
      return created.id;
    } catch (err) {
      notify(friendlyApiMessage(err, 'Unable to submit the application.'), 'error');
      throw err;
    } finally {
      setMutating(false);
    }
  };
  const respondYouthInvitation = async (assistanceId, decision) => {
    setMutating(true);
    try {
      await api.post(`/youth/assistance/${assistanceId}/invitation`, { decision });
      await loadYouthPortal(youthSession);
      notify(decision === 'join' ? 'You confirmed that you will join.' : 'You declined the invitation.');
    } catch (err) {
      notify(friendlyApiMessage(err, 'Unable to send your answer.'), 'error');
    } finally {
      setMutating(false);
    }
  };
  const respondProgramInvitation = async (programId, decision) => {
    setMutating(true);
    try {
      await api.post(`/youth/programs/${programId}/invitation`, { decision });
      await loadYouthPortal(youthSession);
      notify(decision === 'join' ? 'You confirmed that you will join.' : 'You declined the invitation.');
    } catch (err) {
      notify(friendlyApiMessage(err, 'Unable to send your answer.'), 'error');
    } finally {
      setMutating(false);
    }
  };
  const uploadRequirementFile = async (applicationId, requirementId, file) => {
    setMutating(true);
    try {
      const saved = await api.upload(
        `/youth/applications/${applicationId}/requirements/${requirementId}/file`,
        file
      );
      await loadYouthPortal(youthSession);
      notify('Requirement document uploaded.');
      return saved;
    } finally {
      setMutating(false);
    }
  };
  const removeRequirementFile = async (applicationId, requirementId) => {
    setMutating(true);
    try {
      await api.delete(`/youth/applications/${applicationId}/requirements/${requirementId}/file`);
      await loadYouthPortal(youthSession);
      notify('Requirement document removed.');
    } finally {
      setMutating(false);
    }
  };
  const resubmitYouthApplication = async (id) => {
    setMutating(true);
    try {
      await api.post(`/youth/applications/${id}/resubmit`, {});
      await loadYouthPortal(youthSession);
      notify('Application resubmitted.');
      return true;
    } catch (err) {
      notify(friendlyApiMessage(err, 'Unable to resubmit the application.'), 'error');
      throw err;
    } finally {
      setMutating(false);
    }
  };
  const readYouthNotification = async (id) => {
    try {
      await api.post(`/youth/notifications/${id}/read`, {});
      await loadYouthPortal(youthSession);
    } catch (err) {
      notify(friendlyApiMessage(err, 'Unable to update the notification.'), 'error');
    }
  };
  const readAllYouthNotifications = async () => {
    try {
      await api.post('/youth/notifications/read-all', {});
      await loadYouthPortal(youthSession);
    } catch (err) {
      notify(friendlyApiMessage(err, 'Unable to update notifications.'), 'error');
    }
  };
  const deleteYouthNotification = async (id) => {
    try {
      await api.delete(`/youth/notifications/${id}`);
      await loadYouthPortal(youthSession);
    } catch (err) {
      notify(friendlyApiMessage(err, 'Unable to delete the notification.'), 'error');
    }
  };
  const resetDemoData = () => notify('Live SK data cannot be reset from the browser.', 'warning');
  const clearOutbox = () => notify('SMS and email delivery history is kept on the server and cannot be cleared from the browser.', 'info');

  return {
    sessionReady,
    sessionKind: youthSession ? 'youth' : 'sk',
    youthSession,
    youthPortal,
    loadYouthPortal,
    checkInAttendance,
    registerByRegistrationCode,
    applyYouth,
    respondYouthInvitation,
    respondProgramInvitation,
    uploadRequirementFile,
    removeRequirementFile,
    resubmitYouthApplication,
    readYouthNotification,
    readAllYouthNotifications,
    deleteYouthNotification,
    skLoading,
    skError,
    retrySk: refresh,
    mutating,
    user: skUser,
    org,
    orgs: org ? [org] : [],
    db,
    dataVersion,
    ensureData,
    dataReady,
    applicationsPending,
    users,
    types,
    plans,
    plan,
    storedPlan,
    subscription,
    refreshSubscription,
    planName: plan.name,
    usage,
    limits,
    qrUses,
    qrLimit,
    qrRemaining,
    canScan,
    qrLimitMessage,
    dashboard,
    reports,
    flash,
    clearFlash,
    signup,
    signupOrganizations,
    startSignup,
    verifyOtp,
    resendOtp,
    cancelSignup,
    youthRecord: null,
    scoped,
    remaining,
    canAdd,
    allows,
    limitMessage,
    notify,
    requirementsFor,
    submissionsFor,
    targetOf,
    youthById,
    matchProfile,
    accountFor: () => null,
    accountStatusOf,
    login,
    logout,
    resetDemoData,
    changePassword,
    saveYouth,
    saveYouthWithAccount,
    loadYouthProfile,
    updateYouthProfile,
    archiveYouth,
    restoreYouth,
    deleteYouth,
    importYouth,
    saveProgram,
    deleteProgram,
    setProgramStatus,
    register,
    scanYouthQr,
    startAttendanceSession,
    attendanceSessions,
    recordManualAttendance,
    confirmAttendance,
    markAttendance,
    saveAssistance,
    deleteAssistance,
    archiveAssistance,
    closeAssistance,
    confirmSelection,
    saveBeneficiary,
    removeBeneficiary,
    addType,
    approveApplication,
    rejectApplication,
    requestResubmission,
    reviewRequirement,
    addRequirement,
    updateRequirement,
    removeRequirement,
    readNotification,
    readAllNotifications,
    deleteNotification,
    reloadNotifications,
    pollYouthNotifications,
    notificationUnread,
    saveUser,
    toggleUserActive,
    deleteUser,
    saveStaff,
    removeStaff,
    updateOrg,
    saveSkAccount,
    downgrade,
    payAndActivate,
    clearOutbox,
  };
}

function ApiAwareStore({ children }) {
  const mock = useStore();
  const [adminSnapshot, setAdminSnapshot] = useState(() => pageAdminMe);
  const sk = useSkApiStore(mock, setAdminSnapshot);
  const admin = useAdminApiStore(mock, adminSnapshot);

  const value = useMemo(() => {
    if (admin.user) {
      return {
        ...admin,
        sessionReady: sk.sessionReady,
        login: sk.login,
        logout: sk.logout,
      };
    }
    if (sk.user) return sk;
    if (sk.youthSession?.user) {
      const portal = sk.youthPortal;
      return {
        ...mock,
        user: sk.youthSession.user,
        org: sk.youthSession.org,
        youthRecord: sk.youthSession.youthRecord,
        db: {
          ...emptyDb(),
          programs: portal.programs,
          assistance: portal.assistance,
          applications: portal.applications,
          notifications: portal.notifications,
          requirements: portal.requirements,
          submissions: portal.submissions,
          registrations: [
            ...(portal.attendance || []),
            ...(portal.programInvitations || []).map((row) => ({
              id: row.id,
              programId: row.programId,
              youthId: sk.youthSession.user.youthId,
              status: row.status,
              attendance: '',
              source: 'invite',
            })),
          ],
          beneficiaries: (portal.invitations || []).map((row) => ({
            ...row,
            youthId: sk.youthSession.user.youthId,
            programId: row.programId,
          })),
        },
        sessionReady: sk.sessionReady,
        sessionKind: 'youth',
        youthPortalLoading: portal.loading,
        youthPortalError: portal.error,
        retryYouthPortal: () => sk.loadYouthPortal?.(sk.youthSession),
        checkInAttendance: sk.checkInAttendance,
        registerByRegistrationCode: sk.registerByRegistrationCode,
        skLoading: false,
        skError: null,
        retrySk: sk.retrySk,
        mutating: sk.mutating,
        flash: sk.flash,
        clearFlash: sk.clearFlash,
        notify: sk.notify,
        dashboard: null,
        reports: {},
        requirementsFor: (targetType, targetId) => portal.requirements.filter(
          (row) => row.targetType === targetType && Number(row.targetId) === Number(targetId)
        ),
        submissionsFor: (applicationId) => portal.submissions.filter(
          (row) => Number(row.applicationId) === Number(applicationId)
        ),
        targetOf: (application) => (
          application?.targetType === 'program'
            ? portal.programs.find((row) => row.id === application.targetId)
            : portal.assistance.find((row) => row.id === application.targetId)
        ),
        apply: sk.applyYouth,
        respondInvitation: sk.respondYouthInvitation,
        respondProgramInvitation: sk.respondProgramInvitation,
        uploadRequirementFile: sk.uploadRequirementFile,
        removeRequirementFile: sk.removeRequirementFile,
        resubmitApplication: sk.resubmitYouthApplication,
        pollYouthNotifications: sk.pollYouthNotifications,
        readNotification: sk.readYouthNotification,
        readAllNotifications: sk.readAllYouthNotifications,
        deleteNotification: sk.deleteYouthNotification,
        submitRequirement: (applicationId, requirementId, file) => sk.uploadRequirementFile(
          applicationId,
          requirementId,
          file?.file || file
        ),
        removeSubmission: (applicationId, requirementId) => sk.removeRequirementFile(applicationId, requirementId),
        submitApplication: (id) => sk.resubmitYouthApplication(id),
        login: sk.login,
        logout: sk.logout,
        changePassword: sk.changePassword,
        loadYouthProfile: sk.loadYouthProfile,
        updateYouthProfile: sk.updateYouthProfile,
      };
    }
    return {
      ...mock,
      apiMode: true,
      sessionReady: sk.sessionReady,
      sessionKind: 'mock',
      skLoading: false,
      skError: null,
      retrySk: sk.retrySk,
      mutating: false,
      dashboard: null,
      reports: {},
      signup: sk.signup,
      signupOrganizations: sk.signupOrganizations,
      startSignup: sk.startSignup,
      verifyOtp: sk.verifyOtp,
      resendOtp: sk.resendOtp,
      cancelSignup: sk.cancelSignup,
      login: sk.login,
      logout: sk.logout,
    };
  }, [mock, sk, admin]);

  return <StoreContext.Provider value={value}>{children}</StoreContext.Provider>;
}

export function StoreProvider({ children }) {
  return (
    <MockStoreProvider>
      <ApiAwareStore>{children}</ApiAwareStore>
    </MockStoreProvider>
  );
}
