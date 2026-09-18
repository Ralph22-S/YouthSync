// YouthSync — seed dataset for the frontend system.
// No backend, no database. State lives in React and localStorage.

export const APP_NAME = 'YouthSync';

let seed = 20260901;
const rand = () => {
  seed = (seed * 1103515245 + 12345) % 2147483648;
  return seed / 2147483648;
};
const pick = (list) => list[Math.floor(rand() * list.length)];
const int = (min, max) => min + Math.floor(rand() * (max - min + 1));
const iso = (date) => date.toISOString().slice(0, 10);
const addDays = (days, from = new Date()) => {
  const d = new Date(from);
  d.setDate(d.getDate() + days);
  return d;
};

export const today = new Date();

const FIRST = ['Juan', 'Maria', 'Jose', 'Angelica', 'Mark', 'Kristine', 'Paolo', 'Jasmine', 'Rafael', 'Danica',
  'Christian', 'Nicole', 'Jerome', 'Aira', 'Kenneth', 'Camille', 'Vincent', 'Rhea', 'Joshua', 'Bea',
  'Miguel', 'Andrea', 'Carlo', 'Trisha', 'Julius', 'Shaira', 'Leo', 'Grace', 'Emmanuel', 'Diana'];

const LAST = ['Dela Cruz', 'Bagsic', 'Cadapan', 'Quesada', 'Baldemor', 'Villanueva', 'Rivera', 'Manalo',
  'Cosico', 'Rebadulla', 'Adeva', 'Palma', 'Ilagan', 'Cabrera', 'Nolasco', 'Reyes', 'Torres', 'Mercado'];

const SCHOOLS = ['Paete National High School', 'Pakil National High School', 'Laguna State Polytechnic University',
  'Pagsanjan Institute', 'Santa Cruz National High School', 'University of the Philippines Los Baños'];

export const MUNICIPALITIES = ['Paete', 'Pagsanjan', 'Santa Cruz', 'Lumban', 'Kalayaan', 'Pakil', 'Pangil', 'Calamba', 'Los Baños'];

export const PROGRAM_CATEGORIES = ['Sports & Recreation', 'Education & Training', 'Health & Wellness',
  'Livelihood', 'Environment', 'Leadership', 'Community Service', 'Cultural'];

const freeFeatures = {
  csv_import: false, excel_export: false, pdf_export: false, advanced_analytics: false,
  advanced_reports: false, activity_logs: false, audit_trail: false,
  automated_notifications: false, backup_tools: false, priority_support: false,
};

export const PLANS = [
  {
    id: 1, code: 'free', name: 'Free', tagline: 'Start managing your SK digitally.',
    priceMonthly: 0, priceYearly: 0,
    limits: { youth: 20, accounts: 1, programs: 1, assistance: 1, qr: 1 },
    features: { ...freeFeatures },
  },
  {
    id: 2, code: 'basic', name: 'Basic', tagline: 'Everything you need for everyday SK operations.',
    priceMonthly: 999, priceYearly: 9990,
    limits: { youth: 250, accounts: 3, programs: null, assistance: 20, qr: 3 },
    features: { ...freeFeatures, csv_import: true, excel_export: true, pdf_export: true, activity_logs: true },
  },
  {
    id: 3, code: 'premium', name: 'Premium', tagline: 'Complete tools for advanced SK management.',
    priceMonthly: 1999, priceYearly: 19990, popular: true,
    limits: { youth: null, accounts: null, programs: null, assistance: null, qr: null },
    features: Object.fromEntries(Object.keys(freeFeatures).map((k) => [k, true])),
  },
];

export const planByCode = (code) => PLANS.find((p) => p.code === code) || PLANS[0];

/**
 * Each assistance type carries the requirements it normally needs.
 * Picking the type on an assistance program fills these in automatically.
 * "Other" has none on purpose — the SK writes those by hand.
 */
