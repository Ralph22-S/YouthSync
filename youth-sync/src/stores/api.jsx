import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { StoreContext, useStore } from '../store-context.js';
import { MockStoreProvider } from './mock.jsx';
import { PLANS } from '../data/mock.js';
import { api, ApiError, friendlyApiMessage } from '../lib/api.js';
import {
  catalogPlan,
  emptyDb,
  itemsOf,
  mapApplication,
  mapAssistance,
  mapAttendanceToRegistration,
  mapAuthUser,
  mapNotification,
  mapOrganization,
  mapProgram,
  mapSkUser,
  mapYouth,
} from '../lib/mappers.js';

const PAGE = { page: 1, perPage: 100 };

function useSkApiStore(mock) {
  const [sessionReady, setSessionReady] = useState(false);
  const [skUser, setSkUser] = useState(null);
  const [org, setOrg] = useState(null);
  const [db, setDb] = useState(emptyDb);
  const [users, setUsers] = useState([]);
  const [types, setTypes] = useState([]);
  const [subscription, setSubscription] = useState(null);
  const [dashboard, setDashboard] = useState(null);
  const [reports, setReports] = useState({});
  const [skLoading, setSkLoading] = useState(false);
  const [skError, setSkError] = useState(null);
  const [flash, setFlash] = useState(null);
  const [mutating, setMutating] = useState(false);
  const sessionTokens = useRef({});
  const bootGen = useRef(0);
  const lastSnapshot = useRef(null);

  const notify = useCallback((message, tone = 'success') => {
    setFlash({ message, tone });
  }, []);

  const fail = useCallback((err, fallback) => {
    if (err?.name === 'AbortError') return null;
    const message = friendlyApiMessage(err, fallback);
    notify(message, err?.status === 403 ? 'warning' : 'error');
    return null;
  }, [notify]);

  const scoped = useCallback((key) => (db[key] ?? []).filter((row) => row.orgId === org?.id), [db, org]);

  const applySnapshot = useCallback((snapshot, sub) => {
    const mappedOrg = mapOrganization(snapshot.organization, sub);
    setOrg(mappedOrg);
    setSkUser(mapAuthUser(snapshot));
  }, []);

  const loadCollections = useCallback(async (organizationId, gen) => {
    const still = () => gen === bootGen.current;
    const take = (result, fallback) => (result.status === 'fulfilled' ? result.value : fallback);

    const batch = await Promise.allSettled([
      api.get('/sk/youth', { query: { ...PAGE, archived: 0 } }),
      api.get('/sk/youth', { query: { ...PAGE, archived: 1 } }),
      api.get('/sk/programs', { query: PAGE }),
      api.get('/sk/assistance', { query: PAGE }),
      api.get('/sk/applications', { query: PAGE }),
      api.get('/sk/notifications', { query: PAGE }),
      api.get('/sk/users', { query: PAGE }),
      api.get('/sk/assistance-types'),
      api.get('/sk/subscription'),
      api.get('/sk/dashboard'),
    ]);
    if (!still()) return null;

    const failed = batch.find((result) => result.status === 'rejected');
    if (batch.every((result) => result.status === 'rejected')) {
      throw failed.reason;
    }

    const youthLive = take(batch[0], { items: [] });
    const youthArchived = take(batch[1], { items: [] });
    const programs = take(batch[2], { items: [] });
    const assistanceList = take(batch[3], { items: [] });
    const applications = take(batch[4], { items: [] });
    const notifications = take(batch[5], { items: [] });
    const skUsers = take(batch[6], { items: [] });
    const typeList = take(batch[7], { items: [] });
    const sub = take(batch[8], null);
    const dash = take(batch[9], null);

    const youth = [
      ...itemsOf(youthLive).map((row) => mapYouth(row, organizationId)),
      ...itemsOf(youthArchived).map((row) => mapYouth(row, organizationId)),
    ];
    const programRows = itemsOf(programs).map((row) => mapProgram(row, organizationId));
    const assistanceSource = itemsOf(assistanceList);
    const applicationSource = itemsOf(applications);

    const assistanceNeedingDetail = assistanceSource.filter((row) => !Array.isArray(row.requirementItems));
    const extraAssistance = assistanceNeedingDetail.length
      ? await Promise.allSettled(assistanceNeedingDetail.map((row) => api.get(`/sk/assistance/${row.id}`)))
      : [];
    if (!still()) return null;
    const assistanceById = new Map(assistanceSource.map((row) => [row.id, row]));
    extraAssistance.forEach((result, index) => {
      if (result.status !== 'fulfilled' || !result.value) return;
      assistanceById.set(assistanceNeedingDetail[index].id, result.value);
    });
    const assistanceRows = [...assistanceById.values()].map((row) => mapAssistance(row, organizationId));

    const requirements = [];
    const beneficiaries = [];
    assistanceById.forEach((detail) => {
      const mapped = mapAssistance(detail, organizationId);
      (detail.requirementItems || []).forEach((req) => {
        requirements.push({ ...req, orgId: organizationId, targetType: 'assistance', targetId: mapped.id });
      });
      (detail.beneficiaries || []).forEach((ben) => {
        beneficiaries.push({ ...ben, orgId: organizationId, programId: ben.programId || mapped.id });
      });
    });

    const appsNeedingDetail = applicationSource.filter((row) => !Array.isArray(row.submissions));
    const extraApps = appsNeedingDetail.length
      ? await Promise.allSettled(appsNeedingDetail.map((row) => api.get(`/sk/applications/${row.id}`)))
      : [];
    if (!still()) return null;
    const applicationById = new Map(applicationSource.map((row) => [row.id, row]));
    extraApps.forEach((result, index) => {
      if (result.status !== 'fulfilled' || !result.value) return;
      applicationById.set(appsNeedingDetail[index].id, result.value);
    });

    const submissions = [];
    const applicationRows = [...applicationById.values()].map((detail) => {
      (detail.submissions || []).forEach((subRow) => {
        submissions.push({ ...subRow, orgId: organizationId });
      });
      return mapApplication(detail, organizationId);
    });

    const attendanceLists = programRows.length
      ? await Promise.allSettled(programRows.map((program) => api.get(`/sk/programs/${program.id}/attendance`)))
      : [];
    if (!still()) return null;
    const registrations = attendanceLists.flatMap((result) => {
      if (result.status !== 'fulfilled') return [];
      return itemsOf(result.value).map((row) => mapAttendanceToRegistration(row, organizationId));
    });

    setSubscription(sub);
    setDashboard(dash);
    setTypes(Array.isArray(typeList) ? typeList : itemsOf(typeList));
    setUsers(itemsOf(skUsers).map((row) => mapSkUser(row, organizationId)));
    setDb({
      ...emptyDb(),
      youth,
      programs: programRows,
      assistance: assistanceRows,
      applications: applicationRows,
      requirements,
      beneficiaries,
      submissions,
      registrations,
      notifications: itemsOf(notifications).map((row) => mapNotification(row, organizationId)),
    });
    if (failed) {
      setSkError(friendlyApiMessage(failed.reason, 'Some SK records could not be loaded.'));
    } else {
      setSkError(null);
    }
    return sub;
  }, []);

  const bootstrap = useCallback(async (snapshot) => {
    const gen = ++bootGen.current;
    lastSnapshot.current = snapshot;
    setSkLoading(true);
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
      }
    }
  }, [applySnapshot, loadCollections]);

  const refresh = useCallback(async () => {
    const snapshot = lastSnapshot.current;
    if (!snapshot) return;
    try {
      await bootstrap(snapshot);
    } catch (err) {
      fail(err, 'Unable to refresh data.');
    }
  }, [bootstrap, fail]);

  useEffect(() => {
    const ac = new AbortController();
    (async () => {
      try {
        const me = await api.get('/auth/me', { signal: ac.signal });
        if (ac.signal.aborted) return;
        await bootstrap(me);
      } catch (err) {
        if (ac.signal.aborted || err?.name === 'AbortError') return;
        if (err instanceof ApiError && err.status === 401) {
          setSkUser(null);
          setOrg(null);
          setDb(emptyDb());
        }
        setSessionReady(true);
      }
    })();
    return () => {
      ac.abort();
      bootGen.current += 1;
    };
  }, [bootstrap]);

  const enterSk = useCallback(async (snapshot) => {
    mock.logout();
    await bootstrap(snapshot);
    return { user: mapAuthUser(snapshot) };
  }, [bootstrap, mock]);

  const login = useCallback(async (email, password, remember = false) => {
    try {
      const data = await api.post('/auth/login', {
        email,
        password,
        remember: Boolean(remember),
        rememberMe: Boolean(remember),
      });
      const me = await api.get('/auth/me');
      return enterSk(me || data);
    } catch (err) {
      const mockResult = mock.login(email, password);
      if (mockResult?.user && mockResult.user.role !== 'sk_official') {
        return mockResult;
      }
      return { error: friendlyApiMessage(err, 'Those credentials do not match our records.'), code: err.code };
    }
  }, [enterSk, mock]);

  const logout = useCallback(async () => {
    try {
      if (skUser) await api.post('/auth/logout', {});
    } catch {
      // Cookie is cleared by the API; ignore network errors on logout.
    }
    sessionTokens.current = {};
    lastSnapshot.current = null;
    setSkUser(null);
    setOrg(null);
    setDb(emptyDb());
    setUsers([]);
    setSubscription(null);
    setDashboard(null);
    mock.logout();
  }, [mock, skUser]);

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
  const limits = subscription?.limits || catalogPlan(org?.plan).limits;
  const effectiveCode = subscription?.effectivePlan?.code || org?.plan || 'free';
  const plan = {
    ...catalogPlan(effectiveCode),
    name: subscription?.effectivePlan?.name || catalogPlan(effectiveCode).name,
    limits,
  };
  const qrUses = usage.qr ?? org?.qrUses ?? 0;
  const qrLimit = limits.qr ?? null;
  const qrRemaining = qrLimit === null ? null : Math.max(0, qrLimit - qrUses);
  const canScan = () => qrLimit === null || qrUses < qrLimit;
  const qrLimitMessage = () =>
    `QR scan limit reached — the ${plan.name} plan includes ${qrLimit} scan${qrLimit === 1 ? '' : 's'}. `
    + 'Upgrade your plan for more QR scans.';
  const remaining = (key) => (limits[key] === null || limits[key] === undefined ? null : Math.max(0, limits[key] - (usage[key] || 0)));
  const canAdd = (key, count = 1) => limits[key] === null || limits[key] === undefined || (usage[key] || 0) + count <= limits[key];
  const allows = (feature) => {
    if (feature === 'csv_import') return Boolean(subscription?.features?.csvImport ?? plan.features.csv_import);
    return Boolean(plan.features[feature]);
  };
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
  const targetOf = (application) => db.assistance.find((p) => p.id === application.targetId || p.id === application.assistanceId);
  const youthById = (id) => db.youth.find((y) => y.id === id);
  const accountStatusOf = (youth) => youth?.accountStatus || 'not_registered';
  const matchProfile = mock.matchProfile;

  const saveYouth = async (values) => run(async () => {
    const body = { ...values };
    delete body.orgId;
    delete body.organization_id;
    delete body.id;
    if (values.id) {
      const saved = await api.patch(`/sk/youth/${values.id}`, body);
      notify('Youth record updated.');
      await refresh();
      return saved.id || values.id;
    }
    const created = await api.post('/sk/youth', body);
    const youth = created.youth || created;
    notify('Youth record added. It is now at the top of your list.');
    await refresh();
    return youth.id;
  });

  const saveYouthWithAccount = async (values, account) => {
    try {
      setMutating(true);
      const created = await api.post('/sk/youth', {
        ...values,
        createAccount: true,
        accountEmail: account?.email,
      });
      const youth = created.youth || created;
      await refresh();
      if (created.warning) notify(created.warning, 'warning');
      else notify('Youth record and account created successfully.');
      return { id: youth.id, password: created.temporaryPassword, code: youth.code };
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
  const importYouth = (rows) => run(async () => {
    const result = await api.post('/sk/youth/import', { rows });
    await refresh();
    const count = result.imported ?? result.created ?? rows.length;
    notify(`${count} youth records successfully imported.`);
    return count;
  });

  const saveProgram = async (values) => run(async () => {
    const body = { ...values };
    delete body.orgId;
    delete body.id;
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
  });

  const deleteProgram = (id) => run(async () => { await api.delete(`/sk/programs/${id}`); await refresh(); }, 'Activity deleted.');
  const setProgramStatus = (id, status) => run(async () => {
    if (status === 'archived') await api.post(`/sk/programs/${id}/archive`, {});
    else await api.post(`/sk/programs/${id}/status`, { status });
    await refresh();
  }, 'Activity status updated.');

  const saveAssistance = async (values) => run(async () => {
    const body = { ...values };
    delete body.orgId;
    delete body.id;
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
  });

  const deleteAssistance = (id) => run(async () => { await api.delete(`/sk/assistance/${id}`); await refresh(); }, 'Assistance program deleted.');
  const archiveAssistance = (id) => run(async () => { await api.post(`/sk/assistance/${id}/archive`, {}); await refresh(); }, 'Assistance program archived.');
  const saveBeneficiary = (programId, youthId, status, remarks) => run(async () => {
    await api.post(`/sk/assistance/${programId}/beneficiaries`, { youthId, status, remarks });
    await refresh();
  }, 'Beneficiary saved.');
  const removeBeneficiary = (id) => run(async () => { await api.delete(`/sk/beneficiaries/${id}`); await refresh(); }, 'Beneficiary removed.');
  const addType = async (name) => run(async () => {
    await api.post('/sk/assistance-types', typeof name === 'string' ? { name } : name);
    await refresh();
  }, 'Assistance type added.');

  const approveApplication = (id) => run(async () => { await api.post(`/sk/applications/${id}/approve`, {}); await refresh(); }, 'Application approved.');
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

  const readNotification = (id) => run(async () => { await api.post(`/sk/notifications/${id}/read`, {}); await refresh(); });
  const readAllNotifications = () => run(async () => { await api.post('/sk/notifications/read-all', {}); await refresh(); }, 'All notifications marked as read.');
  const deleteNotification = (id) => run(async () => { await api.delete(`/sk/notifications/${id}`); await refresh(); }, 'Notification deleted.');

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

  const ensureSession = async (programId) => {
    const cached = sessionTokens.current[programId];
    if (cached?.token) return cached.token;
    const session = await api.post(`/sk/programs/${programId}/attendance/session`, {});
    if (session.token) sessionTokens.current[programId] = { token: session.token };
    return session.token;
  };

  const scanYouthQr = async (token, programId) => {
    try {
      const sessionToken = await ensureSession(programId);
      const result = await api.post('/sk/attendance/scan', { token, sessionToken, programId });
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
      return { ok: false, message: friendlyApiMessage(err, 'Scan failed.') };
    }
  };

  const confirmAttendance = async (registrationId) => {
    try {
      const saved = await api.post('/sk/attendance/confirm', { attendanceId: registrationId });
      await refresh();
      notify('Attendance confirmed.');
      return { ok: true, attendance: saved };
    } catch (err) {
      fail(err, 'Unable to confirm attendance.');
      return { ok: false };
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

  const changePassword = async (newPassword, currentPassword = '') => {
    try {
      await api.post('/auth/change-password', {
        current_password: currentPassword,
        new_password: newPassword,
      });
      const me = await api.get('/auth/me');
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
    notify('Checkout is not enabled. This SK session shows the live plan from the server (read-only).', 'warning');
    return null;
  };
  const downgrade = () => notify('Plan changes are not enabled in this phase. The live subscription is read-only.', 'warning');
  const updateOrg = () => notify('Organization profile is managed on the server and is not editable from this SK session.', 'warning');
  const saveStaff = (values) => saveUser(values);
  const removeStaff = (id) => deleteUser(id);
  const resetDemoData = () => notify('Live SK data cannot be reset from the browser.', 'warning');
  const clearOutbox = () => notify('Outbox is a local preview only. No messages were stored on the server.', 'info');

  return {
    sessionReady,
    sessionKind: 'sk',
    skLoading,
    skError,
    retrySk: refresh,
    mutating,
    user: skUser,
    org,
    orgs: org ? [org] : [],
    db,
    users,
    types,
    plans: PLANS,
    plan,
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
    clearFlash: () => setFlash(null),
    signup: null,
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
    archiveYouth,
    restoreYouth,
    deleteYouth,
    importYouth,
    saveProgram,
    deleteProgram,
    setProgramStatus,
    register,
    scanYouthQr,
    confirmAttendance,
    markAttendance,
    saveAssistance,
    deleteAssistance,
    archiveAssistance,
    saveBeneficiary,
    removeBeneficiary,
    addType,
    approveApplication,
    rejectApplication,
    requestResubmission,
    reviewRequirement,
    readNotification,
    readAllNotifications,
    deleteNotification,
    saveUser,
    toggleUserActive,
    deleteUser,
    saveStaff,
    removeStaff,
    updateOrg,
    downgrade,
    payAndActivate,
    clearOutbox,
  };
}

function ApiAwareStore({ children }) {
  const mock = useStore();
  const sk = useSkApiStore(mock);

  const value = useMemo(() => {
    if (sk.user) return sk;
    return {
      ...mock,
      sessionReady: sk.sessionReady,
      sessionKind: 'mock',
      skLoading: false,
      skError: null,
      retrySk: sk.retrySk,
      mutating: false,
      dashboard: null,
      reports: {},
      login: sk.login,
      logout: sk.logout,
    };
  }, [mock, sk]);

  return <StoreContext.Provider value={value}>{children}</StoreContext.Provider>;
}

export function StoreProvider({ children }) {
  return (
    <MockStoreProvider>
      <ApiAwareStore>{children}</ApiAwareStore>
    </MockStoreProvider>
  );
}
