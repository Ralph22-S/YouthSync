<?php
declare(strict_types=1);

namespace YouthSync\Payments;

use PDO;
use PDOException;
use Throwable;
use YouthSync\Admin\ActivityLog;
use YouthSync\Http\Json;
use YouthSync\Http\Pagination;
use YouthSync\Org\PlanLimits;
use YouthSync\Subscription\SubscriptionService;

/**
 * Online subscription payments through PayMongo hosted checkout.
 *
 * Trust rules (see docs/PAYMONGO_SETUP.md):
 * - Organization, user, plan price and currency come from the session and the
 *   server catalog. A client-sent amount, organization id, status or transaction
 *   id is never used to decide anything.
 * - The success/cancel return URLs never activate anything; the pages only poll.
 * - A plan is activated only after (1) a webhook whose Paymongo-Signature HMAC
 *   verifies with the webhook secret, AND (2) an independent server-side fetch
 *   of the checkout session (secret key) shows a paid payment whose amount in
 *   integer centavos and currency PHP match this transaction, with the same
 *   checkout session id, reference number, metadata transaction id and mode.
 *   Optional, TEST mode only: PAYMONGO_CONFIRM_BY_RETRIEVAL=true lets the
 *   protected status endpoint activate from (2) alone (no public webhook URL).
 * - Activation runs in one DB transaction holding the organization row lock and
 *   the transaction row lock; applied_at makes retries a no-op (never double-grant).
 * - Any mismatch moves the transaction to "review" (money may have moved but the
 *   plan is NOT changed); a System Administrator resolves it manually.
 *
 * Verification and activation live in PaymentVerificationService (the single authority);
 * this class handles checkout creation, the SK/admin views and webhook authentication.
 *
 * Activation rule (decided in PaymentVerificationService, documented in API_DOCUMENTATION.md):
 * - Same plan, status active and term not yet ended -> extend from the current
 *   expiry by the paid cycle (1 or 12 months); cycle becomes the paid cycle.
 * - Anything else (free, trial, expired, cancelled, payment_failed, another plan)
 *   -> a new term from today: plan, active, started today, expires today + cycle.
 * - Evaluated at confirmation time, so a plan change or expiry that happened
 *   while the payment was pending is handled by the same rule: the organization
 *   gets exactly the plan and cycle it paid for.
 */
final class PaymentService
{
    public const PROVIDER = 'paymongo';

    /** PayMongo checkout payment_method_types values (docs: create-a-checkout). Labels are plain text. */
    public const METHODS = [
        'card' => 'Credit / debit card',
        'gcash' => 'GCash',
        'paymaya' => 'Maya',
        'grab_pay' => 'GrabPay',
        'shopee_pay' => 'ShopeePay',
        'qrph' => 'QR Ph',
        'billease' => 'BillEase',
        'dob' => 'Online banking (BPI)',
        'dob_ubp' => 'Online banking (UnionBank)',
        'brankas_bdo' => 'Online banking (BDO)',
        'brankas_landbank' => 'Online banking (Landbank)',
        'brankas_metrobank' => 'Online banking (Metrobank)',
    ];

    public const STATUSES = ['pending', 'paid', 'failed', 'cancelled', 'expired', 'review', 'refunded', 'partially_refunded'];

    public const STATUS_LABELS = [
        'pending' => 'Awaiting payment',
        'paid' => 'Paid',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        'review' => 'Needs review',
        'refunded' => 'Refunded',
        'partially_refunded' => 'Partially refunded',
    ];

    /**
     * Webhook events handled (docs: Create a Webhook / Events). Refunds: payment.refunded and
     * payment.refund.updated are the subscribable refund events; refund.succeeded is shown in the
     * events reference and is accepted too. Everything else is acknowledged and ignored.
     */
    private const PAID_EVENTS = ['checkout_session.payment.paid', 'payment.paid', 'payment.failed'];
    private const REFUND_EVENTS = ['payment.refunded', 'payment.refund.updated', 'refund.succeeded'];
    private const FINAL_EVENT_RESULTS = ['processed', 'already_applied', 'unpaid', 'expired', 'review', 'ignored', 'ignored_mode', 'unmatched',
        'refunded', 'already_refunded', 'not_pending'];
    private const RECHECK_SECONDS = 10;
    private const RECENT_CHECKOUT_SECONDS = 60;
    private const MAX_WEBHOOK_BYTES = 262144;

    private const SYSTEM_ACTOR = ['userId' => null, 'actorName' => 'PayMongo', 'actorRole' => 'system'];

    private PaymentVerificationService $verifier;

