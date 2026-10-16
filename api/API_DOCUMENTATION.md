# YouthSync API

Base URL: `http://localhost/YouthSync_UI_Refresh/api`

SK Official cookie session. Demo login (local only): `sk1@demo.test` / `YouthSync1!`. Responses never include `password_hash`.

Team setup: repository root `README.md`. Endpoint details below cover auth plus SK youth, programs, attendance/QR, assistance, applications, notifications, dashboard, reports, subscription, and users.

**Out of scope here:** payment gateways, cron, file uploads, Youth Portal APIs, System Administrator APIs.

Optional notification providers (not HTTP endpoints): Semaphore SMS (`SEMAPHORE_*`) and Brevo email (`BREVO_*`). Both are configuration-dependent. Empty keys skip sending without failing the request. Automated tests use fake transports and never contact the live APIs.

All responses are JSON (`Content-Type: application/json`).

Success:

```json
{ "success": true, "data": {} }
```

Error:

```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Human readable message"
  }
}
```

Authentication uses an **HttpOnly** session cookie named `youthsync_session`. After login, send the cookie on later requests (`credentials: include` or curl `-c` / `-b`). Do not send `user_id` or `organization_id` to authenticate.

`password_hash` is never included in responses.

---

## POST `/auth/login`

**Purpose:** Sign in an SK Official.

**Authentication:** Not required.

**Request:**

```json
{
  "email": "sk1@demo.test",
  "password": "YouthSync1!"
}
```

**Success — 200:**

```json
{
  "success": true,
  "data": {
    "user": {
      "id": 1,
      "email": "sk1@demo.test",
      "first_name": "Ariel",
      "last_name": "Baldemor",
      "status": "active",
      "must_change_password": false,
      "last_login_at": "2026-09-18 23:00:00"
    },
    "organization": {
      "id": 1,
      "name": "SK Ibaba del Norte, Paete",
      "barangay": "Ibaba del Norte",
      "municipality": "Paete",
      "province": "Laguna",
      "chairperson": "Ariel B. Baldemor",
      "email": "sk.ibabadelnorte@demo.example",
      "contact": "09171000001",
      "status": "active",
      "subscription": {
        "plan": "premium",
        "status": "trial",
        "cycle": "trial",
        "expires_at": "2026-09-20",
        "youth_count": 0
      }
    },
    "role": "SK_OFFICIAL"
  }
}
```

Sets a new session cookie. The session id is regenerated on success. Organization comes from `organization_users`, not from the request body.

**Errors:**

| HTTP | Code | When |
|---|---|---|
| 400 | `INVALID_JSON` | Body is not JSON |
| 401 | `INVALID_CREDENTIALS` | Unknown email or wrong password (same message) |
| 403 | `ACCOUNT_INACTIVE` | User status is not active |
| 403 | `FORBIDDEN` | Role is not `SK_OFFICIAL` |
| 403 | `ORGANIZATION_REQUIRED` | No active organization membership |
| 403 | `ORGANIZATION_PENDING` | Organization status is `pending` |
| 403 | `ORGANIZATION_INACTIVE` | Organization is not active |
| 422 | `VALIDATION_ERROR` | Missing/invalid email or password |
| 405 | `METHOD_NOT_ALLOWED` | Not POST |
| 500 | `SERVER_ERROR` | Unexpected failure |

---

## POST `/auth/logout`

**Purpose:** End the current session and clear the session cookie.

**Authentication:** Not required (idempotent). If a session exists, it is destroyed.

**Request:** empty body.

**Success — 200:**

```json
{
  "success": true,
  "data": { "logged_out": true }
}
```

**Errors:**

| HTTP | Code | When |
|---|---|---|
| 405 | `METHOD_NOT_ALLOWED` | Not POST |
| 500 | `SERVER_ERROR` | Unexpected failure |

---

## GET `/auth/me`

**Purpose:** Return the authenticated SK Official and their organization.

**Authentication:** Required (session cookie). Role must be `SK_OFFICIAL`. Organization is taken from the server-side session and membership row.

**Request:** none.

**Success — 200:** same `user` / `organization` / `role` shape as login.

**Errors:**

| HTTP | Code | When |
|---|---|---|
| 401 | `UNAUTHENTICATED` | No session, unknown user, or inactive user |
| 403 | `FORBIDDEN` | Not SK Official, or session organization does not match membership |
| 403 | `ORGANIZATION_REQUIRED` | Membership missing |
| 403 | `ORGANIZATION_INACTIVE` | Organization not active |
| 405 | `METHOD_NOT_ALLOWED` | Not GET |
| 500 | `SERVER_ERROR` | Unexpected failure |

---

## POST `/auth/change-password`

**Purpose:** Replace the authenticated user’s password. Clears `must_change_password`.

**Authentication:** Required. SK Official + organization membership, same as `/auth/me`.

**Request:**

```json
{
  "current_password": "YouthSync1!",
  "new_password": "NewPass123"
}
```

New password must be at least 8 characters and different from the current password.

**Success — 200:**

```json
{
  "success": true,
  "data": { "password_changed": true }
}
```

The new hash is never returned.

**Errors:**

| HTTP | Code | When |
|---|---|---|
| 401 | `UNAUTHENTICATED` | No valid session |
| 401 | `INVALID_CREDENTIALS` | Current password is wrong |
| 403 | (same as `/me`) | Role or organization checks fail |
| 422 | `VALIDATION_ERROR` | Missing fields, too short, or same as current |
| 405 | `METHOD_NOT_ALLOWED` | Not POST |
| 500 | `SERVER_ERROR` | Unexpected failure |

Forgot-password is **not** implemented in Phase 1.

---

# Phase 2 — Youth management (SK Official)

All Youth endpoints require the SK Official session cookie. Organization is taken from the **session**, never from `organization_id` / `orgId` in the body, query string, or URL.

Validation errors use HTTP 422:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "First name is required.",
    "fields": { "firstName": "First name is required." }
  }
}
```

Missing records in the caller’s organization return 404:

`This youth record does not exist in this organization.`

## Youth JSON fields

`id`, `code` (`YTH-0001`), `qrToken` (`YSYOUTH-YTH-0001`), `firstName`, `middleName`, `lastName`, `birthDate`, `gender`, `address`, `contact`, `email`, `civilStatus`, `educationStatus`, `education`, `school`, `course`, `yearLevel`, `strand`, `studying`, `employment`, `occupation`, `guardianName`, `guardianEmployment`, `guardianOccupation`, `familyIncome`, `familyMembers`, `skills`, `interests`, `preferredActivities`, `previousScholarship`, `previousAssistance`, `previousParticipation`, `archived`, `selfRegistered`, `level`, `score`, `reasons`, `accountStatus` (`not_registered` | `active` | `inactive`), `createdAt`, `addedAt`, `photo`, `document`.

`photo` and `document` are always `null` in Phase 2 (no file-upload API yet). Detail does **not** include applications, programs, or assistance (later phases).

Required on create/update: first name, last name, birth date (not future, `YYYY-MM-DD`), address, 11-digit contact, at least one interest. Email is optional but must be valid when present.

## GET `/sk/youth`

**Auth:** SK Official.

Query: `q`, `priority` (`High`|`Medium`), `employment`, `archived` (`true`/`false`, default active), `sort` (`newest`|`oldest`|`name`), `page`, `perPage` (default 15).

**Success — 200:** `{ items, page, perPage, total, pages, usage }`.

## GET `/sk/youth/{id}`

**Auth:** SK Official. **Success — 200.** Unknown / other-org id — **404**.

## POST `/sk/youth`

**Auth:** SK Official. **Success — 201.**

```json
{
  "firstName": "Ana",
  "lastName": "Cruz",
  "birthDate": "2005-04-12",
  "gender": "Female",
  "address": "Purok 1, Barangay Ibaba del Norte",
  "contact": "09171234567",
  "interests": ["Community service"],
  "createAccount": false
}
```

Optional `createAccount: true` with `accountEmail` creates a hashed youth **user row** and returns `temporaryPassword` once (`YTS-######`). This is not a youth login API.

