# YouthSync SK Official — Backend Implementation Plan

**Status:** inspection only. No frontend files were modified. No PHP/MySQL backend has been created.

**Scope of this plan:** SK Official / SK User backend only. System Administrator, Admin Dashboard, Super Admin, admin approval of organizations, admin org/user management, Youth Portal APIs, youth self-registration, and youth-specific dashboards are **out of scope**. Those UIs stay in the React app but will not receive SK-owned APIs from this workstream.

**Source inspected:** `C:\xampp\htdocs\YouthSync_UI_Refresh\youth-sync` (React 18 + Vite + Tailwind). Frontend is currently 100% mock + `localStorage`.

---

## 1. Existing frontend architecture

### 1.1 Stack

| Layer | Current |
|---|---|
| UI | React 18.3, Vite 5, Tailwind 3, `react-router-dom` 6 |
| Icons / QR | `lucide-react`, `qrcode`, `html5-qrcode` |
| State | Single React context: `StoreProvider` → `MockStoreProvider` |
| Persistence | `localStorage` key `youthsync.state`, version `5` |
| Auth | Email lookup only; **password is ignored** |
| API | None. `vite.config.js` has no proxy |

### 1.2 How data flows today

```
src/main.jsx
  → BrowserRouter + StoreProvider (src/store.jsx)
    → App.jsx routes
      → pages/sk/* and components call useStore()
        → src/stores/mock.jsx mutates in-memory state
          → useEffect saveState() → src/lib/storage.js → localStorage
```

Seed data lives in `src/data/mock.js` (`buildData()`, `ORGANIZATIONS`, `USERS`, `PLANS`, `ASSISTANCE_TYPES`).

Form rules live in `src/lib/validation.js` (client-side only).

Shared SK widgets: `layouts.jsx`, `ui.jsx`, `filters.jsx`, `tags.jsx`, `education.jsx`, `eligibility.jsx`, `requirements.jsx`, `selection.jsx`, `photo.jsx`, `upload.jsx`, `qr.jsx`.

### 1.3 Routing (SK Official)

Guarded by `SkLayout`: must be logged in, `role === 'sk_official'`, org must not be missing. `mustChangePassword` redirects to `/change-password`.

| Route | Page | File |
|---|---|---|
| `/sk` | Dashboard | `pages/sk/Dashboard.jsx` |
| `/sk/youth` | Youth list | `pages/sk/Youth.jsx` → `YouthList` |
| `/sk/youth/new` | Add youth | `YouthForm` |
| `/sk/youth/import` | CSV import | `YouthImport` |
| `/sk/youth/:id` | Youth detail | `YouthDetail` |
| `/sk/youth/:id/edit` | Edit youth | `YouthForm` |
| `/sk/applications` | Application queue | `pages/sk/Applications.jsx` |
| `/sk/applications/:id` | Review | `ApplicationReview` |
| `/sk/assistance` | Assistance list | `pages/sk/Assistance.jsx` |
| `/sk/assistance/new` | Create assistance | `AssistanceForm` |
| `/sk/assistance/:id` | Detail + beneficiaries | `AssistanceDetail` |
| `/sk/assistance/:id/edit` | Edit | `AssistanceForm` |
| `/sk/programs` | Programs & events | `pages/sk/Programs.jsx` |
| `/sk/programs/new` | Create (`?kind=event\|program`) | `ProgramForm` |
| `/sk/programs/:id` | Detail + registrations | `ProgramDetail` |
| `/sk/programs/:id/edit` | Edit | `ProgramForm` |
| `/sk/attendance` | QR + manual attendance | `pages/sk/Attendance.jsx` |
| `/sk/users` | Org users | `pages/sk/Users.jsx` |
| `/sk/outbox` | Simulated SMS/email (`?tab=sms\|email`) | `pages/sk/Outbox.jsx` |
| `/sk/activity` | Audit log (plan-gated) | `pages/sk/Activity.jsx` |
| `/sk/notifications` | SK inbox | `pages/sk/Misc.jsx` → `Notifications` |
| `/sk/reports` | Reports | `Reports` |
| `/sk/subscription` | Current plan / usage | `Subscription` |
| `/sk/billing` | Billing history | `pages/sk/Billing.jsx` |
| `/sk/subscription/plans` | Plan picker | `Plans` |
| `/sk/subscription/checkout` | Simulated checkout | `Checkout` |
| `/sk/settings` | Org + account + staff | `Settings` |

Public routes used by SK officials (not Youth Portal):

| Route | Notes |
|---|---|
| `/login` | Email + password; SK goes to `/sk` |
| `/forgot-password` | UI only; no store call |
| `/change-password` | After SK-issued temporary password |
| `/register` + `/register/pending` | SK org signup UI; **not persisted**; **admin-owned, out of SK backend scope** |

Landing `/` reads `plans` from the store (static catalog). Keep serving plan catalog from SK API or a public read-only endpoint so the landing page still works without implementing admin.

### 1.4 What the SK UI actually calls on the store

All SK pages consume `useStore()`. Persistence is automatic (`saveState` on every `data` change). Operations that **write** mock state used by SK Official:

**Session:** `login`, `logout`, `changePassword`, `resetDemoData`

**Youth:** `saveYouth`, `saveYouthWithAccount`, `archiveYouth`, `restoreYouth`, `deleteYouth`, `importYouth`