    /**
     * @param array<string, mixed> $config the 'paymongo' block of api/config/config.php
     */
    public function __construct(
        private PDO $pdo,
        private array $config,
        private SubscriptionService $subscriptions,
        private string $logFile,
        ?PaymentVerificationService $verifier = null,
    ) {
        $this->verifier = $verifier ?? new PaymentVerificationService($pdo, $config, $logFile);
    }

    public function verifier(): PaymentVerificationService
    {
        return $this->verifier;
    }

    // ------------------------------------------------------------------ configuration

    private function mode(): string
    {
        return (string) ($this->config['mode'] ?? 'test');
    }

    private function expectLive(): bool
    {
        return $this->mode() === 'live';
    }

    /**
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        $out = [];
        foreach (explode(',', (string) ($this->config['allowed_methods'] ?? '')) as $code) {
            $code = strtolower(trim($code));
            if ($code !== '' && isset(self::METHODS[$code]) && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /**
     * Whether online checkout can be offered, and why not. Never includes key material.
     *
     * @return array<string, mixed>
     */
    public function availability(): array
    {
        $mode = $this->mode();
        $secret = (string) ($this->config['secret_key'] ?? '');
        $reason = null;
        $message = null;
        if (!in_array($mode, ['test', 'live'], true)) {
            $reason = 'invalid_mode';
            $message = 'Online payment is not available: PAYMONGO_MODE must be "test" or "live".';
        } elseif ($secret === '' || ((string) ($this->config['webhook_secret'] ?? '') === '' && !$this->retrievalFallback())) {
            // Without a webhook secret nothing could ever be confirmed, except in TEST mode
            // with PAYMONGO_CONFIRM_BY_RETRIEVAL=true (server-side session fetch).
            $reason = 'not_configured';
            $message = 'Online payment is not configured on this server yet.';
        } elseif (!str_starts_with($secret, 'sk_' . $mode . '_')) {
            $reason = 'key_mode_mismatch';
            $message = 'Online payment is not available: the PayMongo secret key does not match PAYMONGO_MODE.';
        } elseif (!PayMongoClient::baseAllowed((string) ($this->config['api_base'] ?? ''))) {
            $reason = 'not_configured';
            $message = 'Online payment is not configured on this server yet.';
        } elseif ($this->allowedMethods() === []) {
            $reason = 'no_methods';
            $message = 'Online payment is configured, but no payment methods are enabled (PAYMONGO_ALLOWED_METHODS).';
        }

        $methods = [];
        foreach ($this->allowedMethods() as $code) {
            $methods[] = ['code' => $code, 'label' => self::METHODS[$code]];
        }

        return [
            'enabled' => $reason === null,
            'provider' => self::PROVIDER,
            'mode' => in_array($mode, ['test', 'live'], true) ? $mode : null,
            'reason' => $reason,
            'message' => $message,
            'methods' => $reason === null ? $methods : [],
            'currency' => 'PHP',
        ];
    }

    /** TEST mode only: confirm from the server-side checkout-session fetch (no public webhook URL). */
    private function retrievalFallback(): bool
    {
        return $this->verifier->retrievalFallback();
    }

    private function client(): PayMongoClient
    {
        return $this->verifier->client();
    }

