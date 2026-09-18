# YouthSync — React frontend

React 18 + Vite + Tailwind. SK Official screens talk to the PHP API when you sign in as an SK Official (`credentials: include`, cookie session).

Youth Portal and System Administrator screens still use **mock data + `localStorage`**. They are not the SK backend.

Team setup (XAMPP, demo accounts, limitations): see the repository root `README.md`.

## Run

Apache and MySQL must already be running so `http://localhost/YouthSync_UI_Refresh/api` answers.

```bat
cd C:\xampp\htdocs\YouthSync_UI_Refresh\youth-sync
npm install
npm run dev
```

http://localhost:5173

Copy `.env.example` to `.env` if needed:

```
VITE_API_BASE_URL=http://localhost/YouthSync_UI_Refresh/api
```

Production bundle:

```bat
npm run build
```

## SK Official sign-in

| Email | Password | Org / plan |
|---|---|---|
| `sk1@demo.test` | `YouthSync1!` | SK Ibaba del Norte, Paete — premium trial (**demo account**) |
| `sk2@demo.test` | `YouthSync1!` | Basic |
| `sk4@demo.test` | `YouthSync1!` | Free / youth-limit demo |

Local development only. If `api/.env` has `DEMO_MODE=true`, `sk1@demo.test` also accepts any non-empty password.

Youth (`youth1@demo.test`) and admin (`admin@skmms.test`) logins remain **mock** (any password) and do not hit the PHP API.

## Not implemented (do not demo as complete)

- Real SMS (Semaphore) or email (Brevo)
- Payment gateway / checkout
- File storage for photos and requirement documents
- Youth Portal backend
- System Administrator backend
- Scheduled jobs
- Production HTTPS / domain

Outbox and Activity logs in the SK UI are empty or simulated when using the live API.

## Project structure

- `src/stores/api.jsx` — SK Official API store
- `src/stores/mock.jsx` — Youth / Admin / fallback mock
- `src/lib/api.js` — `fetch` + `VITE_API_BASE_URL`
- `src/data/mock.js` — labels, plan catalog copy, QR format helpers