Plan limit (non-archived youth): **403** `PLAN_LIMIT_REACHED`. Duplicate first+last+birth in the same org: **409** `CONFLICT`. Sending `organization_id` is ignored.

## PUT / PATCH `/sk/youth/{id}`

**Auth:** SK Official. **Success — 200** with the updated youth. Cannot move the record to another organization.

## POST `/sk/youth/{id}/archive`

**Success — 200.** Soft-delete (`archived: true`). Hidden from the default list.

## POST `/sk/youth/{id}/restore`

**Success — 200.** Blocked with **403** `PLAN_LIMIT_REACHED` when restoring would exceed the plan.

## DELETE `/sk/youth/{id}`

**Success — 200** `{ "deleted": true }`. Hard delete. Also removes a linked youth user if one exists. Then GET returns 404.

## POST `/sk/youth/import`

**Auth:** SK Official. Body: a JSON array of youth objects, or `{ "rows": [ ... ] }`. CSV parsing stays in the client.

Free plan (or expired orgs using Free limits): **403** `PLAN_FEATURE` (`CSV import is not included in the {Plan} plan`). Overflow: **403** `PLAN_LIMIT_REACHED`.

Empty interests default to `["Community service"]`.

---

# Phase 3 — Programs & events (SK Official)

Programs and events are **the same activity record**, distinguished by `kind`: `program` or `event`. There is no separate events table and no child-event rows. Organization always comes from the session. `organization_id` in the body is ignored.

Auth: SK Official session cookie. Missing/other-org records: **404**.

Statuses: `draft`, `published`, `ongoing`, `completed`, `archived`.  
Active toward the plan limit: `draft`, `published`, `ongoing`.  
Free plan: **1** active activity. Basic/Premium: unlimited. Expired orgs use Free limits. **403** `PLAN_LIMIT_REACHED`.

Duplicate in the same org (`kind` + name + `scheduledOn`): **409** `CONFLICT`.

QR string on the payload is `YSPROG-{id}` (print only). Attendance/QR scan is Phase 4. Publish does **not** send notifications (Phase 7). Registrations are not implemented.

## Fields

`id`, `kind`, `name`, `category`, `status`, `scheduledOn`, `startsAt`, `endsAt`, `location`, `description`, `maxParticipants`, `registrationDeadline`, `tagInterests`, `tagSkills`, `tagActivities`, `requiresStudying`, `minAge`, `maxAge`, `qrCode`, `createdAt`.

Required: `name`, `scheduledOn` (`YYYY-MM-DD`). End time must be after start time. Registration deadline must be on or before the activity date.

## Program routes

| Method | Path |
|---|---|
| GET | `/sk/programs` query: `kind`, `status`, `q`, `page` |
| POST | `/sk/programs` **201** |
| GET | `/sk/programs/{id}` |
| PUT/PATCH | `/sk/programs/{id}` |
| DELETE | `/sk/programs/{id}` |
| POST | `/sk/programs/{id}/status` `{ "status": "published" }` |
| POST | `/sk/programs/{id}/archive` |

## Event aliases

Same rows with `kind=event`:

| Method | Path |
|---|---|
| GET/POST | `/sk/events` |
| GET/PUT/PATCH/DELETE | `/sk/events/{id}` |
| GET | `/sk/programs/{id}/events` — 404 if `{id}` is not in this org; then lists **org events** (not child rows) |
| POST | `/sk/programs/{id}/events` — 404 if `{id}` is not in this org; then creates an independent event in this org |

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_programs.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\programs_http_test.php
```

---

# Phase 4 — Attendance & QR (SK Official)

Attendance records **presence at an activity**. It is not youth login, not youth self-registration, and not program registration.

Organization always comes from the **session**. `organization_id` / `orgId` in the body, query string, or URL is ignored.

### QR types (do not mix)

| Code | Who uses it | Meaning in Phase 4 |
|---|---|---|
| `YSPROG-{programId}` | Printed on the activity | Program identifier only. Youth registration via this QR is **not** implemented. |
| `YSYOUTH-{youth.code}` e.g. `YSYOUTH-YTH-0001` | SK Official scans | Identifies a youth in **this** organization for attendance. |
| Attendance session token | SK Official API | Cryptographically random secret returned **once** when a session is created. Stored as SHA-256. `GET` session returns `publicCode` only, not the secret. |

### Workflow

1. SK publishes (or uses `ongoing` / `completed`) an activity in their organization.
2. `POST /sk/programs/{id}/attendance/session` starts a session (closes any previous active session for that activity).
3. `POST /sk/attendance/scan` with `{ token, sessionToken }` identifies the youth. Plan QR uses are incremented after the youth is found in this org. Status becomes `pending` — **not** present yet.
4. `POST /sk/attendance/confirm` with `{ attendanceId }` sets `present` and timestamps.
5. `GET /sk/programs/{id}/attendance` lists records for that activity.

Duplicate present scans return **409**. Invalid Youth QR format: **422**. Youth not in this barangay: **404**. Expired/closed session: **422**. Other-org activity or session: **404** (same isolation style as Phase 2/3). Draft/archived activities cannot take attendance (**422**).

QR scan limits (existing plans): Free **1**, Basic **3**, Premium unlimited. Expired/cancelled/payment_failed orgs use Free limits. Manual confirm/list marking does **not** consume a QR scan. **403** `PLAN_LIMIT_REACHED` when the scan quota is exhausted.

Attendance records are organization-scoped. There is no bulk delete API; records are treated as durable (status may be corrected with `PATCH`).

## POST `/sk/programs/{id}/attendance/session`

**Auth:** SK Official. **Success — 201.**

Body may be empty. `organization_id` is ignored.

**Success data:** `id`, `publicCode`, `programId`, `status`, `expiresAt`, `createdAt`, `program`, `qrRemaining`, and `token` (secret, create only).

## GET `/sk/programs/{id}/attendance/session`

**Auth:** SK Official. **Success — 200** for an unexpired active session. **404** if none, or if the activity is not in this org. Does **not** return `token` or `token_hash`.

## GET `/sk/programs/{id}/attendance`

**Auth:** SK Official. **Success — 200** `{ items, program }`. Each item includes youth, activity, `status`, `source` (`qr` \| `manual`), `scannedAt`, `confirmedAt`. **404** for other-org activities.

## GET `/sk/attendance/{id}`

**Auth:** SK Official. **Success — 200.** Other-org id: **404**.

## POST `/sk/attendance/scan`

**Auth:** SK Official.

```json
{
  "token": "YSYOUTH-YTH-0001",
  "sessionToken": "<secret from session create>",
  "programId": 1
}
```

`programId` is optional when `sessionToken` is present; if both are sent they must match. **201** on first pending record, **200** if a pending row already exists.

## POST `/sk/attendance/confirm`

**Auth:** SK Official. `{ "attendanceId": 1 }` → `present`. **409** if already present. Does not consume QR.

## POST `/sk/attendance/manual`

**Auth:** SK Official. `{ "programId", "youthId" }` creates/returns pending **without** consuming QR. Then confirm as above.

## PATCH `/sk/attendance/{id}`

**Auth:** SK Official. `{ "status": "present" | "absent" | "excused" }`. Does not consume QR. **409** if already `present` and status is `present` again.

## Errors

| HTTP | Code | When |
|---|---|---|
| 401 | `UNAUTHENTICATED` | No SK session |
| 403 | `FORBIDDEN` / `PLAN_LIMIT_REACHED` | Not SK Official, or QR scan quota reached |
| 404 | `NOT_FOUND` | Activity, session, youth QR, or attendance not in this organization |
| 409 | `CONFLICT` | Attendance already recorded as present |
| 422 | `VALIDATION_ERROR` / `SESSION_EXPIRED` | Bad Youth QR, ineligible activity, expired session |

Password hashes, `token_hash`, and database credentials are never returned.

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_attendance.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\attendance_http_test.php
```

