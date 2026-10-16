<?php
declare(strict_types=1);

namespace YouthSync\Youth;

use PDO;
use YouthSync\Admin\ActivityLog;
use YouthSync\Auth\Password;
use YouthSync\Http\Json;
use YouthSync\Http\Pagination;
use YouthSync\Notifications\EmailService;
use YouthSync\Org\PlanLimits;

final class YouthService
{
    private YouthFileStore $files;

    /** @var array<int, array<string, array{name: string, type: string, size: int}>> */
    private array $fileCache = [];

    /** @var array<int, true> */
    private array $filePrimed = [];

    public function __construct(private PDO $pdo, private ?EmailService $email = null)
    {
        $this->files = new YouthFileStore($pdo);
    }

    public function countActive(int $organizationId): int
    {
        return PlanLimits::count($this->pdo, 'youth', $organizationId);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function list(int $organizationId, array $query, array $orgRow): array
    {
        $q = Pagination::search($query);
        $priority = trim((string) ($query['priority'] ?? ''));
        $employment = trim((string) ($query['employment'] ?? ''));
        $archived = self::queryBool($query['archived'] ?? false);
        $sort = (string) ($query['sort'] ?? 'newest');
        $paging = Pagination::fromQuery($query, 15, 100);

        $where = ['organization_id = :org', 'archived = :archived'];
        $params = ['org' => $organizationId, 'archived' => $archived ? 1 : 0];

        if ($q !== '') {
            $where[] = Pagination::likeAny(
                ['first_name', 'last_name', "CONCAT(first_name, ' ', last_name)", 'school', 'address', 'contact', 'code'],
                $q, 'q', $params
            );
        }
        if ($priority !== '') {
            $where[] = 'priority_level = :priority';
            $params['priority'] = $priority;
        }
        if ($employment !== '') {
            $where[] = 'employment = :employment';
            $params['employment'] = $employment;
        }

        $sqlWhere = implode(' AND ', $where);
        $order = match ($sort) {
            'name' => 'last_name ASC, first_name ASC, id ASC',
            'oldest' => 'created_at ASC, id ASC',
            default => 'created_at DESC, id DESC',
        };

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM youth WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $meta = $paging->meta($total);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM youth WHERE {$sqlWhere} ORDER BY {$order} {$paging->limitSql()}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $this->primeFiles(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->publicYouth($row);
        }

        $plan = PlanLimits::effective($orgRow);
        $usage = $this->countActive($organizationId);
        $limit = $plan['youth'];

        return [
            'items' => $items,
            ...$meta,
            'usage' => [
                'youth' => $usage,
                'limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $usage),
            ],
        ];
    }

    public function get(int $organizationId, int $id): array
    {
        return $this->publicYouth($this->requireInOrg($organizationId, $id));
    }

    /**
     * @param array<string, mixed>|null $file
     * @param array<string, mixed> $actor
     * @return array{name: string, type: string, size: int, url: string}
     */
    public function storeFile(int $organizationId, int $id, string $kind, ?array $file, array $actor = []): array
    {
        $existing = $this->requireInOrg($organizationId, $id);
        $meta = $this->files->store($organizationId, $id, $kind, $file);
        unset($this->filePrimed[$id]);
        $meta['url'] = $this->fileUrl($id, $kind);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'youth.updated',
            'youth',
            ($kind === 'photo' ? 'Updated profile photo for ' : 'Updated supporting document for ')
                . (string) ($existing['code'] ?? $id),
            ['youthId' => $id, 'entityType' => 'youth', 'entityId' => $id]
        );
        return $meta;
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function removeFile(int $organizationId, int $id, string $kind, array $actor = []): void
    {
        $existing = $this->requireInOrg($organizationId, $id);
        $this->files->remove($organizationId, $id, $kind);
        unset($this->filePrimed[$id]);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'youth.updated',
            'youth',
            ($kind === 'photo' ? 'Removed profile photo for ' : 'Removed supporting document for ')
                . (string) ($existing['code'] ?? $id),
            ['youthId' => $id, 'entityType' => 'youth', 'entityId' => $id]
        );
    }

