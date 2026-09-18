<?php
declare(strict_types=1);

namespace YouthSync\Programs;

use PDO;
use YouthSync\Http\Json;
use YouthSync\Org\PlanLimits;

final class ProgramService
{
    private const ACTIVE_STATUSES = ['draft', 'published', 'ongoing'];

    public function __construct(private PDO $pdo)
    {
    }

    public function countActive(int $organizationId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM programs
             WHERE organization_id = :org AND status IN ('draft', 'published', 'ongoing')"
        );
        $stmt->execute(['org' => $organizationId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function list(int $organizationId, array $query, array $orgRow, ?string $forceKind = null): array
    {
        $kind = $forceKind ?? trim((string) ($query['kind'] ?? ''));
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
        if ($kind !== '') {
            $where[] = 'kind = :kind';
            $params['kind'] = $kind;
        }
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($q !== '') {
            $where[] = '(name LIKE :q OR location LIKE :q OR category LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $sqlWhere = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM programs WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil(($total ?: 1) / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT * FROM programs WHERE {$sqlWhere} ORDER BY scheduled_on DESC, id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->publicProgram($row);
        }

        $plan = PlanLimits::effective($orgRow);
        $usage = $this->countActive($organizationId);
        $limit = $plan['programs'] ?? null;

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'usage' => [
                'programs' => $usage,
                'limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $usage),
            ],
        ];
    }

    public function get(int $organizationId, int $id, ?string $expectKind = null): array
    {
        return $this->publicProgram($this->requireInOrg($organizationId, $id, $expectKind));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function create(int $organizationId, array $input, array $orgRow, ?string $forceKind = null): array
    {
        $values = ProgramValidator::normalize($input, $forceKind);
        $errors = ProgramValidator::validate($values);
        if ($errors) {
            Json::validation($errors);
        }
        if ($this->duplicateExists($organizationId, $values)) {
            Json::error('CONFLICT', 'An activity with this name and date already exists in your organization.', 409);
        }

        $countsTowardLimit = in_array($values['status'], self::ACTIVE_STATUSES, true);
        $plan = PlanLimits::effective($orgRow);
        if ($countsTowardLimit && $plan['programs'] !== null && $this->countActive($organizationId) + 1 > $plan['programs']) {
            Json::error('PLAN_LIMIT', PlanLimits::programsLimitMessage($plan), 403);
        }

        $sql = <<<SQL
            INSERT INTO programs (
                organization_id, kind, name, category, status, scheduled_on, starts_at, ends_at,
                location, description, max_participants, registration_deadline, tag_interests,
                tag_skills, tag_activities, requires_studying, min_age, max_age
            ) VALUES (
                :organization_id, :kind, :name, :category, :status, :scheduled_on, :starts_at, :ends_at,
                :location, :description, :max_participants, :registration_deadline, :tag_interests,
                :tag_skills, :tag_activities, :requires_studying, :min_age, :max_age
            )
            SQL;
        $this->pdo->prepare($sql)->execute($this->bind($organizationId, $values));
        return $this->get($organizationId, (int) $this->pdo->lastInsertId());
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $orgRow
     */
    public function update(int $organizationId, int $id, array $input, array $orgRow, ?string $expectKind = null): array
    {
        $existingRow = $this->requireInOrg($organizationId, $id, $expectKind);
        $existing = $this->publicProgram($existingRow);
        unset($existing['id'], $existing['qrCode'], $existing['createdAt']);
        $values = ProgramValidator::normalize(array_merge($existing, $input), $expectKind);
        $errors = ProgramValidator::validate($values);
        if ($errors) {
            Json::validation($errors);
        }
        if ($this->duplicateExists($organizationId, $values, $id)) {
            Json::error('CONFLICT', 'An activity with this name and date already exists in your organization.', 409);
        }

        $wasActive = in_array($existingRow['status'], self::ACTIVE_STATUSES, true);
        $willBeActive = in_array($values['status'], self::ACTIVE_STATUSES, true);
        if (!$wasActive && $willBeActive) {
            $plan = PlanLimits::effective($orgRow);
            if ($plan['programs'] !== null && $this->countActive($organizationId) + 1 > $plan['programs']) {
                Json::error('PLAN_LIMIT', PlanLimits::programsLimitMessage($plan), 403);
            }
        }

        $sql = <<<SQL
            UPDATE programs SET
                kind = :kind, name = :name, category = :category, status = :status,
                scheduled_on = :scheduled_on, starts_at = :starts_at, ends_at = :ends_at,
                location = :location, description = :description, max_participants = :max_participants,
                registration_deadline = :registration_deadline, tag_interests = :tag_interests,
                tag_skills = :tag_skills, tag_activities = :tag_activities,
                requires_studying = :requires_studying, min_age = :min_age, max_age = :max_age
            WHERE id = :id AND organization_id = :organization_id
            SQL;
        $params = $this->bind($organizationId, $values);
        $params['id'] = $id;
        $this->pdo->prepare($sql)->execute($params);
        return $this->get($organizationId, $id, $expectKind);
    }

    /**
     * @param array<string, mixed> $orgRow
     */
    public function setStatus(int $organizationId, int $id, string $status, array $orgRow): array
    {
        $row = $this->requireInOrg($organizationId, $id);
        if (!in_array($status, ProgramValidator::STATUSES, true)) {
            Json::validation(['status' => 'Status is not valid.']);
        }
        $wasActive = in_array($row['status'], self::ACTIVE_STATUSES, true);
        $willBeActive = in_array($status, self::ACTIVE_STATUSES, true);
        if (!$wasActive && $willBeActive) {
            $plan = PlanLimits::effective($orgRow);
            if ($plan['programs'] !== null && $this->countActive($organizationId) + 1 > $plan['programs']) {
                Json::error('PLAN_LIMIT', PlanLimits::programsLimitMessage($plan), 403);
            }
        }
        $stmt = $this->pdo->prepare(
            'UPDATE programs SET status = :status WHERE id = :id AND organization_id = :org'
        );
        $stmt->execute(['status' => $status, 'id' => $id, 'org' => $organizationId]);
        return $this->get($organizationId, $id);
    }

    public function delete(int $organizationId, int $id, ?string $expectKind = null): void
    {
        $this->requireInOrg($organizationId, $id, $expectKind);
        $stmt = $this->pdo->prepare('DELETE FROM programs WHERE id = :id AND organization_id = :org');
        $stmt->execute(['id' => $id, 'org' => $organizationId]);
    }

    private function requireInOrg(int $organizationId, int $id, ?string $expectKind = null): array
    {
        $sql = 'SELECT * FROM programs WHERE id = :id AND organization_id = :org';
        $params = ['id' => $id, 'org' => $organizationId];
        if ($expectKind !== null) {
            $sql .= ' AND kind = :kind';
            $params['kind'] = $expectKind;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row === false) {
            $label = $expectKind === 'event' ? 'event' : 'program';
            Json::error('NOT_FOUND', "This {$label} does not exist in this organization.", 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function duplicateExists(int $organizationId, array $values, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM programs
                WHERE organization_id = :org
                  AND kind = :kind
                  AND LOWER(TRIM(name)) = :name
                  AND scheduled_on = :scheduled_on';
        $params = [
            'org' => $organizationId,
            'kind' => $values['kind'],
            'name' => mb_strtolower(trim($values['name'])),
            'scheduled_on' => $values['scheduledOn'],
        ];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function bind(int $organizationId, array $values): array
    {
        return [
            'organization_id' => $organizationId,
            'kind' => $values['kind'],
            'name' => $values['name'],
            'category' => $values['category'],
            'status' => $values['status'],
            'scheduled_on' => $values['scheduledOn'],
            'starts_at' => $values['startsAt'],
            'ends_at' => $values['endsAt'],
            'location' => $values['location'],
            'description' => $values['description'] !== '' ? $values['description'] : null,
            'max_participants' => $values['maxParticipants'],
            'registration_deadline' => $values['registrationDeadline'] !== '' ? $values['registrationDeadline'] : null,
            'tag_interests' => json_encode($values['tagInterests'], JSON_UNESCAPED_UNICODE),
            'tag_skills' => json_encode($values['tagSkills'], JSON_UNESCAPED_UNICODE),
            'tag_activities' => json_encode($values['tagActivities'], JSON_UNESCAPED_UNICODE),
            'requires_studying' => $values['requiresStudying'] ? 1 : 0,
            'min_age' => $values['minAge'],
            'max_age' => $values['maxAge'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicProgram(array $row): array
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

        $starts = substr((string) $row['starts_at'], 0, 5);
        $ends = substr((string) $row['ends_at'], 0, 5);

        return [
            'id' => (int) $row['id'],
            'kind' => $row['kind'],
            'name' => $row['name'],
            'category' => $row['category'],
            'status' => $row['status'],
            'scheduledOn' => $row['scheduled_on'],
            'startsAt' => $starts,
            'endsAt' => $ends,
            'location' => $row['location'],
            'description' => $row['description'] ?? '',
            'maxParticipants' => $row['max_participants'] !== null ? (int) $row['max_participants'] : null,
            'registrationDeadline' => $row['registration_deadline'],
            'tagInterests' => $decode($row['tag_interests']),
            'tagSkills' => $decode($row['tag_skills']),
            'tagActivities' => $decode($row['tag_activities']),
            'requiresStudying' => (bool) $row['requires_studying'],
            'minAge' => $row['min_age'] !== null ? (int) $row['min_age'] : null,
            'maxAge' => $row['max_age'] !== null ? (int) $row['max_age'] : null,
            'qrCode' => 'YSPROG-' . $row['id'],
            'createdAt' => $row['created_at'],
        ];
    }
}
