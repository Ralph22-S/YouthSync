<?php
declare(strict_types=1);

namespace YouthSync\Subscription;

use PDO;
use YouthSync\Auth\AuthService;
use YouthSync\Org\PlanLimits;

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
        $usage = $this->usage($organizationId, $membership, $query);
        $org = $this->organizationRow($organizationId);
        $storedCode = PlanLimits::storedPlanCode($org);
        $effective = $usage['effectivePlan'];

        $expiresAt = $org['expires_at'] ?? null;
        $expiresIn = null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            $expStmt = $this->pdo->prepare('SELECT DATEDIFF(:expires, CURDATE())');
            $expStmt->execute(['expires' => $expiresAt]);
            $expiresIn = (int) $expStmt->fetchColumn();
        }

        return [
            'plan' => $storedCode,
            'planName' => $this->storedPlanName($storedCode),
            'status' => (string) ($org['sub_status'] ?? ''),
            'cycle' => (string) ($org['cycle'] ?? 'none'),
            'expiresAt' => $expiresAt,
            'expiresIn' => $expiresIn,
            'effectivePlan' => $effective,
            'limits' => $usage['limits'],
            'usage' => $usage['usage'],
            'remaining' => $usage['remaining'],
            'features' => [
                'csvImport' => (bool) $effective['csv_import'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $membership
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function usage(int $organizationId, array $membership, array $query = []): array
    {
        unset($query['organization_id'], $query['orgId'], $query['organizationId'], $query['org_id']);
        $org = $this->organizationRow($organizationId);
        $plan = PlanLimits::effective($org);

        $counts = [
            'youth' => $this->countYouth($organizationId),
            'accounts' => $this->countSkAccounts($organizationId),
            'programs' => $this->countPrograms($organizationId),
            'assistance' => $this->countAssistance($organizationId),
            'qr' => $this->qrUses($organizationId),
        ];

        $limits = [
            'youth' => $plan['youth'],
            'accounts' => $plan['accounts'],
            'programs' => $plan['programs'],
            'assistance' => $plan['assistance'],
            'qr' => $plan['qr'],
        ];

        $remaining = [];
        foreach ($limits as $key => $limit) {
            $remaining[$key] = PlanLimits::remaining($limit, $counts[$key]);
        }

        return [
            'plan' => $plan['code'],
            'planName' => $plan['name'],
            'status' => (string) ($org['sub_status'] ?? ''),
            'effectivePlan' => [
                'code' => $plan['code'],
                'name' => $plan['name'],
                'csv_import' => $plan['csv_import'],
            ],
            'limits' => $limits,
            'usage' => $counts,
            'remaining' => $remaining,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationRow(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, plan, sub_status, cycle, expires_at, qr_uses
             FROM organizations WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $organizationId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : ['plan' => 'free', 'sub_status' => 'free'];
    }

    private function storedPlanName(string $code): string
    {
        foreach (PlanLimits::catalog() as $plan) {
            if ($plan['code'] === $code) {
                return $plan['name'];
            }
        }
        return 'Free';
    }

    private function countYouth(int $organizationId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM youth WHERE organization_id = :org AND archived = 0'
        );
        $stmt->execute(['org' => $organizationId]);
        return (int) $stmt->fetchColumn();
    }

    private function countSkAccounts(int $organizationId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM organization_users ou
             INNER JOIN users u ON u.id = ou.user_id
             INNER JOIN roles r ON r.id = u.role_id
             WHERE ou.organization_id = :org
               AND ou.status = :membership
               AND r.code = :role'
        );
        $stmt->execute([
            'org' => $organizationId,
            'membership' => 'active',
            'role' => AuthService::ROLE_SK_OFFICIAL,
        ]);
        return (int) $stmt->fetchColumn();
    }

    private function countPrograms(int $organizationId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM programs
             WHERE organization_id = :org AND status IN ('draft', 'published', 'ongoing')"
        );
        $stmt->execute(['org' => $organizationId]);
        return (int) $stmt->fetchColumn();
    }

    private function countAssistance(int $organizationId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM assistance_programs
             WHERE organization_id = :org AND status IN ('draft', 'open', 'full')"
        );
        $stmt->execute(['org' => $organizationId]);
        return (int) $stmt->fetchColumn();
    }

    private function qrUses(int $organizationId): int
    {
        $stmt = $this->pdo->prepare('SELECT qr_uses FROM organizations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $organizationId]);
        return (int) $stmt->fetchColumn();
    }
}