---

# Phase 5 — Assistance (SK Official)

SK Official management of assistance programs (scholarships, financial assistance, and other types). This is **not** the youth application/review queue (Phase 6). Organization always comes from the session. `organization_id` / `orgId` in the body is ignored.

Active toward the plan limit: `draft`, `open`, `full`.  
Free: **1** active assistance program. Basic: **20**. Premium: unlimited. Expired orgs use Free limits. **403** `PLAN_LIMIT_REACHED`. Phase 4 QR limits are unchanged.

Duplicate name in the same organization: **409**. Missing/other-org records: **404**.

System assistance types (ids 1–8) are global. Custom types belong to the session organization only.

When an assistance program is created (or its `typeId` changes), preset requirements from that type are copied onto the program (`fromType: true`). Extra requirements can be added with `POST /sk/requirements` (`targetType` must be `assistance`).

Beneficiaries are recorded by the SK (`applied` | `approved` | `released` | `rejected`). Slots count `approved` + `released`. Unique per youth per program (upsert on save). Archived youth: **422**. Youth in another organization: **404**.

## Fields

Assistance: `id`, `name`, `category` (`scholarship` | `financial` | `other`), `typeId`, `status` (`draft` | `open` | `full` | `closed` | `archived`), `slots`, `amount`, `description`, `requirements` (legacy newline text), `deadline`, `requiresStudying`, `tagInterests`, `tagSkills`, `tagActivities`, `minAge`, `maxAge`, `createdAt`, `beneficiaryCount`, `slotsFilled`. Detail also includes `requirementItems` and `beneficiaries`.

Required on create: `name`, `category`.

## Routes

| Method | Path |
|---|---|
| GET/POST | `/sk/assistance` POST **201** |
| GET/PUT/PATCH | `/sk/assistance/{id}` |
| POST | `/sk/assistance/{id}/archive` |
| DELETE | `/sk/assistance/{id}` |
| GET/POST | `/sk/assistance-types` POST **201** (custom type) |
| POST | `/sk/assistance/{id}/beneficiaries` `{ youthId, status, remarks }` |
| DELETE | `/sk/beneficiaries/{id}` |
| POST | `/sk/requirements` `{ targetType: "assistance", targetId, name, description, required, accepts }` |
| PATCH/DELETE | `/sk/requirements/{id}` |

## Errors

| HTTP | Code | When |
|---|---|---|
| 401 | `UNAUTHENTICATED` | No SK session |
| 403 | `FORBIDDEN` / `PLAN_LIMIT_REACHED` | Not SK Official, or assistance quota reached |
| 404 | `NOT_FOUND` | Program, type, beneficiary, requirement, or youth not in this organization |
| 409 | `CONFLICT` | Duplicate assistance name or type name |
| 422 | `VALIDATION_ERROR` | Missing/invalid fields, slots full, archived youth |

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_assistance.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\assistance_http_test.php
```

---

# Phase 6 — Applications (SK Official)

SK Official queue for **assistance program** applications. Youth self-apply APIs, program applications, notifications, and selection batches are not implemented.

Organization always comes from the session. `organization_id`, `orgId`, and `reviewed_by` in JSON are ignored. `reviewedBy` is the authenticated SK user.

One application per youth + assistance program (**409** `CONFLICT` on duplicate). Statuses: `pending`, `approved`, `rejected`, `withdrawn`, `needs_resubmission`.

Apply only when the program is `open` or `full`. Draft/closed/archived: **422**. Archived youth: **422**. Other-org youth/program: **404**. Past deadline: **422**.

Approving calls Phase 5 beneficiary save (`approved`). Slot-full and other Phase 5 rules return **422** and leave the application unchanged. Rejecting an approved application sets the beneficiary to `rejected` so the slot is freed. Applications are not deleted on reject/withdraw.

Submission review statuses: `missing`, `submitted`, `verified`, `needs_resubmission`. File fields store a name/ref only — no filesystem paths.

## Routes

| Method | Path | Success |
|---|---|---|
| GET | `/sk/applications` query: `assistanceId`, `youthId`, `status`, `q`, `page`, `perPage` | **200** `{ items, page, perPage, total, pages }` |
| POST | `/sk/applications` `{ assistanceId, youthId, remarks?, submissions? }` | **201** |
| GET | `/sk/applications/{id}` | **200** youth, assistance, submissions, review |
| PATCH | `/sk/applications/{id}` `{ remarks }` | **200** |
| POST | `/sk/applications/{id}/status` `{ status, remarks? }` | **200** |
| POST | `/sk/applications/{id}/approve` | **200** (same as status `approved`) |
| POST | `/sk/applications/{id}/reject` `{ remarks` or `reason }` required | **200** |
| PATCH | `/sk/application-requirements/{id}` `{ status, remarks }` | **200** |
| POST | `/sk/submissions/{id}/review` | **200** (same review handler) |

Reject without a reason: **422**. Invalid status: **422**.

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_applications.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\applications_http_test.php
```

---

# Phase 7 — Notifications (SK Official)

Organization-scoped in-app inbox for the authenticated SK Official. Optional Semaphore SMS and Brevo email follow the same application events when configured. Notification read/delete actions are written to the activity log. SMS and email history are on the Outbox page.

SK list returns rows for the session organization where `youth_id` is null (SK inbox) and `user_id` is null (org-wide) or equal to the authenticated user. Youth-targeted rows are stored for application review events but are not listed on `/sk/notifications`.

Organization and recipient are taken from the session. `organization_id`, `user_id`, and `created_by` in JSON are ignored.

Internal creates go through `NotificationService` (no public create endpoint). Application create writes an SK inbox notice. Approve / reject / needs-resubmission write youth-targeted notices only.

## Routes

| Method | Path | Success |
|---|---|---|
| GET | `/sk/notifications` query: `page`, `perPage`, `unreadOnly`, `readOnly`, `type`, `q` | **200** `{ items, page, perPage, total, pages, unreadCount }` |
| GET | `/sk/notifications/{id}` | **200** |
| PATCH | `/sk/notifications/{id}/read` | **200** (`is_read`, `read_at`) |
| POST | `/sk/notifications/{id}/read` | **200** (same as PATCH) |
| POST | `/sk/notifications/read-all` | **200** `{ updated }` |
| DELETE | `/sk/notifications/{id}` | **200** `{ deleted: true }` |
| GET | `/sk/deliveries/sms` query: `page`, `perPage` (max 100), `q` | **200** `{ items, page, perPage, total, pages, providerConfigured }` |
| GET | `/sk/deliveries/email` query: `page`, `perPage` (max 100), `q` | **200** `{ items, page, perPage, total, pages, providerConfigured }` |

Unauthenticated: **401**. Non-SK Official: **403**. Other organization or youth-targeted id: **404**.

