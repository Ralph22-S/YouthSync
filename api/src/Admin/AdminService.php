<?php
declare(strict_types=1);

namespace YouthSync\Admin;

use PDO;
use PDOException;
use YouthSync\Auth\Password;
use YouthSync\Auth\RememberTokenStore;
use YouthSync\Http\Json;
use YouthSync\Http\Pagination;
use YouthSync\Org\OrganizationValidator;
use YouthSync\Org\PlanLimits;

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

    public const PLANS = ['free', 'basic', 'premium'];

    private const TRIAL_DAYS = 7;

    public function __construct(private PDO $pdo)
    {
    }

    // ---- dashboard --------------------------------------------------------

    /**
     * Deployment-wide statistics for the System Administrator overview.
     *
     * Every number is a SQL aggregate over the live tables. Dates use the
     * database clock (CURDATE()) so "expiring" matches what SQL reports.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        // Same expiry rule as SK requests, so lapsed terms are counted as expired.
        PlanLimits::expireDue($this->pdo);
        $orgStatus = array_fill_keys(self::STATUSES, 0);
        $orgPlan = array_fill_keys(self::PLANS, 0);
        $subStatus = [];
        $orgTotal = 0;
        foreach ($this->pdo->query(
            'SELECT status, plan, sub_status, COUNT(*) AS n FROM organizations GROUP BY status, plan, sub_status'
        ) as $row) {
            $n = (int) $row['n'];
            $orgTotal += $n;
            $orgStatus[(string) $row['status']] = ($orgStatus[(string) $row['status']] ?? 0) + $n;
            $orgPlan[(string) $row['plan']] = ($orgPlan[(string) $row['plan']] ?? 0) + $n;
            $sub = (string) $row['sub_status'] !== '' ? (string) $row['sub_status'] : '(blank)';
            $subStatus[$sub] = ($subStatus[$sub] ?? 0) + $n;
        }

        $subs = $this->pdo->query(
            "SELECT
                COALESCE(SUM(sub_status IN ('active', 'trial')), 0) AS active_subscriptions,
                COALESCE(SUM(sub_status IN ('active', 'trial')
                    AND expires_at IS NOT NULL
                    AND expires_at BETWEEN CURDATE() AND CURDATE() + INTERVAL 3 DAY), 0) AS expiring_soon,
                COALESCE(SUM(sub_status = 'expired'), 0) AS expired,
                COALESCE(SUM(sub_status = 'trial'), 0) AS trial,
                COALESCE(SUM(plan = 'free'), 0) AS free_plan,
                COALESCE(SUM(plan = 'basic' AND sub_status = 'active'), 0) AS basic_active,
                COALESCE(SUM(plan = 'premium' AND sub_status = 'active'), 0) AS premium_active
             FROM organizations"
        )->fetch() ?: [];

        $users = ['total' => 0, 'active' => 0, 'byRole' => []];
        foreach ($this->pdo->query(
            "SELECT r.code AS role_code, COUNT(*) AS n, COALESCE(SUM(u.status = 'active'), 0) AS active
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             GROUP BY r.code"
        ) as $row) {
            $users['byRole'][(string) $row['role_code']] = (int) $row['n'];
            $users['total'] += (int) $row['n'];
            $users['active'] += (int) $row['active'];
        }

        $youth = $this->pdo->query(
            'SELECT COUNT(*) AS total, COALESCE(SUM(archived = 0), 0) AS active, COALESCE(SUM(archived = 1), 0) AS archived
             FROM youth'
        )->fetch() ?: [];

        $programs = ['total' => 0, 'programs' => 0, 'events' => 0, 'byStatus' => []];
        foreach ($this->pdo->query('SELECT kind, status, COUNT(*) AS n FROM programs GROUP BY kind, status') as $row) {
            $n = (int) $row['n'];
            $programs['total'] += $n;
            if ((string) $row['kind'] === 'program') {
                $programs['programs'] += $n;
            } elseif ((string) $row['kind'] === 'event') {
                $programs['events'] += $n;
            }
            $programs['byStatus'][(string) $row['status']] = ($programs['byStatus'][(string) $row['status']] ?? 0) + $n;
        }

        $applications = ['total' => 0, 'byStatus' => []];
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS n FROM applications GROUP BY status') as $row) {
            $applications['byStatus'][(string) $row['status']] = (int) $row['n'];
            $applications['total'] += (int) $row['n'];
        }

        // Revenue is PayMongo-verified only: transactions verified server-side (verified_at),
        // net of refunds. The manual / offline ledger was removed, so the legacy `payments`
        // table is no longer read here (its historical rows are kept, untouched).
        $payments = [
            'source' => 'paymongo_verified',
            'onlineVerified' => 0.0, 'onlineVerifiedCount' => 0, 'onlineVerifiedByPlan' => [],
        ];
        $verifiedCentavos = 0;
        foreach ($this->pdo->query(
            "SELECT plan, COUNT(*) AS n, COALESCE(SUM(amount_centavos - COALESCE(refunded_centavos, 0)), 0) AS c
             FROM payment_transactions
             WHERE verified_at IS NOT NULL AND status IN ('paid', 'partially_refunded')
             GROUP BY plan"
        ) as $row) {
            $payments['onlineVerifiedCount'] += (int) $row['n'];
            $verifiedCentavos += (int) $row['c'];
            $payments['onlineVerifiedByPlan'][(string) $row['plan']] = round(((int) $row['c']) / 100, 2);
        }
        $payments['onlineVerified'] = round($verifiedCentavos / 100, 2);

        // LEFT JOIN keeps organizations with no youth; one aggregate per org, no row duplication.
        $perOrg = [];
        foreach ($this->pdo->query(
            'SELECT o.id, o.name, o.barangay, o.municipality, o.status,
                    COUNT(y.id) AS youth_total,
                    COALESCE(SUM(y.archived = 0), 0) AS youth_active,
                    COALESCE(SUM(y.archived = 1), 0) AS youth_archived
             FROM organizations o
             LEFT JOIN youth y ON y.organization_id = o.id
             GROUP BY o.id, o.name, o.barangay, o.municipality, o.status
             ORDER BY youth_active DESC, o.name ASC, o.id ASC'
        ) as $row) {
            $perOrg[] = [
                'organizationId' => (int) $row['id'],
                'name' => $row['name'],
                'barangay' => $row['barangay'],
                'municipality' => $row['municipality'],
                'status' => $row['status'],
                'youth' => (int) $row['youth_active'],
                'archivedYouth' => (int) $row['youth_archived'],
                'totalYouth' => (int) $row['youth_total'],
            ];
        }

        return [
            'organizations' => [
                'total' => $orgTotal,
                'pending' => $orgStatus['pending'] ?? 0,
                'byStatus' => $orgStatus,
                'byPlan' => $orgPlan,
                'bySubscriptionStatus' => $subStatus,
                'activeSubscriptions' => (int) ($subs['active_subscriptions'] ?? 0),
                'expiringSoon' => (int) ($subs['expiring_soon'] ?? 0),
                'expired' => (int) ($subs['expired'] ?? 0),
                'trial' => (int) ($subs['trial'] ?? 0),
                'freePlan' => (int) ($subs['free_plan'] ?? 0),
                'basicActive' => (int) ($subs['basic_active'] ?? 0),
                'premiumActive' => (int) ($subs['premium_active'] ?? 0),
            ],
            'users' => $users,
            'youth' => [
                'total' => (int) ($youth['total'] ?? 0),
                'active' => (int) ($youth['active'] ?? 0),
                'archived' => (int) ($youth['archived'] ?? 0),
            ],
            'programs' => $programs,
            'applications' => $applications,
            'payments' => $payments,
            'youthPerOrganization' => $perOrg,
            'generatedAt' => (string) $this->pdo->query('SELECT NOW()')->fetchColumn(),
        ];
    }

    // ---- organizations ----------------------------------------------------

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listOrganizations(array $query): array
    {
        PlanLimits::expireDue($this->pdo);
        $q = Pagination::search($query);
        $status = trim((string) ($query['status'] ?? ''));
        $plan = trim((string) ($query['plan'] ?? ''));
        $sort = (string) ($query['sort'] ?? 'name');

        $where = ['1 = 1'];
        $params = [];

        if ($q !== '') {
            $where[] = Pagination::likeAny(
                ['o.name', 'o.barangay', 'o.municipality', 'o.province', 'o.chairperson', 'o.email', 'o.contact'],
                $q, 'q', $params
            );
        }
        if ($status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                Json::error('VALIDATION_ERROR', 'That organization status is not recognised.', 422);
            }
            $where[] = 'o.status = :status';
            $params['status'] = $status;
        }
        if ($plan !== '') {
            if (!in_array($plan, self::PLANS, true)) {
                Json::error('VALIDATION_ERROR', 'That plan is not recognised.', 422);
            }
            $where[] = 'o.plan = :plan';
            $params['plan'] = $plan;
        }

        $sqlWhere = implode(' AND ', $where);
        $order = match ($sort) {
            'newest' => 'o.created_at DESC, o.id DESC',
            'oldest' => 'o.created_at ASC, o.id ASC',
            'status' => "FIELD(o.status, 'pending', 'active', 'suspended', 'rejected', 'inactive'), o.name ASC, o.id ASC",
            default => 'o.name ASC, o.id ASC',
        };

        $paging = $this->paging($query);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM organizations o WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $meta = $paging->meta($total);

        $stmt = $this->pdo->prepare(
            "SELECT o.*,
                    (SELECT COUNT(*) FROM youth y WHERE y.organization_id = o.id AND y.archived = 0) AS youth_total,
                    (SELECT COUNT(*) FROM organization_users ou WHERE ou.organization_id = o.id) AS user_total
             FROM organizations o
             WHERE {$sqlWhere}
             ORDER BY {$order}
             {$paging->limitSql()}"
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->publicOrganization($row);
        }

        return [
            'items' => $items,
            ...$meta,
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
        $plain = $values['ownerPassword'] !== '' ? $values['ownerPassword'] : 'Ys-' . bin2hex(random_bytes(4));
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
        // Plan usage with the same counting rules as enforcement, for the admin plan dialogs.
        $organization['usage'] = PlanLimits::usage($this->pdo, $id);
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
        $q = Pagination::search($query);
        if ($q !== '') {
            $where[] = Pagination::likeAny(['a.description', 'a.actor_name'], $q, 'q', $params);
        }

        $sqlWhere = $where === [] ? '1 = 1' : implode(' AND ', $where);
        $paging = $this->paging($query);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM activity_logs a WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $meta = $paging->meta($total);

        $stmt = $this->pdo->prepare(
            "SELECT a.*, o.name AS organization_name
             FROM activity_logs a
             LEFT JOIN organizations o ON o.id = a.organization_id
             WHERE {$sqlWhere}
             ORDER BY a.created_at DESC, a.id DESC
             {$paging->limitSql()}"
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
            ...$meta,
            // Dropdown values for the whole trail (or this organization's), not just this page.
            'filters' => [
                'actions' => $this->activityValues($organizationId, 'action'),
                'categories' => $this->activityValues($organizationId, 'category'),
            ],
        ];
    }

    /**
     * Distinct action or category values in the activity trail. The column
     * name comes from a fixed list.
     *
     * @return list<string>
     */
    private function activityValues(?int $organizationId, string $column): array
    {
        if (!in_array($column, ['action', 'category'], true)) {
            return [];
        }
        $sql = "SELECT DISTINCT {$column} FROM activity_logs"
            . ($organizationId !== null ? ' WHERE organization_id = :org' : '')
            . " ORDER BY {$column}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($organizationId !== null ? ['org' => $organizationId] : []);
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
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
        $paging = $this->paging($query);
        [$where, $params] = $this->userFilters($query);

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN organization_users ou ON ou.user_id = u.id
             WHERE {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $meta = $paging->meta($total);

        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.status, u.last_login_at, u.created_at,
                    r.code AS role_code,
                    ou.organization_id, ou.is_owner, org.name AS organization_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN organization_users ou ON ou.user_id = u.id
             LEFT JOIN organizations org ON org.id = ou.organization_id
             WHERE {$where}
             ORDER BY u.id ASC, ou.organization_id ASC
             {$paging->limitSql()}"
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->publicListedUser($row);
        }

        return [
            'items' => $items,
            ...$meta,
        ];
    }

    /**
     * Activate or deactivate an account. Role, password, youth link, and
     * organization membership are left untouched.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function setUserStatus(int $id, array $input, array $admin): array
    {
        if ($id < 1) {
            Json::error('NOT_FOUND', 'That account is not on this deployment.', 404);
        }
        if ($id === (int) ($admin['id'] ?? 0)) {
            Json::error('VALIDATION_ERROR', 'You cannot change your own account status.', 422);
        }

        $status = strtolower(trim((string) ($input['status'] ?? '')));
        if ($status !== 'active' && $status !== 'inactive') {
            Json::validation(['status' => 'Status must be active or inactive.']);
        }

        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.status, u.role_id, u.youth_id, u.password_hash, r.code AS role_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $before = $stmt->fetch();
        if ($before === false) {
            Json::error('NOT_FOUND', 'That account is not on this deployment.', 404);
        }
        if (($before['role_code'] ?? '') === 'SYSTEM_ADMIN' && $status === 'inactive') {
            Json::error('VALIDATION_ERROR', 'System administrator accounts cannot be deactivated from this console.', 422);
        }

        $this->pdo->prepare('UPDATE users SET status = :status WHERE id = :id')->execute([
            'status' => $status,
            'id' => $id,
        ]);

        $after = $this->pdo->prepare(
            'SELECT role_id, youth_id, password_hash FROM users WHERE id = :id LIMIT 1'
        );
        $after->execute(['id' => $id]);
        $check = $after->fetch();
        if (
            $check === false
            || (int) $check['role_id'] !== (int) $before['role_id']
            || (string) $check['youth_id'] !== (string) $before['youth_id']
            || (string) $check['password_hash'] !== (string) $before['password_hash']
        ) {
            Json::error('SERVER_ERROR', 'The account status could not be saved safely.', 500);
        }

        if ($status === 'inactive') {
            $this->rememberTokens()->forgetUser($id);
        }

        $listed = $this->listUsers(['q' => (string) $before['email'], 'perPage' => 50]);
        $saved = null;
        foreach ($listed['items'] as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                $saved = $item;
                break;
            }
        }
        if ($saved === null) {
            Json::error('SERVER_ERROR', 'The account status could not be read back.', 500);
        }

        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($admin), [
            'organizationId' => $saved['orgId'] ?? null,
            'action' => ActivityLog::ACTION_UPDATE,
            'category' => 'user',
            'description' => 'Set account status to ' . $status . ' for ' . (string) $before['email'],
            'entityType' => 'user',
            'entityId' => $id,
        ]));

        return $saved;
    }

    /**
     * Administrative password reset. The plaintext password is returned once
     * and is never written to the database or the activity log.
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function resetUserPassword(int $id, array $admin): array
    {
        if ($id < 1) {
            Json::error('NOT_FOUND', 'That account is not on this deployment.', 404);
        }
        if ($id === (int) ($admin['id'] ?? 0)) {
            Json::error('VALIDATION_ERROR', 'You cannot reset your own password from this console.', 422);
        }

        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, u.role_id, u.youth_id, r.code AS role_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $before = $stmt->fetch();
        if ($before === false) {
            Json::error('NOT_FOUND', 'That account is not on this deployment.', 404);
        }
        if (($before['role_code'] ?? '') === 'SYSTEM_ADMIN') {
            Json::error('VALIDATION_ERROR', 'System administrator passwords cannot be reset from this console.', 422);
        }

        $plain = 'YS-' . strtoupper(bin2hex(random_bytes(4)));
        $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, must_change_password = 1 WHERE id = :id'
        )->execute([
            'hash' => Password::hash($plain),
            'id' => $id,
        ]);

        $after = $this->pdo->prepare('SELECT role_id, youth_id, must_change_password FROM users WHERE id = :id LIMIT 1');
        $after->execute(['id' => $id]);
        $check = $after->fetch();
        if (
            $check === false
            || (int) $check['role_id'] !== (int) $before['role_id']
            || (string) $check['youth_id'] !== (string) $before['youth_id']
            || (int) $check['must_change_password'] !== 1
        ) {
            Json::error('SERVER_ERROR', 'The password could not be reset safely.', 500);
        }

        $this->rememberTokens()->forgetUser($id);

        $listed = $this->listUsers(['q' => (string) $before['email'], 'perPage' => 50]);
        $saved = null;
        foreach ($listed['items'] as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                $saved = $item;
                break;
            }
        }
        if ($saved === null) {
            Json::error('SERVER_ERROR', 'The account could not be read back.', 500);
        }

        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($admin), [
            'organizationId' => $saved['orgId'] ?? null,
            'action' => ActivityLog::ACTION_UPDATE,
            'category' => 'user',
            'description' => 'Reset the password for ' . (string) $before['email'],
            'entityType' => 'user',
            'entityId' => $id,
        ]));

        $saved['temporaryPassword'] = $plain;
        $saved['mustChangePassword'] = true;
        return $saved;
    }

    // ---- shared -----------------------------------------------------------

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
        // Shared status / days-remaining calculation (also used by GET /sk/subscription).
        $sub = PlanLimits::state($this->pdo, $row);

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
            'plan' => $sub['plan'],
            'planName' => $sub['planName'],
            'subStatus' => $sub['status'],
            'subStatusLabel' => $sub['statusLabel'],
            'cycle' => $sub['cycle'],
            'startedOn' => $sub['startedOn'],
            'expiresAt' => $sub['expiresAt'],
            'expiresIn' => $sub['expiresIn'],
            'daysRemaining' => $sub['daysRemaining'],
            'effectivePlan' => $sub['effectivePlan']['code'],
            'restrictedToFree' => $sub['restrictedToFree'],
            'youthCount' => (int) ($row['youth_total'] ?? $row['youth_count'] ?? 0),
            'userCount' => (int) ($row['user_total'] ?? 0),
            'createdAt' => $row['created_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: string, 1: array<string, string>}
     */
    private function userFilters(array $query): array
    {
        $where = '1 = 1';
        $params = [];

        $q = Pagination::search($query);
        if ($q !== '') {
            $where .= ' AND ' . Pagination::likeAny(
                ['u.first_name', 'u.last_name', 'u.email', "CONCAT(u.first_name, ' ', u.last_name)"],
                $q, 'q', $params
            );
        }

        $status = strtolower(trim((string) ($query['status'] ?? '')));
        if ($status === 'active' || $status === 'inactive') {
            $where .= ' AND u.status = :status';
            $params['status'] = $status;
        }

        $role = strtolower(str_replace(['-', ' '], '_', trim((string) ($query['role'] ?? ''))));
        $roleCodes = [
            'system_admin' => 'SYSTEM_ADMIN',
            'sk_official' => 'SK_OFFICIAL',
            'youth' => 'YOUTH',
        ];
        if (isset($roleCodes[$role])) {
            $where .= ' AND r.code = :role';
            $params['role'] = $roleCodes[$role];
        }

        return [$where, $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicListedUser(array $row): array
    {
        $roleMap = [
            'SYSTEM_ADMIN' => 'system_admin',
            'SK_OFFICIAL' => 'sk_official',
            'YOUTH' => 'youth',
        ];
        $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
        return [
            'id' => (int) ($row['id'] ?? 0),
            'email' => $row['email'] ?? '',
            'firstName' => $row['first_name'] ?? '',
            'lastName' => $row['last_name'] ?? '',
            'name' => $name !== '' ? $name : (string) ($row['email'] ?? ''),
            'role' => $roleMap[$row['role_code'] ?? ''] ?? 'sk_official',
            'orgId' => ($row['organization_id'] ?? null) === null ? null : (int) $row['organization_id'],
            'owner' => (bool) ($row['is_owner'] ?? 0),
            'active' => ($row['status'] ?? '') === 'active',
            'status' => $row['status'] ?? 'active',
            'lastLogin' => $row['last_login_at'] ?: 'Never',
            'createdAt' => $row['created_at'] ?? null,
        ] + (array_key_exists('organization_name', $row)
            // The list joins the organization so the console can show its name
            // without downloading every organization first.
            ? ['organizationName' => $row['organization_name']]
            : []);
    }

    private function rememberTokens(): RememberTokenStore
    {
        return new RememberTokenStore(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'remember_tokens.json'
        );
    }

    /**
     * Console listings: default 25 rows, at most 200 per page.
     *
     * @param array<string, mixed> $query
     */
    private function paging(array $query): Pagination
    {
        return Pagination::fromQuery($query, 25, 200);
    }
}
