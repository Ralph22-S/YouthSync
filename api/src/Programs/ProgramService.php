<?php
declare(strict_types=1);

namespace YouthSync\Programs;

use PDO;
use Throwable;
use YouthSync\Admin\ActivityLog;
use YouthSync\Http\Json;
use YouthSync\Http\Pagination;
use YouthSync\Notifications\EmailService;
use YouthSync\Notifications\NotificationService;
use YouthSync\Org\PlanLimits;

final class ProgramService
{
    private const ACTIVE_STATUSES = ['draft', 'published', 'ongoing'];

    public function __construct(
        private PDO $pdo,
        private ?NotificationService $notifications = null,
        private ?EmailService $email = null,
    ) {
    }

    /**
     * When a write adds one active program/event, lock the organization row and
     * check the plan limit (403 PLAN_LIMIT_REACHED). Returns the lock token for
     * PlanLimits::releaseLock().
     *
     * @param array<string, mixed> $orgRow
     */
    private function lockForLimit(int $organizationId, array $orgRow, bool $addsActive): bool
    {
        $plan = PlanLimits::effective($orgRow);
        if (!$addsActive || $plan['programs'] === null) {
            return false;
        }
        $locked = PlanLimits::lockOrganization($this->pdo, $organizationId);
        PlanLimits::assertRoom($this->pdo, 'programs', $plan, $this->countActive($organizationId));
        return $locked;
    }

    public function countActive(int $organizationId): int
    {
        return PlanLimits::count($this->pdo, 'programs', $organizationId);
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
        $q = Pagination::search($query);
        $sort = trim((string) ($query['sort'] ?? ''));
        $paging = Pagination::fromQuery($query, 50, 100);

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
            $where[] = Pagination::likeAny(['name', 'location', 'category'], $q, 'q', $params);
        }
        $sqlWhere = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM programs WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $meta = $paging->meta($total);

        // No sort keeps the original schedule order; the list screen sends one.
        $order = match ($sort) {
            'newest' => 'created_at DESC, id DESC',
            'oldest' => 'created_at ASC, id ASC',
            'name' => 'name ASC, id ASC',
            default => 'scheduled_on DESC, id DESC',
        };
        // registered_count: attendance rows per activity (the "Registered" column),
        // counted in the same query instead of one request per activity.
        $stmt = $this->pdo->prepare(
            "SELECT programs.*,
                    (SELECT COUNT(*) FROM attendance att
                     WHERE att.organization_id = programs.organization_id
                       AND att.program_id = programs.id
                       AND att.status IN ('registered', 'present', 'absent', 'excused')) AS registered_count
             FROM programs WHERE {$sqlWhere} ORDER BY {$order} {$paging->limitSql()}"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $item = $this->publicProgram($row);
            $item['registeredCount'] = (int) $row['registered_count'];
            $items[] = $item;
        }

        $plan = PlanLimits::effective($orgRow);
        $usage = $this->countActive($organizationId);
        $limit = $plan['programs'] ?? null;