Delivery history (`sms_deliveries` / `email_deliveries`) is read-only and scoped to the session organization; `organization_id` / `orgId` query parameters are ignored. `status` is returned exactly as stored (`pending`, `sent`, `failed`). `sent` means the provider accepted the request; it is not a delivery receipt. `sentAt` is set only when the provider accepted the message (null for pending/failed rows). `errorMessage` is the stored failure reason. `providerConfigured` says whether this server currently has the provider configured; API keys are never returned. When Brevo is not configured, email attempts are skipped and no `email_deliveries` row is written.

Item fields: `id`, `type` / `category`, `title`, `message` / `body`, `link`, `related`, `relatedEntityType`, `relatedEntityId`, `isRead`, `readAt`, `createdAt`, `userId`, `youthId`.

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_notifications.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\notifications_http_test.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\notification_history_http_test.php
```

---

# Phase 8 — Dashboard & Reports (SK Official)

Read-only aggregates for the authenticated SK Official's organization. No new tables. `organization_id` / `orgId` query parameters are ignored. The five reports (youth, programs, assistance, applications, attendance) can be downloaded as CSV or Excel (`.xlsx`) — see **Reports** below. PDF and subscription/payment reports are not implemented (the SK Reports page offers browser Print). The audit trail is served by `GET /sk/activity` (see Activity logs).

There is no `registrations` table. A program/event registration is an `applications` row with a `program_id` (created by `/youth/programs/register`). Attendance totals use the `attendance` table only (`present`, `absent`, `excused`, `pending`).

The SK dashboard page reads only `GET /sk/dashboard`. Organization and user always come from the session; `organization_id` / `orgId` / `organizationId` / `org_id` / `user_id` / `userId` in the query, body or headers are ignored. Definitions:

| Field | Definition |
|---|---|
| `summary.youth.active` / `archived` / `total` | youth rows with `archived = 0` / `1` / all |
| `summary.programs.programs` / `events` | `programs.kind = 'program'` / `'event'` (all statuses) |
| `summary.programs.upcoming`, `highlights.upcomingPrograms` | programs **and events** with status `published`/`ongoing` and `scheduled_on >= CURDATE()` |
| `summary.applications.programRegistrations` | applications with `program_id`, status not `rejected`/`withdrawn` |
| `summary.applications.assistanceApplications` | applications with `assistance_id` |
| `summary.assistance.active`, `highlights.activeAssistance` | assistance programs in `draft`, `open` or `full` (same as plan limits) |
| `highlights.families` | distinct non-blank `guardian_name` among active youth |
| `highlights.scholarshipBeneficiaries` | beneficiaries `approved`/`released` of `scholarship` assistance |
| `summary.notifications.unread` | the SK inbox unread count (same rule as `GET /sk/notifications`) |
| `assistance.beneficiariesByCategory` | all beneficiary rows by category (any status) |
| `assistance.awardedBeneficiariesByCategory` | `approved` + `released` beneficiaries by category |
| `programs.upcoming[].registeredCount` / `presentCount` | live registrations / attendance marked present for that program |
| `youth.*` breakdowns | active (non-archived) youth |

Recent activity is derived from existing youth, programs, applications, attendance, and SK inbox notification rows (newest 6; notifications addressed to another SK user are excluded). It is not a separate audit log.

## Routes

| Method | Path | Success |
|---|---|---|
| GET | `/sk/dashboard` | **200** summary, highlights, breakdowns, upcoming programs, recent, newest youth |
| GET | `/sk/reports?type=` `youth` \| `programs` \| `assistance` \| `applications` \| `attendance` | **200** |
| GET | `/sk/reports/youth` (and `/programs`, `/assistance`, `/applications`, `/attendance`) | **200** (same as `?type=`) |
| GET | `/sk/reports/{type}/csv` for `youth`, `programs`, `assistance`, `applications`, `attendance` | **200** CSV file download |
| GET | `/sk/reports/{type}/xlsx` (same five types) | **200** Excel workbook download (Basic/Premium plans; Free → **403** `PLAN_FEATURE_UNAVAILABLE`) |
| GET | `/admin/dashboard` | **200** System Administrator only (**401** anonymous, **403** SK/youth). Deployment-wide SQL aggregates: `organizations` (total, pending, byStatus, byPlan, bySubscriptionStatus, activeSubscriptions = sub_status active/trial, expiringSoon = active/trial with `expires_at` between CURDATE() and CURDATE()+3, expired, trial, freePlan, basicActive, premiumActive), `users` (total, active, byRole), `youth` (total, active, archived), `programs` (total, programs, events, byStatus), `applications` (total, byStatus), `payments` (PayMongo-verified revenue only: `source: "paymongo_verified"`, `onlineVerified` = verified paid / partially refunded transactions net of refunds in PHP, `onlineVerifiedCount`, `onlineVerifiedByPlan`), `youthPerOrganization` (every organization, including those with 0 youth: `youth` = active, `archivedYouth`, `totalYouth`; LEFT JOIN + GROUP BY) |

Report query filters (all optional except `type` on `/sk/reports`): `from`, `to` (`YYYY-MM-DD`), `status`, `category`, `kind`. Invalid type/date/status/kind/category: **422**.

Unauthenticated: **401**. Non-SK Official: **403**.

## Reports

`GET /sk/reports/{type}` (or `/sk/reports?type=`) returns chart aggregates; `GET /sk/reports/{type}/csv` and `/xlsx` return one row per record. JSON and files use the same `DashboardService` filter scope (one WHERE clause per type), so for identical filters `totals.total` = `export.rowCount` = the number of data rows in the CSV and the XLSX. The organization is always the session organization; `organization_id` / `orgId` / `organizationId` / `org_id` / `user_id` / `role` in the query, body or headers are ignored.

Filters that do not apply to a type are ignored and echoed as `null` in `filters`.

| Type | Date filter (`from`/`to`) on | `status` | other filter | JSON `totals` | JSON `series` |
|---|---|---|---|---|---|
| `youth` | `DATE(youth.created_at)` | `active` \| `archived` (default: both) | — | `total`, `active`, `archived` | `ageGroups` (15-17, 18-24, 25-30, other), `gender`, `education`, `employment`, `priority`; plus `highPriorityYouth` (≤ 50: id, firstName, lastName, priorityLevel, firstReason) |
| `programs` | `programs.scheduled_on` | draft, published, ongoing, completed, archived | `kind` = `program` \| `event` | `total`, `programs`, `events`, `registrations` (program applications not `rejected`/`withdrawn`), `participants` (distinct youth among them), `presentAttendance` (attendance `present`) | `byStatus`, `byKind` |
| `assistance` | `DATE(assistance_programs.created_at)` | draft, open, full, closed, archived | `category` = `scholarship` \| `financial` \| `other` | `total`, `beneficiaries` (all statuses), `awarded` (`approved` + `released`) — beneficiaries of the assistance programs in the filtered set | `byStatus`, `byCategory`, `beneficiariesByStatus`, `awardedByCategory`; plus `slots[]` (id, name, category, status, slots, filled = awarded, available) for the same programs |
| `applications` | `DATE(applications.submitted_at)` | pending, approved, rejected, withdrawn, needs_resubmission | `kind` = `program` \| `event` \| `assistance` (what the application is for) | `total` | `byStatus`, `byTarget` (`program`, `event`, `assistance`) |
| `attendance` | `DATE(COALESCE(confirmed_at, created_at))` | pending, present, absent, excused | `kind` = `program` \| `event` | `total`, `present` | `byStatus`, `byKind` (attendance joined to a program of the same organization) |

Every JSON report also has `export`: `{ rowCount, columns: [header labels], formats: ["csv", "xlsx"] }`.

### Export columns

| Type | Columns (one row per record) |
|---|---|
| `youth` | Code, Last name, First name, Middle name, Birth date, Age, Gender, Civil status, Address, Contact number, Education status, Education level, Employment, Guardian, Priority, Status (active/archived), Date added |
| `programs` | ID, Kind, Name, Category, Status, Scheduled on, Starts, Ends, Location, Max participants, Registration deadline, Registrations, Present |
| `assistance` | ID, Name, Category, Status, Slots, Amount, Deadline, Beneficiaries, Awarded (approved + released), Available slots, Created |
| `applications` | ID, Submitted, Status, Youth code, Youth name, Applied for (program/event/assistance), Program / assistance, Reviewed, Remarks |
| `attendance` | ID, Program / event, Kind, Scheduled on, Youth code, Youth name, Status, Source, Scanned at, Confirmed at |

Family income, e-mail addresses, account data and priority reasons are not exported. Sums of the per-row count columns equal the JSON totals (e.g. Σ Registrations = `totals.registrations`, Σ Awarded = `totals.awarded`).

### File format

- Headers: `Content-Type: text/csv; charset=utf-8` or `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`; `Content-Disposition: attachment; filename="{type}_report_YYYY-MM-DD.csv|xlsx"` (date in Asia/Manila); `Content-Length`; `Cache-Control: no-store, private`; `X-Content-Type-Options: nosniff`; `Access-Control-Expose-Headers: Content-Disposition`. The body is the file only (no JSON wrapper).
- CSV: UTF-8 with BOM, CRLF line endings, a header row then data rows. Fields containing a comma, quote, CR or LF are quoted and quotes are doubled. Formula-injection guard: a text cell starting with `=`, `+`, `-`, `@`, tab or CR gets a leading `'`. Numeric columns are plain numbers.
- XLSX: one worksheet named after the report (e.g. `Youth Report`). Row 1 = title with the organization name, row 2 = `Generated YYYY-MM-DD HH:MM (Asia/Manila)`, row 3 = applied filters and row count, row 4 = bold header (frozen, auto filter), rows 5+ = data. Text uses shared strings (never formulas); count/amount columns are numeric cells. Written by `YouthSync\Reports\XlsxWriter` (pure PHP, its own ZIP writer, deflate via zlib `gzdeflate` when available): no Composer package and no `zip` extension needed.
- Errors stay JSON: **401** anonymous, **403** non-SK Official, **403** `PLAN_FEATURE_UNAVAILABLE` for XLSX on the Free plan (`GET /sk/subscription` → `features.excelExport`), **404** `NOT_FOUND` for an unknown type or format (e.g. `/sk/reports/subscription/csv`, `/sk/reports/youth/pdf`), **422** for invalid filters.

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\dashboard_reports_http_test.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync\api\sql\dashboard_statistics_http_test.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync\api\sql\reports_http_test.php
```

`reports_http_test.php` defaults to `http://localhost/YouthSync/api` (override with `YOUTHSYNC_API_BASE`). It compares every report total and series with direct SQL (HTTP for sk1/sk2, `DashboardService` for every other organization with data), downloads every CSV and XLSX, parses them (CSV with `fgetcsv`; XLSX unzipped by a built-in reader, then sheet XML and shared strings), checks headers, filenames, row counts and that CSV and XLSX values match. It covers role protection, organization/user spoofing (query, body, headers), unknown types/formats, invalid filters, empty reports, sensitive fields and the Free-plan XLSX rule (when `sk4@demo.test` exists). It creates one temporary youth (comma, quote, newline, leading `=`) and one draft program through the API, checks escaping and the +1 / -1 counts, and deletes those exact ids. It submits no application and sends no SMS or email.