**Users:** `saveUser`, `toggleUserActive`, `deleteUser`, `saveStaff`, `removeStaff`

**Requirements:** `addRequirement`, `updateRequirement`, `removeRequirement`

**Applications (SK decisions):** `reviewRequirement`, `approveApplication`, `rejectApplication`, `requestResubmission`, `setApplicationStatus`

**Selection:** `confirmSelection`

**Programs:** `saveProgram`, `setProgramStatus`, `deleteProgram`, `register`, `markAttendance`, `cancelRegistration`

**QR attendance:** `scanYouthQr`, `confirmAttendance`

**Assistance:** `saveAssistance`, `archiveAssistance`, `deleteAssistance`, `addType`, `saveBeneficiary`, `removeBeneficiary`

**Notifications / outbox:** `readNotification`, `readAllNotifications`, `deleteNotification`, `clearOutbox`

**Subscription:** `payAndActivate`, `downgrade`

**Org (SK settings):** `updateOrg`

**Reads (no dedicated action):** `scoped(key)`, `db.*`, `users`, `org`, `plan`, `usage`, `limits`, `remaining`, `canAdd`, `allows`, `qrUses` / `qrRemaining` / `canScan`, `requirementsFor`, `submissionsFor`, `targetOf`, `youthById`, `accountFor`, `accountStatusOf`

Youth-only store methods (`apply`, `submitRequirement`, `registerByProgramCode`, `startSignup`, `verifyOtp`, `updateYouthProfile`) must exist in the **same tables** because SK screens list applications, registrations, and submissions created by youth. SK backend should **read** those rows and **write** SK-side decisions; it should **not** implement youth apply/signup APIs in this workstream.

Admin-only methods (`approveOrg`, `setOrgStatus`, `setOrgPlan`, `extendOrg`, `cancelOrgSub`, `runCycle`) are out of scope. SK still **reads** org plan/status produced by whoever owns that process.

---

## 2. SK Official pages (required API operations)

Each page is org-scoped. Never return another org’s rows.

### Dashboard `/sk`

Needs aggregated stats from youth, programs, registrations, beneficiaries, assistance, notifications, plus a small recent-activity list.

Required: `GET /api/sk/dashboard` (or several list endpoints the client aggregates). Fields used: youth (`archived`, `guardianName`, `employment`, `guardianEmployment`, `level`, `birthDate`, `gender`, `education`, `createdAt`, name parts), programs (`status`, `scheduledOn`, `location`, `maxParticipants`, `name`, `id`), registrations (`status`, `attendance`, `programId`), beneficiaries (`status`, `programId`), assistance (`status`, `category`, `id`), notifications (`orgId`, `youthId`, `read`), activity (`description`, `user`, `at`).

**Mismatch:** dashboard and Settings “Activity log” read `db.activity` (seeded demo rows). The real append-only trail is `db.activityLogs`. Backend should expose one audit list; later frontend wiring should prefer `activityLogs`. Until then, map dashboard “Recent activity” to `activity_logs`.

### Youth list / detail / form / import

| UI action | API |
|---|---|
| List + filter + paginate (client currently paginates 15 locally) | `GET /api/sk/youth` |
| Detail | `GET /api/sk/youth/:id` |
| Create | `POST /api/sk/youth` |
| Create + login | `POST /api/sk/youth` with `createAccount` + email |
| Update | `PUT /api/sk/youth/:id` |
| Archive / restore | `POST /api/sk/youth/:id/archive` and `/restore` |
| Permanent delete | `DELETE /api/sk/youth/:id` |
| CSV import confirm | `POST /api/sk/youth/import` |
| Plan gate on new | `canAdd('youth')` → 403 with same copy |

### Applications

| UI | API |
|---|---|
| Queue (tabs: all / scholarship / assistance; statuses) | `GET /api/sk/applications` |
| Review | `GET /api/sk/applications/:id` |
| Verify requirement | `POST .../submissions/:id/review` `{ status: "verified" }` |
| Needs resubmission (per file) | same, `{ status: "needs_resubmission", remarks }` |
| Approve | `POST /api/sk/applications/:id/approve` |
| Reject | `POST /api/sk/applications/:id/reject` `{ reason }` |
| Request resubmission | `POST /api/sk/applications/:id/resubmit` `{ requirementIds, note }` |

### Assistance

CRUD programs, list beneficiaries/applications, record beneficiary, remove beneficiary, custom types, requirements editor.

### Programs & events

CRUD, publish/unpublish/archive, register participant, mark attendance dropdown, confirm selection, requirements, program QR payload `YSPROG-{id}`.

### Attendance

Select activity, scan `YSYOUTH-YTH-###`, confirm present, manual confirm, dropdown present/absent/excused. QR scan **counts against plan** even when identification fails (current mock `consumeQr()`).

### Users

List org users (SK official **and** youth accounts), add/edit, deactivate, delete (not owner, not self). Staff slot limit applies only when `role === 'sk_official'`.

### Outbox

Read-only list of simulated SMS/email generated by SK actions; clear by channel.

### Activity logs

`GET /api/sk/activity-logs` — gated by plan feature `activity_logs`.

### Notifications

SK inbox = `orgId` match **and** `youthId` is null. Mark one/all read, delete.

### Reports

Currently computed client-side. Backend should add `GET /api/sk/reports/{youth\|assistance\|programs\|subscription}` plus later CSV/XLS download. Print stays client-side.

### Subscription / plans / checkout / billing

