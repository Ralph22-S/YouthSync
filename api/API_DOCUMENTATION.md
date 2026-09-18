# YouthSync API — Phase 1

Base URL: `http://localhost/YouthSync_UI_Refresh/api`

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

Plan limit (non-archived youth): **403** `PLAN_LIMIT`. Duplicate first+last+birth in the same org: **409** `CONFLICT`. Sending `organization_id` is ignored.

## PUT / PATCH `/sk/youth/{id}`

**Auth:** SK Official. **Success — 200** with the updated youth. Cannot move the record to another organization.

## POST `/sk/youth/{id}/archive`

**Success — 200.** Soft-delete (`archived: true`). Hidden from the default list.

## POST `/sk/youth/{id}/restore`

**Success — 200.** Blocked with **403** `PLAN_LIMIT` when restoring would exceed the plan.

## DELETE `/sk/youth/{id}`

**Success — 200** `{ "deleted": true }`. Hard delete. Also removes a linked youth user if one exists. Then GET returns 404.

## POST `/sk/youth/import`

**Auth:** SK Official. Body: a JSON array of youth objects, or `{ "rows": [ ... ] }`. CSV parsing stays in the client.

Free plan (or expired orgs using Free limits): **403** `PLAN_FEATURE` (`CSV import is not included in the {Plan} plan`). Overflow: **403** `PLAN_LIMIT`.

Empty interests default to `["Community service"]`.

---

# Phase 3 — Programs & events (SK Official)

Programs and events are **the same activity record**, distinguished by `kind`: `program` or `event`. There is no separate events table and no child-event rows. Organization always comes from the session. `organization_id` in the body is ignored.

Auth: SK Official session cookie. Missing/other-org records: **404**.

Statuses: `draft`, `published`, `ongoing`, `completed`, `archived`.  
Active toward the plan limit: `draft`, `published`, `ongoing`.  
Free plan: **1** active activity. Basic/Premium: unlimited. Expired orgs use Free limits. **403** `PLAN_LIMIT`.

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
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_phase3.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\phase3_http_test.php
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

QR scan limits (existing plans): Free **1**, Basic **3**, Premium unlimited. Expired/cancelled/payment_failed orgs use Free limits. Manual confirm/list marking does **not** consume a QR scan. **403** `PLAN_LIMIT` when the scan quota is exhausted.

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
| 403 | `FORBIDDEN` / `PLAN_LIMIT` | Not SK Official, or QR scan quota reached |
| 404 | `NOT_FOUND` | Activity, session, youth QR, or attendance not in this organization |
| 409 | `CONFLICT` | Attendance already recorded as present |
| 422 | `VALIDATION_ERROR` / `SESSION_EXPIRED` | Bad Youth QR, ineligible activity, expired session |

Password hashes, `token_hash`, and database credentials are never returned.

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_phase4.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\phase4_http_test.php
```

---

# Phase 5 — Assistance (SK Official)

SK Official management of assistance programs (scholarships, financial assistance, and other types). This is **not** the youth application/review queue (Phase 6). Organization always comes from the session. `organization_id` / `orgId` in the body is ignored.

Active toward the plan limit: `draft`, `open`, `full`.  
Free: **1** active assistance program. Basic: **20**. Premium: unlimited. Expired orgs use Free limits. **403** `PLAN_LIMIT`. Phase 4 QR limits are unchanged.

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
| 403 | `FORBIDDEN` / `PLAN_LIMIT` | Not SK Official, or assistance quota reached |
| 404 | `NOT_FOUND` | Program, type, beneficiary, requirement, or youth not in this organization |
| 409 | `CONFLICT` | Duplicate assistance name or type name |
| 422 | `VALIDATION_ERROR` | Missing/invalid fields, slots full, archived youth |

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_phase5.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\phase5_http_test.php
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
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_phase6.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\phase6_http_test.php
```

---

# Phase 7 — Notifications (SK Official)

Organization-scoped in-app inbox for the authenticated SK Official. SMS, email, push, outbox, and activity logs are not implemented.

SK list returns rows for the session organization where `youth_id` is null (SK inbox) and `user_id` is null (org-wide) or equal to the authenticated user. Youth-targeted rows are stored for application review events but are not listed on `/sk/notifications`.

Organization and recipient are taken from the session. `organization_id`, `user_id`, and `created_by` in JSON are ignored.

Internal creates go through `NotificationService` (no public create endpoint). Application create writes an SK inbox notice. Approve / reject / needs-resubmission write youth-targeted notices only.

## Routes

| Method | Path | Success |
|---|---|---|
| GET | `/sk/notifications` query: `page`, `perPage`, `unreadOnly`, `type`, `q` | **200** `{ items, page, perPage, total, pages, unreadCount }` |
| GET | `/sk/notifications/{id}` | **200** |
| PATCH | `/sk/notifications/{id}/read` | **200** (`is_read`, `read_at`) |
| POST | `/sk/notifications/{id}/read` | **200** (same as PATCH) |
| POST | `/sk/notifications/read-all` | **200** `{ updated }` |
| DELETE | `/sk/notifications/{id}` | **200** `{ deleted: true }` |

