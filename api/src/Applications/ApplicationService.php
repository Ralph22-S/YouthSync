<?php
declare(strict_types=1);

namespace YouthSync\Applications;

use PDO;
use PDOException;
use YouthSync\Assistance\AssistanceService;
use YouthSync\Http\Json;
use YouthSync\Notifications\NotificationService;

final class ApplicationService
{
    public function __construct(
        private PDO $pdo,
        private AssistanceService $assistance,
        private NotificationService $notifications,
    ) {
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
        $q = trim((string) ($query['q'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['perPage'] ?? $query['per_page'] ?? 50);
        if ($perPage < 1) {
            $perPage = 50;
        }
        if ($perPage > 100) {
            $perPage = 100;
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
        if ($status !== '' && $status !== 'all') {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }
        if ($q !== '') {
            $where[] = '(y.first_name LIKE :q OR y.last_name LIKE :q OR p.name LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $from = 'applications a
                 INNER JOIN youth y ON y.id = a.youth_id
                 INNER JOIN assistance_programs p ON p.id = a.assistance_id';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$from} WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil(($total ?: 1) / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT a.* FROM {$from} WHERE {$sqlWhere}
             ORDER BY a.submitted_at DESC, a.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->publicApplication($row, true);
        }

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => $pages,
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
    public function create(int $organizationId, array $input): array
    {
        $values = ApplicationValidator::normalizeCreate($input);
        $errors = ApplicationValidator::validateCreate($values);
        if ($errors) {
            Json::validation($errors);
        }

        $program = $this->requireAssistance($organizationId, $values['assistanceId']);
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
                    organization_id, assistance_id, youth_id, status, remarks, submitted_at
                ) VALUES (
                    :organization_id, :assistance_id, :youth_id, :status, :remarks, NOW()
                )'
            )->execute([
                'organization_id' => $organizationId,
                'assistance_id' => $values['assistanceId'],
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
                    'This youth already has an application for this assistance program.',
                    409
                );
            }
            throw $e;
        }

        $id = (int) $this->pdo->lastInsertId();
        $this->seedSubmissions($organizationId, $id, $values['assistanceId'], $values['submissions']);
        $youthName = trim(($youth['first_name'] ?? '') . ' ' . ($youth['last_name'] ?? ''));
        $this->notifications->notifySk($organizationId, [
            'type' => 'applications',
            'title' => 'New application received',
            'message' => $youthName . ' applied for "' . $program['name'] . '".',
            'link' => '/sk/applications/' . $id,
            'related' => $program['name'],
            'relatedEntityType' => 'application',
            'relatedEntityId' => $id,
        ]);
        $this->notifications->deliverSmsOnce(
            $organizationId,
            'application.submitted',
            $id,
            (string) ($youth['contact'] ?? ''),
            'Your application for "' . $program['name'] . '" was submitted.',
            null
        );
        $this->notifications->deliverSmsOnce(
            $organizationId,
            'application.received',
            $id,
            $this->organizationContact($organizationId),
            $youthName . ' applied for "' . $program['name'] . '".',
            null
        );
        return $this->get($organizationId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $organizationId, int $id, array $input): array
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
        }
        return $this->get($organizationId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function setStatus(int $organizationId, int $id, int $reviewerId, array $input): array
    {
        $row = $this->requireApplication($organizationId, $id);
        $values = ApplicationValidator::normalizeStatus($input);
        $errors = ApplicationValidator::validateStatus($values);
        if ($errors) {
            Json::validation($errors);
        }

        if ($values['status'] === 'approved') {
            $this->assistance->saveBeneficiary($organizationId, (int) $row['assistance_id'], [
                'youthId' => (int) $row['youth_id'],
                'status' => 'approved',
                'remarks' => $values['remarks'] !== '' ? $values['remarks'] : $row['remarks'],
            ]);
        } elseif (in_array($values['status'], ['rejected', 'withdrawn'], true)
            && $row['status'] === 'approved') {
            $this->assistance->saveBeneficiary($organizationId, (int) $row['assistance_id'], [
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

        $this->notifyYouthOfApplicationStatus(
            $organizationId,
            $id,
            (int) $row['youth_id'],
            (int) $row['assistance_id'],
            $values['status'],
            $remarks
        );

        return $this->get($organizationId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function reviewSubmission(int $organizationId, int $id, int $reviewerId, array $input): array
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
            $this->notifyYouthOfApplicationStatus(
                $organizationId,
                (int) $application['id'],
                (int) $application['youth_id'],
                (int) $application['assistance_id'],
                'needs_resubmission',
                $remarks
            );
        }

        return $this->publicSubmission($this->requireSubmission($organizationId, $id));
    }

    /**
     * @param list<array<string, mixed>> $provided
     */
    private function seedSubmissions(int $organizationId, int $applicationId, int $assistanceId, array $provided): void
    {
        $byReq = [];
        foreach ($provided as $item) {
            if ($item['requirementId'] > 0) {
                $byReq[$item['requirementId']] = $item;
            }
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM assistance_requirements
             WHERE organization_id = :org AND assistance_id = :aid
             ORDER BY id ASC'
        );
        $stmt->execute(['org' => $organizationId, 'aid' => $assistanceId]);
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
        $program = $this->requireAssistance((int) $row['organization_id'], (int) $row['assistance_id']);
        $payload = [
            'id' => (int) $row['id'],
            'assistanceId' => (int) $row['assistance_id'],
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
                'level' => $youth['priority_level'],
                'archived' => (bool) $youth['archived'],
            ] : null,
            'assistance' => [
                'id' => (int) $program['id'],
                'name' => $program['name'],
                'category' => $program['category'],
                'status' => $program['status'],
                'slots' => $program['slots'] !== null ? (int) $program['slots'] : null,
                'deadline' => $program['deadline'],
            ],
        ];
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
            'fileRef' => $row['file_ref'],
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

    private function notifyYouthOfApplicationStatus(
        int $organizationId,
        int $applicationId,
        int $youthId,
        int $assistanceId,
        string $status,
        string $remarks
    ): void {
        if (!in_array($status, ['approved', 'rejected', 'needs_resubmission'], true)) {
            return;
        }
        $program = $this->requireAssistance($organizationId, $assistanceId);
        $name = (string) $program['name'];
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
        $this->notifications->notifyYouth($organizationId, $youthId, [
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
            null
        );
    }

    private function organizationContact(int $organizationId): string
    {
        $stmt = $this->pdo->prepare('SELECT contact FROM organizations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $organizationId]);
        $contact = $stmt->fetchColumn();
        return is_string($contact) ? $contact : '';
    }
}
