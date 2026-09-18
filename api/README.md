# YouthSync SK Official API

PHP 8 + MySQL backend for **SK Official** only. The React app lives in `youth-sync/`.

**Team setup, demo accounts, XAMPP, and limitations:** see the repository root [`README.md`](../README.md).

| Piece | Needs |
|---|---|
| React UI (`npm install` / `npm run dev`) | Node.js. Vite on http://localhost:5173 |
| This API | Apache (XAMPP) + PHP 8.x |
| Login and data | MySQL / MariaDB, database `youthsync`, port **3307** on this machine |

Base URL: `http://localhost/YouthSync_UI_Refresh/api`

SK Official demo: `sk1@demo.test` / `YouthSync1!` (local only). The API never returns this password or `password_hash`.

`seed.sql` is **INSERT IGNORE** and is meant for a **fresh** database (or to add missing seed rows). `sql/setup_fresh.php` **refuses** to import if tables already exist. Do not DROP/TRUNCATE a populated database to “reset” it.

Copy `api/.env.example` to `api/.env`. Set `DB_PORT=3307`. Keep `DEMO_MODE=false` unless you intentionally want `sk1@demo.test` to accept any non-empty password in development.

---

## 1. Open XAMPP

1. Open **XAMPP Control Panel**.
2. Click **Start** next to **Apache**.
3. Click **Start** next to **MySQL**.

Both modules should show a green/running status.

---

## 2. Create the database

1. Open a terminal (PowerShell or Command Prompt).
2. Run:

```bat
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS youthsync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

This XAMPP MySQL instance listens on **port 3307** (see `C:\xampp\mysql\bin\my.ini`). Set `DB_PORT=3307` in `api/.env`. Standard XAMPP is often 3306 — use the port your server actually uses.

If your MySQL root user has a password, add `-p` and type it when asked. Add `--port=3307` if the client defaults to 3306.

You can also create the database in phpMyAdmin: http://localhost/phpmyadmin → New → name `youthsync` → utf8mb4_unicode_ci.

---

## 3. Import the schema

```bat
C:\xampp\mysql\bin\mysql.exe -u root youthsync < C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\schema.sql
```

This creates four tables: `roles`, `users`, `organizations`, `organization_users`.

---

## 4. Import seed data

```bat
C:\xampp\mysql\bin\mysql.exe -u root youthsync < C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\seed.sql
```

---

## 5. Configure the environment

1. Copy `api/.env.example` to `api/.env`.
2. Edit `DB_USER` and `DB_PASSWORD` if your MySQL account is not empty-password `root`.

Do not commit `.env`. It is listed in `.gitignore`.

---

## 6. Confirm Apache can reach the API

Open:

http://localhost/YouthSync_UI_Refresh/api/auth/me

You should receive JSON with `"success": false` and HTTP 401 (not logged in). That means routing works.

If you see a directory listing or 404, enable `mod_rewrite` and `AllowOverride All` for `C:\xampp\htdocs` in `httpd.conf`, then restart Apache.

---

## 7. Start the React frontend

The UI **does** call this API for SK Official sessions (`VITE_API_BASE_URL`). Apache + MySQL must be running.

```bat
cd C:\xampp\htdocs\YouthSync_UI_Refresh\youth-sync
npm install
npm run dev
```

Frontend: http://localhost:5173  
Backend: http://localhost/YouthSync_UI_Refresh/api

---

## 8. Test login

Development password for seeded SK accounts: **`YouthSync1!`**

This password is for local development only. The API never returns it or `password_hash`.

```bat
curl -i -c cookies.txt -b cookies.txt -H "Content-Type: application/json" -X POST http://localhost/YouthSync_UI_Refresh/api/auth/login -d "{\"email\":\"sk1@demo.test\",\"password\":\"YouthSync1!\"}"
```

Expected: `"success": true` and a `youthsync_session` cookie (HttpOnly).

---

## 9. Test `/auth/me`

```bat
curl -i -c cookies.txt -b cookies.txt http://localhost/YouthSync_UI_Refresh/api/auth/me
```

Expected: the same SK user and **SK Ibaba del Norte, Paete**.

---

## 10. Test logout

```bat
curl -i -c cookies.txt -b cookies.txt -X POST http://localhost/YouthSync_UI_Refresh/api/auth/logout
curl -i -c cookies.txt -b cookies.txt http://localhost/YouthSync_UI_Refresh/api/auth/me
```

The second call should be 401.

---

## Seeded SK Official accounts

| Email | Organization | Plan / demo state |
|---|---|---|
| `sk1@demo.test` | SK Ibaba del Norte, Paete | Trial (premium trial) |
| `sk2@demo.test` | SK Bagumbayan, Pagsanjan | Basic |
| `sk4@demo.test` | SK Maytalang I, Lumban | Free / at youth limit (`youth_count` = 20) |

Password for all of the above: `YouthSync1!`

A `YOUTH` role row exists only so non-SK login can be rejected and so SK Officials can optionally attach a youth **account row** when creating a record. There is **no** youth login API.

Live youth capacity is counted from non-archived rows in `youth`, not from `organizations.youth_count`.

---

## Youth API (after schema_youth.sql)

Apply the Phase 2 schema (does not drop Phase 1 data):

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_youth.php
```