export const ASSISTANCE_TYPES = [
  { id: 1, name: 'SK Educational Scholarship', category: 'scholarship', system: true, requirements: [
    ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
    ['Certificate of Enrolment', 'Current semester or school year.', true, 'image/*,application/pdf'],
    ['Latest Report Card or Grades', 'Most recent grading period.', true, 'image/*,application/pdf'],
    ['Proof of Family Income', 'Payslip, certificate of indigency, or BIR form.', true, 'image/*,application/pdf'],
    ['Valid ID', 'Any government or school-issued ID.', true, 'image/*'],
  ] },
  { id: 2, name: 'College Grant', category: 'scholarship', system: true, requirements: [
    ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
    ['Certificate of Enrolment', 'Current semester, with units enrolled.', true, 'image/*,application/pdf'],
    ['Latest Grades', 'Most recent semester.', true, 'image/*,application/pdf'],
    ['Certificate of Indigency', 'Issued by the barangay or DSWD.', false, 'image/*,application/pdf'],
  ] },
  { id: 3, name: 'Financial Assistance', category: 'financial', system: true, requirements: [
    ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
    ['Valid ID', 'Any government or school-issued ID.', true, 'image/*'],
    ['Proof of Family Income', 'Payslip, certificate of indigency, or BIR form.', true, 'image/*,application/pdf'],
  ] },
  { id: 4, name: 'Educational Assistance', category: 'other', system: true, requirements: [
    ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
    ['Certificate of Enrolment', 'Current semester or school year.', true, 'image/*,application/pdf'],
    ['Valid ID', 'Any government or school-issued ID.', true, 'image/*'],
  ] },
  { id: 5, name: 'Emergency Assistance', category: 'other', system: true, requirements: [
    ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
    ['Valid ID', 'Any government or school-issued ID.', true, 'image/*'],
    ['Incident or Police Report', 'Document describing the emergency.', true, 'image/*,application/pdf'],
  ] },
  { id: 6, name: 'Livelihood Assistance', category: 'other', system: true, requirements: [
    ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
    ['Valid ID', 'Any government or school-issued ID.', true, 'image/*'],
    ['Livelihood Plan', 'Short description of the livelihood you will start.', true, 'image/*,application/pdf'],
  ] },
  { id: 7, name: 'Medical Assistance', category: 'other', system: true, requirements: [
    ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
    ['Valid ID', 'Any government or school-issued ID.', true, 'image/*'],
    ['Medical Certificate', 'Signed by the attending physician.', true, 'image/*,application/pdf'],
    ['Prescription or Hospital Bill', 'Whichever applies to your request.', true, 'image/*,application/pdf'],
  ] },
  { id: 8, name: 'Other', category: 'other', system: true, requirements: [] },
];

/** High / Medium priority recommendation. Recommends only — never approves. */
export function evaluatePriority(y) {
  let score = 0;
  const reasons = [];
  const income = Number(y.familyIncome) || 0;
  const members = Number(y.familyMembers) || 0;
  const perCapita = members > 0 ? income / members : income;

  if (income > 0 && income <= 12000) { score += 3; reasons.push(`Low family income (₱${income.toLocaleString()}/month)`); }
  else if (income > 0 && income <= 20000) { score += 1; reasons.push(`Modest family income (₱${income.toLocaleString()}/month)`); }

  if (members >= 6 && perCapita > 0 && perCapita < 3000) { score += 2; reasons.push(`Large household with low income per member (${members} members)`); }
  if (y.guardianEmployment === 'Unemployed') { score += 3; reasons.push('Parent or guardian unemployed'); }

  if (y.employment === 'Unemployed' && !y.studying) { score += 3; reasons.push('Out of school and unemployed'); }
  else if (y.employment === 'Unemployed') { score += 1; reasons.push('Currently unemployed'); }

  if (!y.studying && y.education !== 'College') { score += 2; reasons.push('Not currently enrolled in school'); }

  if (!y.previousScholarship && !y.previousAssistance) { score += 1; reasons.push('Has not yet received SK assistance'); }
  else reasons.push('Previously received SK assistance');

  if (!y.skills) { score += 1; reasons.push('No recorded skills or training'); }

  return { level: score >= 6 ? 'High' : 'Medium', score, reasons: reasons.slice(0, 5) };
}

export const PRIORITY_DISCLAIMER =
  'Priority recommendation only. Final qualification and approval remain with the SK Official.';

export const age = (birthDate) => {
  const b = new Date(birthDate);
  let a = today.getFullYear() - b.getFullYear();
  const m = today.getMonth() - b.getMonth();
  if (m < 0 || (m === 0 && today.getDate() < b.getDate())) a -= 1;
  return a;
};

