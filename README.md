# YouthSync

SK Official records for Sangguniang Kabataan councils. This repository is a **local XAMPP + Vite** project: PHP/MySQL API under `api/`, React UI under `youth-sync/`.

**Current focus:** SK Official backend and its React integration (Phases 1–11). Phase 12 is setup, demo data, documentation, and verification so the team can run and demonstrate the app.

---

## Demo / Team Setup

### What is currently working (SK Official, live API)

With Apache, MySQL, and Vite running, a demo SK Official can:

1. Log in  
2. Open the Dashboard (database totals)  
3. Manage Youth records (list, add, edit, archive, restore, CSV import as JSON rows)  
4. Manage Programs & Events  
5. Manage Assistance programs, types, and beneficiaries  
6. Review Applications (approve / reject / resubmit)  
7. Take Attendance (camera QR on localhost, or type `YSYOUTH-YTH-0001`)  
8. Read in-app Notifications  
9. Manage SK Official Users  
10. View Subscription / plan limits (read-only)  
11. View Reports on screen (print). CSV/Excel download is **not** implemented in the UI yet.

Youth Portal (`/youth`) and System Administrator (`/admin`) still use **frontend mock + localStorage**. They are not the SK PHP API.

### Demo account

Use this account for the main walkthrough (premium trial org: SK Ibaba del Norte, Paete):

| Email | Password | Notes |
|---|---|---|
| `sk1@demo.test` | `YouthSync1!` | **Primary demo.** SK Official. |

Also seeded (same local password):

| Email | Organization | Plan (seed) |
|---|---|---|
| `sk2@demo.test` | SK Bagumbayan, Pagsanjan | Basic |
| `sk4@demo.test` | SK Maytalang I, Lumban | Free (youth limit demonstration) |

This password is for **local development only**. The API never returns it or `password_hash`.

`DEMO_MODE` in `api/.env` (when `true` and `APP_ENV` is not `production`) lets **`sk1@demo.test` sign in with any non-empty password**. That is a local convenience only. For a realistic demo, set `DEMO_MODE=false` and use `YouthSync1!`.

### How to start the backend

1. Open **XAMPP Control Panel**.  
2. Start **Apache**.  
3. Start **MySQL**. This project’s MySQL listens on **port 3307** (`C:\xampp\mysql\bin\my.ini`). Set `DB_PORT=3307` in `api/.env`.  
4. Confirm: http://localhost/YouthSync_UI_Refresh/api/auth/me → JSON `401` (not logged in).

Backend URL: `http://localhost/YouthSync_UI_Refresh/api`  
Backend folder: `C:\xampp\htdocs\YouthSync_UI_Refresh\api`

### How to start the frontend

```bat
cd C:\xampp\htdocs\YouthSync_UI_Refresh\youth-sync
npm install
npm run dev
```

Frontend URL: `http://localhost:5173`  
Frontend folder: `C:\xampp\htdocs\YouthSync_UI_Refresh\youth-sync`

`youth-sync/.env` must contain:

```
VITE_API_BASE_URL=http://localhost/YouthSync_UI_Refresh/api
```

Apache + MySQL must be running for login and data. Vite must be running for the React UI.

### Basic SK Official demo flow

Login (`sk1@demo.test` / `YouthSync1!`)  
→ Dashboard  
→ Youth  
→ Programs & Events  
→ Assistance  
→ Applications  
→ Attendance / QR  
→ Notifications  
→ Users  
→ Subscription  
→ Reports  

---

## XAMPP requirements

| Piece | Role |
|---|---|
| XAMPP (Windows) | Apache + PHP 8.2 + MySQL/MariaDB |
| Apache | Serves the PHP API from `htdocs` |
| MySQL/MariaDB | Database `youthsync`, **port 3307** on this machine |
| phpMyAdmin | http://localhost/phpmyadmin |
| Node.js + npm | React/Vite frontend only |

Standard XAMPP MySQL is often 3306. **This checkout uses 3307.** Always match `api/.env` `DB_PORT` to `my.ini`.

---

## Database setup

