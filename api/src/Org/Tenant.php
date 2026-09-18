<?php
declare(strict_types=1);

namespace YouthSync\Org;

use PDO;
use YouthSync\Http\Json;

final class Tenant
{
    public const SESSION_ORG_KEY = 'organization_id';
    public const SESSION_USER_KEY = 'user_id';
    public const SESSION_ROLE_KEY = 'role_code';

    public function __construct(private PDO $pdo)
    {
    }

    public function organizationId(): int
    {
        $id = $_SESSION[self::SESSION_ORG_KEY] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            Json::error('FORBIDDEN', 'No organization is associated with this session.', 403);
        }
        return (int) $id;
    }

    public function userId(): int
    {
        $id = $_SESSION[self::SESSION_USER_KEY] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            Json::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        return (int) $id;
    }

    /**
     * Resolve the user's organization from membership rows only.
     * Request body/query organization_id is ignored.
     */
    public function membershipForUser(int $userId): ?array
    {
        $sql = <<<SQL
            SELECT
                ou.id AS membership_id,
                ou.is_owner,
                ou.status AS membership_status,
                o.id AS organization_id,
                o.name,
                o.barangay,
                o.municipality,
                o.province,
                o.chairperson,
                o.email,
                o.contact,
                o.status,
                o.plan,
                o.sub_status,
                o.cycle,
                o.expires_at,
                o.youth_count
            FROM organization_users ou
            INNER JOIN organizations o ON o.id = ou.organization_id
            WHERE ou.user_id = :user_id
              AND ou.status = 'active'
            ORDER BY ou.is_owner DESC, ou.id ASC
            LIMIT 1
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function publicOrganization(array $row): array
    {
        return [
            'id' => (int) $row['organization_id'],
            'name' => $row['name'],
            'barangay' => $row['barangay'],
            'municipality' => $row['municipality'],
            'province' => $row['province'],
            'chairperson' => $row['chairperson'],
            'email' => $row['email'],
            'contact' => $row['contact'],
            'status' => $row['status'],
            'subscription' => [
                'plan' => $row['plan'],
                'status' => $row['sub_status'],
                'cycle' => $row['cycle'],
                'expires_at' => $row['expires_at'],
                'youth_count' => (int) $row['youth_count'],
            ],
        ];
    }
}