export const fullName = (y) => `${y.firstName} ${y.middleName ? y.middleName[0] + '. ' : ''}${y.lastName}`;

let nextId = 1000;
export const newId = () => ++nextId;

/** Picks `count` distinct values, so chips never repeat. */
const sample = (list, count) => {
  const pool = [...list];
  const out = [];
  for (let i = 0; i < count && pool.length; i++) out.push(pool.splice(Math.floor(rand() * pool.length), 1)[0]);
  return out;
};

const levelFields = (level) => {
  const fields = { school: '', course: '', yearLevel: '', strand: '' };
  const wanted = EDUCATION_FIELDS[level] || [];
  if (wanted.includes('school')) fields.school = pick(SCHOOLS);
  if (wanted.includes('course')) fields.course = pick(['BSIT', 'BSEd', 'BSBA', 'BS Criminology', 'Computer Servicing NC II']);
  if (wanted.includes('yearLevel')) fields.yearLevel = pick(YEAR_LEVELS);
  if (wanted.includes('strand')) fields.strand = pick(STRANDS);
  return fields;
};

function makeYouth(orgId, index, overrides = {}) {
  const firstName = overrides.firstName || pick(FIRST);
  const lastName = overrides.lastName || pick(LAST);
  const studying = overrides.studying ?? rand() < 0.62;
  const birth = addDays(-int(0, 364), new Date(today.getFullYear() - int(15, 30), today.getMonth(), today.getDate()));
  const level = studying
    ? pick(['Junior High School', 'Senior High School', 'College', 'Vocational/Technical'])
    : pick(['Senior High School', 'College', 'Graduate/Finished School']);

  const y = {
    id: orgId * 1000 + index,
    code: `YTH-${String(orgId * 1000 + index).slice(-4)}`,
    orgId,
    firstName,
    middleName: pick(LAST),
    lastName,
    birthDate: iso(birth),
    gender: rand() < 0.5 ? 'Male' : 'Female',
    address: `Purok ${int(1, 6)}, Barangay ${ORGANIZATIONS.find((o) => o.id === orgId).barangay}`,
    contact: `09${int(10, 99)}${int(1000000, 9999999)}`,
    civilStatus: rand() < 0.88 ? 'Single' : 'Married',
    educationStatus: studying ? 'Currently Studying' : 'Not Currently Studying',
    education: level,
    ...(studying ? levelFields(level) : { school: '', course: '', yearLevel: '', strand: '' }),
    studying,
    employment: studying ? 'Student' : pick(['Employed', 'Unemployed', 'Self-employed']),
    occupation: studying ? '' : pick(['Woodcarver', 'Sales staff', 'Tricycle driver', 'Online seller']),
    guardianName: `${pick(FIRST)} ${lastName}`,
    guardianEmployment: pick(['Employed', 'Unemployed', 'Self-employed']),
    guardianOccupation: pick(['Farmer', 'Vendor', 'Carpenter', 'Teacher', 'Housekeeper']),
    familyIncome: pick([6000, 8500, 11000, 14000, 18000, 23000, 32000]),
    familyMembers: int(3, 9),
    skills: sample(SKILL_OPTIONS, int(1, 3)),
    interests: sample(INTEREST_OPTIONS, int(1, 3)),
    email: `${firstName}.${lastName}${index}`.toLowerCase().replace(/[^a-z0-9.]/g, '') + '@example.com',
    preferredActivities: sample(ACTIVITY_OPTIONS, int(1, 3)),
    previousScholarship: rand() < 0.18,
    previousAssistance: rand() < 0.22,
    previousParticipation: rand() < 0.45,
    archived: false,
    photo: null,
    // Newest-first ordering depends on this. Seeded youth are staggered into the past
    // so a newly added record always sorts above them.
    createdAt: iso(addDays(-index * 2 - 3)),
  };

  const merged = { ...y, ...overrides };
  return { ...merged, ...evaluatePriority(merged) };
}