        return [
            'items' => $items,
            ...$meta,
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
     * Published and ongoing activities for the youth portal. Plan usage is omitted.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listVisible(int $organizationId, array $query): array
    {
        $kind = trim((string) ($query['kind'] ?? ''));
        $paging = Pagination::fromQuery($query, 50, 100);

        $where = ['organization_id = :org', "status IN ('published', 'ongoing')"];
        $params = ['org' => $organizationId];
        if ($kind === 'program' || $kind === 'event') {
            $where[] = 'kind = :kind';
            $params['kind'] = $kind;
        }
        $sqlWhere = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM programs WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $meta = $paging->meta($total);

        $stmt = $this->pdo->prepare(
            "SELECT programs.*,
                    (SELECT COUNT(*) FROM attendance att
                     WHERE att.organization_id = programs.organization_id
                       AND att.program_id = programs.id
                       AND att.status IN ('registered', 'present', 'absent', 'excused')) AS registered_count
             FROM programs WHERE {$sqlWhere}
             ORDER BY scheduled_on ASC, id ASC
             {$paging->limitSql()}"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $item = $this->publicProgram($row);
            $item['registeredCount'] = (int) $row['registered_count'];
            $items[] = $item;
        }

        return [
            'items' => $items,
            ...$meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getVisible(int $organizationId, int $id): array
    {
        $row = $this->requireInOrg($organizationId, $id);
        if (!in_array((string) ($row['status'] ?? ''), ['published', 'ongoing'], true)) {
            Json::error('NOT_FOUND', 'That activity is not available.', 404);
        }
        return $this->publicProgram($row);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $orgRow
     * @return array<string, mixed>
     */
    public function create(int $organizationId, array $input, array $orgRow, ?string $forceKind = null, array $actor = []): array
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
        $locked = $this->lockForLimit($organizationId, $orgRow, $countsTowardLimit);

        $registrationCode = $this->newRegistrationCode();
        $sql = <<<SQL
            INSERT INTO programs (
                organization_id, kind, name, registration_code, category, status, scheduled_on, starts_at, ends_at,
                location, description, max_participants, registration_deadline, tag_interests,
                tag_skills, tag_activities, requires_studying, min_age, max_age
            ) VALUES (
                :organization_id, :kind, :name, :registration_code, :category, :status, :scheduled_on, :starts_at, :ends_at,
                :location, :description, :max_participants, :registration_deadline, :tag_interests,
                :tag_skills, :tag_activities, :requires_studying, :min_age, :max_age
            )
            SQL;
        $this->pdo->prepare($sql)->execute($this->bind($organizationId, $values) + [
            'registration_code' => $registrationCode,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        PlanLimits::releaseLock($this->pdo, $locked);
        $program = $this->get($organizationId, $id);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'program.created',
            'program',
            'Created ' . (string) ($program['kind'] ?? 'program') . ' - ' . (string) ($program['name'] ?? $id),
            ['entityType' => 'program', 'entityId' => $id]
        );
        $this->remindIfNewlyPublished($organizationId, '', $program);
        return $program;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $orgRow
     */
    public function update(int $organizationId, int $id, array $input, array $orgRow, ?string $expectKind = null, array $actor = []): array
    {
        $existingRow = $this->requireInOrg($organizationId, $id, $expectKind);
        $existing = $this->publicProgram($existingRow);
        unset($existing['id'], $existing['qrCode'], $existing['registrationCode'], $existing['createdAt'], $existing['requirementItems']);
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
        $locked = $this->lockForLimit($organizationId, $orgRow, !$wasActive && $willBeActive);

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
        PlanLimits::releaseLock($this->pdo, $locked);
        $program = $this->get($organizationId, $id, $expectKind);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'program.updated',
            'program',
            'Updated ' . (string) ($program['kind'] ?? 'program') . ' - ' . (string) ($program['name'] ?? $id),
            ['entityType' => 'program', 'entityId' => $id]
        );
        $this->remindIfNewlyPublished($organizationId, (string) $existingRow['status'], $program);
        return $program;
    }

    /**
     * @param array<string, mixed> $orgRow
     */
    public function setStatus(int $organizationId, int $id, string $status, array $orgRow, array $actor = []): array
    {
        $row = $this->requireInOrg($organizationId, $id);
        if (!in_array($status, ProgramValidator::STATUSES, true)) {
            Json::validation(['status' => 'Status is not valid.']);
        }
        $wasActive = in_array($row['status'], self::ACTIVE_STATUSES, true);
        $willBeActive = in_array($status, self::ACTIVE_STATUSES, true);
        $locked = $this->lockForLimit($organizationId, $orgRow, !$wasActive && $willBeActive);
        $stmt = $this->pdo->prepare(
            'UPDATE programs SET status = :status WHERE id = :id AND organization_id = :org'
        );
        $stmt->execute(['status' => $status, 'id' => $id, 'org' => $organizationId]);
        PlanLimits::releaseLock($this->pdo, $locked);
        $program = $this->get($organizationId, $id);
        $action = match ($status) {
            'archived' => 'program.archived',
            'published' => 'program.published',
            default => 'program.updated',
        };
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            $action,
            'program',
            'Set ' . (string) ($program['name'] ?? $id) . ' status to ' . $status,
            ['entityType' => 'program', 'entityId' => $id]
        );
        $this->remindIfNewlyPublished($organizationId, (string) $row['status'], $program);
        return $program;
    }

    public function delete(int $organizationId, int $id, ?string $expectKind = null, array $actor = []): void
    {
        $row = $this->requireInOrg($organizationId, $id, $expectKind);
        $apps = $this->pdo->prepare('SELECT COUNT(*) FROM applications WHERE program_id = :id AND organization_id = :org');
        $apps->execute(['id' => $id, 'org' => $organizationId]);
        if ((int) $apps->fetchColumn() > 0) {
            Json::error(
                'CONFLICT',
                'This activity already has applications, so it cannot be deleted.',
                409
            );
        }
        try {
            $stmt = $this->pdo->prepare('DELETE FROM programs WHERE id = :id AND organization_id = :org');
            $stmt->execute(['id' => $id, 'org' => $organizationId]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                Json::error(
                    'CONFLICT',
                    'This activity already has records linked to it, so it cannot be deleted.',
                    409
                );
            }
            throw $e;
        }
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'program.deleted',
            'program',
            'Deleted ' . (string) ($row['kind'] ?? 'program') . ' - ' . (string) ($row['name'] ?? $id),
            ['entityType' => 'program', 'entityId' => $id]
        );
    }

    /**
     * Email invitation for a program or event. The youth confirms in their account.
     * This is separate from assistance invitations.
     *
     * @param list<int> $youthIds
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function invite(int $organizationId, int $programId, array $youthIds, array $actor = []): array
    {
        $program = $this->requireInOrg($organizationId, $programId);
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
        $kind = (string) ($program['kind'] ?? 'program') === 'event' ? 'event' : 'program';
        $link = '/youth/' . ($kind === 'event' ? 'events' : 'programs') . '/' . $programId;
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
            $fullName = trim($youth['first_name'] . ' ' . $youth['last_name']);
            if ((int) ($youth['user_id'] ?? 0) < 1 || (string) ($youth['user_status'] ?? '') !== 'active') {
                $skipped[] = ['youthId' => $youthId, 'name' => $fullName, 'reason' => 'This youth has no active YouthSync account yet, so they cannot confirm.'];
                continue;
            }

            $existing = $this->pdo->prepare(
                'SELECT id, status FROM attendance WHERE organization_id = :org AND program_id = :program AND youth_id = :youth LIMIT 1'
            );
            $existing->execute(['org' => $organizationId, 'program' => $programId, 'youth' => $youthId]);
            $row = $existing->fetch();
            if ($row !== false && in_array((string) $row['status'], ['registered', 'present', 'absent', 'excused'], true)) {
                $skipped[] = ['youthId' => $youthId, 'name' => $fullName, 'reason' => 'This youth is already registered for this activity.'];
                continue;
            }
            if ($row === false) {
                $this->pdo->prepare(
                    'INSERT INTO attendance (organization_id, program_id, youth_id, status, source)
                     VALUES (:org, :program, :youth, :status, :source)'
                )->execute([
                    'org' => $organizationId,
                    'program' => $programId,
                    'youth' => $youthId,
                    'status' => 'invited',
                    'source' => 'invite',
                ]);
            } else {
                $this->pdo->prepare(
                    'UPDATE attendance SET status = :status, source = :source WHERE id = :id AND organization_id = :org'
                )->execute(['status' => 'invited', 'source' => 'invite', 'id' => (int) $row['id'], 'org' => $organizationId]);
            }

            if ($this->notifications !== null) {
                $this->notifications->notifyYouth($organizationId, $youthId, [
                    'type' => 'programs',
                    'title' => 'Invitation: ' . $name,
                    'message' => 'You are invited to the ' . $kind . ' ' . $name . '. Open YouthSync and confirm if you will join. No text message was sent.',
                    'link' => $link,
                    'related' => $name,
                    'relatedEntityType' => 'program',
                    'relatedEntityId' => $programId,
                ]);
            }

            $to = strtolower(trim((string) ($youth['login_email'] ?: $youth['profile_email'])));
            $sent = false;
            if ($to !== '' && $this->email !== null && $this->email->isConfigured()) {
                $result = $this->email->send(
                    $to,
                    'You are invited to ' . $name,
                    '<p>Hello ' . htmlspecialchars($fullName, ENT_QUOTES) . ',</p>'
                    . '<p>Your SK invited you to the ' . $kind . ' <strong>' . htmlspecialchars($name, ENT_QUOTES) . '</strong>.</p>'
                    . '<p>Sign in to YouthSync and confirm if you will join. This invitation was sent by email only.</p>',
                    "Hello {$fullName},\n\nYour SK invited you to the {$kind} {$name}.\nSign in to YouthSync and confirm if you will join."
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

        ActivityLog::recordSk($this->pdo, $organizationId, $actor, 'program.invite', 'program', 'Invited youth to ' . $name);
        return ['invited' => $invited, 'skipped' => $skipped, 'unsent' => $unsent];
    }

    /**
     * @return array<string, mixed>
     */
    public function respondToInvitation(int $organizationId, int $youthId, int $programId, string $decision): array
    {
        $program = $this->requireInOrg($organizationId, $programId);
        $stmt = $this->pdo->prepare(
            'SELECT id, status FROM attendance
             WHERE organization_id = :org AND program_id = :program AND youth_id = :youth
             LIMIT 1'
        );
        $stmt->execute(['org' => $organizationId, 'program' => $programId, 'youth' => $youthId]);
        $row = $stmt->fetch();
        if ($row === false || (string) $row['status'] !== 'invited') {
            Json::error('NOT_FOUND', 'There is no open invitation for you on this activity.', 404);
        }
        $join = $decision === 'join';
        $this->pdo->prepare('UPDATE attendance SET status = :status WHERE id = :id AND organization_id = :org')
            ->execute([
                'status' => $join ? 'registered' : 'declined',
                'id' => (int) $row['id'],
                'org' => $organizationId,
            ]);

        $youth = $this->pdo->prepare('SELECT first_name, last_name FROM youth WHERE id = :id AND organization_id = :org LIMIT 1');
        $youth->execute(['id' => $youthId, 'org' => $organizationId]);
        $person = $youth->fetch();
        $fullName = $person ? trim($person['first_name'] . ' ' . $person['last_name']) : 'A youth';
        $name = (string) $program['name'];
        if ($this->notifications !== null) {
            $this->notifications->notifySk($organizationId, [
                'type' => 'programs',
                'title' => $fullName . ($join ? ' will join ' : ' declined ') . $name,
                'message' => $fullName . ($join ? ' confirmed they will join ' : ' declined the invitation to ') . $name . '.',
                'link' => '/sk/programs/' . $programId,
                'related' => $name,
                'relatedEntityType' => 'program',
                'relatedEntityId' => $programId,
            ]);
        }
        return ['programId' => $programId, 'youthId' => $youthId, 'status' => $join ? 'registered' : 'declined'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function invitationsForYouth(int $organizationId, int $youthId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.program_id, a.youth_id, a.status, a.source, p.name AS program_name
             FROM attendance a
             INNER JOIN programs p ON p.id = a.program_id
             WHERE a.organization_id = :org AND a.youth_id = :youth AND a.source = :source
               AND a.status IN (\'invited\', \'registered\', \'declined\')
             ORDER BY a.id DESC'
        );
        $stmt->execute(['org' => $organizationId, 'youth' => $youthId, 'source' => 'invite']);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'programId' => (int) $row['program_id'],
                'youthId' => (int) $row['youth_id'],
                'status' => $row['status'],
                'source' => $row['source'],
                'programName' => $row['program_name'],
            ];
        }
        return $items;
    }

    /**
     * One reminder per active youth in this organization when an activity first becomes published.
     * Saving an already published activity does not send another. Other organizations are not included.
     *
     * @param array<string, mixed> $program
     */
    private function remindIfNewlyPublished(int $organizationId, string $previousStatus, array $program): void
    {
        if ($this->notifications === null || $previousStatus === 'published') {
            return;
        }
        if ((string) ($program['status'] ?? '') !== 'published') {
            return;
        }
        $programId = (int) ($program['id'] ?? 0);
        if ($organizationId < 1 || $programId < 1) {
            return;
        }

        $kind = (string) ($program['kind'] ?? 'program') === 'event' ? 'event' : 'program';
        $name = trim((string) ($program['name'] ?? ''));
        if ($name === '') {
            $name = ucfirst($kind);
        }
        $until = trim((string) ($program['registrationDeadline'] ?? ''));
        if ($until === '') {
            $until = trim((string) ($program['scheduledOn'] ?? ''));
        }
        $title = 'New ' . $kind . ': ' . $name;
        $message = 'Registration is open'
            . ($until !== '' ? ' until ' . $until : '')
            . '. Scan the activity QR or open it in Programs & Events to register.';
        $link = '/youth/' . ($kind === 'event' ? 'events' : 'programs') . '/' . $programId;

        $stmt = $this->pdo->prepare(
            'SELECT id, contact, email FROM youth
             WHERE organization_id = :org AND archived = 0
             ORDER BY id'
        );
        $stmt->execute(['org' => $organizationId]);
        foreach ($stmt->fetchAll() as $youth) {
            $youthId = (int) ($youth['id'] ?? 0);
            if ($youthId < 1) {
                continue;
            }
            try {
                $note = $this->notifications->notifyYouth($organizationId, $youthId, [
                    'type' => 'programs',
                    'title' => $title,
                    'message' => $message,
                    'link' => $link,
                    'related' => $name,
                    'relatedEntityType' => 'program',
                    'relatedEntityId' => $programId,
                ]);
                $noteId = isset($note['id']) ? (int) $note['id'] : null;
                $this->notifications->deliverSmsOnce(
                    $organizationId,
                    'program.published',
                    $programId,
                    (string) ($youth['contact'] ?? ''),
                    $message,
                    $noteId
                );
                $this->notifications->deliverEmailOnce(
                    $organizationId,
                    'program.published',
                    $programId,
                    (string) ($youth['email'] ?? ''),
                    $title,
                    $message,
                    $name,
                    $noteId
                );
            } catch (Throwable) {
                continue;
            }
        }
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

    private function newRegistrationCode(): string
    {
        $stmt = $this->pdo->prepare('SELECT id FROM programs WHERE registration_code = :code LIMIT 1');
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = 'REG-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt->execute(['code' => $code]);
            if ($stmt->fetch() === false) {
                return $code;
            }
        }
        Json::error('SERVER_ERROR', 'Unable to create a registration code.', 500);
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
            'registrationCode' => (string) ($row['registration_code'] ?? ''),
            'qrCode' => (string) ($row['registration_code'] ?? ''),
            'createdAt' => $row['created_at'],
            'requirementItems' => $this->requirementItems((int) $row['organization_id'], (int) $row['id']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requirementItems(int $organizationId, int $programId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM assistance_requirements
             WHERE organization_id = :org AND program_id = :id
             ORDER BY id ASC'
        );
        $stmt->execute(['org' => $organizationId, 'id' => $programId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'targetType' => 'program',
                'targetId' => $programId,
                'name' => $row['name'],
                'description' => $row['description'],
                'required' => (bool) $row['required'],
                'accepts' => $row['accepts'],
                'fromType' => (bool) $row['from_type'],
            ];
        }
        return $items;
    }
}
