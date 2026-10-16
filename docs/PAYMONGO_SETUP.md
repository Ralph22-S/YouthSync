# PayMongo online payments — setup, testing and go-live

YouthSync can take subscription payments through **PayMongo hosted checkout** (Checkout Sessions API).
The SK Official reviews the plan in YouthSync, is redirected to PayMongo's page, pays there, and is sent back
to a YouthSync status page. **The plan changes only after the server has verified the payment with PayMongo.**

Status of this integration (2026-10-09): tested against a **MOCK** PayMongo (automated suites) and in PayMongo
**TEST mode** with real test keys (box: activation by server-side retrieval; laptop: activation by a real signed
webhook through a temporary tunnel). Live mode has **not** been verified.
Which payment methods your PayMongo account can accept is decided by PayMongo (account activation), not by YouthSync.

## 1. How it works

1. `POST /sk/subscription/checkout {plan, cycle, method?}` — the server takes the organization and user from the
   session, the price from its own catalog (integer centavos, PHP), creates a `pending` row in
   `payment_transactions`, calls `POST https://api.paymongo.com/v1/checkout_sessions` (Basic auth, secret key) and
   returns PayMongo's `checkout_url`.
2. The customer pays on PayMongo. PayMongo redirects to `PAYMONGO_SUCCESS_URL` / `PAYMONGO_CANCEL_URL`.
   Those pages **only poll** `GET /sk/subscription/checkout/{id}`; visiting them never activates anything.
3. PayMongo calls `POST /YouthSync/api/payments/paymongo/webhook` with event `checkout_session.payment.paid`.
   The server verifies the `Paymongo-Signature` HMAC (webhook secret, raw body, timestamp within 300 s),
   then hands the transaction to the single verification service (`PaymentVerificationService`), which
   **independently fetches** the checkout session (`GET /v1/checkout_sessions/{id}`) **and** the payment
   (`GET /v1/payments/{id}`) with the secret key and checks: session id, reference, metadata transaction id,
   metadata organization id and reference, payment intent, payment id, test/live mode, amount in centavos and
   currency `PHP`, payment status `paid`, and refunds.
4. Only then, in one DB transaction with the transaction and organization rows locked, the plan is applied, the
   row becomes `paid`, `verified_at` / `verified_via` are set and an audit row is written. Any error rolls the
   whole thing back. Duplicate or retried events never grant twice. Mismatches go to `review` and change nothing.

Activation rule: same plan still active → the term is extended from the current expiry by 1 or 12 months;
anything else → a new term from today.

## 2. Environment variables (`api/.env`, git-ignored)

| Variable | Required | Value |
|---|---|---|
| `PAYMONGO_MODE` | yes | `test` (default) or `live`. Must match the key prefix. |
| `PAYMONGO_SECRET_KEY` | yes | `sk_test_...` (test) / `sk_live_...` (live). Dashboard → Developers → API keys. Server only. |
| `PAYMONGO_WEBHOOK_SECRET` | yes | The **webhook signing secret** `whsk_...` of the endpoint you register (not the API key). |
| `PAYMONGO_ALLOWED_METHODS` | yes | Comma list of `payment_method_types`, e.g. `card,gcash,paymaya`. Only methods active on your PayMongo account will work. Valid codes: `card, gcash, paymaya, grab_pay, shopee_pay, qrph, billease, dob, dob_ubp, brankas_bdo, brankas_landbank, brankas_metrobank`. Empty → online payment disabled with a clear message. |
| `PAYMONGO_SUCCESS_URL` / `PAYMONGO_CANCEL_URL` | no | Default `FRONTEND_ORIGIN + /sk/subscription/payment/success?tx={id}` (and `/cancel`). `{id}` = internal transaction id. |
| `PAYMONGO_WEBHOOK_TOLERANCE` | no | Seconds, default 300. |
| `PAYMONGO_CONFIRM_BY_RETRIEVAL` | no | `false` (default). TEST mode only, see §5. Ignored in live mode. |
| `PAYMONGO_API_BASE` | no | Leave unset. Test-only override used by the MOCK suite; plain `http://` is accepted only for 127.0.0.1/localhost. |
| `PAYMONGO_PUBLIC_KEY` | no | Not used: hosted checkout needs no public key in YouthSync. |