**Seed/schema scripts are for a fresh empty database.** They use `CREATE TABLE IF NOT EXISTS` and `INSERT IGNORE`. They do **not** DROP, TRUNCATE, or delete existing rows.

**Do not overwrite an existing populated `youthsync` database.** If tables already exist, skip import and only verify the connection.

### Create the database (if it does not exist)

phpMyAdmin: http://localhost/phpmyadmin → New → name `youthsync` → collation `utf8mb4_unicode_ci`.

Or:

```bat
C:\xampp\mysql\bin\mysql.exe --port=3307 -u root -e "CREATE DATABASE IF NOT EXISTS youthsync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Add `-p` if root has a password.

### Fresh machine only — schema + seed + Phases 2–7

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\setup_fresh.php
```

If `youthsync` already has tables, this script **exits without changing data**.

Manual equivalent (also additive, not destructive):

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_auth_orgs.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_youth.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_programs.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_attendance.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_assistance.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_applications.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\import_notifications.php
```

`seed.sql` inserts demo SK users/orgs with `INSERT IGNORE` (first-time / missing rows only). It will **not** reset passwords of accounts that already exist.

### Configure `api/.env`

Copy `api/.env.example` to `api/.env` if you do not have one. Typical local values:

- `DB_HOST=127.0.0.1`
- `DB_PORT=3307`
- `DB_NAME=youthsync`
- `DB_USER=root`
- `DB_PASSWORD=` (empty on default XAMPP)
- `FRONTEND_ORIGIN=http://localhost:5173`
- `DEMO_MODE=false` (recommended for a password demo)

Never commit `.env`. Never put real SMS/email/payment API keys in this repo.

### Verify the database connection

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\verify_setup.php
```

Expect `pdo_ok`, 15 tables, and demo emails `sk1@demo.test`, `sk2@demo.test`, `sk4@demo.test` **without** hashes.

### phpMyAdmin

1. http://localhost/phpmyadmin  
2. Select database `youthsync`  
3. Confirm tables such as `users`, `organizations`, `youth`, `programs`, `attendance`, `applications`, `notifications`  
4. In `users`, you should see demo emails. Do not copy `password_hash` into chat, tickets, or documentation.

---

## Frontend setup

Requires **Node.js 18+** and npm.

```bat
cd C:\xampp\htdocs\YouthSync_UI_Refresh\youth-sync
npm install
npm run dev
```

Production bundle (does not start a server):

```bat
npm run build
```

`VITE_API_BASE_URL` must point at the PHP API. Copy `youth-sync/.env.example` to `youth-sync/.env`.

---

## Environment variables

| File | Purpose |
|---|---|
| `api/.env.example` | Safe placeholders for PHP (copy to `api/.env`) |
| `youth-sync/.env.example` | `VITE_API_BASE_URL` only |
| `.gitignore` / `api/.gitignore` | Ignore `.env`, logs, storage, uploads, `node_modules` |

`DEMO_MODE` is **development/demo-only**. It must stay `false` in production. It is ignored when `APP_ENV=production`.

---

## Current limitations (not implemented)

Do not present these as finished product features:

- Semaphore SMS  
- Brevo (or any) email delivery  
- Payment gateway, checkout, payment verification, webhooks  
- Automated cron / scheduled reminders  
- File / cloud storage for photos and requirement documents  
- Production hosting, custom domain, HTTPS  
- Youth Portal **backend** (UI is mock)  
- System Administrator **backend** (UI is mock)  
- Activity log table / SK Outbox of real messages  
- Report CSV/Excel file download in the SK UI (API report JSON exists)  

---

## API verification (safe)

Apache must be running.

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\verify_setup.php
C:\xampp\php\php.exe C:\xampp\htdocs\YouthSync_UI_Refresh\api\sql\setup_verification.php
```

`setup_verification.php` logs in as the demo SK Official, GETs SK endpoints, and logs out. It does not delete data.

Endpoint reference: `api/API_DOCUMENTATION.md`  
Older phase-by-phase import notes: `api/README.md`

---

## Project layout

```
C:\xampp\htdocs\YouthSync_UI_Refresh\
  README.md                 ← you are here
  api\                      ← PHP API (Apache)
  youth-sync\               ← React + Vite
```