    public function sendFile(int $organizationId, int $id, string $kind): never
    {
        $this->requireInOrg($organizationId, $id);
        $this->files->send($organizationId, $id, $kind);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $orgRow
     * @param array<string, mixed> $actor authenticated SK user from SkGuard
     * @return array<string, mixed>
     */
    public function create(int $organizationId, array $input, array $orgRow, array $actor = []): array
    {
        $values = YouthValidator::normalize($input);
        $errors = YouthValidator::validate($values, true);
        $errors = array_merge($errors, \YouthSync\Identity\ContactIdentity::youthErrors(
            $this->pdo,
            (string) $values['email'],
            (string) $values['contact']
        ));
        if ($errors) {
            Json::validation($errors);
        }

        if ($this->duplicateExists($organizationId, $values)) {
            Json::error('CONFLICT', 'A youth record with this name already exists in your organization.', 409);
        }

        // Count-then-insert under a row lock on the organization (plan limit is concurrency-safe).
        $plan = PlanLimits::effective($orgRow);
        $locked = $plan['youth'] !== null && PlanLimits::lockOrganization($this->pdo, $organizationId);
        if ($plan['youth'] !== null) {
            PlanLimits::assertRoom($this->pdo, 'youth', $plan, $this->countActive($organizationId));
        }

        $priority = Priority::evaluate($values);
        $code = $this->nextCode($organizationId);

        $sql = <<<SQL
            INSERT INTO youth (
                organization_id, code, first_name, middle_name, last_name, birth_date, gender,
                address, contact, email, civil_status, education_status, education, school,
                course, year_level, strand, studying, employment, occupation, guardian_name,
                guardian_employment, guardian_occupation, family_income, family_members,
                skills, interests, preferred_activities, previous_scholarship, previous_assistance,
                previous_participation, archived, self_registered, priority_level, priority_score,
                priority_reasons, added_at
            ) VALUES (
                :organization_id, :code, :first_name, :middle_name, :last_name, :birth_date, :gender,
                :address, :contact, :email, :civil_status, :education_status, :education, :school,
                :course, :year_level, :strand, :studying, :employment, :occupation, :guardian_name,
                :guardian_employment, :guardian_occupation, :family_income, :family_members,
                :skills, :interests, :preferred_activities, :previous_scholarship, :previous_assistance,
                :previous_participation, 0, 0, :priority_level, :priority_score, :priority_reasons, NOW()
            )
            SQL;

        $this->pdo->prepare($sql)->execute($this->bind($organizationId, $code, $values, $priority));
        $id = (int) $this->pdo->lastInsertId();
        PlanLimits::releaseLock($this->pdo, $locked);
        $youth = $this->get($organizationId, $id);

        $account = $this->maybeCreateAccount($organizationId, $id, $values);
        $youth['accountStatus'] = $this->accountStatus($id);

        $payload = ['youth' => $youth];
        if ($account !== null) {
            $payload['temporaryPassword'] = $account['password'];
            $payload['temporary_password'] = $account['password'];
            $payload['user'] = [
                'id' => $account['userId'],
                'email' => $account['email'],
                'role' => 'YOUTH',
                'youth_id' => $id,
            ];
            $payload['accountCreated'] = true;
        } elseif (!empty($values['createAccount'])) {
            $payload['accountCreated'] = false;
            $payload['warning'] = 'That email already has an account. The youth record was saved without one.';
        }

        $name = trim((string) ($youth['firstName'] ?? $youth['first_name'] ?? '') . ' ' . (string) ($youth['lastName'] ?? $youth['last_name'] ?? ''));
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'youth.created',
            'youth',
            'Created youth record ' . (string) ($youth['code'] ?? $id) . ($name !== '' ? ' - ' . $name : ''),
            ['youthId' => $id, 'entityType' => 'youth', 'entityId' => $id]
        );
        if ($account !== null) {
            // The temporary password is returned to the SK once and never logged.
            ActivityLog::recordSk(
                $this->pdo,
                $organizationId,
                $actor,
                'user.created',
                'user',
                'Created a youth login account for youth record ' . (string) ($youth['code'] ?? $id),
                ['youthId' => $id, 'entityType' => 'user', 'entityId' => (int) $account['userId']]
            );
        }

        return $payload;
    }