export const ORGANIZATIONS = [
  { id: 1, barangay: 'Ibaba del Norte', municipality: 'Paete', chairperson: 'Ariel B. Baldemor', email: 'sk.ibabadelnorte@demo.example', contact: '09171000001', status: 'active', plan: 'premium', subStatus: 'trial', cycle: 'trial', expiresIn: 2, youthCount: 48 },
  { id: 2, barangay: 'Bagumbayan', municipality: 'Pagsanjan', chairperson: 'Nica R. Villanueva', email: 'sk.bagumbayan@demo.example', contact: '09171000002', status: 'active', plan: 'basic', subStatus: 'active', cycle: 'monthly', expiresIn: 18, youthCount: 65 },
  { id: 3, barangay: 'Poblacion', municipality: 'Santa Cruz', chairperson: 'Jomar D. Cadapan', email: 'sk.poblacion@demo.example', contact: '09171000003', status: 'active', plan: 'premium', subStatus: 'active', cycle: 'yearly', expiresIn: 240, youthCount: 80 },
  { id: 4, barangay: 'Maytalang I', municipality: 'Lumban', chairperson: 'Kim P. Quesada', email: 'sk.maytalang@demo.example', contact: '09171000004', status: 'active', plan: 'free', subStatus: 'free', cycle: 'none', expiresIn: null, youthCount: 26 },
  { id: 5, barangay: 'San Antonio', municipality: 'Kalayaan', chairperson: 'Renz L. Adeva', email: 'sk.sanantonio@demo.example', contact: '09171000005', status: 'pending', plan: 'free', subStatus: 'free', cycle: 'none', expiresIn: null, youthCount: 0 },
  { id: 6, barangay: 'Dorado', municipality: 'Pakil', chairperson: 'Aileen M. Palma', email: 'sk.dorado@demo.example', contact: '09171000006', status: 'active', plan: 'basic', subStatus: 'expired', cycle: 'monthly', expiresIn: -10, youthCount: 34 },
].map((o) => ({ ...o, province: 'Laguna', name: `SK ${o.barangay}, ${o.municipality}` }));

export const USERS = [
  { id: 1, name: 'System Administrator', email: 'admin@skmms.test', role: 'system_admin', orgId: null, active: true, lastLogin: 'Today' },
  ...ORGANIZATIONS.map((o, i) => ({
    id: 10 + i, name: o.chairperson, email: `sk${o.id}@demo.test`, role: 'sk_official',
    orgId: o.id, owner: true, active: true, lastLogin: i === 0 ? 'Today' : `${i * 2} days ago`,
  })),
  { id: 30, name: 'Mica Tolentino', email: 'mica@demo.test', role: 'sk_official', orgId: 1, owner: false, active: true, lastLogin: '3 days ago' },
];

/**
 * The named records the demo script walks through. They sit in SK Ibaba del Norte.
 * Jona carries an obvious Sports + Technology profile so profile matching is visible.
 */
const NAMED_YOUTH = [
  {
    firstName: 'Jona', lastName: 'Herbilla', contact: '09171234567', email: 'jona@example.com',
    gender: 'Female', birthDate: '2005-04-12',
    educationStatus: 'Currently Studying', education: 'College',
    school: 'Laguna State Polytechnic University', course: 'BSIT', yearLevel: '2nd Year', strand: '',
    interests: ['Sports', 'Technology'], skills: ['Basketball', 'Graphic Design'],
    preferredActivities: ['Sports', 'Technology'],
  },
  {
    firstName: 'Maria', lastName: 'Santos', contact: '09182345678', email: 'maria.santos@example.com',
    gender: 'Female', birthDate: '2004-09-03',
    educationStatus: 'Currently Studying', education: 'Senior High School',
    school: 'Paete National High School', course: '', yearLevel: 'Grade 12', strand: 'STEM',
    interests: ['Education & training', 'Arts'], skills: ['Public Speaking', 'Photography'],
    preferredActivities: ['Leadership', 'Arts & Culture'],
  },
  {
    firstName: 'Carlo', lastName: 'Reyes', contact: '09193456789', email: 'carlo.reyes@example.com',
    gender: 'Male', birthDate: '2003-01-25',
    educationStatus: 'Not Currently Studying', education: 'Senior High School',
    school: '', course: '', yearLevel: '', strand: '',
    interests: ['Livelihood training', 'Sports'], skills: ['Woodcarving', 'Basketball'],
    preferredActivities: ['Livelihood', 'Sports'],
  },
  {
    firstName: 'Anna', lastName: 'Cruz', contact: '09204567890', email: 'anna.cruz@example.com',
    gender: 'Female', birthDate: '2006-11-08',
    educationStatus: 'Currently Studying', education: 'Junior High School',
    school: 'Paete National High School', course: '', yearLevel: 'Grade 10', strand: '',
    interests: ['Community service', 'Health & wellness'], skills: ['Singing', 'Tutoring'],
    preferredActivities: ['Community Service', 'Health & Wellness'],
  },
  {
    firstName: 'Mark', lastName: 'Dela Cruz', contact: '09215678901', email: 'mark.delacruz@example.com',
    gender: 'Male', birthDate: '2002-06-17',
    educationStatus: 'Not Currently Studying', education: 'Graduate/Finished School',
    school: 'Laguna State Polytechnic University', course: '', yearLevel: '', strand: '',
    interests: ['Arts', 'Technology'], skills: ['Graphic Design', 'Photography'],
    preferredActivities: ['Arts & Culture', 'Technology'],
  },
];

