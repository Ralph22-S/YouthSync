<?php
declare(strict_types=1);

namespace YouthSync\Payments;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use YouthSync\Admin\ActivityLog;
use YouthSync\Http\Json;
use YouthSync\Org\PlanLimits;

/**
 * The ONE place where an online payment is verified with PayMongo and a plan is granted.
 *
 * Every path (signed webhook, SK status poll, System Administrator "Re-check with PayMongo")
 * calls verify(). Nothing the browser or an administrator types is evidence:
 * - The checkout session is fetched server-side (GET /v1/checkout_sessions/{id}) and must
 *   belong to the transaction: same session id, reference_number, metadata transaction_id,
 *   organization_id and reference, same test/live mode, PHP line items totalling the stored
 *   amount in integer centavos.
 * - The paid payment is then fetched on its own (GET /v1/payments/{id}) and must match:
 *   id, amount (centavos), currency PHP, mode and, when present, the stored payment intent.
 *   Its refunds[] (refund status "succeeded" only) decide refunded / partially refunded.
 * - verified_at / verified_via are written only here, inside the activation transaction,
 *   after both checks pass. Admin-entered or client-sent statuses never set them.
 * - Activation (plan change) happens only for a row that is still "pending", under the
 *   organization + transaction row locks, exactly once (applied_at, unique payment id),
 *   and rolls back completely on any error.
 * - Refund after activation: status refunded / partially_refunded, review_required=1, audit.
 *   The plan is NOT downgraded automatically (decision: an administrator reviews it).
 * - Anything that does not match moves the row to "review" (never granted).
 *
 * Sources: "webhook" (signature already verified by PaymentService), "admin_reconcile"
 * (System Administrator), and "poll" (SK status page; activates only in TEST mode with
 * PAYMONGO_CONFIRM_BY_RETRIEVAL=true, otherwise it reports "paid - awaiting verification").
 */
final class PaymentVerificationService
{
    public const PROVIDER = 'paymongo';

    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_POLL = 'poll';
    public const SOURCE_ADMIN = 'admin_reconcile';

    public const VERIFICATION_LABELS = [
        'provider_verified' => 'Verified by PayMongo',
        'awaiting_verification' => 'Paid at PayMongo - awaiting verification',
        'mismatch' => 'Verification failed - needs review',
        'unverified' => 'Not verified',
    ];

    private const SYSTEM_ACTOR = ['userId' => null, 'actorName' => 'PayMongo', 'actorRole' => 'system'];

    private ?PayMongoClient $client = null;

    /**
     * @param array<string, mixed> $config the 'paymongo' block of api/config/config.php
     */
    public function __construct(
        private PDO $pdo,
        private array $config,
        private string $logFile,
    ) {
    }

    // ------------------------------------------------------------------ configuration

    public function mode(): string
    {
        return (string) ($this->config['mode'] ?? 'test');
    }

    public function expectLive(): bool
    {
        return $this->mode() === 'live';
    }

    /** TEST mode only: the SK status poll may activate from the server-side fetch alone. */
    public function retrievalFallback(): bool
    {
        return $this->mode() === 'test' && !empty($this->config['confirm_by_retrieval']);
    }

    public function client(): PayMongoClient
    {
        if ($this->client === null) {
            $this->client = new PayMongoClient(
                (string) ($this->config['secret_key'] ?? ''),
                (string) ($this->config['api_base'] ?? 'https://api.paymongo.com'),
            );
        }
        return $this->client;
    }