`dashboard_statistics_http_test.php` defaults to `http://localhost/YouthSync/api` (override with `YOUTHSYNC_API_BASE`). It compares every dashboard number with direct SQL, checks role protection and organization/user spoofing, and runs a create -> count -> delete cycle (temporary youth, program, event, assistance program; exact ids deleted through the API). It submits no application and sends no SMS or email.

---

# Activity logs (audit trail)

`activity_logs` is the single audit table, written through `YouthSync\Admin\ActivityLog`. A failed audit write never fails the business action; it is reported in `api/logs/app.log` as `ActivityLog write failed (action=..., category=...)` with the database error class only (no descriptions or submitted values). `admin_audit_log` is a legacy table that no code reads or writes.

## GET `/sk/activity`

**Auth:** SK Official (**401** anonymous, **403** other roles). Organization always comes from the session; `organization_id` / `orgId` / `organizationId` / `user_id` / `userId` query parameters are ignored.

| Query | Meaning |
|---|---|
| `page` | 1-based page (clamped to the last page) |
| `perPage` | default 100, max 100 |
| `q` | text search in description and actor name |
| `action` | exact action, e.g. `youth.created`, `login.failed` |
| `category` | exact category, e.g. `auth`, `youth`, `program` |

**Success — 200** `{ items, page, perPage, total, pages, filters: { actions, categories } }`. Items: `id`, `orgId`, `userId`, `youthId`, `actor`, `user`, `role`, `actorRole`, `action`, `category`, `description`, `entityType`, `entityId`, `at`, `createdAt` (server local `YYYY-MM-DD HH:MM:SS`). The client IP is not returned to SK users. `filters` lists the distinct actions and categories recorded for the session organization. Newest first.

`GET /admin/activity` and `GET /admin/organizations/{id}/activity` (System Administrator) take the same filters (`action`, `category`, `q`, `page`, `perPage` default 25 / max 200), items also carry `organizationName` and `ip`, and the response also carries `filters: { actions, categories }` (all organizations for `/admin/activity`, that organization for `/admin/organizations/{id}/activity`).

## Auth events (category `auth`)

