<?php
declare(strict_types=1);

namespace YouthSync\Youth;

use PDO;
use YouthSync\Auth\Password;
use YouthSync\Http\Json;
use YouthSync\Org\PlanLimits;

final class YouthService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function countActive(int $organizationId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM youth WHERE organization_id = :org AND archived = 0'
        );
        $stmt->execute(['org' => $organizationId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function list(int $organizationId, array $query, array $orgRow): array
    {
        $q = trim((string) ($query['q'] ?? ''));
        $priority = trim((string) ($query['priority'] ?? ''));
        $employment = trim((string) ($query['employment'] ?? ''));
        $archived = self::queryBool($query['archived'] ?? false);
        $sort = (string) ($query['sort'] ?? 'newest');
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['perPage'] ?? $query['per_page'] ?? 15);
        if ($perPage < 1) {
            $perPage = 15;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $where = ['organization_id = :org', 'archived = :archived'];
        $params = ['org' => $organizationId, 'archived' => $archived ? 1 : 0];

        if ($q !== '') {
            $where[] = '(first_name LIKE :q OR last_name LIKE :q OR school LIKE :q OR address LIKE :q OR contact LIKE :q OR code LIKE :q)';
            $params['q'] = '%' . $q . '%';
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
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT * FROM youth WHERE {$sqlWhere} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->publicYouth($row);
        }

        $plan = PlanLimits::effective($orgRow);
        $usage = $this->countActive($organizationId);
        $limit = $plan['youth'];

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => $pages,
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
     * @param array<string, mixed> $input
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function create(int $organizationId, array $input, array $orgRow): array
    {
        $values = YouthValidator::normalize($input);
        $errors = YouthValidator::validate($values, true);
        if ($errors) {
            Json::validation($errors);
        }

        $plan = PlanLimits::effective($orgRow);
        $usage = $this->countActive($organizationId);
        if ($plan['youth'] !== null && $usage + 1 > $plan['youth']) {
            Json::error('PLAN_LIMIT', PlanLimits::youthLimitMessage($plan), 403);
        }

        if ($this->duplicateExists($organizationId, $values)) {
            Json::error('CONFLICT', 'A youth record with this name and date of birth already exists in your organization.', 409);
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
        $youth = $this->get($organizationId, $id);

        $account = $this->maybeCreateAccount($organizationId, $id, $values);
        $youth['accountStatus'] = $this->accountStatus($id);

        $payload = ['youth' => $youth];
        if ($account !== null) {
            $payload['temporaryPassword'] = $account['password'];
            $payload['accountCreated'] = true;
        } elseif (!empty($values['createAccount'])) {
            $payload['accountCreated'] = false;
            $payload['warning'] = 'That email already has an account. The youth record was saved without one.';
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(int $organizationId, int $id, array $input): array
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
        if ($errors) {
            Json::validation($errors);
        }
        if ($this->duplicateExists($organizationId, $values, $id)) {
            Json::error('CONFLICT', 'A youth record with this name and date of birth already exists in your organization.', 409);
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

        return $this->get($organizationId, $id);
    }

    /**
     * @param array<string, mixed> $orgRow
     */
    public function archive(int $organizationId, int $id, array $orgRow, bool $archived): array
    {
        $this->requireInOrg($organizationId, $id);
        if (!$archived) {
            $plan = PlanLimits::effective($orgRow);
            $usage = $this->countActive($organizationId);
            if ($plan['youth'] !== null && $usage + 1 > $plan['youth']) {
                Json::error('PLAN_LIMIT', PlanLimits::youthLimitMessage($plan), 403);
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
        return $this->get($organizationId, $id);
    }

    public function delete(int $organizationId, int $id): void
    {
        $this->requireInOrg($organizationId, $id);

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
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function import(int $organizationId, array $rows, array $orgRow): array
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
            if ($errors) {
                Json::error(
                    'VALIDATION_ERROR',
                    'Row ' . ($index + 1) . ': ' . reset($errors),
                    422,
                    ['fields' => $errors, 'row' => $index + 1]
                );
            }
            if ($this->duplicateExists($organizationId, $values)) {
                Json::error(
                    'CONFLICT',
                    'Row ' . ($index + 1) . ' already exists in your youth records.',
                    409,
                    ['row' => $index + 1]
                );
            }
            $prepared[] = $values;
        }

        $usage = $this->countActive($organizationId);
        $remaining = $plan['youth'] === null ? null : max(0, $plan['youth'] - $usage);
        if ($remaining !== null && count($prepared) > $remaining) {
            Json::error('PLAN_LIMIT', PlanLimits::csvOverflowMessage(count($prepared), $remaining, $plan), 403);
        }

        $created = [];
        $this->pdo->beginTransaction();
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
                $created[] = (int) $this->pdo->lastInsertId();
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $items = [];
        foreach (array_reverse($created) as $id) {
            $items[] = $this->get($organizationId, $id);
        }

        return [
            'imported' => count($items),
            'items' => $items,
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
                  AND LOWER(TRIM(last_name)) = :ln
                  AND birth_date = :bd';
        $params = [
            'org' => $organizationId,
            'fn' => mb_strtolower(trim($values['firstName'])),
            'ln' => mb_strtolower(trim($values['lastName'])),
            'bd' => $values['birthDate'],
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
     * @return array{password:string}|null
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

        $plain = 'YTS-' . (string) random_int(100000, 999999);
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

        return ['password' => $plain];
    }

    private function accountStatus(int $youthId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT status FROM users WHERE youth_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $youthId]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            return 'not_registered';
        }
        return $status === 'active' ? 'active' : 'inactive';
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
            'photo' => null,
            'document' => null,
        ];
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