Read org + plan + usage + payments. Checkout `payAndActivate`. Downgrade. Billing is presentational.

### Settings

`PATCH` org details. Account save UI currently only `notify()` — **does not persist**. Staff add via `saveStaff`. Reset demo is frontend-only and should be removed or disabled when API is live.

---

## 3. Existing mock data structures (frontend field contract)

JSON field names below are **exactly** what React reads. PHP responses should use this camelCase (or a thin mapper). Do not rename in the UI.

### 3.1 Organization (`org` / `orgs[]`)

```
id, barangay, municipality, province, chairperson, email, contact,
status,           // 'pending' | 'active' | (others block SK login)
plan,             // 'free' | 'basic' | 'premium'
subStatus,        // 'pending_payment' | 'active' | 'expired' | 'payment_failed' | 'cancelled' | 'free' | 'trial'
cycle,            // 'none' | 'monthly' | 'yearly' | 'trial'
expiresIn,        // integer days remaining, or null
youthCount,       // seed only; live usage is computed
name,             // derived: `SK {barangay}, {municipality}`
qrUses,           // integer, default 0
startedOn,        // ISO date after payment
statusNote        // admin note; SK may not need it
```

Login rules for `sk_official`: org `status === 'pending'` → “awaiting verification”; any other non-`active` → “not active. Contact the system administrator.”

### 3.2 User (staff + youth accounts in one array)

```
id, name, email, mobile, role, orgId, active, lastLogin, createdAt,
owner,                 // boolean; owner cannot be deleted
verified,              // youth OTP; SK-created youth accounts are verified: true
youthId,               // only role === 'youth'
mustChangePassword,    // SK-issued temp password
temporaryPassword,     // shown once in UI then stored in mock (must NOT be returned after create in real API)
password               // form field only; mock does not hash
```

Roles SK Users page allows: `sk_official`, `youth`.

### 3.3 Youth record

```
id, code, orgId, firstName, middleName, lastName,
birthDate,             // YYYY-MM-DD
gender,                // 'Male' | 'Female' | 'Prefer not to say'
address, contact,      // contact: exactly 11 digits
email, civilStatus,    // Single | Married | Widowed | Separated
educationStatus,       // Currently Studying | Not Currently Studying
education,             // EDUCATION_LEVELS
school, course, yearLevel, strand,
studying,              // boolean, derived from educationStatus
employment,            // Student | Employed | Unemployed | Self-employed
occupation,
guardianName, guardianEmployment, guardianOccupation,
familyIncome,          // number
familyMembers,         // number
skills, interests, preferredActivities,  // arrays of strings
previousScholarship, previousAssistance, previousParticipation,  // booleans
archived, photo, document, createdAt, addedAt,
selfRegistered,        // youth signup only
level, score, reasons  // evaluatePriority() output
```

`photo` / `document` mock shape: `{ name, type, size, dataUrl }`. Backend should store files on disk and return `{ name, type, size, url }` while keeping `dataUrl` optional for compatibility.

Youth ID / QR:

- `code`: `YTH-0001` style
- Token scanned by SK: `YSYOUTH-{code}` e.g. `YSYOUTH-YTH-001`
- Parser: `/^YSYOUTH-(YTH-\d+)$/i`

`accountStatusOf`: no user with `youthId` → `not_registered`; `active` → `active`; else `inactive`. Labels: Not registered / Active / Inactive.

Priority (`evaluatePriority`) is computed from income, household size, guardian employment, youth employment, studying, previous aid, skills. **Recommendation only.** Persist `level`, `score`, `reasons[]` or recompute on read.

### 3.4 Plans

```
id, code, name, tagline, priceMonthly, priceYearly, popular?,
limits: { youth, accounts, programs, assistance, qr },  // null = unlimited
features: {
  csv_import, excel_export, pdf_export, advanced_analytics, advanced_reports,
  activity_logs, audit_trail, automated_notifications, backup_tools, priority_support
}
```

Free / Basic / Premium values (from `mock.js`):

| | Free | Basic | Premium |
|---|---|---|---|
| youth | 20 | 250 | unlimited |
| accounts | 1 | 3 | unlimited |
| programs | 1 | unlimited | unlimited |
| assistance | 1 | 20 | unlimited |
| qr | 1 | 3 | unlimited |
| csv_import | no | yes | yes |
| excel/pdf export | no | yes | yes |
| activity_logs | no | yes | yes |
| remaining features | no | no | yes |

Expired / cancelled / payment_failed orgs use **Free limits/features** (`effectivePlan`) while `org.plan` may still show the paid name (`planName`).

Usage counts:

- youth: non-archived youth in org
- accounts: users with `role === 'sk_official'` in org
- programs: status in `draft | published | ongoing`
- assistance: status in `draft | open | full`
- qr: `org.qrUses`

### 3.5 Assistance program

```
id, orgId, name, category,   // scholarship | financial | other
typeId, status,              // draft | open | full | closed | archived
slots, amount, description,
requirements,                // newline-separated display text (legacy field, still shown)
deadline,                    // YYYY-MM-DD
requiresStudying, tagInterests, tagSkills, tagActivities,
minAge, maxAge, createdAt
```

### 3.6 Assistance type

```
id, name, category, system, orgId?,
requirements: [[name, description, required, accepts], ...]
```

System types 1–8 are global. Custom types have `system: false` and `orgId`.