| Action | When | Stored |
|---|---|---|
| `login` | successful sign-in (SK, youth, admin) | user, organization (SK membership; youth record's organization; NULL for admins), IP |
| `login.failed` | wrong password, deactivated account, wrong role, unlinked youth, no/pending/inactive organization | user + organization of the matched account and the reason only |
| `login.failed` | email matches no account | user and organization NULL, actor `Unknown account`; the submitted email is **not** stored |
| `logout` | `POST /auth/logout` with a signed-in session | user, organization |
| `password.changed` | `POST /auth/change-password` success | user, organization |
| `password.reset_requested` | `POST /auth/forgot-password` for an existing active account (nothing stored for unknown emails) | user, organization |
| `password.reset` | `POST /auth/reset-password` success | user, organization |

Passwords, hashes, reset tokens, session ids and cookies are never written. User agent is not stored (no column).

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync\api\sql\sk_activity_http_test.php
```

---

# Phase 9 — Subscription / Plan Limits (SK Official)

Read-only view of the session organization's subscription. `organization_id` / `orgId` query parameters are ignored. There is no SK endpoint that sets a plan directly: plan changes, extensions and cancellations are made by the System Administrator (below), or by a verified online payment (see "Online payments — PayMongo"). Prices come from the server catalog.

Status rules (one shared calculation, `PlanLimits::state()`, used by the SK API and the admin lists; dates use the database `CURDATE()`):

* `trial` / `active` with `expires_at` before today → **expired** (persisted lazily by SK requests, by the admin organization list/dashboard, and by Run cycle).
* `expired`, `cancelled`, `payment_failed` → the **Free limits** apply (`restrictedToFree: true`) while `plan` still shows the stored paid plan. Data is never deleted or archived.
* `daysRemaining` = days until `expiresAt` (0 on the last day) for trial/active only; otherwise `null`. `expiresIn` is the raw signed difference.
* The QR limit is a lifetime counter (`organizations.qr_uses`); it is never reset.

Usage counts are the same queries enforcement uses (`PlanLimits::count()`):

* youth: non-archived
* accounts: active org memberships with role `SK_OFFICIAL`
* programs: `draft | published | ongoing`
* assistance: `draft | open | full`
* qr: `organizations.qr_uses`

`null` limit/remaining means unlimited.

## Routes

| Method | Path | Success |
|---|---|---|
| GET | `/sk/subscription` | **200** subscription view (below) |
| GET | `/sk/subscription/usage` | **200** same view (kept for compatibility) |

Unauthenticated: **401**. Non-SK Official: **403**.

Subscription view:

```json
{
  "plan": "basic", "planName": "Basic", "status": "active", "statusLabel": "Active",
  "cycle": "monthly", "cycleLabel": "Monthly", "startedOn": "2026-10-09",
  "expiresAt": "2026-11-09", "expiresIn": 31, "daysRemaining": 31,
  "effectivePlan": { "code": "basic", "name": "Basic", "csv_import": true, "excel_export": true },
  "restrictedToFree": false,
  "limits":    { "youth": 250, "accounts": 3, "programs": null, "assistance": 20, "qr": 3 },
  "usage":     { "youth": 12,  "accounts": 1, "programs": 4,    "assistance": 2,  "qr": 0 },
  "remaining": { "youth": 238, "accounts": 2, "programs": null, "assistance": 18, "qr": 3 },
  "reached":   { "youth": false, "accounts": false, "programs": false, "assistance": false, "qr": false },
  "overLimit": {},
  "features":  { "csvImport": true, "excelExport": true, "activityLogs": true },
  "plans": [ { "code": "free", "name": "Free", "tagline": "...", "currency": "PHP", "priceMonthly": 0, "priceYearly": 0,
               "popular": false, "limits": { ... }, "features": { ... }, "bullets": ["..."] }, ... ],
  "billing": { "onlinePayment": false, "note": "..." }
}
```

`overLimit` lists resources whose usage is above the effective limit, e.g. `{"youth": {"usage": 21, "limit": 20}}` after a downgrade. `features.activityLogs` is a UI gate for the SK Activity page only; `GET /sk/activity` is not plan-gated on the server.

## Plan limit error

Every limited create/restore/reopen/import/scan returns **403**:

```json
{ "success": false, "error": { "code": "PLAN_LIMIT_REACHED", "message": "...", "resource": "youth", "usage": 20, "limit": 20, "plan": "free" } }
```

`resource` is one of `youth`, `accounts`, `programs`, `assistance`, `qr`. CSV import overflow adds `requested` and `remaining`. The count and the insert run in one transaction after `SELECT ... FOR UPDATE` on the organization row, so parallel requests cannot exceed a limit; the QR counter uses a conditional `UPDATE ... WHERE qr_uses < limit`. A blocked request writes nothing. Feature gates are unchanged: CSV import on Free → **403** `PLAN_FEATURE`; XLSX export on Free → **403** `PLAN_FEATURE_UNAVAILABLE`.

# Subscription management (System Administrator)

All routes: **401** anonymous, **403** SK/youth. Unknown organization → **404**. Every successful change writes one `activity_logs` row (`category` `subscription`, `action` `update`, admin actor; no secrets) and returns `{ organization: {id, name, status}, subscription: <view above>, previous: {plan, status, cycle, expiresAt}, ... }`.

| Method | Path | Body | Result |
|---|---|---|---|
| GET | `/admin/plans` | — | **200** `{items: catalog, cycles: ["monthly","yearly"], trialDays: 7, maxExtendDays: 365, note}` |
| GET | `/admin/organizations/{id}/subscription` | — | **200** `{organization, subscription}` |
| POST | `/admin/organizations/{id}/subscription/plan` | `{plan: free\|basic\|premium, cycle?: monthly\|yearly}` | **200** + `overLimit`, `warning`. Paid plan: status active, new term from today (1 or 12 months). Free: no expiry. **409** same active plan/cycle or already Free. **422** invalid plan/cycle. A downgrade below current usage is allowed: `warning` lists usage vs limit; data is kept and new records beyond the limit are blocked. |
| POST | `/admin/organizations/{id}/subscription/extend` | exactly one of `{days: 1-365}` or `{expiresAt: "YYYY-MM-DD"}` | **200** + `extendedFrom`. Extends from the current expiry while the term is running, otherwise from today. Expired → active (new `startedOn`). A trial stays a trial. Date must be after the base and at most 365 days after it. **422** Free (`fields.status = free`), cancelled (`fields.status = cancelled`) or invalid input. |
| POST | `/admin/organizations/{id}/subscription/cancel` | `{reason?}` (≤255) | **200**. Immediate: status `cancelled`, Free limits apply; plan, cycle and `expiresAt` stay on record. No payment/refund action. **409** already cancelled, **422** Free. |
| POST | `/admin/subscriptions/run-cycle` | — | **200** `{expired, organizations: [{id, name, plan, expiresAt}], today}`. Marks every trial/active term that ended before today as expired (same rule as above). Nothing else (no renewals, no charges). |

The admin organization list/detail also return `subStatusLabel`, `startedOn`, `daysRemaining`, `restrictedToFree` (same calculation) and the detail adds `usage`.

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync\api\sql\subscription_http_test.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync\api\sql\subscription_plans_http_test.php
```

`subscription_plans_http_test.php` works on one temporary organization it creates and deletes (exact ids); it never changes a real organization's plan or QR counter. Optional `YOUTHSYNC_API_BASE` overrides `http://localhost/YouthSync/api`.

---

# Online payments — PayMongo hosted checkout (SK Official, System Administrator, webhook)

Optional. Off until `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SECRET` and `PAYMONGO_ALLOWED_METHODS` are set in `api/.env` (setup: `docs/PAYMONGO_SETUP.md`). Card and e-wallet details are entered only on PayMongo's hosted page; YouthSync never receives them.

Records: one `payment_transactions` row per checkout (provider `paymongo`). Online payments are never copied into the legacy `payments` table (see *Manual / offline ledger (removed)* below). Provider event ids are kept in `payment_webhook_events` (unique per provider) so retried webhooks are handled once.

## Routes

| Method | Path | Who | Result |
|---|---|---|---|
| GET | `/sk/subscription/payment-options` | SK Official | **200** `{enabled, provider, mode: test\|live, reason, message, methods: [{code, label}], currency: "PHP"}`. `reason` when disabled: `not_configured`, `key_mode_mismatch`, `invalid_mode`, `no_methods`. Never contains keys. |
| POST | `/sk/subscription/checkout` | SK Official | Body `{plan: basic\|premium, cycle: monthly\|yearly, method?}`; every other field (amount, currency, organization, status, ids) is ignored. **201** `{transaction, checkoutUrl}`; the browser redirects to `checkoutUrl` (PayMongo). **422** invalid plan/cycle/method (method must be in `PAYMONGO_ALLOWED_METHODS`). **409** `CHECKOUT_IN_PROGRESS` (+`transactionId`) when the organization started a checkout less than 60 s ago that is still pending (double submit). **503** `CHECKOUT_NOT_CONFIGURED` (+`reason`). **502** `PAYMENT_PROVIDER_ERROR` (+`transactionId`): PayMongo refused or was unreachable; the row is marked `failed`, nothing is charged or activated. |
| GET | `/sk/subscription/checkout/{id}` | SK Official, own organization only (else **404**) | **200** `{transaction, subscription}`; `subscription` is the subscription view only when `status = paid`, else `null`. While pending it re-checks PayMongo server-side at most every 10 s (expired sessions become `expired`). It never activates a plan unless test-mode `PAYMONGO_CONFIRM_BY_RETRIEVAL=true` (below). |
| POST | `/sk/subscription/checkout/{id}/cancel` | SK Official, own organization | **200** `cancelled` after PayMongo confirms the session is expired. **409** not pending, or already paid. **502** provider unavailable. |
| GET | `/sk/subscription/checkouts` | SK Official | **200** own organization's online payments, paged (`page`, `perPage` default 10, max 50). |
| GET | `/admin/payment-transactions` | System Administrator (SK/youth **403**) | **200** paged list (`q` = reference / organization / checkout id, `status` incl. `refunded`, `partially_refunded`, `verification` = `provider_verified` \| `awaiting_verification` \| `unverified` \| `mismatch` \| `needs_review` (unknown → 422), `organizationId`, `page`, `perPage` default 25, max 200) + `totals {collected (verified, gross), refunded, verified, awaitingVerification, pending, review, notPaid}` + `gateway` (same as payment-options). There is no route that marks a payment paid or verified. |
| POST | `/admin/payment-transactions/{id}/reconcile` | System Administrator (anon **401**, SK/youth **403**) | Runs the server-side verification with PayMongo (same service as the webhook). The body is ignored. **200** `{result, message, transaction}`; `result` = `applied` \| `already_applied` \| `unpaid` \| `expired` \| `review` \| `refunded` \| `already_refunded` \| `not_pending`. **404** unknown id, **409** never created at PayMongo, **503** `VERIFICATION_UNAVAILABLE`. Audited (category `payment`, admin actor). Activates only a still-pending row whose PayMongo data fully matches; never a `review` row. |
| POST | `/admin/payment-transactions/reconcile` | System Administrator | Body `{reference}`: a YS- reference, `cs_`, `pi_` or `pay_` id **already stored** on an online transaction; then as above. Anything else → **404** (a typed provider id never creates, links or verifies a transaction). Empty → **422**. |
| POST | `/payments/paymongo/webhook` | PayMongo (public, no session) | Verifies `Paymongo-Signature` (below) on the raw body. **400** missing/malformed header or body, **401** bad signature or timestamp older/newer than `PAYMONGO_WEBHOOK_TOLERANCE` (300 s), **503** webhook secret not set or PayMongo verification temporarily unavailable (PayMongo retries), **200** `{received, result}` otherwise. |

`transaction`: `{id, reference, organizationId, plan, planName, cycle, amount (pesos), amountCentavos, currency, provider, mode, status, statusLabel, providerStatus, requestedMethod, method, methodLabel, failureCode, failureMessage, activationNote, createdAt, paidAt, source: "paymongo", verification, verificationLabel, providerVerified, verifiedAt, verifiedVia, refundedAmount, refundedAt, reviewRequired, reviewReason}`; the admin list adds `organizationName, userId, providerReference (cs_...), providerPaymentId (pay_...), providerPaymentIntentId (pi_...), appliedAt, providerCheckedAt, canReconcile`.

`verification` (derived from the row, not stored separately): `provider_verified` ("Verified by PayMongo": `verified_at` set by the verification service), `awaiting_verification` (PayMongo reports paid, not yet verified/applied), `mismatch` (status `review`), `unverified` (everything else). `verified_at` is set only in the same DB transaction as a verified activation.

Statuses: `pending` (awaiting payment), `paid` (verified and plan applied), `failed` (checkout could not be created), `cancelled` (SK cancelled; PayMongo session expired), `expired` (PayMongo session expired unpaid), `review` (PayMongo data did not match; plan NOT changed; resolve manually in the PayMongo dashboard), `refunded` / `partially_refunded` (a succeeded refund at PayMongo; `reviewRequired = true`; the plan is NOT downgraded automatically). A failed card/e-wallet attempt keeps the row `pending` with `providerStatus = payment_failed` because the customer may retry on the same PayMongo page.

## Verification and activation

* Price: `PlanLimits::priceCentavos()` (Basic 99900 / 999000, Premium 199900 / 1999000 centavos, PHP) from the server catalog.
* Webhook signature: header `Paymongo-Signature: t=<unix>,te=<hex>,li=<hex>`; expected = `HMAC-SHA256(webhook_secret, t + "." + raw_body)`; compared with `hash_equals` against `te` in test mode or `li` in live mode; `|now - t| <= 300 s`.
* Events used: `checkout_session.payment.paid` (required; matched by checkout session id), `payment.paid` and `payment.failed` (matched by payment intent id), `payment.refunded` and `payment.refund.updated` (also `refund.succeeded`; matched by payment id, else by the payment's intent). Others are acknowledged as `ignored`; events whose `livemode` differs from `PAYMONGO_MODE` → `ignored_mode`; unknown sessions/payments → `unmatched`.
* A matched event never activates by itself. All paths (webhook, admin re-check, status poll) go through `PaymentVerificationService::verify()`. It fetches `GET /v1/checkout_sessions/{id}` with the secret key and requires: same session id, `reference_number`, `metadata.transaction_id`, `metadata.organization_id`, `metadata.reference`, mode, line-item total and a payment with `status = paid`, `amount` (integer centavos) equal to the stored amount, `currency = PHP` and the stored payment intent. It then fetches the payment itself (`GET /v1/payments/{id}`) and re-checks id, amount, currency, mode, intent, status and `refunds[]`. Mismatch → `review` (`review_required = 1`).
* Out of order / replays: a row already applied is never applied again (it is only re-checked for refunds or mismatches, which flag `review_required` without changing status or plan); `payment.failed` after `paid` changes nothing; a refund is recorded once (`refunded_centavos` only grows); a `paid` event after a refund never re-grants. Refund statuses counted: `succeeded` only (docs: pending, processing, succeeded, failed).
* Activation runs in one DB transaction holding the organization row lock and the transaction row lock. `applied_at` and the unique `(provider, provider_payment_id)` key make retries and duplicate events no-ops; a payment id already linked elsewhere rolls back the whole change and marks `review`.
* Rule: same plan, status `active` and term not ended → extend from the current expiry by 1 or 12 months (cycle becomes the paid cycle). Otherwise (free, trial, expired, cancelled, payment_failed, another plan) → new term from today. It is evaluated at confirmation time, so a plan change or expiry that happened while the checkout was pending is covered by the same rule: the organization gets exactly the plan and cycle it paid for.
* Audit: `activity_logs` category `subscription`, actor `PayMongo` / role `system` on activation; category `payment` for checkout start (SK actor), cancel and review.
* `PAYMONGO_CONFIRM_BY_RETRIEVAL=true` (TEST mode only, ignored in live): the status endpoint may activate from the server-side checkout-session fetch alone. For hosts without a public webhook URL; the same checks, locks and audit apply.
* Admin re-check (`POST /admin/payment-transactions/{id}/reconcile`) runs the same verification; it may activate a pending row that PayMongo confirms (`verified_via = admin reconcile+fetch`), never a `review` row. A `review` row is checked in the PayMongo dashboard, and the System Administrator applies a plan with the normal subscription actions if appropriate.
* Refund after activation: recorded (`refunded` / `partially_refunded`, `refunded_centavos`, `refunded_at`), flagged `review_required`, audited; **no automatic downgrade** (admin decision).
* Manual / offline ledger (removed): SK organizations pay only through PayMongo, so the admin-recorded ledger was removed. `GET /admin/payments`, `POST /admin/payments`, `GET /admin/organizations/{id}/payments`, `POST /admin/organizations/{id}/payments` and `POST /admin/payments/{id}/manual-verify` no longer exist and answer the router's normal **404** `NOT_FOUND` for every caller. The `payments` table and any historical rows are kept unchanged but are no longer read or written by the API; its columns `source`, `manual_verified_at`, `manual_verified_by`, `manual_verification_note` (and `recorded_by`, `note`, `method`) are unused historical schema.

`GET /sk/subscription` still returns `billing.onlinePayment: false` (static catalog note); use `GET /sk/subscription/payment-options` for gateway availability.

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync\api\sql\payments_http_test.php
```

Runs against a **MOCK** PayMongo (`api/sql/mock/paymongo_mock_server.php`) and a local `php -S` copy of the API that receives MOCK `PAYMONGO_*` values as process environment (api/.env is not edited). One temporary organization, exact-id cleanup, real organizations snapshotted. Passing it does not prove real PayMongo payments work.

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync\api\sql\payment_verification_http_test.php
```

Same MOCK setup plus a second local API server with the test-only fault hook `PAYMONGO_TEST_FAULT=after_activation_update` (honoured only in test mode with a loopback http MOCK API base) to prove the activation rolls back. Covers access control, the removed manual ledger routes (**404** for every role, nothing written), fake references, amount / currency / organization / transaction / intent mismatches, signatures and malformed payloads, exactly-once activation, duplicates, out-of-order failed/refund events, refunds before and after activation, pending refunds, provider outage, and verification fields.

---

# Phase 10 — SK Users (SK Official)

Manage SK Official staff accounts in the authenticated organization. `organization_id` / `orgId` / `role_id` / `isOwner` in JSON are ignored. Allowed role code is **`SK_OFFICIAL` only** (`YOUTH`, `SUPER_ADMIN`, `SYSTEM_ADMIN` → **422**).

Account slots use existing `PlanLimits` (`accounts`: Free 1 / Basic 3 / Premium unlimited; expired overlay Free). Count = active `organization_users` memberships with role `SK_OFFICIAL`. At-limit create → **403** `PLAN_LIMIT_REACHED`. Duplicate email → **409**. Cross-org → **404**.

Cannot deactivate or delete self. Cannot delete the owner. Cannot deactivate the last remaining *active* SK Official. Passwords are hashed with `password_hash`. A temporary password is returned **only** on create and reset-password.

## Routes

| Method | Path | Success |
|---|---|---|
| GET | `/sk/users` query: `q`, `status`, `role`, `sort`, `page`, `perPage` | **200** `{ items, page, perPage, total, pages, totalPages, counts }` |
| POST | `/sk/users` | **201** (+ `temporaryPassword` once) |
| GET | `/sk/users/{id}` | **200** |
| PUT / PATCH | `/sk/users/{id}` | **200** |
| POST | `/sk/users/{id}/status` | **200** `{ status: active\|inactive }` |
| POST | `/sk/users/{id}/toggle-active` | **200** |
| POST | `/sk/users/{id}/reset-password` | **200** (+ `temporaryPassword` once) |
| DELETE | `/sk/users/{id}` | **200** `{ deleted: true }` |

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\users_http_test.php
```

---

# Search, filters & pagination (all list endpoints)

Every list endpoint below is searched, filtered and paged **on the server** (SQL `WHERE` + `ORDER BY … LIMIT … OFFSET`). The web app sends the search text, filters and page; it never filters a downloaded page in the browser. The shared rules live in `src/Http/Pagination.php`.

## Query parameters

| Param | Rule |
|---|---|
| `q` | Free-text search. Trimmed, at most 100 characters. Matched literally as "contains" (`LIKE … ESCAPE '!'`), so `%` and `_` are ordinary characters. Empty = no search. |
| `page` | Whole number ≥ 1. Missing, non-numeric, `0` or negative → `1`. Values above 1,000,000 → 1,000,000. |
| `perPage` (also `per_page`) | Whole number ≥ 1. Missing or invalid → the endpoint default. Above the endpoint maximum → the maximum (the cap cannot be bypassed). |
| filters | Endpoint-specific, see below. Values are bound as SQL parameters, never concatenated. |
| `organization_id`, `orgId`, `user_id`, `role` … | **Ignored.** SK lists are always scoped to the signed-in official's organization (session); admin lists require a System Administrator session. |

## Response

Lists keep their existing shape (`Json::success` → `data`) and add `totalPages`:

```json
{ "items": [...], "page": 2, "perPage": 15, "total": 22, "pages": 2, "totalPages": 2 }
```

- `total` counts every row that matches the search and filters (before `LIMIT`). The count and the rows use the same `WHERE` clause.
- `totalPages = ceil(total / perPage)`, and **`1` when `total` is 0**. `pages` is kept as an alias for older callers.
- A `page` past the end returns `items: []` with the requested `page` echoed and the real `total`/`totalPages` (it is **not** an error and is **not** moved back to the last page).
- Ordering is always deterministic (`id` is the final tie-breaker), so rows never repeat or go missing between pages.

## Endpoints

| Endpoint | Search (`q`) matches | Filters | Sort | perPage default / max | Extra keys |
|---|---|---|---|---|---|
| GET `/sk/youth` | first, last, full name, school, address, contact, youth code | `priority`, `employment`, `archived` | `newest` (default), `oldest`, `name` | 15 / 100 | `usage` |
| GET `/sk/programs`, `/sk/events` | name, location, category | `kind` (`program`/`event`), `status` | default schedule date newest; `newest`, `oldest`, `name` | 50 / 100 | each item has `registeredCount` (attendance rows for that activity) |
| GET `/sk/assistance` | name, description | `category`, `status` | — | 50 / 100 | `counts: { all, <category>: n }` (ignores `category` and `q`, for tabs) |
| GET `/sk/applications` | youth first, last, full name, assistance name, program name | `assistanceId`, `youthId`, `status`, `kind` (`scholarship`/`assistance`; anything else → 422) | default submitted newest; `oldest`, `name` | 50 / 100 | `counts: { all, pending, approved, … }` (ignores `status` and `q`, for stat cards); each item has `requirementCount` (requirements of the applied-for program or assistance) and `youth.birthDate` |
| GET `/sk/notifications` | title, message | `type`, `unreadOnly`, `readOnly` | newest | 50 / 100 | `unreadCount` |
| GET `/sk/deliveries/sms`, `/sk/deliveries/email` | as before | — | newest | 100 / 100 | `providerConfigured` |
| GET `/sk/users` | first, last, full name, email | `status` (`active`/`inactive`), `role` (`sk_official` = all; `youth` matches nothing because this list only holds SK officials; anything else → 422) | `newest` (default), `oldest`, `name` | 50 / 100 | `counts: { all, active, inactive }` (whole organization, ignores `q`/`status`, for summary cards) |
| GET `/sk/activity` | description, actor | `action`, `category` | newest | 100 / 100 | `filters` |
| GET `/admin/organizations` | name, barangay, municipality, province, chairperson, email, contact | `status`, `plan` (unknown → 422) | `name` (default), `newest`, `oldest`, `status` | 25 / 200 | `counts` (organizations per status, all organizations) |
| GET `/admin/users` | first, last, full name, email | `role`, `status` | — | 25 / 200 | each item has `organizationName` (`null` when the account has no organization) |
| GET `/admin/activity` | description, actor | `action`, `category` | newest | 25 / 200 | `filters` |
| GET `/admin/payment-transactions` | reference, organization name, PayMongo checkout id | `status` (unknown → 422), `organizationId` | newest | 25 / 200 | `totals`, `gateway` |
| GET `/sk/subscription/checkouts` | — | — | newest | 10 / 50 | — |

Report exports (`/sk/reports/*`, CSV/XLSX) are **not** paged: they always contain every matching row.

## Run tests

```bash
php api/sql/search_pagination_http_test.php [baseUrl]
```

Creates a few draft youth, programs, assistance records and SK accounts tagged `SrchPg<timestamp>`, checks paging, search, filters, totals, tenant isolation and admin lists against SQL, and deletes exactly those records afterward. It never submits applications or sends SMS/email.
