<?php
declare(strict_types=1);

namespace YouthSync\Assistance;

use PDO;
use YouthSync\Admin\ActivityLog;
use YouthSync\Http\Json;
use YouthSync\Http\Pagination;
use YouthSync\Notifications\EmailService;
use YouthSync\Notifications\NotificationService;
use YouthSync\Org\PlanLimits;

final class AssistanceService
{
    public function __construct(
        private PDO $pdo,
        private ?NotificationService $notifications = null,
        private ?EmailService $email = null,
    ) {
    }

    public function countActive(int $organizationId): int
    {
        return PlanLimits::count($this->pdo, 'assistance', $organizationId);
    }

    /**
     * When a write adds one active assistance program, lock the organization
     * row and check the plan limit (403 PLAN_LIMIT_REACHED).
     *
     * @param array<string, mixed> $orgRow
     */
    private function lockForLimit(int $organizationId, array $orgRow, bool $addsActive): bool
    {
        $plan = PlanLimits::effective($orgRow);
        if (!$addsActive || $plan['assistance'] === null) {
            return false;
        }
        $locked = PlanLimits::lockOrganization($this->pdo, $organizationId);
        PlanLimits::assertRoom($this->pdo, 'assistance', $plan, $this->countActive($organizationId));
        return $locked;
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
        $q = Pagination::search($query);
        $paging = Pagination::fromQuery($query, 50, 100);

        $where = ['organization_id = :org'];
        $params = ['org' => $organizationId];
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        // Category tab counts: same organization/status scope, before the
        // category tab and the search box are applied (as the tabs always showed).
        $countsStmt = $this->pdo->prepare(
            'SELECT category, COUNT(*) AS n FROM assistance_programs WHERE ' . implode(' AND ', $where) . ' GROUP BY category'
        );
        $countsStmt->execute($params);
        $counts = ['all' => 0];
        foreach ($countsStmt->fetchAll() as $countRow) {
            $counts[(string) $countRow['category']] = (int) $countRow['n'];
            $counts['all'] += (int) $countRow['n'];
        }

        if ($category !== '' && $category !== 'all') {
            $where[] = 'category = :category';
            $params['category'] = $category;
        }
        if ($q !== '') {
            $where[] = Pagination::likeAny(['name', 'description'], $q, 'q', $params);
        }
        $sqlWhere = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM assistance_programs WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $meta = $paging->meta($total);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM assistance_programs WHERE {$sqlWhere}
             ORDER BY created_at DESC, id DESC
             {$paging->limitSql()}"
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
            ...$meta,
            'counts' => $counts,
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
     * Open assistance a youth may apply for. Beneficiary lists are omitted.
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function listVisible(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM assistance_programs
             WHERE organization_id = :org AND status IN ('open', 'full')
             ORDER BY deadline IS NULL, deadline ASC, id DESC"
        );
        $stmt->execute(['org' => $organizationId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $item = $this->publicProgram($row, true);
            unset($item['beneficiaries']);
            $items[] = $item;
        }
        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function getVisible(int $organizationId, int $id): array
    {
        $row = $this->requireProgram($organizationId, $id);
        if (!in_array((string) ($row['status'] ?? ''), ['open', 'full'], true)) {
            Json::error('NOT_FOUND', 'That assistance program is not open.', 404);
        }
        $item = $this->publicProgram($row, true);
        unset($item['beneficiaries']);
        return $item;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function create(int $organizationId, array $input, array $orgRow, array $actor = []): array
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
        $locked = $this->lockForLimit($organizationId, $orgRow, $countsTowardLimit);

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
        PlanLimits::releaseLock($this->pdo, $locked);
        $program = $this->get($organizationId, $id);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'assistance.created',
            'assistance',
            'Created assistance program - ' . (string) ($program['name'] ?? $id),
            ['entityType' => 'assistance', 'entityId' => $id]
        );
        return $program;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function update(int $organizationId, int $id, array $input, array $orgRow, array $actor = []): array
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
        $locked = $this->lockForLimit($organizationId, $orgRow, !$wasActive && $willBeActive);

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
        PlanLimits::releaseLock($this->pdo, $locked);

        $program = $this->get($organizationId, $id);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'assistance.updated',
            'assistance',
            'Updated assistance program - ' . (string) ($program['name'] ?? $id),
            ['entityType' => 'assistance', 'entityId' => $id]
        );
        return $program;
    }