If the secret key or webhook secret is empty, the SK Plans page shows the old, clearly labelled
"Checkout (simulation)" and `POST /sk/subscription/checkout` returns **503 `CHECKOUT_NOT_CONFIGURED`**.
Keys are never returned by the API, written to logs, stored in the database or sent to the browser.

Check that `.env` is ignored before adding keys: `git check-ignore -v api/.env` must print a rule
(`api/.gitignore:1:.env`) and `git ls-files api/.env` must print nothing.

## 3. Test-mode setup

1. In the PayMongo Dashboard switch to **Test mode**; copy the **secret test key**.
2. Register a webhook endpoint (Dashboard → Developers → Webhooks → Add endpoint, or `POST /v1/webhooks`):
   * URL: `https://<public-host>/YouthSync/api/payments/paymongo/webhook` (public HTTPS with a valid CA certificate is required by PayMongo).
   * Events: `checkout_session.payment.paid` (required), `payment.paid`, `payment.failed` (optional, used for status messages).
   * Copy the endpoint's signing secret (`whsk_...`) into `PAYMONGO_WEBHOOK_SECRET`.
   * Keep **separate** test and live endpoints/secrets.
3. Set in `api/.env`: `PAYMONGO_MODE=test`, `PAYMONGO_SECRET_KEY=sk_test_...`, `PAYMONGO_WEBHOOK_SECRET=whsk_...`,
   `PAYMONGO_ALLOWED_METHODS=card,gcash,paymaya` (adjust to what your account shows).
4. Restart nothing — the API reads `api/.env` per request. `GET /sk/subscription/payment-options` should return `enabled: true, mode: "test"`.

### Exposing localhost for the webhook (development only)

PayMongo cannot reach `localhost`. Use a tunnel to Apache and register the tunnel URL:

* Cloudflare quick tunnel: `cloudflared tunnel --url http://localhost:80` → `https://<random>.trycloudflare.com/YouthSync/api/payments/paymongo/webhook`
* ngrok: `ngrok http 80` → `https://<id>.ngrok-free.app/YouthSync/api/payments/paymongo/webhook`

The tunnel URL changes on each run, so update the webhook endpoint (and its secret if you recreate it).
**Never** disable or bypass signature verification to make local testing easier.

## 4. Test payments

From PayMongo's testing docs (https://docs.paymongo.com/docs/payment-acceptance-testing):

| Card | Result |
|---|---|
| `4343 4343 4343 4345` | Success, no 3DS |
| `4120 0000 0000 0007` | 3DS — choose Authorize |
| `4200 0000 0000 0018` | Declined (expired card) |
| `5100 0000 0000 0198` | Declined (insufficient funds) |

Any future expiry and any 3-digit CVC. E-wallets (GCash, Maya, GrabPay, ShopeePay) open a PayMongo test page
where you choose **Authorize** or **Fail**; no real wallet account is needed.

## 5. No public webhook URL (e.g. the build box)

Without a webhook the strict default never activates a plan: the status page shows "Payment processing"
and then "PayMongo shows a payment. Waiting for its confirmation…". Options:

1. **Preferred:** a tunnel (§3) so real signed webhooks arrive.
2. **Test-mode fallback:** `PAYMONGO_CONFIRM_BY_RETRIEVAL=true` with `PAYMONGO_MODE=test`. The protected status
   endpoint then activates from a server-to-server `GET /v1/checkout_sessions/{id}` (secret key) with the same
   amount/currency/reference checks, locks and audit (audit text says `via fetch (test mode)`). The browser
   still supplies nothing that is trusted. It is ignored in live mode. Turn it off once webhooks work.