export function buildData() {
  const youth = [];
  ORGANIZATIONS.forEach((org) => {
    for (let i = 1; i <= org.youthCount; i++) youth.push(makeYouth(org.id, i));
  });

  // Prepended so they are the newest records and appear at the top of the list.
  NAMED_YOUTH.forEach((named, index) => {
    const base = makeYouth(1, 900 + index);
    const record = {
      ...base,
      ...named,
      id: 900 + index,
      code: `YTH-${String(index + 1).padStart(3, '0')}`,
      middleName: '',
      studying: named.educationStatus === 'Currently Studying',
      address: `Purok ${index + 1}, Barangay Ibaba del Norte`,
      createdAt: iso(addDays(-(NAMED_YOUTH.length - index))),
    };
    youth.unshift({ ...record, ...evaluatePriority(record) });
  });

  const assistance = [];
  const beneficiaries = [];
  const programs = [];
  const registrations = [];
  const notifications = [];
  const payments = [];
  const activity = [];

  ORGANIZATIONS.filter((o) => o.status === 'active').forEach((org) => {
    const orgYouth = youth.filter((y) => y.orgId === org.id);

    [
      ['SK Educational Scholarship 2026', 'scholarship', 1, 'open', 20, 5000,
        'Tuition support for qualified youth residents enrolled in senior high school or college.',
        'Barangay certificate\nCertificate of enrolment\nLatest grades\nProof of family income'],
      ['Emergency Financial Assistance', 'financial', 3, 'open', 30, 2000,
        'One-time cash assistance for youth and families affected by emergencies.',
        'Barangay certificate\nValid ID\nSupporting documents'],
      ['Youth Livelihood Starter Kit', 'other', 6, 'draft', 15, 3500,
        'Starter kits for out-of-school youth beginning a small livelihood.',
        'Barangay certificate\nShort livelihood plan'],
    ].forEach(([name, category, typeId, status, slots, amount, description, requirements], i) => {
      const id = org.id * 100 + i;
      assistance.push({
        id, orgId: org.id, name, category, typeId, status, slots, amount, description, requirements,
        deadline: iso(addDays(int(14, 60))),
        requiresStudying: category === 'scholarship',
        tagInterests: category === 'scholarship' ? ['Education & training'] : [],
        tagSkills: [], tagActivities: [],
      });
      if (status !== 'open') return;
      orgYouth.slice(0, int(4, 10)).forEach((y) => {
        beneficiaries.push({
          id: newId(), orgId: org.id, programId: id, youthId: y.id,
          status: pick(['applied', 'approved', 'approved', 'released']),
          awardedOn: iso(addDays(-int(1, 30))), remarks: '',
        });
      });
    });

    [
      ['Youth Sports Festival', 'event', 'Sports & Recreation', 12, 'published', 60,
        { tagInterests: ['Sports'], tagSkills: ['Basketball', 'Volleyball'], tagActivities: ['Sports'] }],
      ['Digital Skills Workshop', 'program', 'Education & Training', 9, 'published', 40,
        { tagInterests: ['Technology'], tagSkills: ['Graphic Design', 'Basic computer'], tagActivities: ['Technology'] }],
      ['Basic Computer Literacy Workshop', 'program', 'Education & Training', -6, 'completed', 40,
        { tagInterests: ['Technology'], tagSkills: ['Basic computer'], tagActivities: ['Technology'] }],
      ['Community Clean-Up Drive', 'event', 'Environment', 25, 'published', 80,
        { tagInterests: ['Environment', 'Community service'], tagActivities: ['Community Service', 'Environment'] }],
      ['SK Leadership Seminar', 'program', 'Leadership', 40, 'draft', 50,
        { tagInterests: ['Leadership'], tagSkills: ['Public Speaking'], tagActivities: ['Leadership'] }],
    ].forEach(([name, kind, category, offset, status, max, eligibility], i) => {
      const id = org.id * 200 + i;
      programs.push({
        id, orgId: org.id, kind, name, category, status,
        ...(eligibility || {}),
        scheduledOn: iso(addDays(offset)), startsAt: '08:00', endsAt: '15:00',
        location: `Barangay ${org.barangay} Covered Court`,
        description: `Activity record for ${org.name}.`,
        maxParticipants: max, registrationDeadline: iso(addDays(Math.max(offset - 3, -9))),
      });
      if (status === 'draft') return;
      orgYouth.slice(0, Math.min(max, int(10, 28))).forEach((y) => {
        registrations.push({
          id: newId(), orgId: org.id, programId: id, youthId: y.id, status: 'registered',
          registeredAt: iso(addDays(-int(1, 20))),
          attendance: status === 'completed' ? (rand() < 0.72 ? 'present' : 'absent') : null,
        });
      });
    });

    [
      ['programs', 'Registration is filling up', 'The Barangay Youth Sports League has passed half of its participant capacity.', '/sk/programs'],
      ['scholarship', 'Scholarship applications received', 'New applications were recorded for the SK Educational Scholarship.', '/sk/assistance'],
      ['subscription', org.subStatus === 'trial' ? 'Your 7-day Premium trial has started' : 'Subscription updated',
        org.subStatus === 'trial' ? 'You have full Premium access. No payment required.' : 'Your subscription details were updated.', '/sk/subscription'],
      ['system', 'Welcome to YouthSync', 'This organization contains records for evaluation purposes.', null],
    ].forEach(([category, title, body, link], i) => {
      notifications.push({
        id: newId(), orgId: org.id, category, title, body, link,
        createdAt: iso(addDays(-i)), read: i > 1,
      });
    });

    if (org.plan !== 'free') {
      payments.push({
        id: newId(), orgId: org.id, planCode: org.plan, cycle: org.cycle === 'trial' ? 'monthly' : org.cycle,
        amount: org.plan === 'premium' ? 19990 : 999,
        method: 'demo_manual', reference: `DEMO-20260815-${org.id}A${int(1000, 9999)}`,
        status: org.subStatus === 'expired' ? 'paid' : 'paid', paidAt: iso(addDays(-int(10, 40))),
      });
    }

    ['Signed in', 'Added a youth record', 'Generated the assistance report', 'Published an activity'].forEach((action, i) => {
      activity.push({
        id: newId(), orgId: org.id, user: org.chairperson, action: pick(['login', 'create', 'report', 'edit']),
        description: `${action} for ${org.name}.`, at: `${i + 1} hour(s) ago`, ip: '203.0.113.' + int(2, 250),
      });
    });
  });


  // ---- Requirements, applications and requirement submissions -------------
  const requirements = [];
  const applications = [];
  const submissions = [];

  const eventPreset = [
    ['Barangay Certificate', 'Certificate of residency issued by the barangay.', true, 'image/*,application/pdf'],
    ['Valid ID', 'Any government or school-issued ID showing your name and photo.', true, 'image/*'],
    ['Parent Consent Form', 'Required only for participants below 18.', false, 'image/*,application/pdf'],
  ];

  const attach = (targetType, target) => {
    // Assistance requirements come from the assistance type. Events use the event preset.
    const preset = targetType === 'assistance'
      ? (ASSISTANCE_TYPES.find((x) => x.id === target.typeId)?.requirements ?? [])
      : eventPreset;

    preset.forEach(([name, description, required, accepts]) => {
      requirements.push({
        id: newId(), orgId: target.orgId, targetType, targetId: target.id,
        name, description, required, accepts,
      });
    });
  };

  programs.filter((p) => p.status !== 'draft').forEach((p) => attach('program', p));
  assistance.filter((a) => a.status === 'open').forEach((a) => attach('assistance', a));

  // A few applications already in flight, so the SK review queue is not empty.
  const seedStatuses = ['pending', 'approved', 'needs_resubmission', 'rejected'];

  ORGANIZATIONS.filter((o) => o.status === 'active').forEach((org) => {
    const orgYouth = youth.filter((y) => y.orgId === org.id);
    const targets = [
      ...programs.filter((p) => p.orgId === org.id && p.status === 'published').map((p) => ['program', p]),
      ...assistance.filter((a) => a.orgId === org.id && a.status === 'open').map((a) => ['assistance', a]),
    ];

    targets.forEach(([targetType, target], t) => {
      orgYouth.slice(t * 3, t * 3 + 4).forEach((y, i) => {
        const status = seedStatuses[(t + i) % seedStatuses.length];
        const application = {
          id: newId(), orgId: org.id, youthId: y.id, targetType, targetId: target.id,
          status, submittedAt: iso(addDays(-int(2, 15))),
          reviewedAt: ['pending'].includes(status) ? null : iso(addDays(-int(0, 2))),
          remarks: status === 'rejected' ? 'Family income exceeds the threshold for this program.' : '',
          registrationId: null,
        };

        requirements.filter((r) => r.targetType === targetType && r.targetId === target.id).forEach((req, ri) => {
          const reqStatus = status === 'pending' && ri > 1 ? 'not_submitted'
            : status === 'needs_resubmission' && ri === 1 ? 'needs_resubmission'
            : status === 'approved' ? 'accepted'
            : 'submitted';
          if (reqStatus === 'not_submitted') return;
          submissions.push({
            id: newId(), applicationId: application.id, requirementId: req.id,
            fileName: `${req.name.toLowerCase().replace(/\s+/g, '-')}.jpg`,
            fileType: 'image/jpeg', dataUrl: null, size: int(120, 900) * 1024,
            status: reqStatus, remarks: reqStatus === 'needs_resubmission' ? 'The photo is blurred. Please upload a clearer copy.' : '',
            submittedAt: iso(addDays(-int(1, 10))),
          });
        });

        if (targetType === 'program') {
          // Attendance is taken from the youth's own QR, so nothing is issued here.
          // Registration only records that they signed up.
          const registration = registrations.find((r) => r.programId === target.id && r.youthId === y.id);
          if (registration) application.registrationId = registration.id;
        }

        applications.push(application);
      });
    });
  });

  const systemLogs = [
    { id: newId(), event: 'subscription.cycle', level: 'info', message: 'Daily cycle: 1 warned, 1 expired.', at: 'Today 01:00' },
    { id: newId(), event: 'organization.registered', level: 'info', message: 'SK San Antonio, Kalayaan submitted a registration.', at: 'Yesterday 14:22' },
    { id: newId(), event: 'subscription.expired', level: 'warning', message: 'SK Dorado, Pakil subscription expired.', at: '10 days ago' },
    { id: newId(), event: 'system.seeded', level: 'info', message: 'Demo environment seeded with fictional Laguna SK organizations.', at: '30 days ago' },
  ];

  return {
    youth, assistance, beneficiaries, programs, registrations, notifications,
    payments, activity, systemLogs, requirements, applications, submissions,
    sms: [],
    emails: [],
    selections: [],
    activityLogs: [],
  };
}