    // ------------------------------------------------------------------ SK: checkout

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input only plan, cycle and method are read
     * @return array<string, mixed>
     */
    public function createCheckout(int $organizationId, array $user, array $input): array
    {
        $availability = $this->availability();
        if (!$availability['enabled']) {
            Json::error('CHECKOUT_NOT_CONFIGURED', (string) $availability['message'], 503, ['reason' => $availability['reason']]);
        }

        $plan = strtolower(trim((string) ($input['plan'] ?? '')));
        $cycle = strtolower(trim((string) ($input['cycle'] ?? '')));
        $method = strtolower(trim((string) ($input['method'] ?? '')));
        $errors = [];
        if (!in_array($plan, ['basic', 'premium'], true)) {
            $errors['plan'] = 'Choose Basic or Premium.';
        }
        if (!isset(PlanLimits::CYCLES[$cycle])) {
            $errors['cycle'] = 'Billing cycle must be monthly or yearly.';
        }
        $allowed = $this->allowedMethods();
        if ($method !== '' && !in_array($method, $allowed, true)) {
            $errors['method'] = 'That payment method is not available.';
        }
        if ($errors) {
            Json::validation($errors);
        }
        $centavos = PlanLimits::priceCentavos($plan, $cycle);
        if ($centavos === null || $centavos < 100) {
            Json::validation(['plan' => 'That plan has no online price.']);
        }

        $reference = 'YS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
        $userId = (int) ($user['id'] ?? 0);

        // Short locked section: one new checkout per organization per minute (double-submit guard).
        $this->pdo->beginTransaction();
        try {
            PlanLimits::lockOrganization($this->pdo, $organizationId);
            $stmt = $this->pdo->prepare(
                "SELECT id FROM payment_transactions
                 WHERE organization_id = :o AND provider = :p AND status = 'pending'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL " . self::RECENT_CHECKOUT_SECONDS . ' SECOND)
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['o' => $organizationId, 'p' => self::PROVIDER]);
            $recent = $stmt->fetchColumn();
            if ($recent !== false) {
                $this->pdo->rollBack();
                Json::error(
                    'CHECKOUT_IN_PROGRESS',
                    'A checkout was started for your organization less than a minute ago. Finish or cancel it, or wait a minute and try again.',
                    409,
                    ['transactionId' => (int) $recent]
                );
            }
            $this->pdo->prepare(
                "INSERT INTO payment_transactions
                    (organization_id, user_id, plan, cycle, amount, amount_centavos, currency, reference,
                     provider, requested_method, status, livemode)
                 VALUES
                    (:o, :u, :plan, :cycle, :amount, :centavos, 'PHP', :ref, :p, :method, 'pending', :live)"
            )->execute([
                'o' => $organizationId,
                'u' => $userId > 0 ? $userId : null,
                'plan' => $plan,
                'cycle' => $cycle,
                'amount' => number_format($centavos / 100, 2, '.', ''),
                'centavos' => $centavos,
                'ref' => $reference,
                'p' => self::PROVIDER,
                'method' => $method !== '' ? $method : null,
                'live' => $this->expectLive() ? 1 : 0,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $planName = PlanLimits::plan($plan)['name'];
        $cycleLabel = PlanLimits::CYCLE_LABELS[$cycle] ?? $cycle;
        $attributes = [
            'line_items' => [[
                'name' => "YouthSync {$planName} plan ({$cycleLabel})",
                'amount' => $centavos,
                'currency' => 'PHP',
                'quantity' => 1,
            ]],
            'payment_method_types' => $method !== '' ? [$method] : $allowed,
            'reference_number' => $reference,
            'description' => "YouthSync subscription {$reference}",
            'success_url' => str_replace('{id}', (string) $id, (string) ($this->config['success_url'] ?? '')),
            'cancel_url' => str_replace('{id}', (string) $id, (string) ($this->config['cancel_url'] ?? '')),
            'send_email_receipt' => false,
            'show_description' => true,
            'show_line_items' => true,
            'metadata' => [
                'transaction_id' => (string) $id,
                'reference' => $reference,
                'organization_id' => (string) $organizationId,
                'plan' => $plan,
                'cycle' => $cycle,
            ],
        ];

        try {
            $session = $this->client()->createCheckoutSession($attributes);
        } catch (PayMongoException $e) {
            $this->markInitFailed($id, $e->providerCode, $e->getMessage());
            $this->log("checkout_init_failed tx={$id} code={$e->providerCode} http={$e->httpStatus}");
            Json::error(
                'PAYMENT_PROVIDER_ERROR',
                'The payment provider could not start checkout. Nothing was charged and your plan did not change. Please try again later.',
                502,
                ['transactionId' => $id]
            );
        } catch (Throwable $e) {
            $this->markInitFailed($id, 'PROVIDER_ERROR', 'Unexpected error while starting checkout.');
            $this->log("checkout_init_failed tx={$id} exception=" . get_class($e));
            Json::error(
                'PAYMENT_PROVIDER_ERROR',
                'The payment provider could not start checkout. Nothing was charged and your plan did not change. Please try again later.',
                502,
                ['transactionId' => $id]
            );
        }

        $sessionId = (string) ($session['id'] ?? '');
        $sAttr = is_array($session['attributes'] ?? null) ? $session['attributes'] : [];
        $checkoutUrl = (string) ($sAttr['checkout_url'] ?? '');
        if (preg_match('/^cs_[A-Za-z0-9]{6,60}$/', $sessionId) !== 1 || !str_starts_with($checkoutUrl, 'https://')) {
            $this->markInitFailed($id, 'PROVIDER_ERROR', 'The payment provider returned an unexpected checkout session.');
            $this->log("checkout_init_failed tx={$id} code=UNEXPECTED_SESSION");
            Json::error(
                'PAYMENT_PROVIDER_ERROR',
                'The payment provider could not start checkout. Nothing was charged and your plan did not change. Please try again later.',
                502,
                ['transactionId' => $id]
            );
        }
        $intent = $sAttr['payment_intent']['id'] ?? null;
        $intent = is_string($intent) && preg_match('/^pi_[A-Za-z0-9]{6,60}$/', $intent) === 1 ? $intent : null;

        $this->pdo->prepare(
            'UPDATE payment_transactions
             SET provider_reference = :cs, provider_payment_intent_id = :pi, provider_status = :ps, provider_checked_at = NOW()
             WHERE id = :id'
        )->execute([
            'cs' => $sessionId,
            'pi' => $intent,
            'ps' => mb_substr((string) ($sAttr['status'] ?? 'active'), 0, 32),
            'id' => $id,
        ]);

        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($user), [
            'organizationId' => $organizationId,
            'action' => ActivityLog::ACTION_CREATE,
            'category' => 'payment',
            'description' => "Started online checkout {$reference} for {$planName} ({$cycle}), PHP "
                . number_format($centavos / 100, 2) . ' via PayMongo (' . $this->mode() . ' mode). Plan not changed until payment is confirmed.',
            'entityType' => 'payment_transaction',
            'entityId' => $id,
        ]));
        $this->log("checkout_created tx={$id} cs={$sessionId}");

        return [
            'transaction' => $this->view($this->row($id)),
            'checkoutUrl' => $checkoutUrl,
        ];
    }

    /**
     * Protected status for the return pages. Refreshes from PayMongo (server side)
     * at most every RECHECK_SECONDS while pending. Never trusts the browser.
     *
     * @return array<string, mixed>
     */
    public function show(int $organizationId, int $id): array
    {
        $row = $this->ownRow($organizationId, $id);
        if ($row['status'] === 'pending' && $row['provider_reference'] !== null && (string) ($this->config['secret_key'] ?? '') !== '') {
            $checked = $row['provider_checked_at'] !== null ? strtotime((string) $row['provider_checked_at']) : false;
            if ($checked === false || time() - $checked >= self::RECHECK_SECONDS) {
                $this->verifier->verify($row, PaymentVerificationService::SOURCE_POLL);
                $row = $this->row($id);
            }
        }
        return [
            'transaction' => $this->view($row),
            'subscription' => $row['status'] === 'paid' ? $this->subscriptions->view($organizationId) : null,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function cancel(int $organizationId, array $user, int $id): array
    {
        $row = $this->ownRow($organizationId, $id);
        if ($row['status'] !== 'pending' || $row['provider_reference'] === null) {
            Json::error('CONFLICT', 'This checkout is no longer pending, so there is nothing to cancel.', 409);
        }
        try {
            $session = $this->client()->expireCheckoutSession((string) $row['provider_reference']);
        } catch (PayMongoException $e) {
            $this->log("checkout_cancel_failed tx={$id} code={$e->providerCode} http={$e->httpStatus}");
            Json::error('PAYMENT_PROVIDER_ERROR', 'The payment provider could not cancel this checkout right now. Please try again.', 502);
        }
        $verdict = $this->verifier->verdict($row, $session);
        if ($verdict['state'] === 'paid') {
            $this->pdo->prepare("UPDATE payment_transactions SET provider_status = 'paid', provider_checked_at = NOW() WHERE id = :id AND status = 'pending'")
                ->execute(['id' => $id]);
            Json::error('CONFLICT', 'This checkout was already paid. It will be confirmed shortly.', 409);
        }
        $stmt = $this->pdo->prepare(
            "UPDATE payment_transactions SET status = 'cancelled', provider_status = 'expired', provider_checked_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() > 0) {
            ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($user), [
                'organizationId' => $organizationId,
                'action' => ActivityLog::ACTION_UPDATE,
                'category' => 'payment',
                'description' => 'Cancelled online checkout ' . $row['reference'] . ' (PayMongo session expired). Nothing was charged; plan not changed.',
                'entityType' => 'payment_transaction',
                'entityId' => $id,
            ]));
        }
        return ['transaction' => $this->view($this->row($id))];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listForOrganization(int $organizationId, array $query): array
    {
        $paging = Pagination::fromQuery($query, 10, 50);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM payment_transactions WHERE organization_id = :o AND provider = :p');
        $stmt->execute(['o' => $organizationId, 'p' => self::PROVIDER]);
        $meta = $paging->meta((int) $stmt->fetchColumn());
        $stmt = $this->pdo->prepare(
            "SELECT * FROM payment_transactions WHERE organization_id = :o AND provider = :p
             ORDER BY created_at DESC, id DESC {$paging->limitSql()}"
        );
        $stmt->execute(['o' => $organizationId, 'p' => self::PROVIDER]);
        $items = array_map(fn (array $row): array => $this->view($row), $stmt->fetchAll());
        return ['items' => $items, ...$meta];
    }

    // ------------------------------------------------------------------ admin

    /**
     * System Administrator list of online payment transactions (read-only).
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listForAdmin(array $query): array
    {
        $where = ['t.provider = :p'];
        $params = ['p' => self::PROVIDER];
        $status = trim((string) ($query['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                Json::error('VALIDATION_ERROR', 'That payment status is not recognised.', 422);
            }
            $where[] = 't.status = :status';
            $params['status'] = $status;
        }
        $verification = trim((string) ($query['verification'] ?? ''));
        if ($verification !== '') {
            $clause = [
                'provider_verified' => 't.verified_at IS NOT NULL',
                'awaiting_verification' => "t.verified_at IS NULL AND t.status = 'pending' AND t.provider_status = 'paid'",
                'mismatch' => "t.status = 'review'",
                'unverified' => "t.verified_at IS NULL AND t.status <> 'review' AND NOT (t.status = 'pending' AND t.provider_status <=> 'paid')",
                'needs_review' => "(t.status = 'review' OR t.review_required = 1)",
            ][$verification] ?? null;
            if ($clause === null) {
                Json::error('VALIDATION_ERROR', 'That verification filter is not recognised.', 422);
            }
            $where[] = $clause;
        }
        $orgFilter = $query['organizationId'] ?? $query['organization_id'] ?? null;
        if ($orgFilter !== null && $orgFilter !== '') {
            $where[] = 't.organization_id = :org';
            $params['org'] = max(0, (int) $orgFilter);
        }
        $q = Pagination::search($query);
        if ($q !== '') {
            $where[] = Pagination::likeAny(['t.reference', 'o.name', 't.provider_reference'], $q, 'q', $params);
        }
        $sqlWhere = implode(' AND ', $where);
        $paging = Pagination::fromQuery($query, 25, 200);

        $count = $this->pdo->prepare(
            "SELECT COUNT(*) FROM payment_transactions t LEFT JOIN organizations o ON o.id = t.organization_id WHERE {$sqlWhere}"
        );
        $count->execute($params);
        $meta = $paging->meta((int) $count->fetchColumn());

        $stmt = $this->pdo->prepare(
            "SELECT t.*, o.name AS organization_name
             FROM payment_transactions t
             LEFT JOIN organizations o ON o.id = t.organization_id
             WHERE {$sqlWhere}
             ORDER BY t.created_at DESC, t.id DESC
             {$paging->limitSql()}"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->adminItem($row);
        }

        $totals = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status IN ('paid', 'partially_refunded') AND verified_at IS NOT NULL THEN amount_centavos ELSE 0 END), 0) AS collected,
                COALESCE(SUM(COALESCE(refunded_centavos, 0)), 0) AS refunded,
                COALESCE(SUM(verified_at IS NOT NULL), 0) AS verified_count,
                COALESCE(SUM(status = 'pending'), 0) AS pending_count,
                COALESCE(SUM(status = 'pending' AND provider_status = 'paid' AND verified_at IS NULL), 0) AS awaiting_count,
                COALESCE(SUM(status = 'review' OR review_required = 1), 0) AS review_count,
                COALESCE(SUM(status IN ('failed', 'cancelled', 'expired')), 0) AS unpaid_count
             FROM payment_transactions WHERE provider = :p"
        );
        $totals->execute(['p' => self::PROVIDER]);
        $t = $totals->fetch() ?: [];

        return [
            'items' => $items,
            ...$meta,
            'totals' => [
                'collected' => ((int) ($t['collected'] ?? 0)) / 100,
                'refunded' => ((int) ($t['refunded'] ?? 0)) / 100,
                'verified' => (int) ($t['verified_count'] ?? 0),
                'awaitingVerification' => (int) ($t['awaiting_count'] ?? 0),
                'pending' => (int) ($t['pending_count'] ?? 0),
                'review' => (int) ($t['review_count'] ?? 0),
                'notPaid' => (int) ($t['unpaid_count'] ?? 0),
            ],
            'gateway' => $this->availability(),
        ];
    }

    /**
     * @param array<string, mixed> $row payment_transactions row + organization_name
     * @return array<string, mixed>
     */
    private function adminItem(array $row): array
    {
        return array_merge($this->view($row), [
            'organizationName' => isset($row['organization_name']) && $row['organization_name'] !== null ? (string) $row['organization_name'] : null,
            'userId' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'providerReference' => $row['provider_reference'],
            'providerPaymentId' => $row['provider_payment_id'],
            'providerPaymentIntentId' => $row['provider_payment_intent_id'],
            'appliedAt' => $row['applied_at'],
            'providerCheckedAt' => $row['provider_checked_at'],
            'canReconcile' => $row['provider_reference'] !== null,
        ]);
    }

    /**
     * System Administrator "Re-check with PayMongo": runs the verification service. There is
     * deliberately no route that sets a status; only PayMongo's own data can.
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function reconcile(int $id, array $admin): array
    {
        $out = $this->verifier->reconcile($id, $admin);
        $stmt = $this->pdo->prepare(
            'SELECT t.*, o.name AS organization_name FROM payment_transactions t
             LEFT JOIN organizations o ON o.id = t.organization_id WHERE t.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return ['result' => $out['result'], 'message' => $out['message'], 'transaction' => $this->adminItem($stmt->fetch() ?: $out['row'])];
    }

    /**
     * Reconcile by a reference that is ALREADY stored on an online transaction (YS- reference,
     * cs_ session, pi_ intent or pay_ payment). Anything else is not found: a typed provider
     * id cannot create, link or verify a transaction.
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function reconcileByReference(string $reference, array $admin): array
    {
        $row = $this->verifier->rowByStoredReference($reference);
        if ($row === null) {
            Json::error('NOT_FOUND', 'No online transaction has that reference. Only references created by YouthSync checkouts can be verified.', 404);
        }
        return $this->reconcile((int) $row['id'], $admin);
    }

    // ------------------------------------------------------------------ webhook

    /**
     * POST /payments/paymongo/webhook. Public; authenticated only by the
     * Paymongo-Signature HMAC over "{t}.{raw body}" with the webhook secret.
     */
    public function handleWebhook(string $rawBody, string $signatureHeader): never
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        if ($secret === '' || !in_array($this->mode(), ['test', 'live'], true)) {
            Json::error('WEBHOOK_NOT_CONFIGURED', 'Payment webhooks are not configured on this server.', 503);
        }
        if (strlen($rawBody) > self::MAX_WEBHOOK_BYTES) {
            Json::error('PAYLOAD_TOO_LARGE', 'Webhook body is too large.', 413);
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $piece) {
            $kv = explode('=', trim($piece), 2);
            if (count($kv) === 2 && $kv[0] !== '') {
                $parts[strtolower(trim($kv[0]))] = trim($kv[1]);
            }
        }
        if ($signatureHeader === '' || !isset($parts['t']) || preg_match('/^\d{9,11}$/', $parts['t']) !== 1) {
            $this->log('webhook_rejected reason=missing_or_malformed_signature_header');
            Json::error('INVALID_SIGNATURE', 'Missing or malformed Paymongo-Signature header.', 400);
        }
        $key = $this->expectLive() ? 'li' : 'te';
        $provided = strtolower((string) ($parts[$key] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $provided) !== 1) {
            $this->log("webhook_rejected reason=no_{$key}_signature");
            Json::error('INVALID_SIGNATURE', 'Webhook signature is not valid.', 401);
        }
        $expected = hash_hmac('sha256', $parts['t'] . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $provided)) {
            $this->log('webhook_rejected reason=signature_mismatch');
            Json::error('INVALID_SIGNATURE', 'Webhook signature is not valid.', 401);
        }
        $tolerance = (int) ($this->config['webhook_tolerance'] ?? 300);
        if (abs(time() - (int) $parts['t']) > $tolerance) {
            $this->log('webhook_rejected reason=stale_timestamp');
            Json::error('INVALID_SIGNATURE', 'Webhook timestamp is outside the allowed window.', 401);
        }

        $decoded = json_decode($rawBody, true);
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        $eventId = is_array($data) ? (string) ($data['id'] ?? '') : '';
        $attrs = is_array($data) && is_array($data['attributes'] ?? null) ? $data['attributes'] : null;
        $type = is_array($attrs) ? (string) ($attrs['type'] ?? '') : '';
        $resource = is_array($attrs) && is_array($attrs['data'] ?? null) ? $attrs['data'] : null;
        if (preg_match('/^[A-Za-z0-9_]{4,64}$/', $eventId) !== 1 || preg_match('/^[a-z_.]{3,64}$/', $type) !== 1 || $resource === null) {
            $this->log('webhook_rejected reason=malformed_event');
            Json::error('INVALID_EVENT', 'Webhook body is not a PayMongo event.', 400);
        }
        $resourceId = (string) ($resource['id'] ?? '');
        $resourceId = preg_match('/^[A-Za-z0-9_]{3,64}$/', $resourceId) === 1 ? $resourceId : null;
        $livemode = (bool) ($attrs['livemode'] ?? false);

        // Idempotency by provider event id (PayMongo retries up to 12 times).
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO payment_webhook_events (provider, event_id, event_type, livemode, resource_id)
             VALUES (:p, :e, :t, :l, :r)'
        );
        $insert->execute(['p' => self::PROVIDER, 'e' => $eventId, 't' => $type, 'l' => $livemode ? 1 : 0, 'r' => $resourceId]);
        $stmt = $this->pdo->prepare('SELECT id, result FROM payment_webhook_events WHERE provider = :p AND event_id = :e');
        $stmt->execute(['p' => self::PROVIDER, 'e' => $eventId]);
        $event = $stmt->fetch();
        $eventRowId = (int) ($event['id'] ?? 0);
        if ($insert->rowCount() === 0) {
            $this->pdo->prepare('UPDATE payment_webhook_events SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $eventRowId]);
            if (in_array((string) $event['result'], self::FINAL_EVENT_RESULTS, true)) {
                $this->log("webhook_duplicate event={$eventId} result={$event['result']}");
                Json::success(['received' => true, 'duplicate' => true, 'result' => (string) $event['result']]);
            }
        }

        $finish = function (string $result, ?int $txId = null) use ($eventRowId, $eventId, $type): void {
            $this->pdo->prepare(
                'UPDATE payment_webhook_events SET result = :r, transaction_id = COALESCE(:tx, transaction_id), processed_at = NOW() WHERE id = :id'
            )->execute(['r' => $result, 'tx' => $txId, 'id' => $eventRowId]);
            $this->log("webhook event={$eventId} type={$type} tx=" . ($txId ?? '-') . " result={$result}");
        };

        if ($livemode !== $this->expectLive()) {
            $finish('ignored_mode');
            Json::success(['received' => true, 'result' => 'ignored_mode']);
        }
        if (!in_array($type, self::PAID_EVENTS, true) && !in_array($type, self::REFUND_EVENTS, true)) {
            $finish('ignored');
            Json::success(['received' => true, 'result' => 'ignored']);
        }

        $txRow = null;
        try {
            $resAttrs = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
            if ($type === 'checkout_session.payment.paid') {
                if ($resourceId !== null && str_starts_with($resourceId, 'cs_')) {
                    $txRow = $this->rowBy('provider_reference', $resourceId);
                }
            } elseif (in_array($type, self::REFUND_EVENTS, true)) {
                // Lookup only. The refund itself is re-read from PayMongo (GET /v1/payments/{id}).
                $paymentId = null;
                if ($resourceId !== null && str_starts_with($resourceId, 'pay_')) {
                    $paymentId = $resourceId;
                } elseif (is_string($resAttrs['payment_id'] ?? null) && preg_match('/^pay_[A-Za-z0-9]{6,60}$/', $resAttrs['payment_id']) === 1) {
                    $paymentId = $resAttrs['payment_id'];
                }
                if ($paymentId !== null) {
                    $txRow = $this->rowBy('provider_payment_id', $paymentId);
                }
                $intent = $resAttrs['payment_intent_id'] ?? null;
                if ($txRow === null && $paymentId !== null && !is_string($intent)) {
                    try {
                        $fetched = $this->client()->retrievePayment($paymentId);
                        $intent = $fetched['attributes']['payment_intent_id'] ?? null;
                    } catch (PayMongoException) {
                        $intent = null;
                    }
                }
                if ($txRow === null && is_string($intent) && str_starts_with($intent, 'pi_')) {
                    $txRow = $this->rowBy('provider_payment_intent_id', $intent);
                }
            } else {
                $intent = $resAttrs['payment_intent_id'] ?? null;
                if (is_string($intent) && str_starts_with($intent, 'pi_')) {
                    $txRow = $this->rowBy('provider_payment_intent_id', $intent);
                }
                if ($txRow === null) {
                    // Lookup hint only; the checkout session fetch below re-verifies everything.
                    $ref = is_array($resAttrs['metadata'] ?? null) ? ($resAttrs['metadata']['reference'] ?? null) : null;
                    if (is_string($ref) && preg_match('/^YS-\d{8}-[A-F0-9]{10}$/', $ref) === 1) {
                        $txRow = $this->rowBy('reference', $ref);
                    }
                }
            }
            if ($txRow === null) {
                $finish('unmatched');
                Json::success(['received' => true, 'result' => 'unmatched']);
            }
            $txId = (int) $txRow['id'];

            if ($type === 'payment.failed') {
                $code = PayMongoClient::safeText((string) ($resAttrs['failed_code'] ?? 'payment_failed'));
                $msg = PayMongoClient::safeText((string) ($resAttrs['failed_message'] ?? 'The payment attempt failed.'));
                $this->pdo->prepare(
                    "UPDATE payment_transactions
                     SET provider_status = 'payment_failed', failure_code = :c, failure_message = :m
                     WHERE id = :id AND status = 'pending'"
                )->execute(['c' => mb_substr($code, 0, 64), 'm' => $msg, 'id' => $txId]);
            }

            $result = $this->verifier->verify($this->row($txId), PaymentVerificationService::SOURCE_WEBHOOK);
        } catch (Throwable $e) {
            $finish('error', isset($txId) ? $txId : null);
            $this->log("webhook_error event={$eventId} exception=" . get_class($e));
            Json::error('SERVER_ERROR', 'The webhook could not be processed. It will be retried.', 500);
        }

        if ($result === 'unavailable') {
            $finish('error', $txId);
            Json::error('VERIFICATION_UNAVAILABLE', 'The payment could not be verified with the provider yet. Retry later.', 503);
        }
        $map = [
            'applied' => 'processed',
            'already_applied' => 'already_applied',
            'review' => 'review',
            'unpaid' => 'unpaid',
            'expired' => 'expired',
            'awaiting_webhook' => 'processed',
            'refunded' => 'refunded',
            'already_refunded' => 'already_refunded',
            'not_pending' => 'not_pending',
            'not_started' => 'unmatched',
        ];
        $final = $map[$result] ?? 'processed';
        $finish($final, $txId);
        Json::success(['received' => true, 'result' => $final]);
    }

