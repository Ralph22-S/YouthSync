<?php
declare(strict_types=1);

namespace YouthSync\Admin;

use PDO;
use PDOException;
use YouthSync\Auth\Password;
use YouthSync\Http\Json;
use YouthSync\Org\OrganizationValidator;

/**
 * Everything the System Administrator console reads and writes.
 *
 * Deployment-wide by design: unlike the SK services there is no organization
 * scope to enforce, so every query here deliberately spans all organizations.
 */
final class AdminService
{
    public const STATUSES = ['pending', 'active', 'suspended', 'rejected', 'inactive'];

    /** Statuses the admin may set directly. 'pending' is where a registration starts, never a destination. */
    public const SETTABLE_STATUSES = ['active', 'suspended', 'rejected', 'inactive'];

    public const PAYMENT_STATUSES = ['pending', 'paid', 'failed', 'refunded'];

    private const TRIAL_DAYS = 7;

    public function __construct(private PDO $pdo)
    {
    }

    // ---- organizations ----------------------------------------------------

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listOrganizations(array $query): array
    {
        $q = trim((string) ($query['q'] ?? ''));
        $status = trim((string) ($query['status'] ?? ''));
        $plan = trim((string) ($query['plan'] ?? ''));
        $sort = (string) ($query['sort'] ?? 'name');

        $where = ['1 = 1'];
        $params = [];

        if ($q !== '') {
            // One placeholder per column: emulated prepares are off.
            $columns = ['o.name', 'o.barangay', 'o.municipality', 'o.province', 'o.chairperson', 'o.email', 'o.contact'];
            $likes = [];
            foreach ($columns as $i => $column) {
                $likes[] = "{$column} LIKE :q{$i}";
                $params['q' . $i] = '%' . $q . '%';
            }
            $where[] = '(' . implode(' OR ', $likes) . ')';
        }
        if ($status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                Json::error('VALIDATION_ERROR', 'That organization status is not recognised.', 422);
            }
            $where[] = 'o.status = :status';
            $params['status'] = $status;
        }
        if ($plan !== '') {
            $where[] = 'o.plan = :plan';
            $params['plan'] = $plan;
        }

        $sqlWhere = implode(' AND ', $where);
        $order = match ($sort) {
            'newest' => 'o.created_at DESC, o.id DESC',
            'oldest' => 'o.created_at ASC, o.id ASC',
            'status' => "FIELD(o.status, 'pending', 'active', 'suspended', 'rejected', 'inactive'), o.name ASC",
            default => 'o.name ASC, o.id ASC',
        };