/**
 * Two different codes, two different jobs.
 *
 *   Youth QR    — identifies a person. The SK scans it to take attendance.
 *   Program QR  — identifies an activity. The youth scans it to register.
 */
export const youthCode = (youth) => youth?.code || `YTH-${String(youth?.id ?? 0).padStart(3, '0')}`;

/**
 * Account status is a property of the record, not a second record.
 * A youth encoded by the SK starts as 'not_registered' and keeps the same
 * Youth ID for life; creating an account later only activates it.
 */
export const ACCOUNT_STATUSES = {
  not_registered: 'Not registered',
  active: 'Active',
  inactive: 'Inactive',
};

/** Temporary password handed to the youth at the counter. Not guessable. */
export const temporaryPassword = () => {
  const digits = String(Math.floor(100000 + Math.random() * 900000));
  return `YTS-${digits}`;
};

export const makeYouthToken = (youth) => `YSYOUTH-${youthCode(youth)}`;

export const parseYouthToken = (token) => {
  const match = /^YSYOUTH-(YTH-\d+)$/.exec(String(token || '').trim().toUpperCase());
  return match ? match[1] : null;
};

/** Only these four. No under review, no qualified / not qualified. */
export const APPLICATION_STATUSES = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  needs_resubmission: 'Resubmission requested',
};