Unauthenticated: **401**. Non-SK Official: **403**. Other organization or youth-targeted id: **404**.

Item fields: `id`, `type` / `category`, `title`, `message` / `body`, `link`, `related`, `relatedEntityType`, `relatedEntityId`, `isRead`, `readAt`, `createdAt`, `userId`, `youthId`.

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_phase7.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\phase7_http_test.php
```

---

# Phase 8 — Dashboard & Reports (SK Official)

Read-only aggregates for the authenticated SK Official's organization. No new tables. `organization_id` / `orgId` query parameters are ignored. CSV, Excel, PDF, activity_logs, outbox, and subscription reports are not implemented.

There is no `registrations` table; program “registered participants” from the mock UI is not returned. Attendance totals use the `attendance` table only (`present`, `absent`, `excused`, `pending`).

Recent activity is derived from existing youth, programs, applications, attendance, and SK inbox notification rows (newest 6). It is not a separate audit log.

## Routes

| Method | Path | Success |
|---|---|---|
| GET | `/sk/dashboard` | **200** summary, highlights, breakdowns, upcoming programs, recent, newest youth |
| GET | `/sk/reports?type=` `youth` \| `programs` \| `assistance` \| `applications` \| `attendance` | **200** |
| GET | `/sk/reports/youth` (and `/programs`, `/assistance`, `/applications`, `/attendance`) | **200** (same as `?type=`) |

Report query filters (all optional except `type` on `/sk/reports`): `from`, `to` (`YYYY-MM-DD`), `status`, `category`, `kind`. Invalid type/date/status/kind: **422**.

Unauthenticated: **401**. Non-SK Official: **403**.

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\phase8_http_test.php
```

---

# Phase 9 — Subscription / Plan Limits (SK Official)

Read-only view of the session organization's stored plan and **effective** limits. `organization_id` / `orgId` query parameters are ignored. No payment gateway, checkout, billing, invoices, or SK self-upgrade/downgrade endpoints.

Existing `PlanLimits` rules are unchanged except `accounts` is now included in the catalog (Free 1 / Basic 3 / Premium unlimited) for usage reporting. Youth, programs, assistance, QR, and CSV gates still use `PlanLimits::effective()`. Expired / cancelled / `payment_failed` overlay Free limits while `plan` still shows the stored paid name.

Usage counts match existing enforcement:

* youth: non-archived
* accounts: active org memberships with role `SK_OFFICIAL`
* programs: `draft | published | ongoing`
* assistance: `draft | open | full`
* qr: `organizations.qr_uses`

`null` limit/remaining means unlimited.

## Routes

| Method | Path | Success |
|---|---|---|
| GET | `/sk/subscription` | **200** plan, status, cycle, expiresAt/expiresIn, effectivePlan, limits, usage, remaining, features |
| GET | `/sk/subscription/usage` | **200** plan, limits, usage, remaining |

Unauthenticated: **401**. Non-SK Official: **403**.

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\phase9_http_test.php
```

---

# Phase 10 — SK Users (SK Official)

Manage SK Official staff accounts in the authenticated organization. `organization_id` / `orgId` / `role_id` / `isOwner` in JSON are ignored. Allowed role code is **`SK_OFFICIAL` only** (`YOUTH`, `SUPER_ADMIN`, `SYSTEM_ADMIN` → **422**).

Account slots use existing `PlanLimits` (`accounts`: Free 1 / Basic 3 / Premium unlimited; expired overlay Free). Count = active `organization_users` memberships with role `SK_OFFICIAL`. At-limit create → **403** `PLAN_LIMIT`. Duplicate email → **409**. Cross-org → **404**.

Cannot deactivate or delete self. Cannot delete the owner. Cannot deactivate the last remaining *active* SK Official. Passwords are hashed with `password_hash`. A temporary password is returned **only** on create and reset-password.

## Routes

| Method | Path | Success |
|---|---|---|
| GET | `/sk/users` | **200** `{ items, page, perPage, total, pages }` |
| POST | `/sk/users` | **201** (+ `temporaryPassword` once) |
| GET | `/sk/users/{id}` | **200** |
| PUT / PATCH | `/sk/users/{id}` | **200** |
| POST | `/sk/users/{id}/status` | **200** `{ status: active\|inactive }` |
| POST | `/sk/users/{id}/toggle-active` | **200** |
| POST | `/sk/users/{id}/reset-password` | **200** (+ `temporaryPassword` once) |
| DELETE | `/sk/users/{id}` | **200** `{ deleted: true }` |

## Run tests

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\phase10_http_test.php
```