## 6. Admin

Admin → Payments shows "Online payments (PayMongo)" (internal id, reference, organization, plan, cycle, amount +
currency, method, PayMongo checkout / payment ids, provider status, internal status, **verification**, verified
time and channel, source, created, paid). Filters: status (incl. refunded / partially
refunded) and verification (verified by PayMongo, awaiting verification, not verified, verification failed,
needs review).

* There is **no** button that marks a payment paid or verified.
* **Re-check with PayMongo** (`POST /admin/payment-transactions/{id}/reconcile`, System Admin only, audited) runs
  the same server-side verification as the webhook. If PayMongo confirms a matching paid payment for a
  still-pending row it activates (exactly once, `verified_via = admin reconcile+fetch`); otherwise it records the
  outcome and changes nothing. `POST /admin/payment-transactions/reconcile {reference}` does the same for a
  reference that is **already stored** on an online transaction (YS- reference, `cs_`, `pi_`, `pay_`); any other
  value returns 404, so a typed provider id can never create, link or verify anything.
* A `review` row means PayMongo data did not match (amount, currency, reference, organization, mode, intent, or a
  payment id already used). Re-check never auto-activates a review row. Check it in the PayMongo Dashboard and, if
  correct, apply the plan with the normal subscription actions; refund in PayMongo otherwise.

### Verification states (derived, not stored twice)

| State | Meaning |
|---|---|
| `provider_verified` — "Verified by PayMongo" | `verified_at` is set: the server fetched and matched the payment. |
| `awaiting_verification` | PayMongo reports paid but the server has not verified/applied it yet (e.g. no webhook). |
| `mismatch` — "Verification failed" | status `review`: provider data did not match; nothing was activated. |
| `unverified` — "Not verified" | anything else (pending, failed, cancelled, expired, refunded before activation). |

`verified_at` is written only by the verification service, in the same DB transaction as the activation.

### Refunds (decision)

Refund events (`payment.refunded`, `payment.refund.updated`) and refunds seen on any later re-check are
re-read from PayMongo (`GET /v1/payments/{id}`, `refunds[]` with status `succeeded`; refund statuses per the docs:
pending, processing, succeeded, failed). The row becomes `refunded` / `partially_refunded` with
`refunded_centavos`, `refunded_at`, `review_required = 1` and an audit row. **The plan is never downgraded
automatically**: a refund after activation is flagged "needs admin review" and the admin decides with the normal
subscription actions. A refund seen before activation means the plan is never activated. Replays never apply a
refund twice; later "paid" events never re-grant a refunded payment.

### Manual / offline ledger (removed)

SK organizations pay only through PayMongo, so the admin-recorded manual / offline ledger was removed (UI and
API). `GET`/`POST /admin/payments`, `GET`/`POST /admin/organizations/{id}/payments` and
`POST /admin/payments/{id}/manual-verify` now return the router's normal 404. Admin revenue figures are
PayMongo-verified only. The `payments` table and any historical rows are kept (nothing dropped or deleted) but are
no longer read or written; see §9.

## 7. Browser test plan (once test keys and a webhook URL exist)

1. As System Admin: Admin → Payments shows "Gateway configured in TEST mode".
2. As an SK Official of a **test** organization: Plans shows "Pay online for Basic/Premium" and a TEST mode note.
3. Basic · Monthly → review shows ₱999.00 PHP, only the allowed methods, TEST MODE banner. Double-click
   "Continue to Payment": only one checkout is created; the browser goes to `checkout.paymongo.com`.
4. Pay with `4343 4343 4343 4345`. Return page: "Payment processing" then "Payment successful" with the new expiry.
   Subscription page shows Basic, Active, term +1 month. Admin list: status Paid, method card, paid time.
   Activity log: one `subscription` row by PayMongo.
5. Repeat with GCash test page → Authorize: same result (renewal of the same active plan extends from the current expiry).
6. Start a checkout and choose Fail on the e-wallet test page / use `4200 0000 0000 0018`: status stays
   "Awaiting payment" with the failure message; the plan does not change.