export const REQUIREMENT_STATUSES = {
  missing: 'Missing',
  submitted: 'Submitted',
  verified: 'Verified',
  needs_resubmission: 'Needs resubmission',
};

export const SUBSCRIPTION_STATUSES = {
  pending_payment: 'Pending payment',
  active: 'Active',
  expired: 'Expired',
  payment_failed: 'Payment failed',
  cancelled: 'Cancelled',
  free: 'Free',
  trial: 'Trial',
};

export const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

export const INTEREST_OPTIONS = [
  'Sports', 'Music', 'Arts', 'Technology', 'Livelihood training', 'Community service',
  'Education & training', 'Environment', 'Leadership', 'Health & wellness',
];

export const SKILL_OPTIONS = [
  'Basketball', 'Volleyball', 'Graphic Design', 'Public Speaking', 'Basic computer',
  'Photography', 'Cooking', 'Sewing', 'Woodcarving', 'Dancing', 'Singing', 'Tutoring',
];

/** Preferred activities are picked from this fixed list, so matching is comparable. */
export const ACTIVITY_OPTIONS = [
  'Sports', 'Leadership', 'Community Service', 'Technology', 'Arts & Culture',
  'Livelihood', 'Environment', 'Health & Wellness',
];

export const EDUCATION_STATUSES = ['Currently Studying', 'Not Currently Studying'];