    /**
     * Same first name, last name, and birth date inside one organization.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>|null
     */
    public function signupMatch(int $organizationId, array $values): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT y.id, y.archived, y.email, u.id AS user_id
             FROM youth y
             LEFT JOIN users u ON u.youth_id = y.id
             WHERE y.organization_id = :org
               AND LOWER(TRIM(y.first_name)) = :fn
               AND LOWER(TRIM(COALESCE(y.middle_name, \'\'))) = :mn
               AND LOWER(TRIM(y.last_name)) = :ln
             ORDER BY y.id ASC
             LIMIT 1'
        );
        $stmt->execute([
            'org' => $organizationId,
            'fn' => mb_strtolower(trim((string) ($values['firstName'] ?? ''))),
            'mn' => mb_strtolower(trim((string) ($values['middleName'] ?? ''))),
            'ln' => mb_strtolower(trim((string) ($values['lastName'] ?? ''))),
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Creates a self-registered youth record and login, or attaches the login
     * to the existing record with the same name.
     */
    public function registerSelf(int $organizationId, array $input, string $passwordHash, array $orgRow): int
    {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $input['email'] = $email;
        $match = $this->signupMatch($organizationId, $input);
        if ($match !== null) {
            return $this->attachSignupLogin($organizationId, $match, $email, $input, $passwordHash);
        }
        $input['createAccount'] = true;
        $input['accountEmail'] = $email;
        $payload = $this->create($organizationId, $input, $orgRow, []);
        $userId = (int) ($payload['user']['id'] ?? 0);
        $youthId = (int) ($payload['youth']['id'] ?? 0);
        if ($userId < 1 || empty($payload['accountCreated'])) {
            Json::error('CONFLICT', 'That Gmail address already has a YouthSync account. Check the email that was sent to that inbox, then log in. Another account cannot be created.', 409);
        }
        $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id'
        )->execute([
            'hash' => $passwordHash,
            'id' => $userId,
        ]);
        if ($youthId > 0) {
            $this->pdo->prepare(
                'UPDATE youth SET self_registered = 1 WHERE id = :id AND organization_id = :org'
            )->execute([
                'id' => $youthId,
                'org' => $organizationId,
            ]);
        }
        return $userId;
    }

    /**
     * @param array<string, mixed> $match
     * @param array<string, mixed> $input
     */
    private function attachSignupLogin(int $organizationId, array $match, string $email, array $input, string $passwordHash): int
    {
        if ((int) $match['archived'] === 1) {
            Json::error('VALIDATION_ERROR', 'This youth record is archived. Ask your SK to restore it.', 422);
        }
        if ((int) ($match['user_id'] ?? 0) > 0) {
            Json::error('CONFLICT', 'This youth record already has a YouthSync account. Check the Gmail message that was sent, then log in. Another account cannot be created.', 409);
        }
        $youthId = (int) $match['id'];
        $identity = \YouthSync\Identity\ContactIdentity::youthErrors(
            $this->pdo,
            $email,
            (string) ($input['contact'] ?? ''),
            $youthId
        );
        if ($identity) {
            Json::error('CONFLICT', (string) reset($identity), 409);
        }
        $currentEmail = strtolower(trim((string) ($match['email'] ?? '')));
        if ($currentEmail === '') {
            $this->pdo->prepare(
                'UPDATE youth SET email = :email WHERE id = :id AND organization_id = :org'
            )->execute([
                'email' => $email,
                'id' => $youthId,
                'org' => $organizationId,
            ]);
        }

        $roleId = (int) $this->pdo->query("SELECT id FROM roles WHERE code = 'YOUTH' LIMIT 1")->fetchColumn();
        if ($roleId < 1) {
            Json::error('SERVER_ERROR', 'Youth sign-up is not available right now.', 500);
        }
        $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, first_name, last_name, role_id, youth_id, status, must_change_password)
             VALUES (:email, :hash, :fn, :ln, :role_id, :youth_id, :status, 0)'
        )->execute([
            'email' => $email,
            'hash' => $passwordHash,
            'fn' => (string) ($input['firstName'] ?? ''),
            'ln' => (string) ($input['lastName'] ?? ''),
            'role_id' => $roleId,
            'youth_id' => $youthId,
            'status' => 'active',
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO organization_users (user_id, organization_id, is_owner, status)
             VALUES (:user_id, :org, 0, :status)'
        )->execute([
            'user_id' => $userId,
            'org' => $organizationId,
            'status' => 'active',
        ]);
        return $userId;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(int $organizationId, int $id, array $input, array $actor = []): array
    {
        $existingRow = $this->requireInOrg($organizationId, $id);
        $existing = $this->publicYouth($existingRow);
        unset(
            $existing['id'],
            $existing['code'],
            $existing['qrToken'],
            $existing['accountStatus'],
            $existing['photo'],
            $existing['document'],
            $existing['createdAt'],
            $existing['addedAt'],
            $existing['archived'],
            $existing['selfRegistered'],
            $existing['level'],
            $existing['score'],
            $existing['reasons']
        );
        $input = array_merge($existing, $input);
        $values = YouthValidator::normalize($input);
        $errors = YouthValidator::validate($values, true);
        $errors = array_merge($errors, \YouthSync\Identity\ContactIdentity::youthErrors(
            $this->pdo,
            (string) $values['email'],
            (string) $values['contact'],
            $id
        ));
        if ($errors) {
            Json::validation($errors);
        }
        if ($this->duplicateExists($organizationId, $values, $id)) {
            Json::error('CONFLICT', 'A youth record with this name already exists in your organization.', 409);
        }

        $priority = Priority::evaluate($values);
        $sql = <<<SQL
            UPDATE youth SET
                first_name = :first_name, middle_name = :middle_name, last_name = :last_name,
                birth_date = :birth_date, gender = :gender, address = :address, contact = :contact,
                email = :email, civil_status = :civil_status, education_status = :education_status,
                education = :education, school = :school, course = :course, year_level = :year_level,
                strand = :strand, studying = :studying, employment = :employment, occupation = :occupation,
                guardian_name = :guardian_name, guardian_employment = :guardian_employment,
                guardian_occupation = :guardian_occupation, family_income = :family_income,
                family_members = :family_members, skills = :skills, interests = :interests,
                preferred_activities = :preferred_activities, previous_scholarship = :previous_scholarship,
                previous_assistance = :previous_assistance, previous_participation = :previous_participation,
                priority_level = :priority_level, priority_score = :priority_score,
                priority_reasons = :priority_reasons
            WHERE id = :id AND organization_id = :organization_id
            SQL;

        $params = $this->bind($organizationId, '', $values, $priority);
        unset($params['code']);
        $params['id'] = $id;
        $this->pdo->prepare($sql)->execute($params);

        $this->pdo->prepare(
            'UPDATE users SET first_name = :fn, last_name = :ln WHERE youth_id = :id'
        )->execute([
            'fn' => $values['firstName'],
            'ln' => $values['lastName'],
            'id' => $id,
        ]);

        $youth = $this->get($organizationId, $id);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'youth.updated',
            'youth',
            'Updated youth record ' . (string) ($youth['code'] ?? $id),
            ['youthId' => $id, 'entityType' => 'youth', 'entityId' => $id]
        );
        return $youth;
    }

    /**
     * @param array<string, mixed> $orgRow
     */
    public function archive(int $organizationId, int $id, array $orgRow, bool $archived, array $actor = []): array
    {
        $current = $this->requireInOrg($organizationId, $id);
        $locked = false;
        // Restoring an archived record brings it back into the youth limit.
        if (!$archived && (int) ($current['archived'] ?? 0) === 1) {
            $plan = PlanLimits::effective($orgRow);
            if ($plan['youth'] !== null) {
                $locked = PlanLimits::lockOrganization($this->pdo, $organizationId);
                PlanLimits::assertRoom($this->pdo, 'youth', $plan, $this->countActive($organizationId));
            }
        }
        $stmt = $this->pdo->prepare(
            'UPDATE youth SET archived = :archived WHERE id = :id AND organization_id = :org'
        );
        $stmt->execute([
            'archived' => $archived ? 1 : 0,
            'id' => $id,
            'org' => $organizationId,
        ]);
        PlanLimits::releaseLock($this->pdo, $locked);
        $youth = $this->get($organizationId, $id);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            $archived ? 'youth.archived' : 'youth.restored',
            'youth',
            ($archived ? 'Archived' : 'Restored') . ' youth record ' . (string) ($youth['code'] ?? $id),
            ['youthId' => $id, 'entityType' => 'youth', 'entityId' => $id]
        );
        return $youth;
    }

    public function delete(int $organizationId, int $id, array $actor = []): void
    {
        $existing = $this->requireInOrg($organizationId, $id);
        $storedFiles = $this->files->storageNames($id);

        $userStmt = $this->pdo->prepare('SELECT id FROM users WHERE youth_id = :id');
        $userStmt->execute(['id' => $id]);
        $userIds = $userStmt->fetchAll(PDO::FETCH_COLUMN);

        $this->pdo->beginTransaction();
        try {
            if ($userIds) {
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $delMem = $this->pdo->prepare("DELETE FROM organization_users WHERE user_id IN ({$placeholders})");
                $delMem->execute(array_map('intval', $userIds));
                $delUsers = $this->pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})");
                $delUsers->execute(array_map('intval', $userIds));
            }
            $stmt = $this->pdo->prepare('DELETE FROM youth WHERE id = :id AND organization_id = :org');
            $stmt->execute(['id' => $id, 'org' => $organizationId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->files->unlinkNames($storedFiles);

        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'youth.deleted',
            'youth',
            'Deleted youth record ' . (string) ($existing['code'] ?? $id),
            ['youthId' => $id, 'entityType' => 'youth', 'entityId' => $id]
        );
    }

    public function importPreview(array $orgRow, array $file): array
    {
        $plan = PlanLimits::effective($orgRow);
        if (!$plan['csv_import']) {
            Json::error('PLAN_FEATURE', PlanLimits::csvNotIncludedMessage($plan), 403);
        }
        $rows = YouthImportFile::parse($file);
        return ['rows' => $rows, 'total' => count($rows)];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function import(int $organizationId, array $rows, array $orgRow, array $actor = []): array
    {
        $plan = PlanLimits::effective($orgRow);
        if (!$plan['csv_import']) {
            Json::error('PLAN_FEATURE', PlanLimits::csvNotIncludedMessage($plan), 403);
        }
        if ($rows === []) {
            Json::error('VALIDATION_ERROR', 'No youth rows were provided to import.', 422);
        }
        $prepared = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                Json::error('VALIDATION_ERROR', 'Each import row must be an object.', 422);
            }
            $values = YouthValidator::normalize($row);
            if ($values['interests'] === []) {
                $values['interests'] = ['Community service'];
            }
            if ($values['gender'] === '') {
                $values['gender'] = 'Male';
            }
            if ($values['educationStatus'] === '') {
                $values['educationStatus'] = 'Currently Studying';
                $values['studying'] = true;
            }
            if ($values['education'] === '') {
                $values['education'] = 'Senior High School';
            }
            if ($values['employment'] === '') {
                $values['employment'] = 'Student';
            }
            $errors = YouthValidator::validate($values, $values['contact'] !== '');
            if ($values['contact'] === '') {
                unset($errors['contact']);
            }
            if ($values['email'] !== '' && !$this->isGmail((string) $values['email'])) {
                $errors['email'] = 'Use a Gmail address, like name@gmail.com, or leave email blank.';
            }
            $errors = array_merge($errors, \YouthSync\Identity\ContactIdentity::youthErrors(
                $this->pdo,
                (string) $values['email'],
                (string) $values['contact']
            ));
            foreach ($prepared as $earlier) {
                if ($values['email'] !== '' && strtolower((string) $earlier['email']) === strtolower((string) $values['email'])) {
                    $errors['email'] = \YouthSync\Identity\ContactIdentity::EMAIL_MESSAGE;
                }
                if ($values['contact'] !== '' && (string) $earlier['contact'] === (string) $values['contact']) {
                    $errors['contact'] = \YouthSync\Identity\ContactIdentity::CONTACT_MESSAGE;
                }
            }
            if ($errors) {
                Json::error(
                    'VALIDATION_ERROR',
                    'Row ' . ($index + 1) . ': ' . reset($errors),
                    422,
                    ['fields' => $errors, 'row' => $index + 1]
                );
            }
            $nameKey = mb_strtolower(trim((string) $values['firstName']) . '|' . trim((string) ($values['middleName'] ?? '')) . '|' . trim((string) $values['lastName']));
            foreach ($prepared as $earlierName) {
                $earlierKey = mb_strtolower(trim((string) $earlierName['firstName']) . '|' . trim((string) ($earlierName['middleName'] ?? '')) . '|' . trim((string) $earlierName['lastName']));
                if ($earlierKey === $nameKey) {
                    Json::error(
                        'CONFLICT',
                        'Row ' . ($index + 1) . ': a youth record with this name is already in this file.',
                        409,
                        ['row' => $index + 1]
                    );
                }
            }
            if ($this->duplicateExists($organizationId, $values)) {
                Json::error(
                    'CONFLICT',
                    'Row ' . ($index + 1) . ': a youth record with this name already exists in your organization.',
                    409,
                    ['row' => $index + 1]
                );
            }
            if ($this->isGmail((string) $values['email'])) {
                $loginTaken = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $loginTaken->execute(['email' => $values['email']]);
                if ($loginTaken->fetch()) {
                    Json::error(
                        'CONFLICT',
                        'Row ' . ($index + 1) . ': that Gmail address already has a YouthSync account. Another account cannot be created for it.',
                        409,
                        ['row' => $index + 1]
                    );
                }
            }
            $prepared[] = $values;
        }

        $needsNotice = false;
        foreach ($prepared as $values) {
            if ($this->isGmail((string) $values['email'])) {
                $needsNotice = true;
                break;
            }
        }
        if ($needsNotice && ($this->email === null || !$this->email->isConfigured())) {
            Json::error('VALIDATION_ERROR', 'Email is not configured, so youth with a Gmail address cannot be notified about their accounts.', 422);
        }

        $created = [];
        $notices = [];
        $this->pdo->beginTransaction();
        PlanLimits::lockOrganization($this->pdo, $organizationId);
        $usage = $this->countActive($organizationId);
        $remaining = $plan['youth'] === null ? null : max(0, $plan['youth'] - $usage);
        PlanLimits::assertRoom(
            $this->pdo,
            'youth',
            $plan,
            $usage,
            count($prepared),
            $remaining === null ? null : PlanLimits::csvOverflowMessage(count($prepared), $remaining, $plan),
            ['requested' => count($prepared), 'remaining' => $remaining]
        );
        try {
            foreach (array_reverse($prepared) as $values) {
                $priority = Priority::evaluate($values);
                $code = $this->nextCode($organizationId);
                $sql = <<<SQL
                    INSERT INTO youth (
                        organization_id, code, first_name, middle_name, last_name, birth_date, gender,
                        address, contact, email, civil_status, education_status, education, school,
                        course, year_level, strand, studying, employment, occupation, guardian_name,
                        guardian_employment, guardian_occupation, family_income, family_members,
                        skills, interests, preferred_activities, previous_scholarship, previous_assistance,
                        previous_participation, archived, self_registered, priority_level, priority_score,
                        priority_reasons, added_at
                    ) VALUES (
                        :organization_id, :code, :first_name, :middle_name, :last_name, :birth_date, :gender,
                        :address, :contact, :email, :civil_status, :education_status, :education, :school,
                        :course, :year_level, :strand, :studying, :employment, :occupation, :guardian_name,
                        :guardian_employment, :guardian_occupation, :family_income, :family_members,
                        :skills, :interests, :preferred_activities, :previous_scholarship, :previous_assistance,
                        :previous_participation, 0, 0, :priority_level, :priority_score, :priority_reasons, NOW()
                    )
                    SQL;
                $this->pdo->prepare($sql)->execute($this->bind($organizationId, $code, $values, $priority));
                $id = (int) $this->pdo->lastInsertId();
                $created[] = $id;
                if ($this->isGmail((string) $values['email'])) {
                    $account = $this->maybeCreateAccount($organizationId, $id, [
                        'createAccount' => true,
                        'accountEmail' => $values['email'],
                        'firstName' => $values['firstName'],
                        'lastName' => $values['lastName'],
                    ]);
                    if ($account === null) {
                        throw new \RuntimeException('login');
                    }
                    $notices[] = [
                        'name' => trim($values['firstName'] . ' ' . $values['lastName']),
                        'email' => $account['email'],
                        'temporaryPassword' => $account['password'],
                    ];
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof \RuntimeException && $e->getMessage() === 'login') {
                Json::error('VALIDATION_ERROR', 'A login could not be created for one of the Gmail addresses.', 422);
            }
            throw $e;
        }

        $unsent = [];
        foreach (array_reverse($notices) as $notice) {
            $sent = $this->email->send(
                $notice['email'],
                'Your YouthSync account is ready',
                '<p>An SK council created a YouthSync account for you.</p>'
                    . '<p>Sign in with this Gmail address: <strong>' . htmlspecialchars($notice['email'], ENT_QUOTES, 'UTF-8') . '</strong></p>'
                    . '<p>Temporary password: <strong>' . htmlspecialchars($notice['temporaryPassword'], ENT_QUOTES, 'UTF-8') . '</strong></p>'
                    . '<p>Change this password the first time you sign in.</p>',
                'An SK council created a YouthSync account for you. Sign in with '
                    . $notice['email']
                    . '. Temporary password: '
                    . $notice['temporaryPassword']
                    . '. Change this password the first time you sign in.'
            );
            if (empty($sent['ok'])) {
                $unsent[] = $notice;
            }
        }

        $items = [];
        foreach (array_reverse($created) as $id) {
            $items[] = $this->get($organizationId, $id);
        }

        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'youth.imported',
            'youth',
            'Imported ' . count($items) . ' youth record(s)',
            ['entityType' => 'youth']
        );

        return [
            'imported' => count($items),
            'items' => $items,
            'notified' => count($notices) - count($unsent),
            'unsent' => $unsent,
        ];
    }