        [$page, $perPage] = $this->paging($query);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM organizations o WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT o.*,
                    (SELECT COUNT(*) FROM youth y WHERE y.organization_id = o.id AND y.archived = 0) AS youth_total,
                    (SELECT COUNT(*) FROM organization_users ou WHERE ou.organization_id = o.id) AS user_total
             FROM organizations o
             WHERE {$sqlWhere}
             ORDER BY {$order}
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->publicOrganization($row);
        }

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'counts' => $this->statusCounts(),
        ];
    }

    /**
     * Create an organization and the owner account that runs it.
     *
     * The console promises an active organization on a 7-day Premium trial, so
     * that is what this writes - there is no separate approval step for one the
     * administrator entered by hand.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function createOrganization(array $input, array $admin): array
    {
        // A blank password here means "issue one", so it is not required.
        $values = OrganizationValidator::normalize($input);
        $errors = OrganizationValidator::validate($values, false);
        if ($errors) {
            Json::validation($errors);
        }

        $conflict = OrganizationValidator::conflict($this->pdo, $values);
        if ($conflict !== null) {
            Json::error($conflict['code'], $conflict['message'], 409);
        }

        $roleId = (int) $this->pdo->query("SELECT id FROM roles WHERE code = 'SK_OFFICIAL' LIMIT 1")->fetchColumn();
        if ($roleId < 1) {
            Json::error('SERVER_ERROR', 'SK Official role is not configured.', 500);
        }

        [$ownerFirst, $ownerLast] = OrganizationValidator::splitName($values['ownerName']);
        $ownerEmail = $values['ownerEmail'];
        $plain = $values['ownerPassword'] !== '' ? $values['ownerPassword'] : 'YS-' . strtoupper(bin2hex(random_bytes(4)));
        $generated = $values['ownerPassword'] === '';
        $name = OrganizationValidator::displayName($values['barangay'], $values['municipality']);

        try {
            $this->pdo->beginTransaction();

            $this->pdo->prepare(
                "INSERT INTO organizations
                    (name, barangay, municipality, province, chairperson, email, contact,
                     status, status_note, approved_at, plan, sub_status, cycle, started_on, expires_at)
                 VALUES
                    (:name, :barangay, :municipality, :province, :chairperson, :email, :contact,
                     'active', '', NOW(), 'premium', 'trial', 'trial', CURDATE(),
                     DATE_ADD(CURDATE(), INTERVAL :days DAY))"
            )->execute([
                'name' => $name,
                'barangay' => $values['barangay'],
                'municipality' => $values['municipality'],
                'province' => $values['province'],
                'chairperson' => $values['chairperson'],
                'email' => $values['email'],
                'contact' => $values['contact'],
                'days' => self::TRIAL_DAYS,
            ]);
            $organizationId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                'INSERT INTO users (email, password_hash, first_name, last_name, role_id, status, must_change_password)
                 VALUES (:email, :hash, :first_name, :last_name, :role_id, :status, 1)'
            )->execute([
                'email' => $ownerEmail,
                'hash' => Password::hash($plain),
                'first_name' => $ownerFirst,
                'last_name' => $ownerLast,
                'role_id' => $roleId,
                'status' => 'active',
            ]);
            $ownerId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                "INSERT INTO organization_users (user_id, organization_id, is_owner, status)
                 VALUES (:user_id, :org, 1, 'active')"
            )->execute(['user_id' => $ownerId, 'org' => $organizationId]);

            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (self::isDuplicateKey($e)) {
                Json::error('CONFLICT', 'That owner email already has an account.', 409);
            }
            throw $e;
        }

        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($admin), [
            'organizationId' => $organizationId,
            'action' => ActivityLog::ACTION_CREATE,
            'category' => 'organization',
            'description' => 'Created ' . $name . ' with owner ' . $ownerEmail
                . ' and started its ' . self::TRIAL_DAYS . '-day Premium trial',
            'entityType' => 'organization',
            'entityId' => $organizationId,
        ]));

        $organization = $this->getOrganization($organizationId);
        $organization['temporaryPassword'] = $plain;
        $organization['passwordGenerated'] = $generated;
        return $organization;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrganization(int $id): array
    {
        $organization = $this->publicOrganization($this->requireOrganization($id));
        $organization['team'] = $this->teamFor($id);
        return $organization;
    }

    /**
     * Approve a registration: the organization goes active and its Premium
     * trial starts, which is what the console's approve button promises.
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function approveOrganization(int $id, array $admin): array
    {
        $row = $this->requireOrganization($id);
        if ($row['status'] === 'active') {
            Json::error('CONFLICT', 'This organization is already active.', 409);
        }

        $startsTrial = $row['plan'] === 'free' && in_array((string) $row['sub_status'], ['free', ''], true);
        if ($startsTrial) {
            $stmt = $this->pdo->prepare(
                "UPDATE organizations
                 SET status = 'active', status_note = '', approved_at = NOW(),
                     plan = 'premium', sub_status = 'trial', cycle = 'trial',
                     started_on = CURDATE(), expires_at = DATE_ADD(CURDATE(), INTERVAL :days DAY)
                 WHERE id = :id"
            );
            $stmt->execute(['days' => self::TRIAL_DAYS, 'id' => $id]);
        } else {
            $stmt = $this->pdo->prepare(
                "UPDATE organizations
                 SET status = 'active', status_note = '', approved_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute(['id' => $id]);
        }

        $trialNote = $startsTrial ? ' and started its ' . self::TRIAL_DAYS . '-day Premium trial' : '';
        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($admin), [
            'organizationId' => $id,
            'action' => ActivityLog::ACTION_UPDATE,
            'category' => 'organization',
            'description' => 'Approved ' . $row['name'] . $trialNote,
            'entityType' => 'organization',
            'entityId' => $id,
        ]));

        return $this->getOrganization($id);
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function setOrganizationStatus(int $id, string $status, string $note, array $admin): array
    {
        $row = $this->requireOrganization($id);

        $status = trim($status);
        if (!in_array($status, self::SETTABLE_STATUSES, true)) {
            Json::validation(['status' => 'Choose one of: ' . implode(', ', self::SETTABLE_STATUSES) . '.']);
        }
        $note = mb_substr(trim($note), 0, 500);
        if (in_array($status, ['suspended', 'rejected'], true) && $note === '') {
            Json::validation(['note' => 'A short reason is required when suspending or rejecting an organization.']);
        }

        $approvedAt = $status === 'active' ? 'COALESCE(approved_at, NOW())' : 'approved_at';
        $stmt = $this->pdo->prepare(
            "UPDATE organizations
             SET status = :status, status_note = :note, approved_at = {$approvedAt}
             WHERE id = :id"
        );
        $stmt->execute(['status' => $status, 'note' => $note, 'id' => $id]);

        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($admin), [
            'organizationId' => $id,
            'action' => ActivityLog::ACTION_UPDATE,
            'category' => 'organization',
            'description' => 'Changed ' . $row['name'] . ' from ' . $row['status'] . ' to ' . $status
                . ($note !== '' ? ' - ' . $note : ''),
            'entityType' => 'organization',
            'entityId' => $id,
        ]));

        return $this->getOrganization($id);
    }

    // ---- activity ---------------------------------------------------------

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listActivity(?int $organizationId, array $query): array
    {
        $where = [];
        $params = [];

        if ($organizationId !== null) {
            $where[] = 'a.organization_id = :org';
            $params['org'] = $organizationId;
        }

        $action = trim((string) ($query['action'] ?? ''));
        if ($action !== '') {
            $where[] = 'a.action = :action';
            $params['action'] = $action;
        }
        $category = trim((string) ($query['category'] ?? ''));
        if ($category !== '') {
            $where[] = 'a.category = :category';
            $params['category'] = $category;
        }
        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.description LIKE :q0 OR a.actor_name LIKE :q1)';
            $params['q0'] = '%' . $q . '%';
            $params['q1'] = '%' . $q . '%';
        }

        $sqlWhere = $where === [] ? '1 = 1' : implode(' AND ', $where);
        [$page, $perPage] = $this->paging($query);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM activity_logs a WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT a.*, o.name AS organization_name
             FROM activity_logs a
             LEFT JOIN organizations o ON o.id = a.organization_id
             WHERE {$sqlWhere}
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'orgId' => $row['organization_id'] === null ? null : (int) $row['organization_id'],
                'organizationName' => $row['organization_name'],
                'userId' => $row['user_id'] === null ? null : (int) $row['user_id'],
                'youthId' => $row['youth_id'] === null ? null : (int) $row['youth_id'],
                'user' => $row['actor_name'],
                'actorRole' => $row['actor_role'],
                'action' => $row['action'],
                'category' => $row['category'],
                'description' => $row['description'],
                'entityType' => $row['entity_type'],
                'entityId' => $row['entity_id'] === null ? null : (int) $row['entity_id'],
                'ip' => $row['ip'],
                'at' => $row['created_at'],
                'createdAt' => $row['created_at'],
            ];
        }

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    // ---- payments ---------------------------------------------------------

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listPayments(?int $organizationId, array $query): array
    {
        $where = [];
        $params = [];

        if ($organizationId !== null) {
            $where[] = 'p.organization_id = :org';
            $params['org'] = $organizationId;
        }
        $status = trim((string) ($query['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, self::PAYMENT_STATUSES, true)) {
                Json::error('VALIDATION_ERROR', 'That payment status is not recognised.', 422);
            }
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }
        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.reference LIKE :q0 OR o.name LIKE :q1)';
            $params['q0'] = '%' . $q . '%';
            $params['q1'] = '%' . $q . '%';
        }

        $sqlWhere = $where === [] ? '1 = 1' : implode(' AND ', $where);
        [$page, $perPage] = $this->paging($query);

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM payments p
             LEFT JOIN organizations o ON o.id = p.organization_id
             WHERE {$sqlWhere}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT p.*, o.name AS organization_name
             FROM payments p
             LEFT JOIN organizations o ON o.id = p.organization_id
             WHERE {$sqlWhere}
             ORDER BY COALESCE(p.paid_at, p.created_at) DESC, p.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->publicPayment($row);
        }

        $totalsStmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END), 0) AS collected,
                COALESCE(SUM(CASE WHEN p.status = 'refunded' THEN p.amount ELSE 0 END), 0) AS refunded,
                COALESCE(SUM(p.status = 'pending'), 0) AS pending_count,
                COALESCE(SUM(p.status = 'failed'), 0) AS failed_count
             FROM payments p
             WHERE " . ($organizationId !== null ? 'p.organization_id = :org' : '1 = 1')
        );
        $totalsStmt->execute($organizationId !== null ? ['org' => $organizationId] : []);
        $totals = $totalsStmt->fetch() ?: [];

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'totals' => [
                'collected' => (float) ($totals['collected'] ?? 0),
                'refunded' => (float) ($totals['refunded'] ?? 0),
                'pending' => (int) ($totals['pending_count'] ?? 0),
                'failed' => (int) ($totals['failed_count'] ?? 0),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function recordPayment(array $input, array $admin): array
    {
        $organizationId = (int) ($input['organizationId'] ?? $input['orgId'] ?? 0);
        $organization = $this->requireOrganization($organizationId);

        $errors = [];

        $reference = mb_substr(trim((string) ($input['reference'] ?? '')), 0, 64);
        $generated = $reference === '';

        $amountRaw = $input['amount'] ?? '';
        $amount = is_numeric($amountRaw) ? (float) $amountRaw : -1.0;
        if ($amount < 0) {
            $errors['amount'] = 'Enter the amount paid as a number.';
        }

        $status = (string) ($input['status'] ?? 'paid');
        if (!in_array($status, self::PAYMENT_STATUSES, true)) {
            $errors['status'] = 'Choose one of: ' . implode(', ', self::PAYMENT_STATUSES) . '.';
        }

        $planCode = (string) ($input['planCode'] ?? $input['plan'] ?? $organization['plan']);
        if (!in_array($planCode, ['free', 'basic', 'premium'], true)) {
            $errors['planCode'] = 'That plan is not recognised.';
        }

        if ($errors) {
            Json::validation($errors);
        }

        if (!$generated) {
            $duplicate = $this->pdo->prepare('SELECT id FROM payments WHERE reference = :ref LIMIT 1');
            $duplicate->execute(['ref' => $reference]);
            if ($duplicate->fetch() !== false) {
                Json::error('CONFLICT', 'A payment with that transaction number is already recorded.', 409);
            }
        }

        $cycle = mb_substr(trim((string) ($input['cycle'] ?? $organization['cycle'] ?? 'monthly')), 0, 32) ?: 'monthly';
        $method = mb_substr(trim((string) ($input['method'] ?? 'gcash')), 0, 32) ?: 'gcash';
        $note = mb_substr(trim((string) ($input['note'] ?? '')), 0, 255);
        // NOW() rather than PHP's clock: created_at is stamped by MySQL, and the
        // two must agree even when PHP and the database run different timezones.
        $paidAt = $status === 'paid' ? 'NOW()' : 'NULL';

        $insert = $this->pdo->prepare(
            "INSERT INTO payments
                (organization_id, reference, amount, plan_code, cycle, method, status, paid_at, note, recorded_by)
             VALUES
                (:org, :ref, :amount, :plan, :cycle, :method, :status, {$paidAt}, :note, :recorded_by)"
        );
        $row = [
            'org' => $organizationId,
            'amount' => $amount,
            'plan' => $planCode,
            'cycle' => $cycle,
            'method' => $method,
            'status' => $status,
            'note' => $note,
            'recorded_by' => $admin['id'] ?? null,
        ];

        // The unique index is the final arbiter. If two payments are recorded in
        // the same instant and claim the same generated number, the loser simply
        // takes the next one instead of surfacing a collision to the admin.
        $id = 0;
        for ($attempt = 1; ; $attempt++) {
            if ($generated) {
                $reference = $this->nextPaymentReference();
            }
            try {
                $insert->execute($row + ['ref' => $reference]);
                $id = (int) $this->pdo->lastInsertId();
                break;
            } catch (PDOException $e) {
                if (!$generated || $attempt >= 5 || !self::isDuplicateKey($e)) {
                    throw $e;
                }
            }
        }

        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($admin), [
            'organizationId' => $organizationId,
            'action' => ActivityLog::ACTION_CREATE,
            'category' => 'payment',
            'description' => 'Recorded a ' . $status . ' payment of PHP ' . number_format($amount, 2)
                . ' (' . $reference . ') for ' . $organization['name'],
            'entityType' => 'payment',
            'entityId' => $id,
        ]));

        $stmt = $this->pdo->prepare(
            'SELECT p.*, o.name AS organization_name
             FROM payments p LEFT JOIN organizations o ON o.id = p.organization_id
             WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        return $this->publicPayment($stmt->fetch() ?: []);
    }

    // ---- users ------------------------------------------------------------

    /**
     * Every account on the deployment, with the organization it belongs to.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listUsers(array $query): array
    {
        [$page, $perPage] = $this->paging($query);

        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.status, u.last_login_at, u.created_at,
                    r.code AS role_code,
                    ou.organization_id, ou.is_owner
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN organization_users ou ON ou.user_id = u.id
             ORDER BY u.id ASC
             LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
        );
        $stmt->execute();

        $roleMap = [
            'SYSTEM_ADMIN' => 'system_admin',
            'SK_OFFICIAL' => 'sk_official',
            'YOUTH' => 'youth',
        ];

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $name = trim(((string) $row['first_name']) . ' ' . ((string) $row['last_name']));
            $items[] = [
                'id' => (int) $row['id'],
                'email' => $row['email'],
                'firstName' => $row['first_name'],
                'lastName' => $row['last_name'],
                'name' => $name !== '' ? $name : (string) $row['email'],
                'role' => $roleMap[$row['role_code']] ?? 'sk_official',
                'orgId' => $row['organization_id'] === null ? null : (int) $row['organization_id'],
                'owner' => (bool) $row['is_owner'],
                'active' => $row['status'] === 'active',
                'status' => $row['status'],
                'lastLogin' => $row['last_login_at'] ?: 'Never',
                'createdAt' => $row['created_at'],
            ];
        }

        $total = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM users u LEFT JOIN organization_users ou ON ou.user_id = u.id'
        )->fetchColumn();

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    // ---- shared -----------------------------------------------------------

    /**
     * The next transaction number for today, in YSP-YYYYMMDD-0001 form.
     *
     * Dated from MySQL rather than PHP so the number agrees with the paid_at
     * and created_at stamps the same row is about to receive.
     */
    private function nextPaymentReference(): string
    {
        $today = (string) $this->pdo->query("SELECT DATE_FORMAT(NOW(), '%Y%m%d')")->fetchColumn();
        $prefix = 'YSP-' . $today . '-';

        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(reference, :offset) AS UNSIGNED)), 0) + 1
             FROM payments WHERE reference LIKE :prefix'
        );
        $stmt->execute([
            'offset' => strlen($prefix) + 1,
            'prefix' => $prefix . '%',
        ]);

        return $prefix . str_pad((string) (int) $stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);
    }

    private static function isDuplicateKey(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOrganization(int $id): array
    {
        if ($id < 1) {
            Json::error('NOT_FOUND', 'That organization is not on this deployment.', 404);
        }
        $stmt = $this->pdo->prepare(
            "SELECT o.*,
                    (SELECT COUNT(*) FROM youth y WHERE y.organization_id = o.id AND y.archived = 0) AS youth_total,
                    (SELECT COUNT(*) FROM organization_users ou WHERE ou.organization_id = o.id) AS user_total
             FROM organizations o WHERE o.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'That organization is not on this deployment.', 404);
        }
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function teamFor(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.first_name, u.last_name, u.status, u.last_login_at,
                    r.code AS role_code, ou.is_owner
             FROM organization_users ou
             INNER JOIN users u ON u.id = ou.user_id
             INNER JOIN roles r ON r.id = u.role_id
             WHERE ou.organization_id = :org
             ORDER BY ou.is_owner DESC, u.id ASC'
        );
        $stmt->execute(['org' => $organizationId]);

        $team = [];
        foreach ($stmt->fetchAll() as $row) {
            $name = trim(((string) $row['first_name']) . ' ' . ((string) $row['last_name']));
            $team[] = [
                'id' => (int) $row['id'],
                'name' => $name !== '' ? $name : (string) $row['email'],
                'email' => $row['email'],
                'role' => $row['role_code'] === 'YOUTH' ? 'youth' : 'sk_official',
                'owner' => (bool) $row['is_owner'],
                'active' => $row['status'] === 'active',
                'lastLogin' => $row['last_login_at'] ?: 'Never',
            ];
        }
        return $team;
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        $counts['all'] = 0;
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS n FROM organizations GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
            $counts['all'] += (int) $row['n'];
        }
        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicOrganization(array $row): array
    {
        $expiresAt = $row['expires_at'] ?? null;
        $expiresIn = null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            $days = (strtotime($expiresAt . ' 23:59:59') - time()) / 86400;
            $expiresIn = (int) floor($days);
        }

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'barangay' => $row['barangay'],
            'municipality' => $row['municipality'],
            'province' => $row['province'],
            'chairperson' => $row['chairperson'],
            'email' => $row['email'],
            'contact' => $row['contact'],
            'status' => $row['status'],
            'statusNote' => $row['status_note'] ?? '',
            'approvedAt' => $row['approved_at'] ?? null,
            'plan' => $row['plan'],
            'subStatus' => $row['sub_status'],
            'cycle' => $row['cycle'],
            'startedOn' => $row['started_on'] ?? null,
            'expiresAt' => $expiresAt,
            'expiresIn' => $expiresIn,
            'youthCount' => (int) ($row['youth_total'] ?? $row['youth_count'] ?? 0),
            'userCount' => (int) ($row['user_total'] ?? 0),
            'createdAt' => $row['created_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicPayment(array $row): array
    {
        if ($row === []) {
            return [];
        }
        return [
            'id' => (int) $row['id'],
            'orgId' => (int) $row['organization_id'],
            'organizationName' => $row['organization_name'] ?? null,
            'reference' => $row['reference'],
            'amount' => (float) $row['amount'],
            'planCode' => $row['plan_code'],
            'cycle' => $row['cycle'],
            'method' => $row['method'],
            'status' => $row['status'],
            'paidAt' => $row['paid_at'],
            'note' => $row['note'],
            'createdAt' => $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{int, int}
     */
    private function paging(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['perPage'] ?? $query['per_page'] ?? 25);
        if ($perPage < 1) {
            $perPage = 25;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }
        return [$page, $perPage];
    }
}