Then, as a logged-in SK Official:

- `GET /sk/youth`
- `POST /sk/youth`
- `GET /sk/youth/{id}`
- `PUT` or `PATCH /sk/youth/{id}`
- `POST /sk/youth/{id}/archive`
- `POST /sk/youth/{id}/restore`
- `DELETE /sk/youth/{id}`
- `POST /sk/youth/import`

See `API_DOCUMENTATION.md`.

---

## Phase 3 — Programs & events

Apply the Phase 3 schema (does not drop Phase 1/2 data):

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_programs.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\programs_http_test.php
```

Programs and events share the `programs` table (`kind` = `program` or `event`). SK Official only. See `API_DOCUMENTATION.md`.

---

## Phase 4 — Attendance & QR

Apply the Phase 4 schema (does not drop Phase 1–3 data):

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_attendance.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\attendance_http_test.php
```

SK Official only. Youth personal QR (`YSYOUTH-YTH-####`) is scanned by SK for attendance. Program QR (`YSPROG-{id}`) is print-only; youth self-registration is not implemented. Attendance is not program registration.

---

## Phase 5 — Assistance

Apply the Phase 5 schema (does not drop Phase 1–4 data):

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_assistance.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\assistance_http_test.php
```

SK Official only. Assistance programs, system/custom types, beneficiaries, and assistance requirements. Youth applications/review queue is Phase 6.

---

## Phase 6 — Applications

Apply the Phase 6 schema (does not drop Phase 1–5 data):

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_applications.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\applications_http_test.php
```

SK Official review of youth applications to assistance programs. Approve uses existing Phase 5 beneficiary rules (slots, archived youth, org isolation). No youth portal, notifications, or program-registration applications.

---

## Phase 7 — Notifications

Apply the Phase 7 schema (does not drop Phase 1–6 data):

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_notifications.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\notifications_http_test.php
```

SK Official in-app inbox only. SMS, email, push, and outbox are not implemented. Organization and recipient always come from the session/server; client `organization_id` / `user_id` / `created_by` are ignored.

---

## Phase 8 — Dashboard & Reports

No schema change. Reads existing Phase 1–7 tables only.

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\dashboard_reports_http_test.php
```

SK Official dashboard and read-only reports. Organization always comes from the session. CSV/Excel/PDF export and subscription reports are not implemented.

---

## Phase 9 — Subscription / Plan Limits

No schema change. Uses `organizations.plan`, `sub_status`, `cycle`, `expires_at`, `qr_uses` and existing `PlanLimits`.

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\subscription_http_test.php
```

Read-only SK Official subscription and usage. Checkout, billing, payment gateways, and self-serve upgrades are not implemented. Expired / cancelled / `payment_failed` still apply Free limits via existing `PlanLimits::effective()`.

---

## Phase 10 — SK Users

No schema change. Reuses `users`, `roles`, and `organization_users`.

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\users_http_test.php
```

SK Official management of SK Official staff in the session organization only. Role is always `SK_OFFICIAL` (resolved server-side). Youth accounts, admin roles, and payment flows are not implemented.

---

## Endpoints (Phase 1)

See `API_DOCUMENTATION.md`.

- `POST /auth/login`
- `POST /auth/logout`
- `GET /auth/me`
- `POST /auth/change-password`

Base URL: `http://localhost/YouthSync_UI_Refresh/api`
