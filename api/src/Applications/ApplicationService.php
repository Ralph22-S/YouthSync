<?php
declare(strict_types=1);

namespace YouthSync\Applications;

use PDO;
use PDOException;
use YouthSync\Admin\ActivityLog;
use YouthSync\Assistance\AssistanceService;
use YouthSync\Http\Json;
use YouthSync\Http\Pagination;
use YouthSync\Notifications\NotificationService;

final class ApplicationService
{
    private ApplicationRequirementFileStore $files;

    public function __construct(
        private PDO $pdo,
        private AssistanceService $assistance,
        private NotificationService $notifications,
    ) {
        $this->files = new ApplicationRequirementFileStore();
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function list(int $organizationId, array $query): array
    {
        $assistanceId = (int) ($query['assistanceId'] ?? $query['assistance_id'] ?? 0);
        $youthId = (int) ($query['youthId'] ?? $query['youth_id'] ?? 0);
        $status = strtolower(trim((string) ($query['status'] ?? '')));
        $q = Pagination::search($query);
        $kind = strtolower(trim((string) ($query['kind'] ?? '')));
        $sort = trim((string) ($query['sort'] ?? ''));
        $paging = Pagination::fromQuery($query, 50, 100);
        if (!in_array($kind, ['', 'all', 'scholarship', 'assistance'], true)) {
            Json::validation(['kind' => 'Use all, scholarship, or assistance.']);
        }

        $where = ['a.organization_id = :org'];
        $params = ['org' => $organizationId];
        if ($assistanceId > 0) {
            $where[] = 'a.assistance_id = :assistance_id';
            $params['assistance_id'] = $assistanceId;
        }
        if ($youthId > 0) {
            $where[] = 'a.youth_id = :youth_id';
            $params['youth_id'] = $youthId;
        }
        // Sidebar tabs: scholarships, or every other assistance application.
        if ($kind === 'scholarship') {
            $where[] = "ap.category = 'scholarship'";
        } elseif ($kind === 'assistance') {
            $where[] = "a.assistance_id IS NOT NULL AND (ap.category IS NULL OR ap.category <> 'scholarship')";
        }
        $from = 'applications a
                 INNER JOIN youth y ON y.id = a.youth_id
                 LEFT JOIN assistance_programs ap ON ap.id = a.assistance_id
                 LEFT JOIN programs pr ON pr.id = a.program_id';

        // Status tab counts: same organization/kind scope, before the status
        // tab and the search box are applied (as the tabs always showed).
        $countsStmt = $this->pdo->prepare(
            "SELECT a.status, COUNT(*) AS n FROM {$from} WHERE " . implode(' AND ', $where) . ' GROUP BY a.status'
        );
        $countsStmt->execute($params);
        $counts = ['all' => 0];
        foreach ($countsStmt->fetchAll() as $countRow) {
            $counts[(string) $countRow['status']] = (int) $countRow['n'];
            $counts['all'] += (int) $countRow['n'];
        }

        if ($status !== '' && $status !== 'all') {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }
        if ($q !== '') {
            $where[] = Pagination::likeAny(
                ['y.first_name', 'y.last_name', "CONCAT(y.first_name, ' ', y.last_name)", 'ap.name', 'pr.name'],
                $q, 'q', $params
            );
        }
        $sqlWhere = implode(' AND ', $where);
        // Scholarship and other assistance programs always list low family
        // income first. Events and programs keep the usual date or name sort.
        $assistanceQueue = $assistanceId > 0 || $kind === 'scholarship' || $kind === 'assistance';
        $order = $assistanceQueue
            ? "FIELD(y.priority_level, 'High', 'Medium'), CASE WHEN y.family_income > 0 THEN 0 ELSE 1 END, y.family_income ASC, a.submitted_at DESC, a.id DESC"
            : match ($sort) {
                'oldest' => 'a.submitted_at ASC, a.id ASC',
                'name' => 'y.last_name ASC, y.first_name ASC, a.id ASC',
                default => 'a.submitted_at DESC, a.id DESC',
            };

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$from} WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $meta = $paging->meta($total);

        // requirement_count: requirements of the applied-for program or
        // assistance (the "Requirements" column), counted in the same query.
        $stmt = $this->pdo->prepare(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM assistance_requirements rq
                     WHERE rq.organization_id = a.organization_id
                       AND CASE WHEN COALESCE(a.program_id, 0) > 0
                                THEN rq.program_id = a.program_id
                                ELSE rq.assistance_id = a.assistance_id END) AS requirement_count
             FROM {$from} WHERE {$sqlWhere}
             ORDER BY {$order}
             {$paging->limitSql()}"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $item = $this->publicApplication($row, true);
            $item['requirementCount'] = (int) $row['requirement_count'];
            $items[] = $item;
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
        return $this->publicApplication($this->requireApplication($organizationId, $id), true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $organizationId, array $input, array $actor = []): array
    {
        $values = ApplicationValidator::normalizeCreate($input);
        $errors = ApplicationValidator::validateCreate($values);
        if ($errors) {
            Json::validation($errors);
        }

        $programId = (int) ($values['programId'] ?? 0);
        $assistanceId = (int) $values['assistanceId'];
        if ($programId > 0) {
            $program = $this->requireActivity($organizationId, $programId);
            if (!in_array((string) $program['status'], ['published', 'ongoing'], true)) {
                Json::error(
                    'VALIDATION_ERROR',
                    'Applications can only be submitted for published or ongoing programs and events.',
                    422
                );
            }
            $deadline = $program['registration_deadline'] ?? null;
            if ($deadline !== null && $deadline !== '' && $deadline < date('Y-m-d')) {
                Json::error('VALIDATION_ERROR', 'The registration deadline for this activity has passed.', 422);
            }
        } else {
            $program = $this->requireAssistance($organizationId, $assistanceId);
            if (!in_array($program['status'], ApplicationValidator::APPLY_STATUSES, true)) {
                Json::error(
                    'VALIDATION_ERROR',
                    'Applications can only be submitted for open or full assistance programs.',
                    422
                );
            }
            if ($program['deadline'] !== null && $program['deadline'] !== '' && $program['deadline'] < date('Y-m-d')) {
                Json::error('VALIDATION_ERROR', 'The application deadline for this program has passed.', 422);
            }
        }

        $youth = $this->youthInOrg($organizationId, $values['youthId']);
        if ($youth === null) {
            Json::error('NOT_FOUND', 'This youth record does not exist in this organization.', 404);
        }
        if ((int) $youth['archived'] === 1) {
            Json::error('VALIDATION_ERROR', 'This youth record is archived.', 422);
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO applications (
                    organization_id, assistance_id, program_id, youth_id, status, remarks, submitted_at
                ) VALUES (
                    :organization_id, :assistance_id, :program_id, :youth_id, :status, :remarks, NOW()
                )'
            )->execute([
                'organization_id' => $organizationId,
                'assistance_id' => $programId > 0 ? null : $assistanceId,
                'program_id' => $programId > 0 ? $programId : null,
                'youth_id' => $values['youthId'],
                'status' => 'pending',
                'remarks' => $values['remarks'],
            ]);
        } catch (PDOException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? '');
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($sqlState === '23000' || $driverCode === 1062) {
                Json::error(
                    'CONFLICT',
                    $programId > 0
                        ? 'This youth already has an application for this program or event.'
                        : 'This youth already has an application for this assistance program.',
                    409
                );
            }
            throw $e;
        }