When saving assistance, preset requirements are copied onto `requirements` rows with `fromType: true`. Changing `typeId` replaces those `fromType` rows.

### 3.7 Beneficiary

```
id, orgId, programId, youthId, status, awardedOn, remarks
```

Statuses used: `applied | approved | released | rejected`. Slots count `approved` + `released`.

### 3.8 Program / event

```
id, orgId, kind,             // event | program
name, category, status,      // draft | published | ongoing | completed | archived
scheduledOn, startsAt, endsAt, location, description,
maxParticipants, registrationDeadline,
tagInterests, tagSkills, tagActivities, requiresStudying, minAge, maxAge,
createdAt
```

Program QR: `YSPROG-{id}` (parser also accepts `SKPROG-{id}`). **Youth scans this** (out of SK API write scope except generating the string for SK print UI).

### 3.9 Registration

```
id, orgId, programId, youthId,
status,          // registered | waitlisted | cancelled
selected,        // boolean after Confirm Selection / approve application
registeredAt, attendance, scannedAt
```

Attendance values: `null | present | absent | excused`. Present is set only after confirm scan or manual confirm / dropdown.

### 3.10 Requirement (per opportunity)

```
id, orgId, targetType, targetId,  // targetType: program | assistance
name, description, required, accepts, fromType?
```

`accepts`: `image/*,application/pdf` | `image/*` | `application/pdf`.

### 3.11 Application

```
id, orgId, youthId, targetType, targetId,
status,          // pending | approved | rejected | needs_resubmission
submittedAt, createdAt, reviewedAt, remarks, registrationId
```

Scholarship vs assistance tabs: `targetType === 'assistance'` and `target.category === 'scholarship'` vs not.

### 3.12 Submission

```
id, applicationId, requirementId, fileName, fileType, dataUrl, size,
status, remarks, submittedAt, createdAt
```

Seed uses `submitted | accepted | needs_resubmission | not_submitted`. Review UI uses `REQUIREMENT_STATUSES`: `missing | submitted | verified | needs_resubmission`. SK review writes **`verified`**. **Mismatch:** seed `accepted` vs UI `verified`. Backend should store `verified` and map legacy `accepted` → `verified`.

Max upload: `MAX_UPLOAD_BYTES = 2MB`.

### 3.13 Notification

```
id, orgId, youthId,          // null = SK inbox
recipientUserId?, category, title, body, link, related,
createdAt, sentAt, read
```

SK categories: `scholarship | assistance | programs | applications | subscription | system`.

### 3.14 SMS / email outbox

SMS: `id, orgId, youthId, name, mobile, type, related, text, characters, status, sentAt`  
Email: `id, orgId, youthId, name, email, subject, body, type, related, status, sentAt`  
`status` today: `Simulated Sent`.

### 3.15 Activity log vs demo activity

`activityLogs`: `id, orgId, action, description, actor, role, at`  
Actions: `application | requirement | attendance | register | program | youth | user | subscription`

Seed `activity`: `id, orgId, user, action, description, at, ip` — **not** written by live SK actions except seed.

### 3.16 Payments / selections

Payment: `id, orgId, planCode, cycle, amount, method, reference, status, paidAt`  
`method`: `ewallet | bank_transfer | demo_manual`; `status`: `paid | failed`

Selection batch: `id, orgId, targetType, targetId, label, youthIds, count, verb, at`

### 3.17 Validation (must be mirrored server-side)

Youth: first/last name, birthDate not future, address, 11-digit contact, optional valid email, ≥1 interest.

User: name, required email unique, optional 11-digit mobile, password ≥8 when creating.

Program: name, scheduledOn; endsAt > startsAt; registrationDeadline ≤ scheduledOn.

Assistance: name, category.

---

## 4. Required database tables (SK-owned + shared reads)

All tenant tables include `org_id`. Use InnoDB, utf8mb4, FK `ON DELETE RESTRICT` except child rows that SK delete already cascades in mock.

**In scope to create and use:**

| Table | Purpose |
|---|---|
| `organizations` | Tenant; plan/status/qr_uses. SK can UPDATE profile fields only |
| `plans` | Catalog |
| `users` | SK staff + youth logins (youth rows needed for account status) |
| `youth` | Profiles |
| `youth_files` | Photo/document metadata |
| `assistance_types` | System + org custom |
| `assistance_programs` | Scholarships / assistance |
| `beneficiaries` | Awarded / applied |
| `programs` | Events + programs |
| `registrations` | Signup + attendance |
| `requirements` | Per program/assistance |
| `applications` | Queue |
| `submissions` | Uploaded files |
| `notifications` | In-app |
| `outbox_sms`, `outbox_email` | Simulated send log |
| `activity_logs` | Audit |
| `payments` | Billing history |
| `selections` | Confirm-selection batches |
| `sessions` or `refresh_tokens` | Auth |

**Out of SK write-scope (do not build admin/youth APIs; tables may still exist for FK integrity):**

- Admin org approval workflow
- Youth OTP signup
- Youth apply/upload/scan-program-QR endpoints

Suggested extra columns vs mock: `password_hash`, `created_at`/`updated_at` DATETIME, `expires_at` DATE instead of only `expiresIn`, file paths instead of data URLs.

Priority: either generated columns / JSON `reasons` or compute in PHP on save/read.

Youth `code` unique per org (or globally unique like seed).

---

## 5. Required API endpoints (SK Official)