    /**
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function archive(int $organizationId, int $id, array $orgRow, array $actor = []): array
    {
        $program = $this->update($organizationId, $id, ['status' => 'archived'], $orgRow, []);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'assistance.archived',
            'assistance',
            'Archived assistance program - ' . (string) ($program['name'] ?? $id),
            ['entityType' => 'assistance', 'entityId' => $id]
        );
        return $program;
    }

    public function delete(int $organizationId, int $id, array $actor = []): void
    {
        $row = $this->requireProgram($organizationId, $id);
        $this->pdo->prepare(
            'DELETE FROM beneficiaries WHERE assistance_id = :id AND organization_id = :org'
        )->execute(['id' => $id, 'org' => $organizationId]);
        $this->pdo->prepare(
            'DELETE FROM assistance_requirements WHERE assistance_id = :id AND organization_id = :org'
        )->execute(['id' => $id, 'org' => $organizationId]);
        $this->pdo->prepare(
            'DELETE FROM assistance_programs WHERE id = :id AND organization_id = :org'
        )->execute(['id' => $id, 'org' => $organizationId]);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'assistance.deleted',
            'assistance',
            'Deleted assistance program - ' . (string) ($row['name'] ?? $id),
            ['entityType' => 'assistance', 'entityId' => $id]
        );
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
    public function createType(int $organizationId, array $input, array $actor = []): array
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
        $typeId = (int) $this->pdo->lastInsertId();
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'assistance.type_created',
            'assistance',
            'Created assistance type - ' . (string) $values['name'],
            ['entityType' => 'assistance_type', 'entityId' => $typeId]
        );
        return $this->publicType($this->typeRow($typeId));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addRequirement(int $organizationId, array $input, array $actor = []): array
    {
        unset($input['organization_id'], $input['orgId'], $input['organizationId'], $input['org_id']);
        $targetType = strtolower(trim((string) ($input['targetType'] ?? $input['target_type'] ?? 'assistance')));
        $targetId = (int) ($input['targetId'] ?? $input['target_id'] ?? $input['assistanceId'] ?? $input['programId'] ?? 0);
        $assistanceId = null;
        $programId = null;
        if ($targetType === 'assistance') {
            $this->requireProgram($organizationId, $targetId);
            $assistanceId = $targetId;
        } elseif ($targetType === 'program' || $targetType === 'event') {
            $activity = $this->requireActivity($organizationId, $targetId);
            if ($targetType === 'event' && (string) $activity['kind'] !== 'event') {
                Json::error('VALIDATION_ERROR', 'That activity is not an event.', 422);
            }
            if ($targetType === 'program' && !in_array((string) $activity['kind'], ['program', 'event'], true)) {
                Json::error('VALIDATION_ERROR', 'Requirements can only be attached to a program or event.', 422);
            }
            $programId = $targetId;
        } else {
            Json::error('VALIDATION_ERROR', 'Requirements can be attached to assistance programs, programs, or events.', 422);
        }
        $values = AssistanceValidator::normalizeRequirement($input);
        $errors = AssistanceValidator::validateRequirement($values);
        if ($errors) {
            Json::validation($errors);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO assistance_requirements (
                organization_id, assistance_id, program_id, name, description, required, accepts, from_type
            ) VALUES (
                :organization_id, :assistance_id, :program_id, :name, :description, :required, :accepts, 0
            )'
        );
        $stmt->execute([
            'organization_id' => $organizationId,
            'assistance_id' => $assistanceId,
            'program_id' => $programId,
            'name' => $values['name'],
            'description' => $values['description'],
            'required' => $values['required'] ? 1 : 0,
            'accepts' => $values['accepts'],
        ]);
        $created = $this->publicRequirement($this->requirementRow($organizationId, (int) $this->pdo->lastInsertId()));
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'requirement.created',
            'requirement',
            'Added requirement "' . (string) ($created['name'] ?? $created['id']) . '"',
            ['entityType' => 'requirement', 'entityId' => (int) $created['id']]
        );
        return $created;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateRequirement(int $organizationId, int $id, array $input, array $actor = []): array
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
        $updated = $this->publicRequirement($this->requirementRow($organizationId, $id));
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'requirement.updated',
            'requirement',
            'Updated requirement "' . (string) ($updated['name'] ?? $id) . '"',
            ['entityType' => 'requirement', 'entityId' => $id]
        );
        return $updated;
    }

    public function deleteRequirement(int $organizationId, int $id, array $actor = []): void
    {
        $row = $this->requirementRow($organizationId, $id);
        $this->pdo->prepare(
            'DELETE FROM assistance_requirements WHERE id = :id AND organization_id = :org'
        )->execute(['id' => $id, 'org' => $organizationId]);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'requirement.deleted',
            'requirement',
            'Deleted requirement "' . (string) ($row['name'] ?? $id) . '"',
            ['entityType' => 'requirement', 'entityId' => $id]
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveBeneficiary(int $organizationId, int $assistanceId, array $input, array $actor = []): array
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
            $saved = $this->publicBeneficiary($this->beneficiaryRow($organizationId, $id));
        } else {
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
            $saved = $this->publicBeneficiary($this->beneficiaryRow($organizationId, (int) $existing['id']));
        }

        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'assistance.beneficiary_added',
            'assistance',
            'Recorded beneficiary for assistance program - ' . (string) ($program['name'] ?? $assistanceId),
            ['youthId' => $values['youthId'], 'entityType' => 'beneficiary', 'entityId' => (int) ($saved['id'] ?? 0)]
        );
        return $saved;
    }

    public function deleteBeneficiary(int $organizationId, int $id, array $actor = []): void
    {
        $row = $this->beneficiaryRow($organizationId, $id);
        $this->pdo->prepare(
            'DELETE FROM beneficiaries WHERE id = :id AND organization_id = :org'
        )->execute(['id' => $id, 'org' => $organizationId]);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'assistance.beneficiary_removed',
            'assistance',
            'Removed beneficiary ' . (string) ($row['code'] ?? ('#' . $id)),
            ['youthId' => (int) ($row['youth_id'] ?? 0), 'entityType' => 'beneficiary', 'entityId' => $id]
        );
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
            "SELECT b.*, y.code, y.first_name, y.last_name, y.priority_level, y.archived
             FROM beneficiaries b
             INNER JOIN youth y ON y.id = b.youth_id
             WHERE b.organization_id = :org AND b.assistance_id = :id
             ORDER BY FIELD(y.priority_level, 'High', 'Medium'),
                      CASE WHEN y.family_income > 0 THEN 0 ELSE 1 END,
                      y.family_income ASC,
                      b.id DESC"
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
        $programId = (int) ($row['program_id'] ?? 0);
        $assistanceId = (int) ($row['assistance_id'] ?? 0);
        return [
            'id' => (int) $row['id'],
            'targetType' => $programId > 0 ? 'program' : 'assistance',
            'targetId' => $programId > 0 ? $programId : $assistanceId,
            'name' => $row['name'],
            'description' => $row['description'],
            'required' => (bool) $row['required'],
            'accepts' => $row['accepts'],
            'fromType' => (bool) $row['from_type'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireActivity(int $organizationId, int $id): array
    {
        if ($id < 1) {
            Json::error('NOT_FOUND', 'This program or event does not exist in this organization.', 404);
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM programs WHERE id = :id AND organization_id = :org LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'org' => $organizationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This program or event does not exist in this organization.', 404);
        }
        return $row;
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
     * Invite youth to an assistance program by email. They confirm in their account.
     * No SMS is sent.
     *
     * @param list<int> $youthIds
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function invite(int $organizationId, int $assistanceId, array $youthIds, array $actor = []): array
    {
        $program = $this->requireProgram($organizationId, $assistanceId);
        $ids = [];
        foreach ($youthIds as $youthId) {
            $youthId = (int) $youthId;
            if ($youthId > 0) {
                $ids[$youthId] = $youthId;
            }
        }
        if ($ids === []) {
            Json::validation(['youthIds' => 'Select at least one youth.']);
        }

        $name = (string) $program['name'];
        $invited = 0;
        $skipped = [];
        $unsent = [];

        foreach ($ids as $youthId) {
            $stmt = $this->pdo->prepare(
                'SELECT y.id, y.first_name, y.last_name, y.email AS profile_email, y.archived,
                        u.id AS user_id, u.email AS login_email, u.status AS user_status
                 FROM youth y
                 LEFT JOIN users u ON u.youth_id = y.id
                 WHERE y.id = :id AND y.organization_id = :org
                 LIMIT 1'
            );
            $stmt->execute(['id' => $youthId, 'org' => $organizationId]);
            $youth = $stmt->fetch();
            if ($youth === false || (int) $youth['archived'] === 1) {
                $skipped[] = ['youthId' => $youthId, 'reason' => 'This youth record is not active in this organization.'];
                continue;
            }
            if ((int) ($youth['user_id'] ?? 0) < 1 || (string) ($youth['user_status'] ?? '') !== 'active') {
                $skipped[] = [
                    'youthId' => $youthId,
                    'name' => trim($youth['first_name'] . ' ' . $youth['last_name']),
                    'reason' => 'This youth has no active YouthSync account yet, so they cannot confirm.',
                ];
                continue;
            }

            $existing = $this->findBeneficiary($organizationId, $assistanceId, $youthId);
            if ($existing && in_array((string) $existing['status'], ['approved', 'released', 'confirmed'], true)) {
                $skipped[] = [
                    'youthId' => $youthId,
                    'name' => trim($youth['first_name'] . ' ' . $youth['last_name']),
                    'reason' => 'This youth already joined this program.',
                ];
                continue;
            }

            if ($existing === null) {
                $this->pdo->prepare(
                    'INSERT INTO beneficiaries (organization_id, assistance_id, youth_id, status, awarded_on, remarks)
                     VALUES (:organization_id, :assistance_id, :youth_id, :status, NULL, :remarks)'
                )->execute([
                    'organization_id' => $organizationId,
                    'assistance_id' => $assistanceId,
                    'youth_id' => $youthId,
                    'status' => 'invited',
                    'remarks' => '',
                ]);
            } else {
                $this->pdo->prepare(
                    'UPDATE beneficiaries SET status = :status, awarded_on = NULL WHERE id = :id AND organization_id = :org'
                )->execute(['status' => 'invited', 'id' => (int) $existing['id'], 'org' => $organizationId]);
            }

            $fullName = trim($youth['first_name'] . ' ' . $youth['last_name']);
            $link = '/youth/assistance/' . $assistanceId;
            if ($this->notifications !== null) {
                $this->notifications->notifyYouth($organizationId, $youthId, [
                    'type' => 'assistance',
                    'title' => 'Invitation: ' . $name,
                    'message' => 'You are invited to ' . $name . '. Open YouthSync and confirm if you will join. No text message was sent.',
                    'link' => $link,
                    'related' => $name,
                    'relatedEntityType' => 'assistance',
                    'relatedEntityId' => $assistanceId,
                ]);
            }

            $to = strtolower(trim((string) ($youth['login_email'] ?: $youth['profile_email'])));
            $sent = false;
            if ($to !== '' && $this->email !== null && $this->email->isConfigured()) {
                $result = $this->email->send(
                    $to,
                    'You are invited to ' . $name,
                    '<p>Hello ' . htmlspecialchars($fullName, ENT_QUOTES) . ',</p>'
                    . '<p>Your SK invited you to <strong>' . htmlspecialchars($name, ENT_QUOTES) . '</strong>.</p>'
                    . '<p>Sign in to YouthSync and confirm if you will join. This invitation was sent by email only.</p>',
                    "Hello {$fullName},\n\nYour SK invited you to {$name}.\nSign in to YouthSync and confirm if you will join."
                );
                $sent = (bool) ($result['ok'] ?? false);
            }
            if (!$sent) {
                $unsent[] = [
                    'youthId' => $youthId,
                    'name' => $fullName,
                    'email' => $to,
                    'reason' => $to === '' ? 'No email address on this account.' : 'The invitation email could not be sent.',
                ];
            }
            $invited++;
        }

        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'assistance.invite',
            'assistance',
            'Invited youth to ' . $name
        );

        return ['invited' => $invited, 'skipped' => $skipped, 'unsent' => $unsent];
    }

    /**
     * @return array<string, mixed>
     */
    public function respondToInvitation(int $organizationId, int $youthId, int $assistanceId, string $decision): array
    {
        $program = $this->requireProgram($organizationId, $assistanceId);
        $existing = $this->findBeneficiary($organizationId, $assistanceId, $youthId);
        if ($existing === null || (string) $existing['status'] !== 'invited') {
            Json::error('NOT_FOUND', 'There is no open invitation for you on this program.', 404);
        }
        $join = $decision === 'join';
        $status = $join ? 'confirmed' : 'declined';
        $this->pdo->prepare(
            'UPDATE beneficiaries SET status = :status, awarded_on = :awarded WHERE id = :id AND organization_id = :org'
        )->execute([
            'status' => $status,
            'awarded' => $join ? date('Y-m-d') : null,
            'id' => (int) $existing['id'],
            'org' => $organizationId,
        ]);

        $youth = $this->youthInOrg($organizationId, $youthId);
        $fullName = $youth ? trim($youth['first_name'] . ' ' . $youth['last_name']) : 'A youth';
        $name = (string) $program['name'];
        if ($this->notifications !== null) {
            $this->notifications->notifySk($organizationId, [
                'type' => 'assistance',
                'title' => $fullName . ($join ? ' will join ' : ' declined ') . $name,
                'message' => $fullName . ($join ? ' confirmed they will join ' : ' declined the invitation to ') . $name . '.',
                'link' => '/sk/assistance/' . $assistanceId,
                'related' => $name,
                'relatedEntityType' => 'assistance',
                'relatedEntityId' => $assistanceId,
            ]);
        }

        return $this->publicBeneficiary($this->beneficiaryRow($organizationId, (int) $existing['id']));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function invitationsForYouth(int $organizationId, int $youthId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, y.code, y.first_name, y.last_name, y.priority_level, p.name AS program_name
             FROM beneficiaries b
             INNER JOIN youth y ON y.id = b.youth_id
             INNER JOIN assistance_programs p ON p.id = b.assistance_id
             WHERE b.organization_id = :org AND b.youth_id = :youth
               AND b.status IN (\'invited\', \'confirmed\', \'declined\')
             ORDER BY b.id DESC'
        );
        $stmt->execute(['org' => $organizationId, 'youth' => $youthId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $item = $this->publicBeneficiary($row);
            $item['programName'] = $row['program_name'];
            $items[] = $item;
        }
        return $items;
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
