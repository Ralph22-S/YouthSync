# YouthSync — frontend

React 18 + Vite + Tailwind. Frontend only: no backend, no API, no database.
State is held in React and persisted to `localStorage`, structured so the data layer
can be replaced with API responses without redesigning the UI.

## Run

```bash
npm install
npm run dev
```

http://localhost:5173

## Sign-in accounts

Credentials are kept out of the interface. Any password is accepted while there is no
backend authentication.

| Role | Email |
|---|---|
| System Administrator | `admin@skmms.test` |
| SK Official | `sk1@demo.test` (trial), `sk2@demo.test` (Basic), `sk4@demo.test` (Free, at limit) |
| Youth | `youth1@demo.test`, `youth2@demo.test`, `youth3@demo.test` |

Youth can also self-register from the login screen.

## Three QR purposes, kept separate

| | Purpose | Scanned by |
|---|---|---|
| Registration QR | Youth account sign-up | the youth |
| Program QR (`YSPROG-12`) | Register for one activity | the youth |
| Youth Personal QR (`YSYOUTH-YTH-001`) | Identification and attendance | the SK |

A personal QR exists only once a youth has an active account.

## Youth record vs youth account

A record encoded by the SK has a permanent Youth ID and an account status of
**Not registered** — no login, no personal QR. Ticking *Create a youth account* on the
Add Youth form issues a temporary password (`YTS-482916` format) against the same Youth ID.
The youth must set their own password at first sign-in.

## Registration is not attendance

Registering puts a youth on the participant list. They remain **Not yet attended** until the
SK selects the activity and scans their personal QR, or confirms them through manual
attendance. Duplicate scans and unregistered youth are both refused.

## Plan limits

| | Free | Basic | Premium |
|---|---|---|---|
| Youth | 20 | 250 | Unlimited |
| Users | 1 | 3 | Unlimited |
| Programs | 1 | Unlimited | Unlimited |
| Assistance | 1 | 20 | Unlimited |
| QR scans | 1 | 3 | Unlimited |

Limits block the action itself, not just the button. `sk4@demo.test` starts at the Free
youth limit — opening `/sk/youth/new` directly is refused.

## Project structure

- `src/data/mock.js` — seed records, plans, statuses, QR formats, priority scoring
- `src/stores/mock.jsx` — all state transitions in one place; replace bodies with API calls
- `src/lib/storage.js` — persistence (bump `VERSION` to discard saved state)
- `src/lib/validation.js` — form rules
- `src/components/` — shared UI: layouts, ui primitives, filters, tags, education, qr, upload

## Not implemented

No SMS or email is sent; both outboxes hold frontend records ready for backend delivery.
No payment processing — billing shows frontend state only. The camera scanner requires
localhost or HTTPS; manual attendance covers every other case.

## Role	Account	Password
System Administrator	admin@skmms.test	Any password
SK Official – Trial	sk1@demo.test	Any password
SK Official – Basic	sk2@demo.test	Any password
SK Official – Free / at limit	sk4@demo.test	Any password
Youth – Jona	youth1@demo.test	Any password
Youth – Maria	youth2@demo.test	Any password
Youth – Carlo	youth3@demo.test	Any password