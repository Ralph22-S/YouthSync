<?php
declare(strict_types=1);

namespace YouthSync\Subscription;

use PDO;
use YouthSync\Admin\ActivityLog;
use YouthSync\Http\Json;
use YouthSync\Org\PlanLimits;

/**
 * System Administrator subscription actions: change plan, extend, cancel and
 * the expiry sweep ("run subscription cycle"). Every action locks the
 * organization row, validates on the server, persists, writes one
 * ActivityLog row (category "subscription", admin actor) and returns the
 * same subscription view the SK app reads. There is no payment provider:
 * nothing here charges, refunds or cancels a payment.
 */
final class SubscriptionAdminService
{
    private const MAX_EXTEND_DAYS = 365;

    public function __construct(
        private PDO $pdo,
        private SubscriptionService $subscriptions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function plans(): array
    {
        return [
            'items' => PlanLimits::publicCatalog(),
            'cycles' => array_keys(PlanLimits::CYCLES),
            'trialDays' => PlanLimits::TRIAL_DAYS,
            'maxExtendDays' => self::MAX_EXTEND_DAYS,
            'note' => PlanLimits::PRICE_NOTE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $id): array
    {
        $row = $this->organization($id, false);
        return $this->payload($row);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function changePlan(int $id, array $input, array $admin): array
    {
        $plan = strtolower(trim((string) ($input['plan'] ?? $input['planCode'] ?? '')));
        $cycle = strtolower(trim((string) ($input['cycle'] ?? '')));
        $errors = [];
        if (!PlanLimits::isPlan($plan)) {
            $errors['plan'] = 'Choose Free, Basic or Premium.';
        } elseif ($plan !== 'free') {
            if ($cycle === '') {
                $cycle = 'monthly';
            }
            if (!isset(PlanLimits::CYCLES[$cycle])) {
                $errors['cycle'] = 'Billing cycle must be monthly or yearly.';
            }
        }
        if ($errors) {
            Json::validation($errors);
        }

        $this->pdo->beginTransaction();
        $row = $this->organization($id, true);
        $before = PlanLimits::state($this->pdo, $row);

        if ($plan === 'free') {
            if ($before['plan'] === 'free' && $before['status'] === 'free') {
                $this->fail('CONFLICT', 'This organization is already on the Free plan.', 409);
            }
            $this->pdo->prepare(
                "UPDATE organizations
                 SET plan = 'free', sub_status = 'free', cycle = 'none',
                     started_on = CURDATE(), expires_at = NULL
                 WHERE id = :id"
            )->execute(['id' => $id]);
        } else {
            if ($before['plan'] === $plan && $before['cycle'] === $cycle && $before['status'] === 'active') {
                $this->fail(
                    'CONFLICT',
                    'This organization is already on an active ' . PlanLimits::plan($plan)['name'] . ' (' . $cycle
                        . ') subscription. Use Extend to add time.',
                    409
                );
            }
            $this->pdo->prepare(
                "UPDATE organizations
                 SET plan = :plan, sub_status = 'active', cycle = :cycle,
                     started_on = CURDATE(), expires_at = DATE_ADD(CURDATE(), INTERVAL :months MONTH)
                 WHERE id = :id"
            )->execute(['plan' => $plan, 'cycle' => $cycle, 'months' => PlanLimits::CYCLES[$cycle], 'id' => $id]);
        }

        $after = $this->subscriptions->view($id);
        $over = (array) $after['overLimit'];
        $warning = null;
        if ($over !== []) {
            $parts = [];
            foreach ($over as $resource => $numbers) {
                $parts[] = $resource . ' ' . $numbers['usage'] . '/' . $numbers['limit'];
            }
            $warning = 'This organization is now over the ' . $after['planName'] . ' plan limits (' . implode(', ', $parts)
                . '). Existing records are kept; new records beyond the limits are blocked.';
        }

        $description = 'Changed ' . $row['name'] . ' from ' . $this->label($before) . ' to ' . $this->label($after)
            . ($after['expiresAt'] !== null ? ', term until ' . $after['expiresAt'] : '')
            . ($over !== [] ? ' - over plan limits: ' . implode(', ', array_keys($over)) : '');
        $this->log($admin, $id, $description);
        $this->pdo->commit();

        return $this->payload($row, [
            'previous' => $this->summary($before),
            'overLimit' => $over === [] ? new \stdClass() : $over,
            'warning' => $warning,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function extend(int $id, array $input, array $admin): array
    {
        $daysRaw = $input['days'] ?? null;
        $dateRaw = $input['expiresAt'] ?? $input['expires_at'] ?? $input['date'] ?? null;
        $hasDays = $daysRaw !== null && $daysRaw !== '';
        $hasDate = $dateRaw !== null && $dateRaw !== '';
        if ($hasDays === $hasDate) {
            Json::validation(['days' => 'Enter either a number of days (1-' . self::MAX_EXTEND_DAYS . ') or a new expiry date.']);
        }
        $days = null;
        $date = null;
        if ($hasDays) {
            $text = trim((string) $daysRaw);
            if (preg_match('/^\d{1,4}$/', $text) !== 1 || (int) $text < 1 || (int) $text > self::MAX_EXTEND_DAYS) {
                Json::validation(['days' => 'Days must be a whole number from 1 to ' . self::MAX_EXTEND_DAYS . '.']);
            }
            $days = (int) $text;
        } else {
            $text = trim((string) $dateRaw);
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                Json::validation(['expiresAt' => 'Expiry date must be a valid date (YYYY-MM-DD).']);
            }
            $date = $text;
        }

        $this->pdo->beginTransaction();
        $row = $this->organization($id, true);
        $before = PlanLimits::state($this->pdo, $row);

        if ($before['plan'] === 'free' || $before['status'] === 'free') {
            $this->fail('VALIDATION_ERROR', 'The Free plan does not expire, so there is nothing to extend. Use Change plan to start a paid term.', 422, ['fields' => ['status' => 'free']]);
        }
        if ($before['status'] === 'cancelled') {
            $this->fail('VALIDATION_ERROR', 'This subscription was cancelled. Use Change plan to start a new term.', 422, ['fields' => ['status' => 'cancelled']]);
        }
        if (!in_array($before['status'], PlanLimits::EXTENDABLE_STATUSES, true)) {
            $this->fail('VALIDATION_ERROR', 'A subscription with status "' . $before['status'] . '" cannot be extended. Use Change plan.', 422, ['fields' => ['status' => $before['status']]]);
        }

        // Extend from the current expiry while the term is still running, otherwise from today.
        $today = PlanLimits::today($this->pdo);
        $running = $before['expiresAt'] !== null && ($before['expiresIn'] ?? -1) >= 0
            && in_array($before['status'], ['trial', 'active'], true);
        $base = $running ? (string) $before['expiresAt'] : $today;
        $stmt = $this->pdo->prepare('SELECT DATE_ADD(:base, INTERVAL :days DAY)');
        $stmt->execute(['base' => $base, 'days' => self::MAX_EXTEND_DAYS]);
        $maxDate = (string) $stmt->fetchColumn();

        if ($days !== null) {
            $stmt = $this->pdo->prepare('SELECT DATE_ADD(:base, INTERVAL :days DAY)');
            $stmt->execute(['base' => $base, 'days' => $days]);
            $newExpiry = (string) $stmt->fetchColumn();
        } else {
            if ($date <= $base) {
                $this->fail('VALIDATION_ERROR', 'The new expiry date must be after ' . $base . '.', 422, ['fields' => ['expiresAt' => 'Must be after ' . $base . '.']]);
            }
            if ($date > $maxDate) {
                $this->fail('VALIDATION_ERROR', 'The new expiry date can be at most ' . self::MAX_EXTEND_DAYS . ' days after ' . $base . ' (' . $maxDate . ').', 422, ['fields' => ['expiresAt' => 'Must be on or before ' . $maxDate . '.']]);
            }
            $newExpiry = $date;
        }

        $reactivate = !in_array($before['status'], ['trial', 'active'], true);
        // A running trial stays a trial; an expired (or payment-failed) term becomes active.
        $newStatus = $before['status'] === 'trial' ? 'trial' : 'active';
        $newCycle = $before['cycle'];
        if ($newStatus === 'active' && !isset(PlanLimits::CYCLES[$newCycle])) {
            $newCycle = 'monthly';
        }
        $this->pdo->prepare(
            'UPDATE organizations
             SET sub_status = :status, cycle = :cycle, expires_at = :expires,
                 started_on = ' . ($reactivate ? 'CURDATE()' : 'started_on') . '
             WHERE id = :id'
        )->execute(['status' => $newStatus, 'cycle' => $newCycle, 'expires' => $newExpiry, 'id' => $id]);

        $after = $this->subscriptions->view($id);
        $this->log(
            $admin,
            $id,
            'Extended ' . $row['name'] . ' ' . $this->label($before) . ' to ' . $newExpiry
                . ' (from ' . ($running ? 'current expiry ' . $base : 'today ' . $today) . ')'
                . ($reactivate ? '; status ' . $before['status'] . ' -> ' . $newStatus : '')
        );
        $this->pdo->commit();

        return $this->payload($row, [
            'previous' => $this->summary($before),
            'extendedFrom' => $base,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function cancel(int $id, array $input, array $admin): array
    {
        $reason = mb_substr(trim((string) ($input['reason'] ?? $input['note'] ?? '')), 0, 255);

        $this->pdo->beginTransaction();
        $row = $this->organization($id, true);
        $before = PlanLimits::state($this->pdo, $row);
        if ($before['plan'] === 'free' || $before['status'] === 'free') {
            $this->fail('VALIDATION_ERROR', 'The Free plan has no subscription to cancel.', 422, ['fields' => ['status' => 'free']]);
        }
        if ($before['status'] === 'cancelled') {
            $this->fail('CONFLICT', 'This subscription is already cancelled.', 409);
        }

        // Immediate: Free limits apply now. plan, cycle and expires_at stay on record.
        $this->pdo->prepare(
            "UPDATE organizations SET sub_status = 'cancelled' WHERE id = :id"
        )->execute(['id' => $id]);

        $this->log(
            $admin,
            $id,
            'Cancelled ' . $row['name'] . ' ' . $this->label($before) . ' subscription'
                . ($before['expiresAt'] !== null ? ' (term was until ' . $before['expiresAt'] . ')' : '')
                . '; Free plan limits apply' . ($reason !== '' ? ' - ' . $reason : '')
        );
        $this->pdo->commit();

        return $this->payload($row, ['previous' => $this->summary($before)]);
    }

    /**
     * "Run subscription cycle": move every subscription whose term has ended
     * to expired (the same rule SK requests apply lazily). Nothing else.
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function runCycle(array $admin): array
    {
        $ids = PlanLimits::expireDue($this->pdo);
        $items = [];
        if ($ids !== []) {
            $rows = $this->pdo->query(
                'SELECT id, name, plan, expires_at FROM organizations WHERE id IN (' . implode(',', $ids) . ') ORDER BY id'
            )->fetchAll();
            foreach ($rows as $row) {
                $items[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'plan' => $row['plan'], 'expiresAt' => $row['expires_at']];
                $this->log($admin, (int) $row['id'], 'Subscription cycle: ' . $row['name'] . ' expired (term ended ' . $row['expires_at'] . '); Free plan limits apply');
            }
        }
        return [
            'expired' => count($items),
            'organizations' => $items,
            'today' => PlanLimits::today($this->pdo),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organization(int $id, bool $lock): array
    {
        if ($id < 1) {
            $this->fail('NOT_FOUND', 'That organization is not on this deployment.', 404);
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, name, status, plan, sub_status, cycle, started_on, expires_at
             FROM organizations WHERE id = :id LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->fail('NOT_FOUND', 'That organization is not on this deployment.', 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payload(array $row, array $extra = []): array
    {
        return array_merge([
            'organization' => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'status' => (string) $row['status'],
            ],
            'subscription' => $this->subscriptions->view((int) $row['id']),
        ], $extra);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function summary(array $state): array
    {
        return [
            'plan' => $state['plan'],
            'status' => $state['status'],
            'cycle' => $state['cycle'],
            'expiresAt' => $state['expiresAt'],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function label(array $state): string
    {
        return $state['planName'] . ' (' . $state['status'] . ($state['cycle'] !== 'none' && $state['cycle'] !== $state['status'] ? ', ' . $state['cycle'] : '') . ')';
    }

    /**
     * @param array<string, mixed> $admin
     */
    private function log(array $admin, int $organizationId, string $description): void
    {
        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($admin), [
            'organizationId' => $organizationId,
            'action' => ActivityLog::ACTION_UPDATE,
            'category' => 'subscription',
            'description' => $description,
            'entityType' => 'organization',
            'entityId' => $organizationId,
        ]));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function fail(string $code, string $message, int $status, array $extra = []): never
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        Json::error($code, $message, $status, $extra);
    }
}
