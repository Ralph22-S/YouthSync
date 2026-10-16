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
  payments: [],
});

export const mapYouthSession = (snapshot) => {
  const account = snapshot?.user || {};
  const youth = snapshot?.youth || {};
  const first = youth.firstName || account.first_name || account.firstName || '';
  const last = youth.lastName || account.last_name || account.lastName || '';
  const organization = snapshot?.organization || null;
  return {
    user: {
      id: account.id,
      email: account.email,
      firstName: first,
      lastName: last,
      name: `${first} ${last}`.trim() || account.email,
      role: 'youth',
      youthId: youth.id ?? null,
      orgId: organization?.id ?? youth.organizationId ?? null,
      active: (account.status || 'active') === 'active',
      mustChangePassword: Boolean(account.must_change_password ?? account.mustChangePassword),
      lastLogin: account.last_login_at ?? account.lastLoginAt ?? 'Never',
    },
    org: organization,
    youthRecord: youth.id ? youth : null,
  };
};

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
    subStatusLabel: subscription?.statusLabel || null,
    cycle: subscription?.cycle || sub.cycle || 'none',
    startedOn: subscription?.startedOn ?? null,
    expiresAt: subscription?.expiresAt || sub.expires_at || null,
    expiresIn: subscription?.expiresIn ?? null,
    // Days left in a running trial/active term (null when free, expired or cancelled).
    daysRemaining: subscription?.daysRemaining ?? null,
    restrictedToFree: Boolean(subscription?.restrictedToFree),
    qrUses: subscription?.usage?.qr ?? 0,
  };
};

/**
 * Plan catalog from the API (GET /sk/subscription `plans`, GET /admin/plans
 * `items`). Prices are reference list prices for display only.
 */
export const mapPlanCatalog = (rows) => (Array.isArray(rows) ? rows : []).map((row, index) => ({
  id: index + 1,
  code: row.code,
  name: row.name,
  tagline: row.tagline || '',
  currency: row.currency || 'PHP',
  priceMonthly: Number(row.priceMonthly ?? 0),
  priceYearly: Number(row.priceYearly ?? 0),
  popular: Boolean(row.popular),
  limits: row.limits || {},
  features: {
    csv_import: Boolean(row.features?.csvImport),
    excel_export: Boolean(row.features?.excelExport),
    activity_logs: Boolean(row.features?.activityLogs),
  },
  bullets: Array.isArray(row.bullets) ? row.bullets : [],
}));

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
  registrationCode: row.registrationCode || '',
  qrCode: row.registrationCode || row.qrCode || '',
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
  targetType: row.targetType || (row.programId ? 'program' : 'assistance'),
  targetId: row.targetId || row.programId || row.assistanceId,
  assistanceId: row.assistanceId || null,
  programId: row.programId || null,
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
  // The SK screens (Users, Settings staff list) read `owner`, as the admin team list does.
  owner: Boolean(row.isOwner),
  mustChangePassword: Boolean(row.mustChangePassword),
  lastLogin: row.lastLoginAt || 'Never',
  createdAt: row.createdAt || '',
});

export const mapAttendanceToRegistration = (row, orgId) => {
  const raw = String(row.status || '').toLowerCase();
  const source = String(row.source || '').toLowerCase();
  const attendance = ['present', 'absent', 'excused'].includes(raw)
    ? raw
    : (source === 'self' ? 'present' : '');
  return {
    id: row.id,
    orgId,
    programId: row.programId,
    youthId: row.youthId,
    status: ['invited', 'declined'].includes(raw) ? raw : 'registered',
    attendance,
    scannedAt: row.confirmedAt || row.scannedAt || row.createdAt || '',
    source,
    pending: raw === 'pending' && source !== 'self',
  };
};

export const mapYouthProgram = (row, orgId) => ({
  id: row.id,
  orgId,
  kind: row.kind || 'program',
  name: row.name,
  category: row.category || '',
  status: row.status,
  scheduledOn: row.scheduledOn,
  startsAt: row.startsAt,
  endsAt: row.endsAt,
  location: row.location || '',
  description: row.description || '',
  maxParticipants: row.maxParticipants,
  registeredCount: Number(row.registeredCount) || 0,
  registrationDeadline: row.registrationDeadline,
  tagInterests: row.tagInterests || [],
  tagSkills: row.tagSkills || [],
  tagActivities: row.tagActivities || [],
  requiresStudying: Boolean(row.requiresStudying),
  minAge: row.minAge,
  maxAge: row.maxAge,
  requirementItems: row.requirementItems || [],
});

export const mapYouthAssistance = (row, orgId) => ({
  id: row.id,
  orgId,
  name: row.name,
  category: row.category || '',
  status: row.status,
  slots: row.slots,
  amount: row.amount,
  description: row.description || '',
  deadline: row.deadline,
  requirements: row.requirements || '',
  requirementItems: row.requirementItems || [],
  tagInterests: row.tagInterests || [],
  tagSkills: row.tagSkills || [],
  tagActivities: row.tagActivities || [],
  requiresStudying: Boolean(row.requiresStudying),
  minAge: row.minAge,
  maxAge: row.maxAge,
});

export const mapYouthApplication = (row, orgId) => ({
  id: row.id,
  orgId,
  youthId: row.youthId,
  targetType: row.targetType || (row.programId ? 'program' : 'assistance'),
  targetId: row.targetId || row.programId || row.assistanceId,
  assistanceId: row.assistanceId || null,
  programId: row.programId || null,
  status: row.status,
  submittedAt: String(row.submittedAt || '').slice(0, 10),
  reviewedAt: row.reviewedAt ? String(row.reviewedAt).slice(0, 10) : null,
  remarks: row.remarks || '',
  assistanceName: row.assistance?.name || row.program?.name || '',
});

export const mapYouthNotification = (row, orgId) => ({
  id: row.id,
  orgId,
  youthId: row.youthId,
  title: row.title,
  body: row.body || row.message || '',
  read: Boolean(row.isRead ?? row.read),
  createdAt: row.createdAt,
  link: row.link && String(row.link).startsWith('/youth') ? row.link : '',
  category: row.type || row.category || 'system',
});

export const itemsOf = (payload) => {
  if (!payload) return [];
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload.items)) return payload.items;
  return [];
};
