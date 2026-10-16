<?php
declare(strict_types=1);

namespace YouthSync\Youth;

use PDO;
use YouthSync\Admin\ActivityLog;
use YouthSync\Applications\ApplicationService;
use YouthSync\Attendance\AttendanceService;
use YouthSync\Assistance\AssistanceService;
use YouthSync\Http\Json;
use YouthSync\Notifications\NotificationService;
use YouthSync\Programs\ProgramService;

/**
 * Youth-facing reads and writes. Organization and youth identity come from the
 * authenticated youth record, never from the request.
 */
final class YouthPortalService
{
    private YouthFileStore $files;

    public function __construct(
        private PDO $pdo,
        private ProgramService $programs,
        private AssistanceService $assistance,
        private ApplicationService $applications,
        private NotificationService $notifications,
        private YouthService $youthRecords,
        private AttendanceService $attendanceRecords,
    ) {
        $this->files = new YouthFileStore($pdo);
    }

    /**
     * Adds the signed-in youth's photo reference. The URL is the youth route,
     * so another youth's file path is never returned.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function withPhoto(array $snapshot): array
    {
        $youthId = (int) ($snapshot['youth']['id'] ?? 0);
        $photo = $youthId > 0 ? $this->files->meta($youthId, 'photo') : null;
        if ($photo !== null) {
            $photo['url'] = '/youth/profile/photo';
        }
        if (isset($snapshot['youth']) && is_array($snapshot['youth'])) {
            $snapshot['youth']['photo'] = $photo;
        }
        return $snapshot;
    }

    /**
     * Profile fields only. Organization, role, status, and record identity
     * stay on the authenticated youth row.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function updateProfile(int $youthId, array $input, array $actor): array
    {
        foreach ([
            'id', 'youth_id', 'youthId', 'organization_id', 'organizationId', 'orgId', 'org_id',
            'role', 'role_code', 'status', 'archived', 'code', 'qrToken', 'accountStatus',
            'createAccount', 'accountEmail', 'account_email', 'photo', 'document',
            'selfRegistered', 'level', 'score', 'reasons', 'user_id', 'userId',
        ] as $key) {
            unset($input[$key]);
        }

        $organizationId = $this->organizationId($youthId);
        $youth = $this->youthRecords->update($organizationId, $youthId, $input, []);
        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($actor), [
            'organizationId' => $organizationId,
            'youthId' => $youthId,
            'actorRole' => 'YOUTH',
            'action' => 'youth.updated',
            'category' => 'youth',
            'description' => 'Updated own youth profile',
            'entityType' => 'youth',
            'entityId' => $youthId,
        ]));
        return $youth;
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array{name: string, type: string, size: int, url: string}
     */
    public function storePhoto(int $youthId, ?array $file, array $actor): array
    {
        $organizationId = $this->organizationId($youthId);
        $meta = $this->files->store($organizationId, $youthId, 'photo', $file);
        $meta['url'] = '/youth/profile/photo';
        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($actor), [
            'organizationId' => $organizationId,
            'youthId' => $youthId,
            'actorRole' => 'YOUTH',
            'action' => 'youth.updated',
            'category' => 'youth',
            'description' => 'Updated own profile photo',
            'entityType' => 'youth',
            'entityId' => $youthId,
        ]));
        return $meta;
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function removePhoto(int $youthId, array $actor): void
    {
        $organizationId = $this->organizationId($youthId);
        $this->files->remove($organizationId, $youthId, 'photo');
        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($actor), [
            'organizationId' => $organizationId,
            'youthId' => $youthId,
            'actorRole' => 'YOUTH',
            'action' => 'youth.updated',
            'category' => 'youth',
            'description' => 'Removed own profile photo',
            'entityType' => 'youth',
            'entityId' => $youthId,
        ]));
    }

    public function sendPhoto(int $youthId): never
    {
        $this->files->send($this->organizationId($youthId), $youthId, 'photo');
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function programs(int $youthId, array $query): array
    {
        unset($query['organization_id'], $query['organizationId'], $query['youth_id'], $query['youthId']);
        $organizationId = $this->organizationId($youthId);
        $listed = $this->programs->listVisible($organizationId, $query);
        $listed['invitations'] = $this->programs->invitationsForYouth($organizationId, $youthId);
        return $listed;
    }

    /**
     * @return array<string, mixed>
     */
    public function program(int $youthId, int $id): array
    {
        return $this->programs->getVisible($this->organizationId($youthId), $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function assistance(int $youthId): array
    {
        $organizationId = $this->organizationId($youthId);
        $listed = $this->assistance->listVisible($organizationId);
        $listed['invitations'] = $this->assistance->invitationsForYouth($organizationId, $youthId);
        return $listed;
    }

    /**
     * @return array<string, mixed>
     */
    public function respondToInvitation(int $youthId, int $assistanceId, string $decision): array
    {
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['join', 'decline'], true)) {
            Json::validation(['decision' => 'Choose join or decline.']);
        }
        return $this->assistance->respondToInvitation($this->organizationId($youthId), $youthId, $assistanceId, $decision);
    }

    /**
     * @return array<string, mixed>
     */
    public function respondToProgramInvitation(int $youthId, int $programId, string $decision): array
    {
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['join', 'decline'], true)) {
            Json::validation(['decision' => 'Choose join or decline.']);
        }
        return $this->programs->respondToInvitation($this->organizationId($youthId), $youthId, $programId, $decision);
    }

    /**
     * @return array<string, mixed>
     */
    public function assistanceItem(int $youthId, int $id): array
    {
        return $this->assistance->getVisible($this->organizationId($youthId), $id);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function applications(int $youthId, array $query): array
    {
        unset($query['organization_id'], $query['organizationId'], $query['youth_id'], $query['youthId']);
        $query['youthId'] = $youthId;
        $organizationId = $this->organizationId($youthId);
        $this->applications->syncOpenRequirementSlots($organizationId, $youthId);
        $listed = $this->applications->list($organizationId, $query);
        $listed['items'] = array_values(array_filter(
            $listed['items'],
            static fn (array $item): bool => (int) ($item['youthId'] ?? 0) === $youthId
        ));
        return $listed;
    }

    /**
     * @return array<string, mixed>
     */
    public function application(int $youthId, int $id): array
    {
        $item = $this->applications->get($this->organizationId($youthId), $id);
        if ((int) ($item['youthId'] ?? 0) !== $youthId) {
            Json::error('NOT_FOUND', 'This application does not exist.', 404);
        }
        return $item;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function createApplication(int $youthId, array $input, array $actor): array
    {
        unset(
            $input['organization_id'],
            $input['organizationId'],
            $input['orgId'],
            $input['youth_id'],
            $input['youthId'],
            $input['status'],
            $input['reviewedBy'],
            $input['reviewed_by'],
            $input['role'],
            $input['role_id'],
            $input['roleId']
        );
        $input['youthId'] = $youthId;
        return $this->applications->create($this->organizationId($youthId), $input, $actor);
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $query
     * @return array{items: list<array<string, mixed>>}
     */
    public function attendance(int $youthId, array $query = []): array
    {
        unset($query['organization_id'], $query['organizationId'], $query['youth_id'], $query['youthId'], $query['role']);
        return $this->attendanceRecords->listForYouth($this->organizationId($youthId), $youthId, $query);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function checkIn(int $youthId, array $input, array $actor): array
    {
        return $this->attendanceRecords->checkIn($this->organizationId($youthId), $youthId, $input, $actor);
    }

    /**
     * Poster registration. The code selects the program. Identity comes from the youth session.
     * This creates an application only. It does not write an attendance row.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function registerByCode(int $youthId, array $input, array $actor): array
    {
        unset(
            $input['organization_id'],
            $input['organizationId'],
            $input['orgId'],
            $input['youth_id'],
            $input['youthId'],
            $input['user_id'],
            $input['userId'],
            $input['program_id'],
            $input['programId'],
            $input['role'],
            $input['role_id'],
            $input['roleId'],
            $input['status']
        );
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        if (preg_match('/^REG-[A-F0-9]{8}$/', $code) !== 1) {
            Json::error('INVALID_REGISTRATION_CODE', 'Invalid or unavailable registration code.', 404);
        }

        $organizationId = $this->organizationId($youthId);
        $stmt = $this->pdo->prepare('SELECT * FROM programs WHERE registration_code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $program = $stmt->fetch();
        if ($program === false || (int) $program['organization_id'] !== $organizationId) {
            Json::error('INVALID_REGISTRATION_CODE', 'Invalid or unavailable registration code.', 404);
        }
        if (!in_array((string) $program['status'], ['published', 'ongoing'], true)) {
            Json::error('INVALID_REGISTRATION_CODE', 'Invalid or unavailable registration code.', 422);
        }
        $deadline = $program['registration_deadline'] ?? null;
        if ($deadline !== null && $deadline !== '' && $deadline < date('Y-m-d')) {
            Json::error('INVALID_REGISTRATION_CODE', 'Invalid or unavailable registration code.', 422);
        }

        $existing = $this->pdo->prepare(
            'SELECT id FROM applications
             WHERE organization_id = :org AND program_id = :program AND youth_id = :youth
             LIMIT 1'
        );
        $existing->execute([
            'org' => $organizationId,
            'program' => (int) $program['id'],
            'youth' => $youthId,
        ]);
        if ($existing->fetch() !== false) {
            Json::error('ALREADY_REGISTERED', 'Already registered for this program.', 409);
        }

        $application = $this->applications->create($organizationId, [
            'programId' => (int) $program['id'],
            'youthId' => $youthId,
        ], $actor);

        return [
            'message' => 'Registration successful.',
            'application' => $application,
            'program' => [
                'id' => (int) $program['id'],
                'kind' => $program['kind'],
                'name' => $program['name'],
            ],
        ];
    }

    public function resubmitApplication(int $youthId, int $id, array $actor): array
    {
        return $this->applications->resubmit($this->organizationId($youthId), $youthId, $id, $actor);
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array<string, mixed>
     */
    public function storeRequirementFile(int $youthId, int $applicationId, int $requirementId, ?array $file, array $actor = []): array
    {
        return $this->applications->storeYouthRequirementFile(
            $this->organizationId($youthId),
            $youthId,
            $applicationId,
            $requirementId,
            $file,
            $actor
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function removeRequirementFile(int $youthId, int $applicationId, int $requirementId, array $actor = []): array
    {
        return $this->applications->removeYouthRequirementFile(
            $this->organizationId($youthId),
            $youthId,
            $applicationId,
            $requirementId,
            $actor
        );
    }

    public function sendRequirementFile(int $youthId, int $applicationId, int $requirementId, bool $attachment): never
    {
        $this->applications->sendYouthRequirementFile(
            $this->organizationId($youthId),
            $youthId,
            $applicationId,
            $requirementId,
            $attachment
        );
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function notifications(int $youthId, array $query): array
    {
        unset($query['organization_id'], $query['organizationId'], $query['youth_id'], $query['youthId']);
        return $this->notifications->listForYouth($this->organizationId($youthId), $youthId, $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function markNotificationRead(int $youthId, int $id): array
    {
        return $this->notifications->markReadForYouth($this->organizationId($youthId), $youthId, $id);
    }

    /**
     * @return array{updated: int}
     */
    public function markAllNotificationsRead(int $youthId): array
    {
        return $this->notifications->markAllReadForYouth($this->organizationId($youthId), $youthId);
    }

    /**
     * @return array{deleted: true}
     */
    public function deleteNotification(int $youthId, int $id): array
    {
        return $this->notifications->deleteForYouth($this->organizationId($youthId), $youthId, $id);
    }

    private function organizationId(int $youthId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT organization_id, archived FROM youth WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $youthId]);
        $row = $stmt->fetch();
        if ($row === false || (int) $row['archived'] === 1) {
            Json::error('FORBIDDEN', 'This youth account is not linked to a youth record.', 403);
        }
        return (int) $row['organization_id'];
    }
}