    private function markInitFailed(int $id, string $code, string $message): void
    {
        $this->pdo->prepare(
            "UPDATE payment_transactions
             SET status = 'failed', provider_status = 'not_created', failure_code = :c, failure_message = :m
             WHERE id = :id AND status = 'pending'"
        )->execute(['c' => mb_substr($code, 0, 64), 'm' => PayMongoClient::safeText($message), 'id' => $id]);
    }

    // ------------------------------------------------------------------ rows + views

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payment_transactions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'That payment was not found.', 404);
        }
        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowBy(string $column, string $value): ?array
    {
        if (!in_array($column, ['provider_reference', 'provider_payment_intent_id', 'provider_payment_id', 'reference'], true)) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT * FROM payment_transactions WHERE provider = :p AND {$column} = :v ORDER BY id DESC LIMIT 1");
        $stmt->execute(['p' => self::PROVIDER, 'v' => $value]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function ownRow(int $organizationId, int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payment_transactions WHERE id = :id AND organization_id = :o AND provider = :p');
        $stmt->execute(['id' => $id, 'o' => $organizationId, 'p' => self::PROVIDER]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'That payment was not found.', 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function view(array $row): array
    {
        $status = (string) $row['status'];
        $method = $row['method'] ?? null;
        $requested = $row['requested_method'] ?? null;
        $centavos = $row['amount_centavos'] !== null ? (int) $row['amount_centavos'] : (int) round(((float) $row['amount']) * 100);
        return [
            'id' => (int) $row['id'],
            'reference' => (string) $row['reference'],
            'organizationId' => (int) $row['organization_id'],
            'plan' => (string) $row['plan'],
            'planName' => PlanLimits::plan((string) $row['plan'])['name'],
            'cycle' => (string) $row['cycle'],
            'amount' => $centavos / 100,
            'amountCentavos' => $centavos,
            'currency' => (string) $row['currency'],
            'provider' => (string) $row['provider'],
            'mode' => $row['livemode'] === null ? null : ((int) $row['livemode'] === 1 ? 'live' : 'test'),
            'status' => $status,
            'statusLabel' => self::STATUS_LABELS[$status] ?? ucfirst($status),
            'providerStatus' => $row['provider_status'],
            'requestedMethod' => $requested,
            'method' => $method,
            'methodLabel' => is_string($method) ? (self::METHODS[$method] ?? ucfirst(str_replace('_', ' ', $method))) : null,
            'failureCode' => $row['failure_code'],
            'failureMessage' => $row['failure_message'],
            'activationNote' => $row['activation_note'],
            'createdAt' => $row['created_at'],
            'paidAt' => $row['paid_at'],
        ] + $this->verifier->verificationView($row);
    }

    private function log(string $line): void
    {
        @file_put_contents($this->logFile, sprintf("[%s] paymongo %s\n", date('c'), $line), FILE_APPEND);
    }
}