Base: `https://localhost/YouthSync_UI_Refresh/api` (or `/youth-sync/api`). JSON, UTF-8. Auth required unless noted.

Convention: `{ "ok": true, "data": ... }` / `{ "ok": false, "error": "message" }` using **the same English strings** the mock `notify()` / `login()` already show.

### Auth (SK users)

| Method | Path | Notes |
|---|---|---|
| POST | `/api/auth/login` | email, password, remember?; SK only in this workstream — reject youth/admin or return them without SK session |
| POST | `/api/auth/logout` | |
| GET | `/api/auth/me` | `{ user, org, plan, planName, usage, limits, qrUses, qrRemaining, allows }` |
| POST | `/api/auth/change-password` | temp-password first login |
| POST | `/api/auth/forgot-password` | optional stub; UI exists |

Login currently calls `login(email, password)` but mock ignores password. **Real auth must verify password.** Demo seed passwords should be documented (not “any password”).

### Catalog / session extras

| GET | `/api/sk/bootstrap` | org, users-in-org (for Users page), types, plans — or split |

### Youth

| GET | `/api/sk/youth` | query: q, priority, employment, archived, sort, page |
| GET | `/api/sk/youth/:id` | include applications, beneficiaries, registrations |
| POST | `/api/sk/youth` | body = youth fields; optional `{ createAccount, accountEmail }` |
| PUT | `/api/sk/youth/:id` | |
| POST | `/api/sk/youth/:id/archive` | |
| POST | `/api/sk/youth/:id/restore` | |
| DELETE | `/api/sk/youth/:id` | also delete registrations, applications, beneficiaries, youth user |
| POST | `/api/sk/youth/import` | JSON array of validated rows (CSV parse can stay in browser) |

### Applications

| GET | `/api/sk/applications` | tab, status, q |
| GET | `/api/sk/applications/:id` | youth, target, requirements, submissions |
| POST | `/api/sk/applications/:id/approve` | |
| POST | `/api/sk/applications/:id/reject` | `{ reason }` required |
| POST | `/api/sk/applications/:id/resubmit` | `{ requirementIds, note }` |
| POST | `/api/sk/submissions/:id/review` | `{ status, remarks }` |

Approve side effects: assistance → beneficiary `approved`; program → registration `selected: true`; notify youth (when youth APIs exist, still write notification/outbox rows).

### Assistance

| GET/POST | `/api/sk/assistance` | |
| GET/PUT | `/api/sk/assistance/:id` | |
| POST | `/api/sk/assistance/:id/archive` | |
| DELETE | `/api/sk/assistance/:id` | |
| POST | `/api/sk/assistance-types` | custom type |
| POST | `/api/sk/assistance/:id/beneficiaries` | `{ youthId, status, remarks }` |
| DELETE | `/api/sk/beneficiaries/:id` | |

### Programs

| GET/POST | `/api/sk/programs` | |
| GET/PUT | `/api/sk/programs/:id` | include registrations, applications, QR code string |
| POST | `/api/sk/programs/:id/status` | `{ status }` publish fans out notifications to all non-archived youth |
| DELETE | `/api/sk/programs/:id` | |
| POST | `/api/sk/programs/:id/registrations` | `{ youthId }` waitlist if full |
| PATCH | `/api/sk/registrations/:id` | `{ attendance }` or `{ status: cancelled }` |

### Attendance / QR

| POST | `/api/sk/attendance/scan` | `{ token, programId }` — identify + increment qrUses; return pending confirm payload |
| POST | `/api/sk/attendance/confirm` | `{ registrationId }` → present + scannedAt + notify |
| POST | `/api/sk/attendance/manual` | same as confirm without consuming extra QR if SK chooses; mock consumeQr only on scan path |

### Requirements

| POST | `/api/sk/requirements` | `{ targetType, targetId, name, description, required, accepts }` |
| PATCH | `/api/sk/requirements/:id` | |
| DELETE | `/api/sk/requirements/:id` | |

### Selection

| POST | `/api/sk/selections` | `{ targetType, targetId, youthIds, verb, label }` |

### Users

| GET | `/api/sk/users` | |
| POST | `/api/sk/users` | |
| PUT | `/api/sk/users/:id` | |
| POST | `/api/sk/users/:id/toggle-active` | not self |
| DELETE | `/api/sk/users/:id` | not owner, not self |

### Notifications / outbox / logs

| GET | `/api/sk/notifications` | SK inbox only |
| POST | `/api/sk/notifications/:id/read` | |
| POST | `/api/sk/notifications/read-all` | |
| DELETE | `/api/sk/notifications/:id` | |
| GET | `/api/sk/outbox?channel=sms\|email` | |
| DELETE | `/api/sk/outbox?channel=` | clear org channel |
| GET | `/api/sk/activity-logs` | 403 if feature off |

### Dashboard / reports

| GET | `/api/sk/dashboard` | stats + charts series + upcoming + recent logs + unread count |
| GET | `/api/sk/reports/:type` | youth, assistance, programs, subscription |
| GET | `/api/sk/reports/:type/export?format=csv\|xls` | gate excel |

### Org / subscription / billing

| PATCH | `/api/sk/organization` | barangay, municipality, province, chairperson, email, contact; recompute `name` |
| GET | `/api/sk/subscription` | |
| GET | `/api/plans` | public or auth |
| POST | `/api/sk/subscription/checkout` | `{ planCode, cycle, method, outcome? }` — simulate gateway until real PSP |
| POST | `/api/sk/subscription/downgrade` | `{ planCode }` keep data; block new inserts if over limit |
| GET | `/api/sk/billing` | org + usage + payments |