export const EDUCATION_LEVELS = [
  'Elementary', 'Junior High School', 'Senior High School',
  'College', 'Vocational/Technical', 'Graduate/Finished School',
];

/** Which extra fields each level needs. Anything not listed here shows none. */
export const EDUCATION_FIELDS = {
  Elementary: ['school', 'yearLevel'],
  'Junior High School': ['school', 'yearLevel'],
  'Senior High School': ['school', 'yearLevel', 'strand'],
  College: ['school', 'course', 'yearLevel'],
  'Vocational/Technical': ['school', 'course'],
  'Graduate/Finished School': ['school'],
};

export const STRANDS = ['STEM', 'HUMSS', 'ABM', 'GAS', 'TVL', 'Sports', 'Arts & Design'];

export const YEAR_LEVELS = [
  'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12',
  '1st Year', '2nd Year', '3rd Year', '4th Year',
];

/** Code printed on the activity poster. Youth scan this to register. */
export const makeProgramCode = (programId) => `YSPROG-${programId}`;

export const parseProgramCode = (code) => {
  const match = /^(?:YSPROG|SKPROG)-(\d+)$/.exec(String(code || '').trim().toUpperCase());
  return match ? Number(match[1]) : null;
};

export const SELECTION_STATUSES = {
  registered: 'Registered',
  selected: 'Selected',
  approved: 'Approved',
  present: 'Present',
};

/**
 * Youth accounts that already exist and are already OTP-verified.
 * youth1 is Jona, youth2 Maria, youth3 Carlo, so the demo script is predictable.
 */
export function buildYouthAccounts(youth) {
  const wanted = ['Herbilla', 'Santos', 'Reyes'];

  return wanted
    .map((last) => youth.find((y) => y.orgId === 1 && y.lastName === last))
    .filter(Boolean)
    .map((y, i) => ({
      id: 500 + i,
      name: `${y.firstName} ${y.lastName}`,
      email: `youth${i + 1}@demo.test`,
      mobile: y.contact,
      role: 'youth',
      orgId: y.orgId,
      youthId: y.id,
      verified: true,
      active: true,
      lastLogin: i === 0 ? 'Today' : `${i + 2} days ago`,
    }));
}
