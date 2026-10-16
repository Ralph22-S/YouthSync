<?php
declare(strict_types=1);

namespace YouthSync\Subscription;

use PDO;
use YouthSync\Org\PlanLimits;

/**
 * Read model for one organization's subscription. GET /sk/subscription and
 * GET /sk/subscription/usage (session organization only) and the admin
 * subscription endpoints all return this same shape.
 */
final class SubscriptionService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $membership
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(int $organizationId, array $membership, array $query = []): array
    {
        unset($query['organization_id'], $query['orgId'], $query['organizationId'], $query['org_id']);
        return $this->view($organizationId);
    }

    /**
     * Same payload as get(): usage is only meaningful next to the plan, status
     * and limits it is measured against.
     *
     * @param array<string, mixed> $membership
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function usage(int $organizationId, array $membership, array $query = []): array
    {
        unset($query['organization_id'], $query['orgId'], $query['organizationId'], $query['org_id']);
        return $this->view($organizationId);
    }

    /**
     * @return array<string, mixed>
     */
    public function view(int $organizationId): array
    {
        $org = $this->organizationRow($organizationId);
        $state = PlanLimits::state($this->pdo, $org);
        $plan = PlanLimits::effective(['plan' => $state['plan'], 'sub_status' => $state['status']]);
        $usage = PlanLimits::usage($this->pdo, $organizationId);

        $limits = [];
        $remaining = [];
        $reached = [];
        foreach (PlanLimits::RESOURCES as $resource) {
            $limit = $plan[$resource];
            $limits[$resource] = $limit;
            $remaining[$resource] = PlanLimits::remaining($limit, $usage[$resource]);
            $reached[$resource] = $limit !== null && $usage[$resource] >= $limit;
        }
        $over = [];
        foreach (PlanLimits::RESOURCES as $resource) {
            if ($limits[$resource] !== null && $usage[$resource] > $limits[$resource]) {
                $over[$resource] = ['usage' => $usage[$resource], 'limit' => $limits[$resource]];
            }
        }

        return $state + [
            'limits' => $limits,
            'usage' => $usage,
            'remaining' => $remaining,
            'reached' => $reached,
            'overLimit' => $over === [] ? new \stdClass() : $over,
            'features' => [
                'csvImport' => (bool) $plan['csv_import'],
                'excelExport' => (bool) $plan['excel_export'],
                'activityLogs' => (bool) $plan['activity_logs'],
            ],
            'plans' => PlanLimits::publicCatalog(),
            'billing' => [
                'onlinePayment' => false,
                'note' => PlanLimits::PRICE_NOTE,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationRow(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, plan, sub_status, cycle, started_on, expires_at, qr_uses
             FROM organizations WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $organizationId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return ['id' => 0, 'plan' => 'free', 'sub_status' => 'free', 'cycle' => 'none', 'expires_at' => null];
        }
        return $row;
    }
}
