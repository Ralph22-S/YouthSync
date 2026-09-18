<?php
declare(strict_types=1);

namespace YouthSync\Users;

use PDO;
use PDOException;
use YouthSync\Auth\AuthService;
use YouthSync\Auth\Password;
use YouthSync\Http\Json;
use YouthSync\Org\PlanLimits;

final class UserService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function list(int $organizationId, array $query): array
    {
        unset($query['organization_id'], $query['orgId'], $query['organizationId'], $query['org_id']);

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['perPage'] ?? $query['per_page'] ?? 50);
        if ($perPage < 1) {
            $perPage = 50;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        [$sqlWhere, $params] = $this->orgSkWhere($organizationId);
        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            $sqlWhere .= ' AND (u.first_name LIKE :q OR u.last_name LIKE :q OR u.email LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $status = strtolower(trim((string) ($query['status'] ?? '')));
        if ($status === 'active' || $status === 'inactive') {
            $sqlWhere .= ' AND u.status = :status';
            $params['status'] = $status;
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->fromSql()} WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil(($total ?: 1) / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $listStmt = $this->pdo->prepare(
            "SELECT {$this->selectSql()} FROM {$this->fromSql()}
             WHERE {$sqlWhere}
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $listStmt->execute($params);
        $items = [];
        foreach ($listStmt->fetchAll() as $row) {
            $items[] = $this->publicUser($row);
        }

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $organizationId, int $id): array
    {
        return $this->publicUser($this->requireInOrg($organizationId, $id));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function create(int $organizationId, array $input, array $orgRow): array
    {
        unset($input['organization_id'], $input['organizationId'], $input['orgId'], $input['org_id']);
        $values = UserValidator::normalizeCreate($input);
        $errors = UserValidator::validateCreate($values);
        if ($errors) {
            Json::validation($errors);
        }

        $plan = PlanLimits::effective($orgRow);
        if ($plan['accounts'] !== null && $this->countSkAccounts($organizationId) + 1 > $plan['accounts']) {
            Json::error('PLAN_LIMIT', PlanLimits::accountsLimitMessage($plan), 403);
        }

        $roleId = $this->skOfficialRoleId();
        $plain = $values['password'] !== '' ? $values['password'] : $this->temporaryPassword();
        $generated = $values['password'] === '';

        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare(
                'INSERT INTO users (
                    email, password_hash, first_name, last_name, role_id, status, must_change_password
                ) VALUES (
                    :email, :hash, :first_name, :last_name, :role_id, :status, 1
                )'
            )->execute([
                'email' => $values['email'],
                'hash' => Password::hash($plain),
                'first_name' => $values['firstName'],
                'last_name' => $values['lastName'],
                'role_id' => $roleId,
                'status' => 'active',
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                'INSERT INTO organization_users (user_id, organization_id, is_owner, status)
                 VALUES (:user_id, :org, 0, :status)'
            )->execute([
                'user_id' => $id,
                'org' => $organizationId,
                'status' => 'active',
            ]);
            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $sqlState = (string) ($e->errorInfo[0] ?? '');
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($sqlState === '23000' || $driverCode === 1062) {
                Json::error('CONFLICT', 'That email already has an account.', 409);
            }
            throw $e;
        }

        $payload = $this->get($organizationId, $id);
        $payload['temporaryPassword'] = $plain;
        $payload['passwordGenerated'] = $generated;
        return $payload;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $organizationId, int $id, array $input): array
    {
        unset($input['organization_id'], $input['organizationId'], $input['orgId'], $input['org_id']);
        $row = $this->requireInOrg($organizationId, $id);
        $values = UserValidator::normalizeUpdate($input);
        if ($values === []) {
            return $this->get($organizationId, $id);
        }
        if (array_key_exists('firstName', $values) || array_key_exists('lastName', $values)) {
            $values['firstName'] = ($values['firstName'] ?? '') !== ''
                ? $values['firstName']
                : (string) $row['first_name'];
            $values['lastName'] = ($values['lastName'] ?? '') !== ''
                ? $values['lastName']
                : (string) $row['last_name'];
        }
        $errors = UserValidator::validateUpdate($values);
        if ($errors) {
            Json::validation($errors);
        }

        $sets = [];
        $params = ['id' => $id];
        if (isset($values['firstName'])) {
            $sets[] = 'first_name = :first_name';
            $params['first_name'] = $values['firstName'];
        }
        if (isset($values['lastName'])) {
            $sets[] = 'last_name = :last_name';
            $params['last_name'] = $values['lastName'];
        }
        if (isset($values['email'])) {
            $sets[] = 'email = :email';
            $params['email'] = $values['email'];
        }
        if (isset($values['password'])) {
            $sets[] = 'password_hash = :hash';
            $sets[] = 'must_change_password = 0';
            $params['hash'] = Password::hash($values['password']);
        }

        if ($sets !== []) {
            try {
                $this->pdo->prepare(
                    'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id'
                )->execute($params);
            } catch (PDOException $e) {
                $sqlState = (string) ($e->errorInfo[0] ?? '');
                $driverCode = (int) ($e->errorInfo[1] ?? 0);
                if ($sqlState === '23000' || $driverCode === 1062) {
                    Json::error('CONFLICT', 'That email already has an account.', 409);
                }
                throw $e;
            }
        }

        return $this->get($organizationId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function setStatus(int $organizationId, int $actorId, int $id, array $input): array
    {
        unset($input['organization_id'], $input['organizationId'], $input['orgId'], $input['org_id']);
        $row = $this->requireInOrg($organizationId, $id);
        if ($id === $actorId) {
            Json::error('VALIDATION_ERROR', 'You cannot deactivate your own account.', 422);
        }

        $status = strtolower(trim((string) ($input['status'] ?? '')));
        if ($status !== 'active' && $status !== 'inactive') {
            Json::validation(['status' => 'Status must be active or inactive.']);
        }
        if ($status === 'inactive') {
            $this->assertNotLastActiveOfficial($organizationId, $id);
        }

        $this->pdo->prepare(
            'UPDATE users SET status = :status WHERE id = :id'
        )->execute(['status' => $status, 'id' => $id]);

        return $this->get($organizationId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleActive(int $organizationId, int $actorId, int $id): array
    {
        $row = $this->requireInOrg($organizationId, $id);
        $next = ($row['status'] ?? '') === 'active' ? 'inactive' : 'active';
        return $this->setStatus($organizationId, $actorId, $id, ['status' => $next]);
    }

    /**
     * @return array<string, mixed>
     */
    public function resetPassword(int $organizationId, int $id): array
    {
        $this->requireInOrg($organizationId, $id);
        $plain = $this->temporaryPassword();
        $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, must_change_password = 1 WHERE id = :id'
        )->execute([
            'hash' => Password::hash($plain),
            'id' => $id,
        ]);
        $payload = $this->get($organizationId, $id);
        $payload['temporaryPassword'] = $plain;
        return $payload;
    }

    /**
     * @return array{deleted: true}
     */
    public function delete(int $organizationId, int $actorId, int $id): array
    {
        $row = $this->requireInOrg($organizationId, $id);
        if ($id === $actorId) {
            Json::error('VALIDATION_ERROR', 'You cannot delete your own account.', 422);
        }
        if ((int) ($row['is_owner'] ?? 0) === 1) {
            Json::error('VALIDATION_ERROR', 'The owner account cannot be removed.', 422);
        }
        if (($row['status'] ?? '') === 'active') {
            $this->assertNotLastActiveOfficial($organizationId, $id);
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM organization_users WHERE user_id = :id AND organization_id = :org'
            )->execute(['id' => $id, 'org' => $organizationId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['deleted' => true];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function orgSkWhere(int $organizationId): array
    {
        return [
            'ou.organization_id = :org AND r.code = :role',
            ['org' => $organizationId, 'role' => AuthService::ROLE_SK_OFFICIAL],
        ];
    }

    private function fromSql(): string
    {
        return 'users u
            INNER JOIN roles r ON r.id = u.role_id
            INNER JOIN organization_users ou ON ou.user_id = u.id';
    }

    private function selectSql(): string
    {
        return 'u.id, u.email, u.first_name, u.last_name, u.status, u.must_change_password,
                u.last_login_at, u.created_at, r.code AS role_code, ou.is_owner';
    }

    /**
     * @return array<string, mixed>
     */
    private function requireInOrg(int $organizationId, int $id): array
    {
        if ($id < 1) {
            Json::error('NOT_FOUND', 'This user does not exist in this organization.', 404);
        }
        [$sqlWhere, $params] = $this->orgSkWhere($organizationId);
        $stmt = $this->pdo->prepare(
            "SELECT {$this->selectSql()} FROM {$this->fromSql()}
             WHERE u.id = :id AND {$sqlWhere}
             LIMIT 1"
        );
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This user does not exist in this organization.', 404);
        }
        return $row;
    }

    private function assertNotLastActiveOfficial(int $organizationId, int $exceptId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             INNER JOIN organization_users ou ON ou.user_id = u.id
             WHERE ou.organization_id = :org
               AND r.code = :role
               AND u.status = 'active'
               AND u.id <> :except"
        );
        $stmt->execute([
            'org' => $organizationId,
            'role' => AuthService::ROLE_SK_OFFICIAL,
            'except' => $exceptId,
        ]);
        if ((int) $stmt->fetchColumn() < 1) {
            Json::error(
                'VALIDATION_ERROR',
                'At least one active SK Official account must remain in this organization.',
                422
            );
        }
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

    private function skOfficialRoleId(): int
    {
        $id = (int) $this->pdo->query(
            "SELECT id FROM roles WHERE code = 'SK_OFFICIAL' LIMIT 1"
        )->fetchColumn();
        if ($id < 1) {
            Json::error('SERVER_ERROR', 'SK Official role is not configured.', 500);
        }
        return $id;
    }

    private function temporaryPassword(): string
    {
        return 'YS-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicUser(array $row): array
    {
        $first = (string) ($row['first_name'] ?? '');
        $last = (string) ($row['last_name'] ?? '');
        return [
            'id' => (int) ($row['id'] ?? 0),
            'email' => $row['email'] ?? '',
            'firstName' => $first,
            'lastName' => $last,
            'name' => trim($first . ' ' . $last),
            'role' => $row['role_code'] ?? AuthService::ROLE_SK_OFFICIAL,
            'status' => $row['status'] ?? 'active',
            'isOwner' => (bool) ($row['is_owner'] ?? 0),
            'mustChangePassword' => (bool) ($row['must_change_password'] ?? 0),
            'lastLoginAt' => $row['last_login_at'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
        ];
    }
}
