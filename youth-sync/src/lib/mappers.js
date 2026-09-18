import { planByCode } from '../data/mock.js';

export const emptyDb = () => ({
  youth: [],
  programs: [],
  registrations: [],
  assistance: [],
  beneficiaries: [],
  applications: [],
  requirements: [],
  submissions: [],
  notifications: [],
  emails: [],
  sms: [],
  activity: [],
  activityLogs: [],
  payments: [],
});

export const mapAuthUser = (snapshot) => {
  const user = snapshot?.user || {};
  const org = snapshot?.organization || {};
  const first = user.first_name ?? user.firstName ?? '';
  const last = user.last_name ?? user.lastName ?? '';
  return {
    id: user.id,
    email: user.email,
    firstName: first,
    lastName: last,
    name: `${first} ${last}`.trim() || user.email,
    role: 'sk_official',
    orgId: org.id,
    active: (user.status || 'active') === 'active',
    mustChangePassword: Boolean(user.must_change_password ?? user.mustChangePassword),
    lastLogin: user.last_login_at ?? user.lastLoginAt ?? 'Never',
  };
};

export const mapOrganization = (org, subscription) => {
  const sub = org?.subscription || {};
  const plan = String(subscription?.plan || sub.plan || 'free').toLowerCase();
  const status = subscription?.status || sub.status || org?.status || 'free';
  return {
    id: org.id,
    name: org.name,
    barangay: org.barangay,
    municipality: org.municipality,
    province: org.province,
    chairperson: org.chairperson,
    email: org.email,
    contact: org.contact,
    status: org.status,
    plan,
    subStatus: status,
    cycle: subscription?.cycle || sub.cycle || 'none',
    expiresAt: subscription?.expiresAt || sub.expires_at || null,
    expiresIn: subscription?.expiresIn ?? null,
    qrUses: subscription?.usage?.qr ?? 0,
  };
};

export const mapYouth = (row, orgId) => ({
  ...row,
  orgId,
  skills: row.skills || [],
  interests: row.interests || [],
  preferredActivities: row.preferredActivities || [],
  reasons: row.reasons || [],
  photo: row.photo ?? null,
  document: row.document ?? null,
});

export const mapProgram = (row, orgId) => ({
  ...row,
  orgId,
  tagInterests: row.tagInterests || [],
  tagSkills: row.tagSkills || [],
  tagActivities: row.tagActivities || [],
  qrCode: row.qrCode || `YSPROG-${row.id}`,
});

export const mapAssistance = (row, orgId) => ({
  ...row,
  orgId,
  tagInterests: row.tagInterests || [],
  tagSkills: row.tagSkills || [],
  tagActivities: row.tagActivities || [],
});

export const mapApplication = (row, orgId) => ({
  id: row.id,
  orgId,
  youthId: row.youthId,
  targetType: 'assistance',
  targetId: row.assistanceId ?? row.targetId,
  assistanceId: row.assistanceId,
  status: row.status,
  remarks: row.remarks || '',
  submittedAt: row.submittedAt,
  createdAt: row.createdAt,
  reviewedAt: row.reviewedAt,
  youth: row.youth,
  assistance: row.assistance,
});

export const mapNotification = (row, orgId) => ({
  id: row.id,
  orgId,
  youthId: row.youthId ?? null,
  category: row.category || row.type || 'system',
  type: row.type || row.category,
  title: row.title,
  body: row.body || row.message || '',
  message: row.message || row.body || '',
  link: row.link || '',
  related: row.related || '',
  read: Boolean(row.isRead ?? row.read),
  isRead: Boolean(row.isRead ?? row.read),
  createdAt: row.createdAt,
  sentAt: row.createdAt,
});

export const mapSkUser = (row, orgId) => ({
  id: row.id,
  email: row.email,
  firstName: row.firstName || '',
  lastName: row.lastName || '',
  name: row.name || `${row.firstName || ''} ${row.lastName || ''}`.trim(),
  role: 'sk_official',
  orgId,
  active: (row.status || 'active') === 'active',
  status: row.status,
  isOwner: Boolean(row.isOwner),
  mustChangePassword: Boolean(row.mustChangePassword),
  lastLogin: row.lastLoginAt || 'Never',
  createdAt: row.createdAt || '',
});

export const mapAttendanceToRegistration = (row, orgId) => {
  const status = row.status === 'pending' ? 'registered' : 'registered';
  const attendance = ['present', 'absent', 'excused'].includes(row.status) ? row.status : '';
  return {
    id: row.id,
    orgId,
    programId: row.programId,
    youthId: row.youthId,
    status,
    attendance,
    scannedAt: row.confirmedAt || row.scannedAt || row.createdAt || '',
    source: row.source,
    pending: row.status === 'pending',
  };
};

export const catalogPlan = (code) => planByCode(String(code || 'free').toLowerCase());

export const itemsOf = (payload) => {
  if (!payload) return [];
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload.items)) return payload.items;
  return [];
};