### Files

| POST | `/api/sk/uploads` | multipart, 2MB, returns file metadata |

---

## 6. Authentication flow

**Chosen approach:** HttpOnly, Secure (when HTTPS), SameSite=Lax **session cookie** *or* JWT in HttpOnly cookie. Prefer PHP sessions + CSRF token for XAMPP/Apache simplicity; JWT in cookie if you need stateless PHP. Do **not** put JWT in `localStorage` (XSS + current app already overuses localStorage).

1. SK official POSTs email + password (bcrypt/`password_hash`).
2. Load `users` where `role = sk_official`.
3. Reject inactive, unverified (N/A for SK), missing org, org pending / not active — **same messages as mock**.
4. If `must_change_password`, frontend already routes to `/change-password`; API `me` must include that flag.
5. Session stores `user_id`, `org_id`, `role`.
6. Every SK request: verify session, **force `org_id` from session**, ignore client-supplied orgId.
7. Logout destroys session.
8. Remember-me: longer cookie lifetime.

Passwords: mock accepts any password. After cutover, seed hashes for `sk1@demo.test` etc. Frontend login already sends password.

---

## 7. Organization isolation

- Every SELECT/UPDATE/DELETE includes `WHERE org_id = :sessionOrg`.
- Youth QR lookup: token → code → youth **and** `org_id` match, else “No youth in your barangay matches …”.
- Cross-org IDs in URLs return 404 with the existing empty-state copy (“does not exist in this organization”).
- Custom assistance types: `system = 1` OR `org_id = session`.
- Notifications: SK list filters `youth_id IS NULL`.
- Users list: `org_id = session` only.
- File download URLs must authorize org.

Do not implement listing other organizations (admin).

---

## 8. Subscription limits

Enforce **in PHP before insert**, not only in UI (`canAdd` is already checked in forms, but `/sk/youth/new` must still 403).

On expired/cancelled/payment_failed: apply Free `limits` + `features` (`effectivePlan`).

Messages to reuse:

- Youth/accounts/programs/assistance: `You have reached the {Plan} plan limit of {n} {label}. Your existing data is untouched — upgrade your plan to add more.`
- QR: `QR scan limit reached — the {Plan} plan includes {n} scan(s). Upgrade your plan for more QR scans.`
- CSV: `CSV import is not included in the {Plan} plan`
- Import overflow: `This import contains {n} records but only {remaining} slots remain on the {Plan} plan.`
- Activity logs empty-plan: `Activity logs are not included in the {Plan} plan`
- Excel: `Excel export is not included in the {Plan} plan. Upgrade to unlock it.`

Downgrade: **never delete** youth; only block adds when `usage.youth > new limit`.

QR: increment `organizations.qr_uses` on each `scanYouthQr` attempt after plan check (including not-registered and already-present). Manual attendance dropdown does **not** increment in mock.

Publish program: does not consume QR.

---

## 9. QR / attendance flow

Two codes, two jobs (do not mix):

| Code | Who scans | Meaning |
|---|---|---|
| `YSPROG-{programId}` | Youth (out of SK write APIs) | Self-register |
| `YSYOUTH-{youth.code}` | SK Official | Identify person for attendance |

SK flow (must stay two-step):

1. SK selects activity (`published | ongoing | completed`).
2. Scan or type youth token.
3. Server: plan QR remaining; parse token; find youth in org; find non-cancelled registration for that program.
4. If not registered: consume QR, return error with youth payload.
5. If already `present`: consume QR, error with `scannedAt`.
6. If OK: consume QR, return `{ pending: true, youth, registration, program, message }` — **do not mark present yet**.
7. SK confirms → `attendance = present`, `scannedAt` timestamp `YYYY-MM-DD HH:MM`, in-app + SMS outbox to youth, activity log `attendance`.

Manual list: confirm modal then same confirm endpoint; mock does **not** consume QR for manual confirm from the list.

Dropdown `markAttendance` sets present/absent/excused without notification (mock `markAttendance` does not `dispatchToYouth`).

Registration is **not** attendance. Approving an application only sets `selected`.

---

## 10. Notification flow (SK-triggered)

`dispatchToYouth` (used by approve/reject/resubmit/selection/attendance/publish) writes:

1. `notifications` with `youthId` set (youth inbox — SK must still write these so youth UI keeps working on mock until youth backend exists).
2. `outbox_sms` if `contact` present (`YouthSync: {body}`).
3. `outbox_email` if `email` and `email !== false`.

SK-facing notices (`youthId` null): new application, requirement submitted, requirements ready, new youth signup (youth-owned), subscription copy.

Publish program: one notification **per** non-archived youth (`youthId` set) — not a single SK broadcast.

Nothing is sent to a real SMS/email gateway in this phase; `status = Simulated Sent`. Structure rows so a later worker can send them.

SK inbox UI uses `skInbox`: `orgId` match and **no** `youthId`.

---

## 11. Dashboard statistics

Compute for non-archived youth in org:

- Total youth, unique `guardianName` as “families”
- Unemployed youth / unemployed parents
- High priority count (“Youth needing assistance”)
- Scholarship beneficiaries: beneficiaries approved|released whose assistance `category === scholarship`
- Upcoming programs: status published|ongoing and `scheduledOn >= today`
- Active assistance: draft|open|full
- Charts: age buckets 15–17 / 18–24 / 25–30 (`age()` from birthDate), gender, education, employment, beneficiaries by scholarship/financial/other, youth by priority
- Program overview: registered count, present count, upcoming count
- Upcoming list (5): name, date, location, registered/max
- Recent activity (6)
- Footer: newest 3 youth names, unread SK notifications

---

## 12. Reports

Four tabs, currently client-computed. Backend should return the same numbers/series:

1. **Youth:** totals + age groups + gender/education/employment series  
2. **Assistance:** high priority count, beneficiaries by category, priority series, list of High youth + first reason  
3. **Programs:** counts by kind, registrations, unique youthIds, present, status series  
4. **Subscription:** planName, subLabel, cycle, expiresIn, payment table  

Export: CSV always (unless you choose to gate it); XLS/PDF gated. Mock export only `notify()`s. Print uses `window.print()`.

`subLabel(org)`: if active/trial and `expiresIn` in 0–3 → `expiring soon`; else `subStatus`. Banner in layout uses this.

---

## 13. CSV import

UI-owned parse/preview (`YouthImport`). Confirm sends **already mapped** objects.

Required CSV columns: `first_name, last_name, birth_date, gender, address`  
Optional: `middle_name, contact_number, education, school, family_income, family_members, interest`

Mapped fields on import (defaults):

```
civilStatus: Single, educationStatus: Currently Studying,
education default Senior High School, studying: true, employment: Student,
empty course/yearLevel/strand/guardian*, skills [], 
interests: [interest] or ['Community service'],
preferredActivities [], previous* false
```

Duplicate key: `firstName|lastName|birthDate` case-insensitive vs existing org youth and within file. Invalid/duplicate rows skipped in UI; only `valid[]` posted.

Feature flag `csv_import`. Then `canAdd('youth', n)`.

---

## 14. Payment / subscription flow

UI checkout is **simulated** (`outcome` success/fail). SK backend should:

1. Create `payments` row (`ewallet`/`bank_transfer`, unique `PAY-YYYYMMDD-XXXXXX`).
2. Fail: `status=failed`, `org.subStatus=payment_failed`, no plan change.
3. Success: `status=paid`, `plan=planCode`, `subStatus=active`, `cycle`, `expiresIn` 30 or 365, `startedOn` today. **No admin approval.**
4. Return payment object for receipt screen.

Downgrade to basic/free as mock. Free: `subStatus=free`, `cycle=none`, `expiresIn=null`.

Expiry cron (`runCycle`) is **admin/system**, out of SK scope; still store `expires_at` so SK reads remaining days. Document that another process must decrement/expire. SK banner depends on it.

Payment method “Update” is disabled in UI — no API.

---

## 15. Frontend-to-backend integration plan (later; not this task)

Do **not** restyle. Replace the data layer only.

1. Add `src/lib/api.js` (`fetch` + credentials + JSON + flash errors).
2. Add `src/stores/api.jsx` implementing the **same context shape** as `MockStoreProvider` (`user`, `org`, `db` or equivalent getters, all action names).
3. Switch `StoreProvider` in `store.jsx` via `VITE_API_URL` / `apiMode`.
4. Keep `scoped()`, `allows()`, `canAdd()` either client-side from bootstrap or as helpers wrapping GET usage.
5. Photos: upload via FormData; stop stuffing data URLs into localStorage.
6. Stop `saveState` / `loadState` when API mode is on.
7. `login(email, password)` already async — wire it.
8. Youth pages will keep using mock until a youth workstream exists; optional dual-store is messy — prefer API store that implements youth methods as 501 or keep mock for youth routes only if product requires. **Recommendation:** SK API store still *reads* youth-created applications from MySQL so SK queue is real; youth writes stay mock until their backend exists (**split-brain risk** — see issues).
9. Vite proxy `/api` → Apache PHP.
10. Leave Admin routes on mock or disconnected.

Until step 8 is solved, run SK against MySQL only after youth writes also persist, **or** accept that SK demo applications come from SQL seed not from youth UI.

---

## 16. File / folder structure (proposed; not created yet)

```
C:\xampp\htdocs\YouthSync_UI_Refresh\
  youth-sync\                 # existing Vite app
  api\                        # new PHP API (sibling so Apache serves it)
    public\index.php          # front controller
    .htaccess                 # rewrite to index.php
    config\database.php       # PDO DSN, never commit secrets
    src\
      Auth\
      Http\Router.php, Json.php, Middleware\
      Org\Tenant.php          # org_id from session
      Youth\
      Applications\
      Programs\
      Attendance\
      Assistance\
      Users\
      Notifications\
      Subscription\
      Reports\
      Files\
    sql\schema.sql
    sql\seed.sql              # Laguna demo orgs for SK login
    uploads\                  # gitignore
```

No Laravel. Plain PHP 8, PDO prepared statements, one router.

---

## 17. Implementation phases

