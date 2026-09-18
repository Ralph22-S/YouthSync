<?php
declare(strict_types=1);

namespace YouthSync\Assistance;

use PDO;
use YouthSync\Http\Json;
use YouthSync\Org\PlanLimits;

final class AssistanceService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function countActive(int $organizationId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM assistance_programs
             WHERE organization_id = :org AND status IN ('draft', 'open', 'full')"
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
        $category = trim((string) ($query['category'] ?? ''));
        $status = trim((string) ($query['status'] ?? ''));
        $q = trim((string) ($query['q'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['perPage'] ?? $query['per_page'] ?? 50);
        if ($perPage < 1) {
            $perPage = 50;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $where = ['organization_id = :org'];
        $params = ['org' => $organizationId];
        if ($category !== '' && $category !== 'all') {
            $where[] = 'category = :category';
            $params['category'] = $category;
        }
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($q !== '') {
            $where[] = '(name LIKE :q OR description LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $sqlWhere = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM assistance_programs WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil(($total ?: 1) / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT * FROM assistance_programs WHERE {$sqlWhere}
             ORDER BY created_at DESC, id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->publicProgram($row, true);
        }

        $plan = PlanLimits::effective($orgRow);
        $usage = $this->countActive($organizationId);
        $limit = $plan['assistance'] ?? null;

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'usage' => [
                'assistance' => $usage,
                'limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $usage),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $organizationId, int $id): array
    {
        return $this->publicProgram($this->requireProgram($organizationId, $id), true);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function create(int $organizationId, array $input, array $orgRow): array
    {
        $values = AssistanceValidator::normalizeProgram($input);
        $errors = AssistanceValidator::validateProgram($values);
        if ($errors) {
            Json::validation($errors);
        }
        $this->assertTypeAllowed($organizationId, $values['typeId']);
        if ($this->duplicateName($organizationId, $values['name'])) {
            Json::error('CONFLICT', 'An assistance program with this name already exists in your organization.', 409);
        }

        $countsTowardLimit = in_array($values['status'], AssistanceValidator::ACTIVE_STATUSES, true);
        $plan = PlanLimits::effective($orgRow);
        if ($countsTowardLimit && $plan['assistance'] !== null && $this->countActive($organizationId) + 1 > $plan['assistance']) {
            Json::error('PLAN_LIMIT', PlanLimits::assistanceLimitMessage($plan), 403);
        }

        $sql = <<<SQL
            INSERT INTO assistance_programs (
                organization_id, type_id, name, category, status, slots, amount, description,
                requirements_text, deadline, requires_studying, tag_interests, tag_skills,
                tag_activities, min_age, max_age
            ) VALUES (
                :organization_id, :type_id, :name, :category, :status, :slots, :amount, :description,
                :requirements_text, :deadline, :requires_studying, :tag_interests, :tag_skills,
                :tag_activities, :min_age, :max_age
            )
            SQL;
        $this->pdo->prepare($sql)->execute($this->bindProgram($organizationId, $values));
        $id = (int) $this->pdo->lastInsertId();
        $this->applyTypePreset($organizationId, $id, $values['typeId']);
        return $this->get($organizationId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function update(int $organizationId, int $id, array $input, array $orgRow): array
    {
        $existingRow = $this->requireProgram($organizationId, $id);
        $existing = $this->publicProgram($existingRow, false);
        unset($existing['id'], $existing['createdAt'], $existing['beneficiaryCount'], $existing['slotsFilled']);
        $values = AssistanceValidator::normalizeProgram(array_merge($existing, $input));
        $errors = AssistanceValidator::validateProgram($values);
        if ($errors) {
            Json::validation($errors);
        }
        $this->assertTypeAllowed($organizationId, $values['typeId']);
        if ($this->duplicateName($organizationId, $values['name'], $id)) {
            Json::error('CONFLICT', 'An assistance program with this name already exists in your organization.', 409);
        }

        $wasActive = in_array($existingRow['status'], AssistanceValidator::ACTIVE_STATUSES, true);
        $willBeActive = in_array($values['status'], AssistanceValidator::ACTIVE_STATUSES, true);
        if (!$wasActive && $willBeActive) {
            $plan = PlanLimits::effective($orgRow);
            if ($plan['assistance'] !== null && $this->countActive($organizationId) + 1 > $plan['assistance']) {
                Json::error('PLAN_LIMIT', PlanLimits::assistanceLimitMessage($plan), 403);
            }
        }

        $sql = <<<SQL
            UPDATE assistance_programs SET
                type_id = :type_id, name = :name, category = :category, status = :status,
                slots = :slots, amount = :amount, description = :description,
                requirements_text = :requirements_text, deadline = :deadline,
                requires_studying = :requires_studying, tag_interests = :tag_interests,
                tag_skills = :tag_skills, tag_activities = :tag_activities,
                min_age = :min_age, max_age = :max_age
            WHERE id = :id AND organization_id = :organization_id
            SQL;
        $params = $this->bindProgram($organizationId, $values);
        $params['id'] = $id;
        $this->pdo->prepare($sql)->execute($params);

        $oldType = $existingRow['type_id'] !== null ? (int) $existingRow['type_id'] : null;
        if ($oldType !== $values['typeId']) {
            $this->applyTypePreset($organizationId, $id, $values['typeId']);
        }

        return $this->get($organizationId, $id);
    }

    /**
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function archive(int $organizationId, int $id, array $orgRow): array
    {
        return $this->update($organizationId, $id, ['status' => 'archived'], $orgRow);
    }

    public function delete(int $organizationId, int $id): void
    {
        $this->requireProgram($organizationId, $id);
        $this->pdo->prepare(
            'DELETE FROM beneficiaries WHERE assistance_id = :id AND organization_id = :org'
        )->execute(['id' => $id, 'org' => $organizationId]);
        $this->pdo->prepare(
            'DELETE FROM assistance_requirements WHERE assistance_id = :id AND organization_id = :org'
        )->execute(['id' => $id, 'org' => $organizationId]);
        $this->pdo->prepare(
            'DELETE FROM assistance_programs WHERE id = :id AND organization_id = :org'
        )->execute(['id' => $id, 'org' => $organizationId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTypes(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM assistance_types
             WHERE is_system = 1 OR organization_id = :org
             ORDER BY is_system DESC, id ASC'
        );
        $stmt->execute(['org' => $organizationId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->publicType($row);
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createType(int $organizationId, array $input): array
    {
        $values = AssistanceValidator::normalizeType($input);
        $errors = AssistanceValidator::validateType($values);
        if ($errors) {
            Json::validation($errors);
        }
        $dup = $this->pdo->prepare(
            'SELECT id FROM assistance_types
             WHERE name = :name AND (is_system = 1 OR organization_id = :org)
             LIMIT 1'
        );
        $dup->execute(['name' => $values['name'], 'org' => $organizationId]);
        if ($dup->fetch() !== false) {
            Json::error('CONFLICT', 'An assistance type with this name already exists.', 409);
        }

        $tuples = [];
        foreach ($values['requirements'] as $req) {
            $tuples[] = [$req['name'], $req['description'], $req['required'], $req['accepts']];
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO assistance_types (organization_id, name, category, is_system, requirements_json)
             VALUES (:organization_id, :name, :category, 0, :requirements_json)'
        );
        $stmt->execute([
            'organization_id' => $organizationId,
            'name' => $values['name'],
            'category' => $values['category'],
            'requirements_json' => json_encode($tuples, JSON_UNESCAPED_UNICODE),
        ]);
        return $this->publicType($this->typeRow((int) $this->pdo->lastInsertId()));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addRequirement(int $organizationId, array $input): array
    {
        unset($input['organization_id'], $input['orgId'], $input['organizationId']);
        $targetType = strtolower(trim((string) ($input['targetType'] ?? $input['target_type'] ?? 'assistance')));
        if ($targetType !== 'assistance') {
            Json::error('VALIDATION_ERROR', 'Requirements in this phase can only be attached to assistance programs.', 422);
        }
        $targetId = (int) ($input['targetId'] ?? $input['target_id'] ?? $input['assistanceId'] ?? 0);
        $this->requireProgram($organizationId, $targetId);
        $values = AssistanceValidator::normalizeRequirement($input);
        $errors = AssistanceValidator::validateRequirement($values);
        if ($errors) {
            Json::validation($errors);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO assistance_requirements (
                organization_id, assistance_id, name, description, required, accepts, from_type
            ) VALUES (
                :organization_id, :assistance_id, :name, :description, :required, :accepts, 0
            )'
        );
        $stmt->execute([
            'organization_id' => $organizationId,
            'assistance_id' => $targetId,
            'name' => $values['name'],
            'description' => $values['description'],
            'required' => $values['required'] ? 1 : 0,
            'accepts' => $values['accepts'],
        ]);
        return $this->publicRequirement($this->requirementRow($organizationId, (int) $this->pdo->lastInsertId()));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateRequirement(int $organizationId, int $id, array $input): array
    {
        $row = $this->requirementRow($organizationId, $id);
        $merged = array_merge($this->publicRequirement($row), $input);
        $values = AssistanceValidator::normalizeRequirement($merged);
        $errors = AssistanceValidator::validateRequirement($values);
        if ($errors) {
            Json::validation($errors);
        }
        $this->pdo->prepare(
            'UPDATE assistance_requirements
             SET name = :name, description = :description, required = :required, accepts = :accepts
             WHERE id = :id AND organization_id = :org'
        )->execute([
            'name' => $values['name'],
            'description' => $values['description'],
            'required' => $values['required'] ? 1 : 0,
            'accepts' => $values['accepts'],
            'id' => $id,
            'org' => $organizationId,
        ]);
        return $this->publicRequirement($this->requirementRow($organizationId, $id));
    }

    public function deleteRequirement(int $organizationId, int $id): void
    {
        $this->requirementRow($organizationId, $id);
        $this->pdo->prepare(
            'DELETE FROM assistance_requirements WHERE id = :id AND organization_id = :org'
        )->execute(['id' => $id, 'org' => $organizationId]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveBeneficiary(int $organizationId, int $assistanceId, array $input): array
    {
        $program = $this->requireProgram($organizationId, $assistanceId);
        $values = AssistanceValidator::normalizeBeneficiary($input);
        $errors = AssistanceValidator::validateBeneficiary($values);
        if ($errors) {
            Json::validation($errors);
        }

        $youth = $this->youthInOrg($organizationId, $values['youthId']);
        if ($youth === null) {
            Json::error('NOT_FOUND', 'This youth record does not exist in this organization.', 404);
        }
        if ((int) $youth['archived'] === 1) {
            Json::error('VALIDATION_ERROR', 'This youth record is archived.', 422);
        }

        $existing = $this->findBeneficiary($organizationId, $assistanceId, $values['youthId']);
        $alreadySlot = $existing && in_array($existing['status'], ['approved', 'released'], true);
        $wantsSlot = in_array($values['status'], ['approved', 'released'], true);
        if ($wantsSlot && !$alreadySlot) {
            $slots = $program['slots'] !== null ? (int) $program['slots'] : null;
            if ($slots !== null && $this->slotsFilled($organizationId, $assistanceId) >= $slots) {
                Json::error('VALIDATION_ERROR', 'All available slots for this program are already filled.', 422);
            }
        }

        $awarded = null;
        if ($wantsSlot) {
            $awarded = $existing['awarded_on'] ?? date('Y-m-d');
            if ($awarded === null || $awarded === '') {
                $awarded = date('Y-m-d');
            }
        }

        if ($existing === null) {
            $this->pdo->prepare(
                'INSERT INTO beneficiaries (
                    organization_id, assistance_id, youth_id, status, awarded_on, remarks
                ) VALUES (
                    :organization_id, :assistance_id, :youth_id, :status, :awarded_on, :remarks
                )'
            )->execute([
                'organization_id' => $organizationId,
                'assistance_id' => $assistanceId,
                'youth_id' => $values['youthId'],
                'status' => $values['status'],
                'awarded_on' => $awarded,
                'remarks' => $values['remarks'],
            ]);
            $id = (int) $this->pdo->lastInsertId();
            return $this->publicBeneficiary($this->beneficiaryRow($organizationId, $id));
        }

        $this->pdo->prepare(
            'UPDATE beneficiaries
             SET status = :status, awarded_on = :awarded_on, remarks = :remarks
             WHERE id = :id AND organization_id = :org'
        )->execute([
            'status' => $values['status'],
            'awarded_on' => $awarded,
            'remarks' => $values['remarks'],
            'id' => (int) $existing['id'],
            'org' => $organizationId,
        ]);
        return $this->publicBeneficiary($this->beneficiaryRow($organizationId, (int) $existing['id']));
    }

    public function deleteBeneficiary(int $organizationId, int $id): void
    {
        $this->beneficiaryRow($organizationId, $id);
        $this->pdo->prepare(
            'DELETE FROM beneficiaries WHERE id = :id AND organization_id = :org'
        )->execute(['id' => $id, 'org' => $organizationId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireProgram(int $organizationId, int $id): array
    {
        if ($id < 1) {
            Json::error('NOT_FOUND', 'This assistance program does not exist in this organization.', 404);
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM assistance_programs WHERE id = :id AND organization_id = :org LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'org' => $organizationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This assistance program does not exist in this organization.', 404);
        }
        return $row;
    }

    private function duplicateName(int $organizationId, string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM assistance_programs
                WHERE organization_id = :org AND name = :name';
        $params = ['org' => $organizationId, 'name' => $name];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    }

    private function assertTypeAllowed(int $organizationId, ?int $typeId): void
    {
        if ($typeId === null) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM assistance_types
             WHERE id = :id AND (is_system = 1 OR organization_id = :org)
             LIMIT 1'
        );
        $stmt->execute(['id' => $typeId, 'org' => $organizationId]);
        if ($stmt->fetch() === false) {
            Json::error('NOT_FOUND', 'This assistance type does not exist in this organization.', 404);
        }
    }

    private function applyTypePreset(int $organizationId, int $assistanceId, ?int $typeId): void
    {
        $this->pdo->prepare(
            'DELETE FROM assistance_requirements
             WHERE organization_id = :org AND assistance_id = :id AND from_type = 1'
        )->execute(['org' => $organizationId, 'id' => $assistanceId]);
        if ($typeId === null) {
            return;
        }
        $type = $this->typeRow($typeId);
        if ($type === null) {
            return;
        }
        foreach ($this->decodeTypeRequirements($type['requirements_json'] ?? null) as $req) {
            $item = AssistanceValidator::normalizeRequirementRow($req);
            if ($item['name'] === '') {
                continue;
            }
            $this->pdo->prepare(
                'INSERT INTO assistance_requirements (
                    organization_id, assistance_id, name, description, required, accepts, from_type
                ) VALUES (
                    :organization_id, :assistance_id, :name, :description, :required, :accepts, 1
                )'
            )->execute([
                'organization_id' => $organizationId,
                'assistance_id' => $assistanceId,
                'name' => $item['name'],
                'description' => $item['description'],
                'required' => $item['required'] ? 1 : 0,
                'accepts' => $item['accepts'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function bindProgram(int $organizationId, array $values): array
    {
        return [
            'organization_id' => $organizationId,
            'type_id' => $values['typeId'],
            'name' => $values['name'],
            'category' => $values['category'],
            'status' => $values['status'],
            'slots' => $values['slots'],
            'amount' => $values['amount'],
            'description' => $values['description'] !== '' ? $values['description'] : null,
            'requirements_text' => $values['requirements'] !== '' ? $values['requirements'] : null,
            'deadline' => $values['deadline'] !== '' ? $values['deadline'] : null,
            'requires_studying' => $values['requiresStudying'] ? 1 : 0,
            'tag_interests' => json_encode($values['tagInterests'], JSON_UNESCAPED_UNICODE),
            'tag_skills' => json_encode($values['tagSkills'], JSON_UNESCAPED_UNICODE),
            'tag_activities' => json_encode($values['tagActivities'], JSON_UNESCAPED_UNICODE),
            'min_age' => $values['minAge'],
            'max_age' => $values['maxAge'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicProgram(array $row, bool $detail): array
    {
        $id = (int) $row['id'];
        $org = (int) $row['organization_id'];
        $payload = [
            'id' => $id,
            'name' => $row['name'],
            'category' => $row['category'],
            'typeId' => $row['type_id'] !== null ? (int) $row['type_id'] : null,
            'status' => $row['status'],
            'slots' => $row['slots'] !== null ? (int) $row['slots'] : null,
            'amount' => $row['amount'] !== null ? (float) $row['amount'] : null,
            'description' => $row['description'] ?? '',
            'requirements' => $row['requirements_text'] ?? '',
            'deadline' => $row['deadline'],
            'requiresStudying' => (bool) $row['requires_studying'],
            'tagInterests' => $this->decodeList($row['tag_interests'] ?? null),
            'tagSkills' => $this->decodeList($row['tag_skills'] ?? null),
            'tagActivities' => $this->decodeList($row['tag_activities'] ?? null),
            'minAge' => $row['min_age'] !== null ? (int) $row['min_age'] : null,
            'maxAge' => $row['max_age'] !== null ? (int) $row['max_age'] : null,
            'createdAt' => $row['created_at'],
            'beneficiaryCount' => $this->beneficiaryCount($org, $id),
            'slotsFilled' => $this->slotsFilled($org, $id),
        ];
        if ($detail) {
            $payload['requirementItems'] = $this->requirementsFor($org, $id);
            $payload['beneficiaries'] = $this->beneficiariesFor($org, $id);
        }
        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requirementsFor(int $organizationId, int $assistanceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM assistance_requirements
             WHERE organization_id = :org AND assistance_id = :id
             ORDER BY id ASC'
        );
        $stmt->execute(['org' => $organizationId, 'id' => $assistanceId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->publicRequirement($row);
        }
        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function beneficiariesFor(int $organizationId, int $assistanceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, y.code, y.first_name, y.last_name, y.priority_level, y.archived
             FROM beneficiaries b
             INNER JOIN youth y ON y.id = b.youth_id
             WHERE b.organization_id = :org AND b.assistance_id = :id
             ORDER BY b.id DESC'
        );
        $stmt->execute(['org' => $organizationId, 'id' => $assistanceId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->publicBeneficiary($row);
        }
        return $items;
    }

    private function beneficiaryCount(int $organizationId, int $assistanceId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM beneficiaries WHERE organization_id = :org AND assistance_id = :id'
        );
        $stmt->execute(['org' => $organizationId, 'id' => $assistanceId]);
        return (int) $stmt->fetchColumn();
    }

    private function slotsFilled(int $organizationId, int $assistanceId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM beneficiaries
             WHERE organization_id = :org AND assistance_id = :id AND status IN ('approved', 'released')"
        );
        $stmt->execute(['org' => $organizationId, 'id' => $assistanceId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicType(array $row): array
    {
        $reqs = [];
        foreach ($this->decodeTypeRequirements($row['requirements_json'] ?? null) as $item) {
            $norm = AssistanceValidator::normalizeRequirementRow($item);
            $reqs[] = [$norm['name'], $norm['description'], $norm['required'], $norm['accepts']];
        }
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'category' => $row['category'],
            'system' => (bool) $row['is_system'],
            'orgId' => $row['organization_id'] !== null ? (int) $row['organization_id'] : null,
            'requirements' => $reqs,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRequirement(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'targetType' => 'assistance',
            'targetId' => (int) $row['assistance_id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'required' => (bool) $row['required'],
            'accepts' => $row['accepts'],
            'fromType' => (bool) $row['from_type'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicBeneficiary(array $row): array
    {
        $youth = null;
        if (isset($row['first_name']) || isset($row['code'])) {
            $youth = [
                'id' => (int) $row['youth_id'],
                'code' => $row['code'] ?? null,
                'firstName' => $row['first_name'] ?? '',
                'lastName' => $row['last_name'] ?? '',
                'level' => $row['priority_level'] ?? null,
            ];
        } else {
            $loaded = $this->youthInOrg((int) $row['organization_id'], (int) $row['youth_id']);
            if ($loaded) {
                $youth = [
                    'id' => (int) $loaded['id'],
                    'code' => $loaded['code'],
                    'firstName' => $loaded['first_name'],
                    'lastName' => $loaded['last_name'],
                    'level' => $loaded['priority_level'],
                ];
            }
        }
        return [
            'id' => (int) $row['id'],
            'programId' => (int) $row['assistance_id'],
            'youthId' => (int) $row['youth_id'],
            'status' => $row['status'],
            'awardedOn' => $row['awarded_on'],
            'remarks' => $row['remarks'],
            'youth' => $youth,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function typeRow(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM assistance_types WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirementRow(int $organizationId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM assistance_requirements WHERE id = :id AND organization_id = :org LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'org' => $organizationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This requirement does not exist in this organization.', 404);
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function beneficiaryRow(int $organizationId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, y.code, y.first_name, y.last_name, y.priority_level, y.archived
             FROM beneficiaries b
             INNER JOIN youth y ON y.id = b.youth_id
             WHERE b.id = :id AND b.organization_id = :org
             LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'org' => $organizationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This beneficiary record does not exist in this organization.', 404);
        }
        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBeneficiary(int $organizationId, int $assistanceId, int $youthId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM beneficiaries
             WHERE organization_id = :org AND assistance_id = :aid AND youth_id = :youth
             LIMIT 1'
        );
        $stmt->execute(['org' => $organizationId, 'aid' => $assistanceId, 'youth' => $youthId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function youthInOrg(int $organizationId, int $youthId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM youth WHERE id = :id AND organization_id = :org LIMIT 1'
        );
        $stmt->execute(['id' => $youthId, 'org' => $organizationId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<mixed> */
    private function decodeTypeRequirements(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private function decodeList(mixed $json): array
    {
        if (is_array($json)) {
            return array_values(array_map('strval', $json));
        }
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