    /**
     * MOCK-only fault injection for the rollback test. Honoured ONLY in test mode while the
     * API base is a plain-http loopback address (the local MOCK server); never with the real
     * https://api.paymongo.com, so it cannot affect real or live payments.
     */
    private function fault(string $point): void
    {
        $fault = (string) ($this->config['test_fault'] ?? '');
        if ($fault === '' || $fault !== $point || $this->mode() !== 'test') {
            return;
        }
        $base = (string) ($this->config['api_base'] ?? '');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        if (strtolower((string) parse_url($base, PHP_URL_SCHEME)) === 'http' && in_array($host, ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('MOCK fault injection: ' . $point);
        }
    }

    // ------------------------------------------------------------------ public API

    /**
     * Verify one transaction with PayMongo and act on the result.
     *
     * @param array<string, mixed> $row a payment_transactions row
     * @return string applied|already_applied|awaiting_webhook|unpaid|expired|review|refunded|already_refunded|unavailable|not_started|not_pending
     */
    public function verify(array $row, string $source): string
    {
        $id = (int) $row['id'];
        if ($row['provider_reference'] === null || (string) $row['provider_reference'] === '') {
            return 'not_started';
        }
        $status = (string) $row['status'];

        // Already granted: re-check the payment itself (refunds, ownership). Never re-grants.
        if ($row['applied_at'] !== null && $row['provider_payment_id'] !== null) {
            try {
                $payment = $this->client()->retrievePayment((string) $row['provider_payment_id']);
            } catch (PayMongoException $e) {
                return $this->unavailable($id, $e);
            }
            $check = $this->paymentCheck($row, $payment, (string) $row['provider_payment_id']);
            if ($check['state'] === 'mismatch') {
                $this->flagReview($id, (string) $check['reason']);
                return 'review';
            }
            if ($check['refunded'] > 0) {
                return $this->recordRefund($id, (int) $check['refunded'], (string) $row['provider_payment_id']);
            }
            $this->touch($id);
            return 'already_applied';
        }

        if ($status === 'review') {
            return 'review';
        }
        if (in_array($status, ['refunded', 'partially_refunded'], true)) {
            return 'already_refunded';
        }
        if ($status === 'failed') {
            return 'not_pending';
        }

        try {
            $session = $this->client()->retrieveCheckoutSession((string) $row['provider_reference']);
        } catch (PayMongoException $e) {
            return $this->unavailable($id, $e);
        }
        $verdict = $this->verdict($row, $session);

        switch ($verdict['state']) {
            case 'paid':
                // Independent second fetch of the payment resource before trusting it.
                try {
                    $payment = $this->client()->retrievePayment((string) $verdict['paymentId']);
                } catch (PayMongoException $e) {
                    return $this->unavailable($id, $e);
                }
                $check = $this->paymentCheck($row, $payment, (string) $verdict['paymentId']);
                if ($check['state'] === 'mismatch') {
                    $this->markReview($id, (string) $check['reason']);
                    return 'review';
                }
                if ($check['intent'] !== null) {
                    $verdict['intent'] = $check['intent'];
                }
                if ($check['refunded'] > 0) {
                    // Refunded before we granted anything: never activates.
                    return $this->recordRefund($id, (int) $check['refunded'], (string) $verdict['paymentId']);
                }
                if ($status !== 'pending') {
                    $this->markReview($id, "PayMongo reports a paid payment for a checkout that is {$status} here. Not activated.");
                    return 'review';
                }
                $activate = $source === self::SOURCE_WEBHOOK || $source === self::SOURCE_ADMIN
                    || ($source === self::SOURCE_POLL && $this->retrievalFallback());
                if ($activate) {
                    $via = match ($source) {
                        self::SOURCE_WEBHOOK => 'webhook+fetch',
                        self::SOURCE_ADMIN => 'admin reconcile+fetch',
                        default => 'fetch (test mode)',
                    };
                    return $this->apply($id, $verdict, $via);
                }
                $this->pdo->prepare(
                    "UPDATE payment_transactions SET provider_status = 'paid', provider_checked_at = NOW() WHERE id = :id AND status = 'pending'"
                )->execute(['id' => $id]);
                return 'awaiting_webhook';
            case 'mismatch':
                $this->markReview($id, (string) $verdict['reason']);
                return 'review';
            case 'expired':
                $this->pdo->prepare(
                    "UPDATE payment_transactions SET status = 'expired', provider_status = 'expired', provider_checked_at = NOW()
                     WHERE id = :id AND status = 'pending'"
                )->execute(['id' => $id]);
                return 'expired';
            default:
                $this->pdo->prepare(
                    'UPDATE payment_transactions SET provider_status = :ps, provider_checked_at = NOW() WHERE id = :id AND status = :st'
                )->execute([
                    'ps' => $row['provider_status'] === 'payment_failed' ? 'payment_failed' : mb_substr((string) ($verdict['sessionStatus'] ?? 'active'), 0, 32),
                    'id' => $id,
                    'st' => 'pending',
                ]);
                return 'unpaid';
        }
    }

    /**
     * System Administrator "Re-check with PayMongo". Audited with the admin as actor.
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function reconcile(int $id, array $admin): array
    {
        $row = $this->row($id);
        if ((string) $row['provider'] !== self::PROVIDER) {
            Json::error('NOT_FOUND', 'That online payment was not found.', 404);
        }
        if ($row['provider_reference'] === null || (string) $row['provider_reference'] === '') {
            Json::error('CONFLICT', 'This checkout was never created at PayMongo, so there is nothing to verify.', 409);
        }
        $result = $this->verify($row, self::SOURCE_ADMIN);
        $messages = [
            'applied' => 'Verified with PayMongo. The paid plan was activated.',
            'already_applied' => 'Already verified with PayMongo and applied. Nothing changed.',
            'unpaid' => 'PayMongo shows no completed payment for this checkout yet. Nothing changed.',
            'expired' => 'The PayMongo checkout expired without a payment. Nothing was activated.',
            'review' => 'PayMongo data does not match this transaction. It needs review; nothing was activated.',
            'refunded' => 'PayMongo reports a refund for this payment. Recorded and flagged for review; the plan was not changed.',
            'already_refunded' => 'The refund was already recorded. Nothing changed.',
            'not_pending' => 'This transaction is not pending, so nothing was activated.',
            'awaiting_webhook' => 'PayMongo reports the payment as paid; awaiting verification.',
        ];
        if ($result === 'unavailable') {
            Json::error('VERIFICATION_UNAVAILABLE', 'PayMongo could not be reached to verify this payment. Try again later.', 503);
        }
        $fresh = $this->row($id);
        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($admin), [
            'organizationId' => (int) $fresh['organization_id'],
            'action' => ActivityLog::ACTION_UPDATE,
            'category' => 'payment',
            'description' => 'Re-checked online payment ' . $fresh['reference'] . ' with PayMongo: ' . $result
                . '. Verification: ' . (self::VERIFICATION_LABELS[$this->verificationState($fresh)] ?? 'Not verified') . '.',
            'entityType' => 'payment_transaction',
            'entityId' => $id,
        ]));
        $this->log("reconcile tx={$id} result={$result}");
        return ['result' => $result, 'message' => $messages[$result] ?? 'Checked with PayMongo.', 'row' => $fresh];
    }

    /**
     * Resolve a reference ONLY against values already stored on an online transaction
     * (our YS- reference, cs_ session, pi_ intent, pay_ payment). Unknown values -> null.
     *
     * @return array<string, mixed>|null
     */
    public function rowByStoredReference(string $reference): ?array
    {
        $reference = trim($reference);
        if (preg_match('/^(YS-\d{8}-[A-F0-9]{10}|(cs|pi|pay)_[A-Za-z0-9]{6,60})$/', $reference) !== 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM payment_transactions
             WHERE provider = :p AND (reference = :r1 OR provider_reference = :r2 OR provider_payment_intent_id = :r3 OR provider_payment_id = :r4)
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['p' => self::PROVIDER, 'r1' => $reference, 'r2' => $reference, 'r3' => $reference, 'r4' => $reference]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Derived verification state; never stored separately (no duplicate source of truth).
     *
     * @param array<string, mixed> $row
     */
    public function verificationState(array $row): string
    {
        if (($row['verified_at'] ?? null) !== null) {
            return 'provider_verified';
        }
        if ((string) $row['status'] === 'review') {
            return 'mismatch';
        }
        if ((string) $row['status'] === 'pending' && (string) ($row['provider_status'] ?? '') === 'paid') {
            return 'awaiting_verification';
        }
        return 'unverified';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function verificationView(array $row): array
    {
        $state = $this->verificationState($row);
        $refunded = isset($row['refunded_centavos']) && $row['refunded_centavos'] !== null ? (int) $row['refunded_centavos'] : 0;
        return [
            'source' => 'paymongo',
            'verification' => $state,
            'verificationLabel' => self::VERIFICATION_LABELS[$state],
            'providerVerified' => $state === 'provider_verified',
            'verifiedAt' => $row['verified_at'] ?? null,
            'verifiedVia' => $row['verified_via'] ?? null,
            'refundedAmount' => $refunded / 100,
            'refundedAt' => $row['refunded_at'] ?? null,
            'reviewRequired' => (int) ($row['review_required'] ?? 0) === 1 || (string) $row['status'] === 'review',
            'reviewReason' => $row['review_reason'] ?? ((string) $row['status'] === 'review' ? ($row['failure_message'] ?? null) : null),
        ];
    }

    // ------------------------------------------------------------------ checks (pure)

    /**
     * Compare a fetched checkout session with our transaction. Pure; no writes.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function verdict(array $row, array $session): array
    {
        $mismatch = static fn (string $reason): array => ['state' => 'mismatch', 'reason' => $reason];
        $a = is_array($session['attributes'] ?? null) ? $session['attributes'] : [];
        $expected = (int) $row['amount_centavos'];

        if ((string) ($session['id'] ?? '') !== (string) $row['provider_reference']) {
            return $mismatch('Checkout session id from the provider does not match.');
        }
        if ((string) ($a['reference_number'] ?? '') !== (string) $row['reference']) {
            return $mismatch('Reference number from the provider does not match.');
        }
        $meta = is_array($a['metadata'] ?? null) ? $a['metadata'] : [];
        if ((string) ($meta['transaction_id'] ?? '') !== (string) $row['id']) {
            return $mismatch('Transaction id in the provider metadata does not match.');
        }
        if ((string) ($meta['organization_id'] ?? '') !== (string) $row['organization_id']) {
            return $mismatch('Organization in the provider metadata does not match.');
        }
        if ((string) ($meta['reference'] ?? '') !== (string) $row['reference']) {
            return $mismatch('Reference in the provider metadata does not match.');
        }
        if (array_key_exists('livemode', $a) && (bool) $a['livemode'] !== $this->expectLive()) {
            return $mismatch('Provider test/live mode does not match this server.');
        }
        $lineTotal = 0;
        foreach ((array) ($a['line_items'] ?? []) as $item) {
            if (!is_array($item) || strtoupper((string) ($item['currency'] ?? '')) !== 'PHP') {
                return $mismatch('Checkout line item currency is not PHP.');
            }
            $lineTotal += (int) ($item['amount'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1));
        }
        if ($lineTotal !== $expected) {
            return $mismatch("Checkout total {$lineTotal} centavos does not match the expected {$expected} centavos.");
        }

        $paid = null;
        foreach ((array) ($a['payments'] ?? []) as $payment) {
            $st = is_array($payment) ? (string) ($payment['attributes']['status'] ?? '') : '';
            if ($st === 'paid' || $st === 'refunded') {
                $paid = $payment;
                break;
            }
        }
        if ($paid !== null) {
            $pa = is_array($paid['attributes'] ?? null) ? $paid['attributes'] : [];
            $payId = (string) ($paid['id'] ?? '');
            if (preg_match('/^pay_[A-Za-z0-9]{6,60}$/', $payId) !== 1) {
                return $mismatch('Paid payment id from the provider is not valid.');
            }
            if (!is_int($pa['amount'] ?? null) || $pa['amount'] !== $expected) {
                return $mismatch('Paid amount ' . (is_scalar($pa['amount'] ?? null) ? (string) $pa['amount'] : '?')
                    . " centavos does not match the expected {$expected} centavos.");
            }
            if (strtoupper((string) ($pa['currency'] ?? '')) !== 'PHP') {
                return $mismatch('Paid currency is not PHP.');
            }
            if (array_key_exists('livemode', $pa) && (bool) $pa['livemode'] !== $this->expectLive()) {
                return $mismatch('Paid payment test/live mode does not match this server.');
            }
            $intent = $pa['payment_intent_id'] ?? ($a['payment_intent']['id'] ?? null);
            $intent = is_string($intent) && preg_match('/^pi_[A-Za-z0-9]{6,60}$/', $intent) === 1 ? $intent : null;
            if ($intent !== null && $row['provider_payment_intent_id'] !== null && $intent !== (string) $row['provider_payment_intent_id']) {
                return $mismatch('Payment intent from the provider does not match this checkout.');
            }
            $method = $pa['source']['type'] ?? ($a['payment_method_used'] ?? null);
            return [
                'state' => 'paid',
                'paymentId' => $payId,
                'method' => is_string($method) ? mb_substr(preg_replace('/[^a-z0-9_]/', '', strtolower($method)) ?? '', 0, 32) : null,
                'intent' => $intent,
                'paidAt' => is_int($pa['paid_at'] ?? null) ? $pa['paid_at'] : null,
                'amount' => $expected,
            ];
        }
        $sessionStatus = (string) ($a['status'] ?? 'active');
        if ($sessionStatus === 'expired') {
            return ['state' => 'expired', 'sessionStatus' => 'expired'];
        }
        return ['state' => 'unpaid', 'sessionStatus' => $sessionStatus];
    }

    /**
     * Check a payment fetched with GET /v1/payments/{id} against our transaction. Pure.
     * Refunds: attributes.refunds[] entries whose status is "succeeded" (refund statuses per
     * the docs: pending, processing, succeeded, failed). A payment status of "refunded" with
     * no itemised refunds is treated as a full refund.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payment
     * @return array{state: string, reason?: string, refunded: int, intent: ?string}
     */
    public function paymentCheck(array $row, array $payment, string $expectedId): array
    {
        $mismatch = static fn (string $reason): array => ['state' => 'mismatch', 'reason' => $reason, 'refunded' => 0, 'intent' => null];
        $pa = is_array($payment['attributes'] ?? null) ? $payment['attributes'] : [];
        $expected = (int) $row['amount_centavos'];
        if ((string) ($payment['id'] ?? '') !== $expectedId) {
            return $mismatch('Payment id from the provider does not match.');
        }
        if (!is_int($pa['amount'] ?? null) || $pa['amount'] !== $expected) {
            return $mismatch("Payment amount at the provider does not match the expected {$expected} centavos.");
        }
        if (strtoupper((string) ($pa['currency'] ?? '')) !== 'PHP') {
            return $mismatch('Payment currency at the provider is not PHP.');
        }
        if (array_key_exists('livemode', $pa) && (bool) $pa['livemode'] !== $this->expectLive()) {
            return $mismatch('Payment test/live mode does not match this server.');
        }
        $intent = $pa['payment_intent_id'] ?? null;
        $intent = is_string($intent) && $intent !== '' ? $intent : null;
        if ($intent !== null && $row['provider_payment_intent_id'] !== null && $intent !== (string) $row['provider_payment_intent_id']) {
            return $mismatch('Payment intent at the provider does not match this checkout.');
        }
        $status = (string) ($pa['status'] ?? '');
        if (!in_array($status, ['paid', 'refunded'], true)) {
            return $mismatch("Payment status at the provider is \"{$status}\", not paid.");
        }
        $refunded = 0;
        foreach ((array) ($pa['refunds'] ?? []) as $refund) {
            if (!is_array($refund)) {
                continue;
            }
            $ra = is_array($refund['attributes'] ?? null) ? $refund['attributes'] : $refund;
            if ((string) ($ra['status'] ?? '') === 'succeeded' && is_int($ra['amount'] ?? null) && $ra['amount'] > 0
                && strtoupper((string) ($ra['currency'] ?? 'PHP')) === 'PHP') {
                $refunded += $ra['amount'];
            }
        }
        if ($status === 'refunded' && $refunded === 0) {
            $refunded = $expected;
        }
        return ['state' => 'ok', 'refunded' => min($refunded, $expected), 'intent' => $intent];
    }

    // ------------------------------------------------------------------ writes

    /**
     * Grant the paid plan exactly once. Only for a row that is still pending.
     *
     * @param array<string, mixed> $verdict
     */
    private function apply(int $id, array $verdict, string $via): string
    {
        $stmt = $this->pdo->prepare('SELECT organization_id FROM payment_transactions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $organizationId = (int) $stmt->fetchColumn();
        if ($organizationId < 1) {
            return 'review';
        }

        try {
            PlanLimits::lockOrganization($this->pdo, $organizationId);
            $stmt = $this->pdo->prepare('SELECT * FROM payment_transactions WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $tx = $stmt->fetch();
            if ($tx === false || (int) $tx['organization_id'] !== $organizationId) {
                $this->pdo->rollBack();
                return 'review';
            }
            if ($tx['applied_at'] !== null || $tx['status'] === 'paid') {
                $this->pdo->commit();
                return 'already_applied';
            }
            if ($tx['status'] !== 'pending') {
                $status = (string) $tx['status'];
                $this->pdo->commit();
                return in_array($status, ['review', 'failed'], true) ? 'review' : 'not_pending';
            }
            $plan = (string) $tx['plan'];
            $cycle = (string) $tx['cycle'];
            if (!in_array($plan, ['basic', 'premium'], true) || !isset(PlanLimits::CYCLES[$cycle])) {
                $this->pdo->rollBack();
                $this->markReview($id, 'Stored plan or cycle is not valid.');
                return 'review';
            }
            $months = PlanLimits::CYCLES[$cycle];

            $orgStmt = $this->pdo->prepare(
                'SELECT id, name, status, plan, sub_status, cycle, started_on, expires_at FROM organizations WHERE id = :id FOR UPDATE'
            );
            $orgStmt->execute(['id' => $organizationId]);
            $org = $orgStmt->fetch();
            if ($org === false) {
                $this->pdo->rollBack();
                $this->markReview($id, 'Organization no longer exists.');
                return 'review';
            }
            $before = PlanLimits::state($this->pdo, $org);
            $extend = $before['plan'] === $plan && $before['status'] === 'active'
                && $before['expiresAt'] !== null && ($before['expiresIn'] ?? -1) >= 0;

            if ($extend) {
                $this->pdo->prepare(
                    "UPDATE organizations SET sub_status = 'active', cycle = :cycle,
                        expires_at = DATE_ADD(expires_at, INTERVAL :months MONTH)
                     WHERE id = :id"
                )->execute(['cycle' => $cycle, 'months' => $months, 'id' => $organizationId]);
            } else {
                $this->pdo->prepare(
                    "UPDATE organizations SET plan = :plan, sub_status = 'active', cycle = :cycle,
                        started_on = CURDATE(), expires_at = DATE_ADD(CURDATE(), INTERVAL :months MONTH)
                     WHERE id = :id"
                )->execute(['plan' => $plan, 'cycle' => $cycle, 'months' => $months, 'id' => $organizationId]);
            }
            $this->fault('after_activation_update');
            $orgStmt->execute(['id' => $organizationId]);
            $after = PlanLimits::state($this->pdo, $orgStmt->fetch());

            $planName = PlanLimits::plan($plan)['name'];
            $note = $planName . ' (' . $cycle . ') active until ' . $after['expiresAt']
                . ($extend ? ' - extended from current expiry ' . $before['expiresAt'] : ' - new term from today')
                . '; before: ' . $before['planName'] . ' (' . $before['status'] . ')';

            $paidTs = (int) ($verdict['paidAt'] ?? 0);
            $this->pdo->prepare(
                "UPDATE payment_transactions
                 SET status = 'paid', provider_status = 'paid', provider_payment_id = :pay,
                     provider_payment_intent_id = COALESCE(:pi, provider_payment_intent_id),
                     method = :method, paid_at = IF(:ts > 0, FROM_UNIXTIME(:ts2), NOW()),
                     verified_at = NOW(), verified_via = :via, applied_at = NOW(), provider_checked_at = NOW(),
                     activation_note = :note, failure_code = NULL, failure_message = NULL
                 WHERE id = :id AND status = 'pending' AND applied_at IS NULL"
            )->execute([
                'pay' => $verdict['paymentId'],
                'pi' => $verdict['intent'],
                'method' => $verdict['method'],
                'ts' => $paidTs,
                'ts2' => $paidTs,
                'via' => mb_substr($via, 0, 32),
                'note' => mb_substr($note, 0, 255),
                'id' => $id,
            ]);

            ActivityLog::record($this->pdo, array_merge(self::SYSTEM_ACTOR, [
                'organizationId' => $organizationId,
                'action' => ActivityLog::ACTION_UPDATE,
                'category' => 'subscription',
                'description' => 'Online payment ' . $tx['reference'] . ' verified with PayMongo (' . $verdict['paymentId']
                    . ', ' . ($verdict['method'] ?? 'unknown method') . ', PHP ' . number_format(((int) $tx['amount_centavos']) / 100, 2)
                    . ', ' . $this->mode() . ' mode, via ' . $via . '). ' . $note . '.',
                'entityType' => 'organization',
                'entityId' => $organizationId,
            ]));
            $this->fault('before_commit');
            $this->pdo->commit();
            $this->log("activated tx={$id} org={$organizationId} pay={$verdict['paymentId']} via={$via}");
            return 'applied';
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((string) $e->getCode() === '23000') {
                $this->markReview($id, 'This provider payment is already linked to another transaction.');
                return 'review';
            }
            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->log("activation_rolled_back tx={$id} exception=" . get_class($e));
            throw $e;
        }
    }

    /**
     * A succeeded refund at PayMongo. Never changes the plan (decision: admin review).
     */
    private function recordRefund(int $id, int $refundedCentavos, string $paymentId): string
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM payment_transactions WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $tx = $stmt->fetch();
            if ($tx === false) {
                $this->pdo->rollBack();
                return 'review';
            }
            $amount = (int) $tx['amount_centavos'];
            $total = min($refundedCentavos, $amount);
            $previous = $tx['refunded_centavos'] !== null ? (int) $tx['refunded_centavos'] : 0;
            if ($total <= $previous) {
                $this->pdo->commit();
                return 'already_refunded';
            }
            $full = $total >= $amount;
            $wasApplied = $tx['applied_at'] !== null;
            $status = (string) $tx['status'];
            $newStatus = $status === 'review' ? 'review' : ($full ? 'refunded' : 'partially_refunded');
            $peso = 'PHP ' . number_format($total / 100, 2) . ' of PHP ' . number_format($amount / 100, 2);
            $reason = $wasApplied
                ? "Refund of {$peso} succeeded at PayMongo after the plan was activated. The plan was NOT changed automatically; review the subscription."
                : "Payment {$paymentId} was refunded ({$peso}) at PayMongo before activation. Nothing was activated.";
            $this->pdo->prepare(
                'UPDATE payment_transactions
                 SET status = :st, provider_status = :ps, refunded_centavos = :rc, refunded_at = NOW(),
                     review_required = 1, review_reason = :rr, provider_checked_at = NOW()
                 WHERE id = :id'
            )->execute([
                'st' => $newStatus,
                'ps' => $full ? 'refunded' : 'partially_refunded',
                'rc' => $total,
                'rr' => mb_substr($reason, 0, 255),
                'id' => $id,
            ]);
            if ($tx['provider_payment_id'] === null) {
                try {
                    $this->pdo->prepare('UPDATE payment_transactions SET provider_payment_id = :pay WHERE id = :id AND provider_payment_id IS NULL')
                        ->execute(['pay' => $paymentId, 'id' => $id]);
                } catch (PDOException) {
                    // Already linked elsewhere; the refund record above stands on its own.
                }
            }
            ActivityLog::record($this->pdo, array_merge(self::SYSTEM_ACTOR, [
                'organizationId' => (int) $tx['organization_id'],
                'action' => ActivityLog::ACTION_UPDATE,
                'category' => 'payment',
                'description' => 'Online payment ' . $tx['reference'] . ' ' . ($full ? 'refunded' : 'partially refunded')
                    . ' at PayMongo (' . $paymentId . ', ' . $peso . '). ' . $reason,
                'entityType' => 'payment_transaction',
                'entityId' => $id,
            ]));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->log("refund_recorded tx={$id} refunded={$total}");
        return 'refunded';
    }

    /** Mismatch on a not-yet-granted row: status review, never granted. */
    public function markReview(int $id, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE payment_transactions
             SET status = 'review', failure_code = 'VERIFICATION_MISMATCH', failure_message = :m,
                 review_required = 1, review_reason = :r, provider_checked_at = NOW()
             WHERE id = :id AND status IN ('pending', 'cancelled', 'expired') AND applied_at IS NULL"
        );
        $stmt->execute(['m' => mb_substr($reason, 0, 255), 'r' => mb_substr($reason, 0, 255), 'id' => $id]);
        if ($stmt->rowCount() > 0) {
            $row = $this->row($id);
            ActivityLog::record($this->pdo, array_merge(self::SYSTEM_ACTOR, [
                'organizationId' => (int) $row['organization_id'],
                'action' => ActivityLog::ACTION_UPDATE,
                'category' => 'payment',
                'description' => 'Online payment ' . $row['reference'] . ' needs review: ' . $reason . ' The plan was not changed.',
                'entityType' => 'payment_transaction',
                'entityId' => $id,
            ]));
            $this->log("review tx={$id}");
        }
    }

    /** Mismatch on an already granted row: keep its status (no downgrade), flag for review once. */
    private function flagReview(int $id, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE payment_transactions SET review_required = 1, review_reason = :r, provider_checked_at = NOW()
             WHERE id = :id AND review_required = 0'
        );
        $stmt->execute(['r' => mb_substr($reason, 0, 255), 'id' => $id]);
        if ($stmt->rowCount() > 0) {
            $row = $this->row($id);
            ActivityLog::record($this->pdo, array_merge(self::SYSTEM_ACTOR, [
                'organizationId' => (int) $row['organization_id'],
                'action' => ActivityLog::ACTION_UPDATE,
                'category' => 'payment',
                'description' => 'Online payment ' . $row['reference'] . ' flagged for review: ' . $reason . ' Status and plan were not changed.',
                'entityType' => 'payment_transaction',
                'entityId' => $id,
            ]));
            $this->log("flag_review tx={$id}");
        }
    }

    private function unavailable(int $id, PayMongoException $e): string
    {
        $this->pdo->prepare('UPDATE payment_transactions SET provider_checked_at = NOW() WHERE id = :id')->execute(['id' => $id]);
        $this->log("verify_unavailable tx={$id} code={$e->providerCode} http={$e->httpStatus}");
        return 'unavailable';
    }

    private function touch(int $id): void
    {
        $this->pdo->prepare('UPDATE payment_transactions SET provider_checked_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payment_transactions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'That payment was not found.', 404);
        }
        return $row;
    }

    public function log(string $line): void
    {
        @file_put_contents($this->logFile, sprintf("[%s] paymongo %s\n", date('c'), $line), FILE_APPEND);
    }
}