        $id = (int) $this->pdo->lastInsertId();
        $this->seedSubmissions(
            $organizationId,
            $id,
            $programId > 0 ? 0 : $assistanceId,
            $programId,
            $values['submissions']
        );
        $youthName = trim(($youth['first_name'] ?? '') . ' ' . ($youth['last_name'] ?? ''));
        $skNote = $this->notifications->notifySk($organizationId, [
            'type' => 'applications',
            'title' => 'New application received',
            'message' => $youthName . ' applied for "' . $program['name'] . '".',
            'link' => '/sk/applications/' . $id,
            'related' => $program['name'],
            'relatedEntityType' => 'application',
            'relatedEntityId' => $id,
        ]);
        $submittedMessage = 'Your application for "' . $program['name'] . '" was submitted.';
        $youthNote = $this->notifications->notifyYouth($organizationId, (int) $values['youthId'], [
            'type' => 'applications',
            'title' => 'Application submitted',
            'message' => $submittedMessage,
            'link' => '/youth/applications/' . $id,
            'related' => $program['name'],
            'relatedEntityType' => 'application',
            'relatedEntityId' => $id,
        ]);
        $this->notifications->deliverSmsOnce(
            $organizationId,
            'application.submitted',
            $id,
            (string) ($youth['contact'] ?? ''),
            $submittedMessage,
            isset($youthNote['id']) ? (int) $youthNote['id'] : null
        );
        $this->notifications->deliverSmsOnce(
            $organizationId,
            'application.received',
            $id,
            $this->organizationContact($organizationId),
            $youthName . ' applied for "' . $program['name'] . '".',
            isset($skNote['id']) ? (int) $skNote['id'] : null
        );
        $this->notifications->deliverEmailOnce(
            $organizationId,
            'application.submitted',
            $id,
            (string) ($youth['email'] ?? ''),
            'Application submitted',
            $submittedMessage,
            (string) $program['name'],
            isset($youthNote['id']) ? (int) $youthNote['id'] : null
        );
        $this->notifications->deliverEmailOnce(
            $organizationId,
            'application.received',
            $id,
            $this->organizationEmail($organizationId),
            'New application received',
            $youthName . ' applied for "' . $program['name'] . '".',
            (string) $program['name'],
            isset($skNote['id']) ? (int) $skNote['id'] : null
        );
        $application = $this->get($organizationId, $id);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'application.created',
            'application',
            'Created application for ' . (string) ($program['name'] ?? $values['assistanceId']),
            ['youthId' => $values['youthId'], 'entityType' => 'application', 'entityId' => $id]
        );
        return $application;
    }

    /**
     * Youth returns an application to pending after SK requested resubmission,
     * or after a rejection once they can supply the missing requirements.
     * Ownership and organization come from the authenticated youth record.
     *
     * @return array<string, mixed>
     */
    public function resubmit(int $organizationId, int $youthId, int $id, array $actor = []): array
    {
        $row = $this->requireApplication($organizationId, $id);
        if ((int) $row['youth_id'] !== $youthId) {
            Json::error('NOT_FOUND', 'This application does not exist.', 404);
        }
        if (!in_array($row['status'], ['needs_resubmission', 'rejected'], true)) {
            Json::error('VALIDATION_ERROR', 'This application cannot be resubmitted.', 422);
        }

        $this->pdo->prepare(
            "UPDATE applications
             SET status = 'pending',
                 reviewed_at = NULL,
                 reviewed_by = NULL,
                 submitted_at = NOW()
             WHERE id = :id
               AND organization_id = :org
               AND youth_id = :youth
               AND status IN ('needs_resubmission', 'rejected')"
        )->execute([
            'id' => $id,
            'org' => $organizationId,
            'youth' => $youthId,
        ]);

        $application = $this->get($organizationId, $id);
        $this->notifyApplicationResubmitted($organizationId, $youthId, $row, $application);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'application.resubmitted',
            'application',
            'Youth resubmitted application',
            ['youthId' => $youthId, 'entityType' => 'application', 'entityId' => $id]
        );
        return $application;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $organizationId, int $id, array $input, array $actor = []): array
    {
        $this->requireApplication($organizationId, $id);
        $values = ApplicationValidator::normalizePatch($input);
        if ($values === []) {
            return $this->get($organizationId, $id);
        }
        if (array_key_exists('remarks', $values)) {
            $this->pdo->prepare(
                'UPDATE applications SET remarks = :remarks WHERE id = :id AND organization_id = :org'
            )->execute([
                'remarks' => $values['remarks'],
                'id' => $id,
                'org' => $organizationId,
            ]);
            ActivityLog::recordSk(
                $this->pdo,
                $organizationId,
                $actor,
                'application.updated',
                'application',
                'Updated application remarks',
                ['entityType' => 'application', 'entityId' => $id]
            );
        }
        return $this->get($organizationId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function setStatus(int $organizationId, int $id, int $reviewerId, array $input, array $actor = []): array
    {
        $row = $this->requireApplication($organizationId, $id);
        $values = ApplicationValidator::normalizeStatus($input);
        $errors = ApplicationValidator::validateStatus($values);
        if ($errors) {
            Json::validation($errors);
        }

        $assistanceId = (int) ($row['assistance_id'] ?? 0);
        if ($assistanceId > 0 && $values['status'] === 'approved') {
            $this->assistance->saveBeneficiary($organizationId, $assistanceId, [
                'youthId' => (int) $row['youth_id'],
                'status' => 'approved',
                'remarks' => $values['remarks'] !== '' ? $values['remarks'] : $row['remarks'],
            ]);
        } elseif ($assistanceId > 0 && in_array($values['status'], ['rejected', 'withdrawn'], true)
            && $row['status'] === 'approved') {
            $this->assistance->saveBeneficiary($organizationId, $assistanceId, [
                'youthId' => (int) $row['youth_id'],
                'status' => 'rejected',
                'remarks' => $values['remarks'],
            ]);
        }

        $remarks = $values['remarks'] !== '' ? $values['remarks'] : $row['remarks'];
        $this->pdo->prepare(
            'UPDATE applications
             SET status = :status,
                 remarks = :remarks,
                 reviewed_at = NOW(),
                 reviewed_by = :reviewed_by
             WHERE id = :id AND organization_id = :org'
        )->execute([
            'status' => $values['status'],
            'remarks' => $remarks,
            'reviewed_by' => $reviewerId,
            'id' => $id,
            'org' => $organizationId,
        ]);

        $activityName = $assistanceId > 0
            ? (string) $this->requireAssistance($organizationId, $assistanceId)['name']
            : (string) $this->requireActivity($organizationId, (int) ($row['program_id'] ?? 0))['name'];
        $this->notifyYouthOfApplicationStatus(
            $organizationId,
            $id,
            (int) $row['youth_id'],
            $activityName,
            $values['status'],
            $remarks
        );

        $action = match ($values['status']) {
            'approved' => 'application.approved',
            'rejected' => 'application.rejected',
            'needs_resubmission' => 'application.needs_resubmission',
            default => 'application.reviewed',
        };
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            $action,
            'application',
            'Set application status to ' . $values['status'],
            ['youthId' => (int) $row['youth_id'], 'entityType' => 'application', 'entityId' => $id]
        );

        return $this->get($organizationId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function reviewSubmission(int $organizationId, int $id, int $reviewerId, array $input, array $actor = []): array
    {
        $row = $this->requireSubmission($organizationId, $id);
        $values = ApplicationValidator::normalizeSubmissionReview($input);
        $errors = ApplicationValidator::validateSubmissionReview($values);
        if ($errors) {
            Json::validation($errors);
        }

        $status = $values['status'] ?? $row['status'];
        $remarks = $values['remarks'] ?? $row['remarks'];
        $this->pdo->prepare(
            'UPDATE application_submissions
             SET status = :status,
                 remarks = :remarks,
                 reviewed_at = NOW(),
                 reviewed_by = :reviewed_by
             WHERE id = :id AND organization_id = :org'
        )->execute([
            'status' => $status,
            'remarks' => $remarks,
            'reviewed_by' => $reviewerId,
            'id' => $id,
            'org' => $organizationId,
        ]);

        if ($status === 'needs_resubmission') {
            $this->pdo->prepare(
                "UPDATE applications
                 SET status = 'needs_resubmission',
                     reviewed_at = NOW(),
                     reviewed_by = :reviewed_by
                 WHERE id = :id AND organization_id = :org AND status IN ('pending', 'needs_resubmission')"
            )->execute([
                'reviewed_by' => $reviewerId,
                'id' => (int) $row['application_id'],
                'org' => $organizationId,
            ]);
            $application = $this->requireApplication($organizationId, (int) $row['application_id']);
            $linkedAssistance = (int) ($application['assistance_id'] ?? 0);
            $activityName = $linkedAssistance > 0
                ? (string) $this->requireAssistance($organizationId, $linkedAssistance)['name']
                : (string) $this->requireActivity($organizationId, (int) ($application['program_id'] ?? 0))['name'];
            $this->notifyYouthOfApplicationStatus(
                $organizationId,
                (int) $application['id'],
                (int) $application['youth_id'],
                $activityName,
                'needs_resubmission',
                $remarks
            );
        }

        $reviewed = $this->publicSubmission($this->requireSubmission($organizationId, $id));
        $action = $status === 'needs_resubmission' ? 'application.needs_resubmission' : 'requirement.reviewed';
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            $action,
            $status === 'needs_resubmission' ? 'application' : 'requirement',
            $status === 'needs_resubmission'
                ? 'Marked application as needing resubmission'
                : 'Reviewed requirement submission',
            [
                'entityType' => 'submission',
                'entityId' => $id,
                'youthId' => (int) ($row['youth_id'] ?? 0) ?: null,
            ]
        );
        return $reviewed;
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array<string, mixed>
     */
    public function storeYouthRequirementFile(
        int $organizationId,
        int $youthId,
        int $applicationId,
        int $requirementId,
        ?array $file,
        array $actor = []
    ): array {
        $this->syncOpenRequirementSlots($organizationId, $youthId, $applicationId);
        $row = $this->ownedSubmission($organizationId, $youthId, $applicationId, $requirementId);
        $this->assertRequirementEditable($row);
        $stored = $this->files->store($file, (string) ($row['accepts'] ?? ''));
        $previous = (string) ($row['file_ref'] ?? '');
        $nextStatus = in_array((string) $row['status'], ['missing', 'needs_resubmission', 'verified'], true)
            || (string) ($row['application_status'] ?? '') === 'rejected'
            ? 'submitted'
            : (string) $row['status'];
        try {
            $this->pdo->prepare(
                'UPDATE application_submissions
                 SET file_name = :file_name,
                     file_type = :file_type,
                     file_size = :file_size,
                     file_ref = :file_ref,
                     status = :status
                 WHERE id = :id AND organization_id = :org AND application_id = :application_id'
            )->execute([
                'file_name' => $stored['name'],
                'file_type' => $stored['type'],
                'file_size' => $stored['size'],
                'file_ref' => $stored['storageName'],
                'status' => $nextStatus,
                'id' => (int) $row['id'],
                'org' => $organizationId,
                'application_id' => $applicationId,
            ]);
        } catch (\Throwable $e) {
            $this->files->discard($stored['storageName']);
            throw $e;
        }
        if ($previous !== '' && $previous !== $stored['storageName']) {
            $this->files->discard($previous);
        }
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'requirement.uploaded',
            'requirement',
            'Uploaded a document for requirement ' . (string) ($row['requirement_name'] ?? $requirementId)
                . ' on application #' . $applicationId,
            ['youthId' => $youthId, 'entityType' => 'submission', 'entityId' => (int) $row['id']]
        );
        return $this->publicSubmission($this->requireSubmission($organizationId, (int) $row['id']));
    }

    /**
     * @return array<string, mixed>
     */
    public function removeYouthRequirementFile(
        int $organizationId,
        int $youthId,
        int $applicationId,
        int $requirementId,
        array $actor = []
    ): array {
        $this->syncOpenRequirementSlots($organizationId, $youthId, $applicationId);
        $row = $this->ownedSubmission($organizationId, $youthId, $applicationId, $requirementId);
        $this->assertRequirementEditable($row);
        $previous = (string) ($row['file_ref'] ?? '');
        if (!$this->files->isStoredName($previous)) {
            Json::error('NOT_FOUND', 'No document is stored for this requirement.', 404);
        }
        $nextStatus = (string) $row['status'] === 'submitted' ? 'missing' : (string) $row['status'];
        $this->pdo->prepare(
            "UPDATE application_submissions
             SET file_name = '',
                 file_type = '',
                 file_size = 0,
                 file_ref = '',
                 status = :status
             WHERE id = :id AND organization_id = :org AND application_id = :application_id"
        )->execute([
            'status' => $nextStatus,
            'id' => (int) $row['id'],
            'org' => $organizationId,
            'application_id' => $applicationId,
        ]);
        $this->files->discard($previous);
        ActivityLog::recordSk(
            $this->pdo,
            $organizationId,
            $actor,
            'requirement.file_removed',
            'requirement',
            'Removed the document for requirement ' . (string) ($row['requirement_name'] ?? $requirementId)
                . ' on application #' . $applicationId,
            ['youthId' => $youthId, 'entityType' => 'submission', 'entityId' => (int) $row['id']]
        );
        return $this->publicSubmission($this->requireSubmission($organizationId, (int) $row['id']));
    }

    public function sendYouthRequirementFile(
        int $organizationId,
        int $youthId,
        int $applicationId,
        int $requirementId,
        bool $attachment
    ): never {
        $row = $this->ownedSubmission($organizationId, $youthId, $applicationId, $requirementId);
        $this->sendStoredSubmission($row, $attachment);
    }

    public function sendOrganizationRequirementFile(
        int $organizationId,
        int $applicationId,
        int $requirementId,
        bool $attachment
    ): never {
        $row = $this->submissionForApplication($organizationId, $applicationId, $requirementId);
        $this->sendStoredSubmission($row, $attachment);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertRequirementEditable(array $row): void
    {
        $applicationStatus = (string) ($row['application_status'] ?? '');
        $reapplying = $applicationStatus === 'rejected';
        if (!in_array($applicationStatus, ['pending', 'needs_resubmission', 'rejected'], true)) {
            Json::error('VALIDATION_ERROR', 'This application can no longer be changed.', 422);
        }
        $status = (string) ($row['status'] ?? '');
        $editable = ['missing', 'submitted', 'needs_resubmission'];
        if ($reapplying) {
            $editable[] = 'verified';
        }
        if (!in_array($status, $editable, true)) {
            Json::error('VALIDATION_ERROR', 'This requirement can no longer be changed.', 422);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sendStoredSubmission(array $row, bool $attachment): never
    {
        $storageName = (string) ($row['file_ref'] ?? '');
        if (!$this->files->isStoredName($storageName)) {
            Json::error('NOT_FOUND', 'No document is stored for this requirement.', 404);
        }
        $this->files->send(
            $storageName,
            (string) ($row['file_name'] ?? ''),
            (string) ($row['file_type'] ?? ''),
            $attachment
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ownedSubmission(int $organizationId, int $youthId, int $applicationId, int $requirementId): array
    {
        $row = $this->submissionForApplication($organizationId, $applicationId, $requirementId);
        if ((int) ($row['youth_id'] ?? 0) !== $youthId) {
            Json::error('NOT_FOUND', 'This application does not exist.', 404);
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function submissionForApplication(int $organizationId, int $applicationId, int $requirementId): array
    {
        if ($applicationId < 1 || $requirementId < 1) {
            Json::error('NOT_FOUND', 'This application requirement does not exist.', 404);
        }
        $stmt = $this->pdo->prepare(
            'SELECT s.*, a.youth_id, a.status AS application_status,
                    r.name AS requirement_name, r.required AS requirement_required, r.accepts
             FROM application_submissions s
             INNER JOIN applications a
                ON a.id = s.application_id AND a.organization_id = s.organization_id
             INNER JOIN assistance_requirements r ON r.id = s.requirement_id
             WHERE s.organization_id = :org
               AND s.application_id = :application_id
               AND s.requirement_id = :requirement_id
             LIMIT 1'
        );
        $stmt->execute([
            'org' => $organizationId,
            'application_id' => $applicationId,
            'requirement_id' => $requirementId,
        ]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This application requirement does not exist.', 404);
        }
        return $row;
    }

    /**
     * Adds submission slots for requirements created after the youth applied.
     * Approved and withdrawn applications are left unchanged.
     */
    public function syncOpenRequirementSlots(int $organizationId, int $youthId, int $applicationId = 0): void
    {
        $sql = "INSERT INTO application_submissions (
                    organization_id, application_id, requirement_id, status
                )
                SELECT a.organization_id, a.id, r.id, 'missing'
                FROM applications a
                INNER JOIN assistance_requirements r
                  ON r.organization_id = a.organization_id
                 AND (
                      (a.program_id IS NOT NULL AND a.program_id > 0 AND r.program_id = a.program_id)
                      OR (
                        (a.program_id IS NULL OR a.program_id = 0)
                        AND a.assistance_id IS NOT NULL
                        AND a.assistance_id > 0
                        AND r.assistance_id = a.assistance_id
                      )
                 )
                LEFT JOIN application_submissions s
                  ON s.application_id = a.id AND s.requirement_id = r.id
                WHERE a.organization_id = :org
                  AND a.youth_id = :youth
                  AND a.status IN ('pending', 'needs_resubmission', 'rejected')
                  AND s.id IS NULL";
        $params = ['org' => $organizationId, 'youth' => $youthId];
        if ($applicationId > 0) {
            $sql .= ' AND a.id = :application_id';
            $params['application_id'] = $applicationId;
        }
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * @param list<array<string, mixed>> $provided
     */
    private function seedSubmissions(
        int $organizationId,
        int $applicationId,
        int $assistanceId,
        int $programId,
        array $provided
    ): void {
        $byReq = [];
        foreach ($provided as $item) {
            if ($item['requirementId'] > 0) {
                $byReq[$item['requirementId']] = $item;
            }
        }
        if ($programId > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM assistance_requirements
                 WHERE organization_id = :org AND program_id = :pid
                 ORDER BY id ASC'
            );
            $stmt->execute(['org' => $organizationId, 'pid' => $programId]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM assistance_requirements
                 WHERE organization_id = :org AND assistance_id = :aid
                 ORDER BY id ASC'
            );
            $stmt->execute(['org' => $organizationId, 'aid' => $assistanceId]);
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO application_submissions (
                organization_id, application_id, requirement_id, file_name, file_type,
                file_size, file_ref, status
            ) VALUES (
                :organization_id, :application_id, :requirement_id, :file_name, :file_type,
                :file_size, :file_ref, :status
            )'
        );
        foreach ($stmt->fetchAll() as $req) {
            $reqId = (int) $req['id'];
            $extra = $byReq[$reqId] ?? null;
            $hasFile = $extra && ($extra['fileName'] !== '' || $extra['fileRef'] !== '');
            $insert->execute([
                'organization_id' => $organizationId,
                'application_id' => $applicationId,
                'requirement_id' => $reqId,
                'file_name' => $extra['fileName'] ?? '',
                'file_type' => $extra['fileType'] ?? '',
                'file_size' => $extra['fileSize'] ?? 0,
                'file_ref' => $extra['fileRef'] ?? '',
                'status' => $hasFile ? 'submitted' : 'missing',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireApplication(int $organizationId, int $id): array
    {
        if ($id < 1) {
            Json::error('NOT_FOUND', 'This application does not exist in this organization.', 404);
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM applications WHERE id = :id AND organization_id = :org LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'org' => $organizationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This application does not exist in this organization.', 404);
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireSubmission(int $organizationId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, r.name AS requirement_name, r.required AS requirement_required, r.accepts
             FROM application_submissions s
             INNER JOIN assistance_requirements r ON r.id = s.requirement_id
             WHERE s.id = :id AND s.organization_id = :org
             LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'org' => $organizationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This application requirement does not exist in this organization.', 404);
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireAssistance(int $organizationId, int $id): array
    {
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicApplication(array $row, bool $detail): array
    {
        $youth = $this->youthInOrg((int) $row['organization_id'], (int) $row['youth_id']);
        $assistanceId = (int) ($row['assistance_id'] ?? 0);
        $programId = (int) ($row['program_id'] ?? 0);
        $payload = [
            'id' => (int) $row['id'],
            'assistanceId' => $assistanceId > 0 ? $assistanceId : null,
            'programId' => $programId > 0 ? $programId : null,
            'targetType' => $programId > 0 ? 'program' : 'assistance',
            'targetId' => $programId > 0 ? $programId : $assistanceId,
            'youthId' => (int) $row['youth_id'],
            'status' => $row['status'],
            'remarks' => $row['remarks'],
            'submittedAt' => $row['submitted_at'],
            'reviewedAt' => $row['reviewed_at'],
            'reviewedBy' => $this->publicReviewer($row['reviewed_by'] ?? null),
            'createdAt' => $row['created_at'],
            'youth' => $youth ? [
                'id' => (int) $youth['id'],
                'code' => $youth['code'],
                'firstName' => $youth['first_name'],
                'lastName' => $youth['last_name'],
                'birthDate' => $youth['birth_date'] ?? null,
                'level' => $youth['priority_level'],
                'archived' => (bool) $youth['archived'],
            ] : null,
        ];
        if ($assistanceId > 0) {
            $program = $this->requireAssistance((int) $row['organization_id'], $assistanceId);
            $payload['assistance'] = [
                'id' => (int) $program['id'],
                'name' => $program['name'],
                'category' => $program['category'],
                'status' => $program['status'],
                'slots' => $program['slots'] !== null ? (int) $program['slots'] : null,
                'deadline' => $program['deadline'],
            ];
            $payload['program'] = null;
        } else {
            $activity = $this->requireActivity((int) $row['organization_id'], $programId);
            $payload['assistance'] = null;
            $payload['program'] = [
                'id' => (int) $activity['id'],
                'name' => $activity['name'],
                'kind' => $activity['kind'],
                'category' => $activity['category'],
                'status' => $activity['status'],
            ];
        }
        if ($detail) {
            $payload['submissions'] = $this->submissionsFor((int) $row['organization_id'], (int) $row['id']);
        }
        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function submissionsFor(int $organizationId, int $applicationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, r.name AS requirement_name, r.required AS requirement_required, r.accepts
             FROM application_submissions s
             INNER JOIN assistance_requirements r ON r.id = s.requirement_id
             WHERE s.organization_id = :org AND s.application_id = :id
             ORDER BY s.id ASC'
        );
        $stmt->execute(['org' => $organizationId, 'id' => $applicationId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->publicSubmission($row);
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicSubmission(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'applicationId' => (int) $row['application_id'],
            'requirementId' => (int) $row['requirement_id'],
            'name' => $row['requirement_name'] ?? '',
            'required' => (bool) ($row['requirement_required'] ?? false),
            'accepts' => $row['accepts'] ?? '',
            'fileName' => $row['file_name'],
            'fileType' => $row['file_type'],
            'fileSize' => (int) $row['file_size'],
            'hasFile' => $this->files->isStoredName((string) ($row['file_ref'] ?? '')),
            'status' => $row['status'],
            'remarks' => $row['remarks'],
            'reviewedAt' => $row['reviewed_at'],
            'reviewedBy' => $this->publicReviewer($row['reviewed_by'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicReviewer(mixed $userId): ?array
    {
        $id = (int) $userId;
        if ($id < 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, first_name, last_name, email FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['id' => $id];
        }
        return [
            'id' => (int) $row['id'],
            'firstName' => $row['first_name'],
            'lastName' => $row['last_name'],
            'email' => $row['email'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $application
     */
    private function notifyApplicationResubmitted(int $organizationId, int $youthId, array $row, array $application): void
    {
        $assistanceId = (int) ($row['assistance_id'] ?? 0);
        $name = $assistanceId > 0
            ? (string) $this->requireAssistance($organizationId, $assistanceId)['name']
            : (string) $this->requireActivity($organizationId, (int) ($row['program_id'] ?? 0))['name'];
        $youth = $this->youthInOrg($organizationId, $youthId);
        $youthName = trim((string) (($youth['first_name'] ?? '') . ' ' . ($youth['last_name'] ?? '')));
        $id = (int) ($row['id'] ?? $application['id'] ?? 0);
        if ($id < 1) {
            return;
        }
        $youthMessage = 'Your application for "' . $name . '" was resubmitted.';
        $skMessage = ($youthName !== '' ? $youthName : 'A youth') . ' resubmitted an application for "' . $name . '".';
        $skNote = $this->notifications->notifySk($organizationId, [
            'type' => 'applications',
            'title' => 'Application resubmitted',
            'message' => $skMessage,
            'link' => '/sk/applications/' . $id,
            'related' => $name,
            'relatedEntityType' => 'application',
            'relatedEntityId' => $id,
        ]);
        $youthNote = $this->notifications->notifyYouth($organizationId, $youthId, [
            'type' => 'applications',
            'title' => 'Application resubmitted',
            'message' => $youthMessage,
            'link' => '/youth/applications/' . $id,
            'related' => $name,
            'relatedEntityType' => 'application',
            'relatedEntityId' => $id,
        ]);
        $this->notifications->deliverSmsOnce(
            $organizationId,
            'application.resubmitted',
            $id,
            is_array($youth) ? (string) ($youth['contact'] ?? '') : '',
            $youthMessage,
            isset($youthNote['id']) ? (int) $youthNote['id'] : null
        );
        $this->notifications->deliverSmsOnce(
            $organizationId,
            'application.resubmitted.received',
            $id,
            $this->organizationContact($organizationId),
            $skMessage,
            isset($skNote['id']) ? (int) $skNote['id'] : null
        );
        $this->notifications->deliverEmailOnce(
            $organizationId,
            'application.resubmitted',
            $id,
            is_array($youth) ? (string) ($youth['email'] ?? '') : '',
            'Application resubmitted',
            $youthMessage,
            $name,
            isset($youthNote['id']) ? (int) $youthNote['id'] : null
        );
        $this->notifications->deliverEmailOnce(
            $organizationId,
            'application.resubmitted.received',
            $id,
            $this->organizationEmail($organizationId),
            'Application resubmitted',
            $skMessage,
            $name,
            isset($skNote['id']) ? (int) $skNote['id'] : null
        );
    }

    private function notifyYouthOfApplicationStatus(
        int $organizationId,
        int $applicationId,
        int $youthId,
        string $name,
        string $status,
        string $remarks
    ): void {
        if (!in_array($status, ['approved', 'rejected', 'needs_resubmission'], true)) {
            return;
        }
        $copy = [
            'approved' => [
                'Application approved',
                'Congratulations! Your ' . $name . ' application has been approved.',
            ],
            'rejected' => [
                'Application rejected',
                'Your application for ' . $name . ' was not approved' . ($remarks !== '' ? ': ' . $remarks : '.'),
            ],
            'needs_resubmission' => [
                'Requirements need resubmission',
                'Some requirements for ' . $name . ' need to be replaced.',
            ],
        ];
        $youthNote = $this->notifications->notifyYouth($organizationId, $youthId, [
            'type' => 'applications',
            'title' => $copy[$status][0],
            'message' => $copy[$status][1],
            'link' => '/youth/applications/' . $applicationId,
            'related' => $name,
            'relatedEntityType' => 'application',
            'relatedEntityId' => $applicationId,
        ]);
        $youth = $this->youthInOrg($organizationId, $youthId);
        $this->notifications->deliverSmsOnce(
            $organizationId,
            'application.' . $status,
            $applicationId,
            is_array($youth) ? (string) ($youth['contact'] ?? '') : '',
            $copy[$status][1],
            isset($youthNote['id']) ? (int) $youthNote['id'] : null
        );
        $this->notifications->deliverEmailOnce(
            $organizationId,
            'application.' . $status,
            $applicationId,
            is_array($youth) ? (string) ($youth['email'] ?? '') : '',
            $copy[$status][0],
            $copy[$status][1],
            $name,
            isset($youthNote['id']) ? (int) $youthNote['id'] : null
        );
    }

    private function organizationContact(int $organizationId): string
    {
        $stmt = $this->pdo->prepare('SELECT contact FROM organizations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $organizationId]);
        $contact = $stmt->fetchColumn();
        return is_string($contact) ? $contact : '';
    }

    private function organizationEmail(int $organizationId): string
    {
        $stmt = $this->pdo->prepare('SELECT email FROM organizations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $organizationId]);
        $email = $stmt->fetchColumn();
        return is_string($email) ? $email : '';
    }
}
