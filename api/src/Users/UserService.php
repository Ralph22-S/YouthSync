<?php
declare(strict_types=1);

namespace YouthSync\Users;

use PDO;
use PDOException;
use YouthSync\Admin\ActivityLog;
use YouthSync\Auth\AuthService;
use YouthSync\Auth\Password;
use YouthSync\Auth\RememberTokenStore;
use YouthSync\Http\Json;
use YouthSync\Http\Pagination;
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

        $paging = Pagination::fromQuery($query, 50, 100);

        [$sqlWhere, $params] = $this->orgSkWhere($organizationId);

        // Status totals for the whole organization (before search and filters),
        // for the screen's summary cards.
        $countsStmt = $this->pdo->prepare(
            "SELECT u.status, COUNT(*) AS total FROM {$this->fromSql()} WHERE {$sqlWhere} GROUP BY u.status"
        );
        $countsStmt->execute($params);
        $counts = ['all' => 0, 'active' => 0, 'inactive' => 0];
        foreach ($countsStmt->fetchAll() as $row) {
            $key = (string) $row['status'];
            $counts[$key] = ($counts[$key] ?? 0) + (int) $row['total'];
            $counts['all'] += (int) $row['total'];
        }

        $q = Pagination::search($query);
        if ($q !== '') {
            $sqlWhere .= ' AND ' . Pagination::likeAny(
                ['u.first_name', 'u.last_name', "CONCAT(u.first_name, ' ', u.last_name)", 'u.email'],
                $q, 'q', $params
            );
        }
        $status = strtolower(trim((string) ($query['status'] ?? '')));
        if ($status === 'active' || $status === 'inactive') {
            $sqlWhere .= ' AND u.status = :status';
            $params['status'] = $status;
        }
        // This list only holds SK official accounts, so "youth" matches nothing.
        $role = strtolower(str_replace(['-', ' '], '_', trim((string) ($query['role'] ?? ''))));
        if ($role !== '' && $role !== 'sk_official') {
            if ($role !== 'youth') {
                Json::validation(['role' => 'Choose sk_official or youth.']);
            }
            $sqlWhere .= ' AND 1 = 0';
        }
        $order = match (trim((string) ($query['sort'] ?? ''))) {
            'oldest' => 'u.created_at ASC, u.id ASC',
            'name' => 'u.first_name ASC, u.last_name ASC, u.id ASC',
            default => 'u.created_at DESC, u.id DESC',
        };

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->fromSql()} WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $meta = $paging->meta($total);

        $listStmt = $this->pdo->prepare(
            "SELECT {$this->selectSql()} FROM {$this->fromSql()}
             WHERE {$sqlWhere}
             ORDER BY {$order}
             {$paging->limitSql()}"
        );
        $listStmt->execute($params);
        $items = [];
        foreach ($listStmt->fetchAll() as $row) {
            $items[] = $this->publicUser($row);
        }

        return [
            'items' => $items,
            ...$meta,
            'counts' => $counts,
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
    public function create(int $organizationId, array $input, array $orgRow, array $actor = []): array
    {
        unset($input['organization_id'], $input['organizationId'], $input['orgId'], $input['org_id']);
        $values = UserValidator::normalizeCreate($input);
        $errors = UserValidator::validateCreate($values);
        if ($errors) {
            Json::validation($errors);
        }

        $plan = PlanLimits::effective($orgRow);

        $roleId = $this->skOfficialRoleId();
        $plain = $values['password'] !== '' ? $values['password'] : $this->temporaryPassword();
        $generated = $values['password'] === '';

        try {
            $this->pdo->beginTransaction();
            if ($plan['accounts'] !== null) {
                // Same transaction as the inserts: organization row lock, then count.
                PlanLimits::lockOrganization($this->pdo, $organizationId);
                PlanLimits::assertRoom($this->pdo, 'accounts', $plan, $this->countSkAccounts($organizationId));
            }
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
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'user.created',
            'user',
            'Created SK official account ' . $values['email'],
            ['entityType' => 'user', 'entityId' => $id]
        );
        return $payload;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $organizationId, int $id, array $input, array $actor = []): array
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
            ActivityLog::recordSk(
                $this->pdo,
                $organizationId,
                $actor,
                'user.updated',
                'user',
                'Updated SK official account',
                ['entityType' => 'user', 'entityId' => $id]
            );
        }

        return $this->get($organizationId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function setStatus(int $organizationId, int $actorId, int $id, array $input, array $actor = []): array
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

        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'user.updated',
            'user',
            'Set SK official status to ' . $status,
            ['entityType' => 'user', 'entityId' => $id]
        );

        return $this->get($organizationId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleActive(int $organizationId, int $actorId, int $id, array $actor = []): array
    {
        $row = $this->requireInOrg($organizationId, $id);
        $next = ($row['status'] ?? '') === 'active' ? 'inactive' : 'active';
        return $this->setStatus($organizationId, $actorId, $id, ['status' => $next], $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function resetPassword(int $organizationId, int $id, array $actor = []): array
    {
        $this->requireInOrg($organizationId, $id);
        $plain = $this->temporaryPassword();
        $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, must_change_password = 1 WHERE id = :id'
        )->execute([
            'hash' => Password::hash($plain),
            'id' => $id,
        ]);
        $this->forgetRememberTokens($id);
        $payload = $this->get($organizationId, $id);
        $payload['temporaryPassword'] = $plain;
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'user.updated',
            'user',
            'Reset SK official password',
            ['entityType' => 'user', 'entityId' => $id]
        );
        return $payload;
    }

    /**
     * @return array{deleted: true}
     */
    public function delete(int $organizationId, int $actorId, int $id, array $actor = []): array
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

        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'user.deleted',
            'user',
            'Deleted SK official account',
            ['entityType' => 'user', 'entityId' => $id]
        );

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
        return PlanLimits::count($this->pdo, 'accounts', $organizationId);
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
        return 'Ys-' . bin2hex(random_bytes(4));
    }

    private function forgetRememberTokens(int $userId): void
    {
        if ($userId < 1) {
            return;
        }
        (new RememberTokenStore(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'remember_tokens.json'
        ))->forgetUser($userId);
    }

    /**
     * The signed-in SK official's own profile. The user id comes from the session.
     *
     * @return array<string, mixed>
     */
    public function ownAccount(int $userId): array
    {
        return $this->presentOwnAccount($this->requireOwnAccount($userId));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function updateOwnAccount(int $userId, array $input, array $actor = []): array
    {
        foreach ([
            'id', 'user_id', 'userId', 'organization_id', 'organizationId', 'orgId', 'org_id',
            'role', 'role_id', 'roleId', 'roleCode', 'status', 'youth_id', 'youthId',
            'password', 'password_hash', 'passwordHash', 'currentPassword', 'current_password',
            'newPassword', 'new_password', 'must_change_password', 'mustChangePassword',
        ] as $blocked) {
            unset($input[$blocked]);
        }

        $this->requireOwnAccount($userId);
        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 160);
        if ($name === '' && (isset($input['firstName']) || isset($input['first_name']) || isset($input['lastName']) || isset($input['last_name']))) {
            $first = mb_substr(trim((string) ($input['firstName'] ?? $input['first_name'] ?? '')), 0, 80);
            $last = mb_substr(trim((string) ($input['lastName'] ?? $input['last_name'] ?? '')), 0, 80);
            $name = trim($first . ' ' . $last);
        }
        $email = mb_strtolower(mb_substr(trim((string) ($input['email'] ?? '')), 0, 190));

        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $firstName = mb_substr((string) ($parts[0] ?? ''), 0, 80);
        $lastName = mb_substr(trim((string) ($parts[1] ?? '')), 0, 80);

        $errors = [];
        if ($firstName === '' || $lastName === '') {
            $errors['name'] = 'Enter a first and last name.';
        }
        if ($email === '') {
            $errors['email'] = 'An email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($errors) {
            Json::validation($errors);
        }

        $taken = $this->pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $taken->execute(['email' => $email, 'id' => $userId]);
        if ($taken->fetch() !== false) {
            Json::error('CONFLICT', 'That email already has an account.', 409);
        }

        try {
            $this->pdo->prepare(
                'UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email WHERE id = :id'
            )->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'id' => $userId,
            ]);
        } catch (PDOException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? '');
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($sqlState === '23000' || $driverCode === 1062) {
                Json::error('CONFLICT', 'That email already has an account.', 409);
            }
            throw $e;
        }

        $organizationId = (int) ($actor['organization_id'] ?? 0);
        if ($organizationId < 1) {
            $org = $this->pdo->prepare(
                'SELECT organization_id FROM organization_users WHERE user_id = :id AND status = :status ORDER BY is_owner DESC, id ASC LIMIT 1'
            );
            $org->execute(['id' => $userId, 'status' => 'active']);
            $organizationId = (int) $org->fetchColumn();
        }
        if ($organizationId > 0) {
            ActivityLog::recordSk(
                $this->pdo,
                $organizationId,
                $actor,
                'user.updated',
                'user',
                'Updated their account profile',
                ['entityType' => 'user', 'entityId' => $userId]
            );
        }

        return $this->presentOwnAccount($this->requireOwnAccount($userId));
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOwnAccount(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, first_name, last_name FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentOwnAccount(array $row): array
    {
        $first = (string) $row['first_name'];
        $last = (string) $row['last_name'];
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'firstName' => $first,
            'lastName' => $last,
            'name' => trim($first . ' ' . $last),
        ];
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