| Phase | Work | Stop condition |
|---|---|---|
| **0** | This plan | Done when you approve |
| **1** | `schema.sql` + PDO + auth + `GET /me` + org isolation middleware | SK can log in with password; session cookie set |
| **2** | Youth CRUD, archive/restore/delete, account+temp password, CSV import, plan limits | Youth list/detail/form match mock fields |
| **3** | Programs CRUD, registrations, requirements, publish notify | Program detail works |
| **4** | Attendance scan/confirm + qr_uses | Two-step QR matches UI |
| **5** | Assistance, types, beneficiaries, eligibility tags | |
| **6** | Applications review, approve/reject/resubmit, selection | Queue + review |
| **7** | Notifications, outbox, activity_logs | |
| **8** | Dashboard + reports + export stubs | |
| **9** | Subscription checkout simulation, billing, downgrade, SK org profile | |
| **10** | SK users | |
| **11** | Frontend `api` store + proxy; freeze mock for admin/youth | Visual UI unchanged |
| **12** | Seed data + XAMPP README | |

Do not start Phase 1 until you instruct to proceed.

---

## 18. Testing plan

**Auth:** valid SK login; wrong password; inactive user; pending org; non-active org; youth/admin emails not given SK session; mustChangePassword.

**Isolation:** two orgs; IDs from org A must 404 in org B.

**Limits:** Free org at 20 youth cannot POST 21st; restore when at cap fails; CSV over remaining slots; QR 1st scan allowed, 2nd blocked on Free after one use; expired org behaves as Free.

**Youth:** validation parity; priority High/Medium; delete cascades; archive hidden from default list.

**Attendance:** invalid token; other org youth; unregistered; duplicate present; confirm then present; manual vs QR qr_uses.

**Applications:** reject without reason 400; resubmit without ids/note 400; approve assistance creates beneficiary; approve program sets selected not present.

**Publish:** N youth notifications with youthId set; SK inbox unchanged.

**Checkout:** fail vs success org fields; downgrade keeps youth over cap.

**Files:** >2MB rejected; wrong MIME rejected.

**Regression:** do not change CSS/JSX layout. Manual click-through of every `/sk/*` route after integration.

---

## 19. Potential issues / mismatches discovered

1. **Password ignored** — `login(email)` only. Backend must verify hashes; UI already sends password.
2. **Register SK org** — form does not call the store; pending page is static. Admin-owned; SK backend should not implement approval. SK login still depends on `organizations.status = active` created outside this scope (seed).
3. **`activity` vs `activityLogs`** — Dashboard/Settings show seed `activity`; live SK writes `activityLogs`. Unify on `activity_logs`.
4. **Submission status `accepted` vs `verified`** — seed vs review button. Standardize on `verified`.
5. **REQUIREMENT_STATUSES vs seed `submitted`** — map missing when no row.
6. **Users page can create `role: youth`** — creates a login without a youth profile. Decide: require `youthId` or keep orphan accounts.
7. **Settings “Save account”** — no `saveUser` call; password fields unused. Backend should add `PATCH /api/sk/account` when wiring.
8. **Settings add staff** — password input is not in `staff` state; `saveStaff` never receives password. Must fix when integrating (out of this “no frontend change” task).
9. **`saveStaff` vs accounts limit** — `saveUser` checks `sk_official` limit; staff form doesn’t pass role explicitly but `saveStaff` sets it.
10. **Program detail table** — header “Attendance” column vs extra `selected` badge column (6 td vs 5 th). UI bug, not backend.
11. **Assistance list “Applicants”** uses `beneficiaries.length` not `applications.length`.
12. **YouthSignup already references `apiMode` / `signupOrganizations`** which mock store does **not** provide. Harmless today (`apiMode` undefined). Out of SK scope.
13. **Upload.jsx comment** — “API store posts `file`, mock ignores it.” Design the API store to send `File`.
14. **Photos in localStorage** — quota warnings; backend must use disk.
15. **Publish notifies all youth** — heavy; needs batch insert.
16. **QR uses increment on failed scans** — easy to exhaust Free/Basic; preserve this behavior unless product says otherwise.
17. **effectivePlan vs planName** — billing shows `planName` from actual `org.plan` while limits come from effective Free when expired. Keep both.
18. **Youth ID lifetime** — account creation must not create a second youth row.
19. **Temp password `YTS-######` shown once** — API should return it only on create response, store hash only.
20. **Forgot-password** — no store method.
21. **Reports export** — placeholder notify; needs real files later.
22. **Dual write problem** — if SK uses MySQL and youth UI still uses localStorage, application queue will not show live youth applies. Seed SQL for demo, or delay SK cutover until youth persistence exists.
23. **`login` does not check password length / lockout.**
24. **Interests on youth form required; CSV import can default `Community service`.**
25. **`evaluatePriority` uses `skills` truthiness** — empty array is truthy in JS so “No recorded skills” rarely applies to seeded arrays. PHP should treat empty array as no skills.
26. **Admin `runCycle` and payments monitoring** — out of scope but `expiresIn` will go stale without a cron.
27. **CORS / cookie** — Vite `:5173` vs Apache `:80` needs proxy or `credentials` + Apache `Access-Control-Allow-Credentials`.
28. **Do not implement** youth OTP, youth apply, program-QR register-by-youth, admin org CRUD — even though mock.jsx contains them.

---

## 20. Explicit non-goals (reminder)

- System Administrator backend and `/admin/*` APIs  
- Admin approval / organization management / global user admin  
- Youth Portal backend, self-registration, youth dashboards  
- Laravel  
- Visual redesign of the React/Vite UI  
- Real SMS, email, or payment gateway (schema + simulated records only)

---

**End of Phase 0.** Waiting for instruction before creating PHP files, SQL, or changing any frontend source.