7. Start a checkout, press back/cancel on PayMongo: cancel page → "Cancel this checkout" → Cancelled; nothing changes.
8. In the Dashboard, resend the same webhook event: the admin list and the organization do not change again.
9. Open `/sk/subscription/payment/success?tx=<id of another org's payment>` → "Payment not found".
10. Remove `PAYMONGO_SECRET_KEY` → Plans/Checkout fall back to the labelled simulation; checkout API returns 503.

## 8. Going live (do not skip)

* Complete PayMongo account activation; confirm each method shows **Active** in live mode (Settings → Payment Methods).
* Live keys `sk_live_...`, `PAYMONGO_MODE=live`, a **separate live webhook endpoint** on the production HTTPS host and its own `whsk_...`.
* `PAYMONGO_CONFIRM_BY_RETRIEVAL` must be `false` (it is ignored in live anyway).
* Production `APP_ENV=production`, HTTPS, `SESSION_SECURE=true`, success/cancel URLs on the production frontend.
* Run one small real payment per enabled method, verify the webhook arrived with a valid signature, the plan
  applied once, the admin list matches the PayMongo Dashboard; refund the test payments.
* Do not claim live payments work until this has been done.

## 9. Data model

* `payment_transactions` (existing table, additive nullable columns): `user_id, amount_centavos, requested_method,
  method, provider_payment_id, provider_payment_intent_id, provider_status, livemode, failure_code, failure_message,
  activation_note, provider_checked_at, paid_at, applied_at`; unique `(provider, provider_reference)` and
  `(provider, provider_payment_id)`. `provider_reference` holds the checkout session id (`cs_...`).
* `payment_webhook_events` (new): unique `(provider, event_id)`, type, result, attempts.
* `payments` (legacy manual admin ledger, feature removed): kept with any historical rows, not read or written by
  the API. Unused historical columns: `source`, `manual_verified_at`, `manual_verified_by`, `manual_verification_note`
  (plus the original `recorded_by`, `note`, `method`). Not dropped (no destructive migration).
* Migration: applied automatically by `SchemaPatches::applyPaymentGateway`; same SQL in `api/sql/migration_20261009_payment_gateway.sql`.
* Verification migration (additive, nullable / defaulted; `SchemaPatches::applyPaymentVerification`, same SQL in
  `api/sql/migration_20261009_payment_verification.sql`): `payment_transactions.verified_via, refunded_centavos,
  refunded_at, review_required, review_reason`; `payments.source (default 'manual'), manual_verified_at,
  manual_verified_by, manual_verification_note`. No backfill: existing online rows keep their `verified_at`
  (set only by the old activation, which was already provider-verified); existing manual rows read as manual + unverified.
* Test-only fault hook `PAYMONGO_TEST_FAULT` (used by `api/sql/payment_verification_http_test.php` to prove the
  rollback) is honoured only with `PAYMONGO_MODE=test` **and** a plain-http loopback `PAYMONGO_API_BASE` (the MOCK);
  never set it in `.env`.

## 10. References (read 2026-10-09)

* Create a Checkout Session — https://developers.paymongo.com/reference/create-a-checkout
* Retrieve a Checkout Session — https://developers.paymongo.com/reference/retrieve-a-checkout
* Expire a Checkout Session — https://docs.paymongo.com/reference/expire-a-checkout-session
* Checkout Session resource — https://docs.paymongo.com/reference/checkout-session-resource
* Hosted checkout — https://docs.paymongo.com/docs/payment-channels-hosted-checkout
* Webhook setup & signature — https://docs.paymongo.com/docs/developer-tools-webhook-setup-management
* Webhook events — https://docs.paymongo.com/docs/developer-tools-webhooks-events
* Testing — https://docs.paymongo.com/docs/payment-acceptance-testing
* Go-live checklist — https://docs.paymongo.com/docs/developer-tools-go-live-checklist