    private function requireInOrg(int $organizationId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM youth WHERE id = :id AND organization_id = :org LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'org' => $organizationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This youth record does not exist in this organization.', 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function duplicateExists(int $organizationId, array $values, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM youth
                WHERE organization_id = :org
                  AND LOWER(TRIM(first_name)) = :fn
                  AND LOWER(TRIM(COALESCE(middle_name, \'\'))) = :mn
                  AND LOWER(TRIM(last_name)) = :ln';
        $params = [
            'org' => $organizationId,
            'fn' => mb_strtolower(trim((string) $values['firstName'])),
            'mn' => mb_strtolower(trim((string) ($values['middleName'] ?? ''))),
            'ln' => mb_strtolower(trim((string) $values['lastName'])),
        ];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    }

    private function nextCode(int $organizationId): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)), 0) + 1
             FROM youth WHERE organization_id = :org"
        );
        $stmt->execute(['org' => $organizationId]);
        $n = (int) $stmt->fetchColumn();
        return 'YTH-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed> $values
     * @param array{level:string,score:int,reasons:list<string>} $priority
     * @return array<string, mixed>
     */
    private function bind(int $organizationId, string $code, array $values, array $priority): array
    {
        $params = [
            'organization_id' => $organizationId,
            'first_name' => $values['firstName'],
            'middle_name' => $values['middleName'],
            'last_name' => $values['lastName'],
            'birth_date' => $values['birthDate'],
            'gender' => $values['gender'] !== '' ? $values['gender'] : 'Prefer not to say',
            'address' => $values['address'],
            'contact' => $values['contact'],
            'email' => $values['email'] !== '' ? $values['email'] : null,
            'civil_status' => $values['civilStatus'],
            'education_status' => $values['educationStatus'] !== '' ? $values['educationStatus'] : 'Not Currently Studying',
            'education' => $values['education'] !== '' ? $values['education'] : 'Senior High School',
            'school' => $values['school'],
            'course' => $values['course'],
            'year_level' => $values['yearLevel'],
            'strand' => $values['strand'],
            'studying' => $values['studying'] ? 1 : 0,
            'employment' => $values['employment'] !== '' ? $values['employment'] : 'Unemployed',
            'occupation' => $values['occupation'],
            'guardian_name' => $values['guardianName'],
            'guardian_employment' => $values['guardianEmployment'] !== '' ? $values['guardianEmployment'] : null,
            'guardian_occupation' => $values['guardianOccupation'],
            'family_income' => $values['familyIncome'],
            'family_members' => $values['familyMembers'],
            'skills' => json_encode($values['skills'], JSON_UNESCAPED_UNICODE),
            'interests' => json_encode($values['interests'], JSON_UNESCAPED_UNICODE),
            'preferred_activities' => json_encode($values['preferredActivities'], JSON_UNESCAPED_UNICODE),
            'previous_scholarship' => $values['previousScholarship'] ? 1 : 0,
            'previous_assistance' => $values['previousAssistance'] ? 1 : 0,
            'previous_participation' => $values['previousParticipation'] ? 1 : 0,
            'priority_level' => $priority['level'],
            'priority_score' => $priority['score'],
            'priority_reasons' => json_encode($priority['reasons'], JSON_UNESCAPED_UNICODE),
        ];
        if ($code !== '') {
            $params['code'] = $code;
        }
        return $params;
    }

    /**
     * @param array<string, mixed> $values
     * @return array{password:string,userId:int,email:string}|null
     */
    private function maybeCreateAccount(int $organizationId, int $youthId, array $values): ?array
    {
        if (empty($values['createAccount']) || $values['accountEmail'] === '') {
            return null;
        }

        $exists = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $exists->execute(['email' => $values['accountEmail']]);
        if ($exists->fetch()) {
            return null;
        }

        $roleId = (int) $this->pdo->query("SELECT id FROM roles WHERE code = 'YOUTH' LIMIT 1")->fetchColumn();
        if ($roleId < 1) {
            return null;
        }

        $plain = $this->temporaryPassword();
        $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, first_name, last_name, role_id, youth_id, status, must_change_password)
             VALUES (:email, :hash, :fn, :ln, :role_id, :youth_id, :status, 1)'
        )->execute([
            'email' => $values['accountEmail'],
            'hash' => Password::hash($plain),
            'fn' => $values['firstName'],
            'ln' => $values['lastName'],
            'role_id' => $roleId,
            'youth_id' => $youthId,
            'status' => 'active',
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO organization_users (user_id, organization_id, is_owner, status)
             VALUES (:user_id, :org, 0, :status)'
        )->execute([
            'user_id' => $userId,
            'org' => $organizationId,
            'status' => 'active',
        ]);

        return [
            'password' => $plain,
            'userId' => $userId,
            'email' => (string) $values['accountEmail'],
        ];
    }

    /**
     * Shown once in the create response. Only Password::hash() is stored.
     */
    private function isGmail(string $email): bool
    {
        return (bool) preg_match('/^[^@\s]+@gmail\.com$/', strtolower(trim($email)));
    }

    private function temporaryPassword(): string
    {
        return 'Yts-' . bin2hex(random_bytes(4));
    }

    private function accountStatus(int $youthId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, must_change_password FROM users WHERE youth_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $youthId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return 'not_registered';
        }
        if ((string) $row['status'] !== 'active') {
            return 'inactive';
        }
        if ((int) $row['must_change_password'] === 1) {
            return 'password_pending';
        }
        return 'active';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicYouth(array $row): array
    {
        $decode = static function (mixed $value): array {
            if (is_array($value)) {
                return $value;
            }
            if (!is_string($value) || $value === '') {
                return [];
            }
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        };

        return [
            'id' => (int) $row['id'],
            'code' => $row['code'],
            'qrToken' => 'YSYOUTH-' . $row['code'],
            'firstName' => $row['first_name'],
            'middleName' => $row['middle_name'],
            'lastName' => $row['last_name'],
            'birthDate' => $row['birth_date'],
            'gender' => $row['gender'],
            'address' => $row['address'],
            'contact' => $row['contact'],
            'email' => $row['email'],
            'civilStatus' => $row['civil_status'],
            'educationStatus' => $row['education_status'],
            'education' => $row['education'],
            'school' => $row['school'],
            'course' => $row['course'],
            'yearLevel' => $row['year_level'],
            'strand' => $row['strand'],
            'studying' => (bool) $row['studying'],
            'employment' => $row['employment'],
            'occupation' => $row['occupation'],
            'guardianName' => $row['guardian_name'],
            'guardianEmployment' => $row['guardian_employment'],
            'guardianOccupation' => $row['guardian_occupation'],
            'familyIncome' => (float) $row['family_income'],
            'familyMembers' => (int) $row['family_members'],
            'skills' => $decode($row['skills']),
            'interests' => $decode($row['interests']),
            'preferredActivities' => $decode($row['preferred_activities']),
            'previousScholarship' => (bool) $row['previous_scholarship'],
            'previousAssistance' => (bool) $row['previous_assistance'],
            'previousParticipation' => (bool) $row['previous_participation'],
            'archived' => (bool) $row['archived'],
            'selfRegistered' => (bool) $row['self_registered'],
            'level' => $row['priority_level'],
            'score' => (int) $row['priority_score'],
            'reasons' => $decode($row['priority_reasons']),
            'accountStatus' => $this->accountStatus((int) $row['id']),
            'createdAt' => substr((string) $row['created_at'], 0, 10),
            'addedAt' => $row['added_at'],
            'photo' => $this->fileView((int) $row['id'], 'photo'),
            'document' => $this->fileView((int) $row['id'], 'document'),
        ];
    }

    /**
     * @param list<int> $ids
     */
    private function primeFiles(array $ids): void
    {
        $grouped = $this->files->grouped($ids);
        foreach ($grouped as $youthId => $kinds) {
            $this->fileCache[(int) $youthId] = $kinds;
            $this->filePrimed[(int) $youthId] = true;
        }
    }

    /**
     * @return array{name: string, type: string, size: int, url: string}|null
     */
    private function fileView(int $youthId, string $kind): ?array
    {
        if (!isset($this->filePrimed[$youthId])) {
            $this->primeFiles([$youthId]);
        }
        $meta = $this->fileCache[$youthId][$kind] ?? null;
        if ($meta === null) {
            return null;
        }
        $meta['url'] = $this->fileUrl($youthId, $kind);
        return $meta;
    }

    private function fileUrl(int $youthId, string $kind): string
    {
        return '/sk/youth/' . $youthId . '/' . $kind;
    }

    private static function queryBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
