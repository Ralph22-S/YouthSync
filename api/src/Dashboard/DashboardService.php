<?php
declare(strict_types=1);

namespace YouthSync\Dashboard;

use DateTimeImmutable;
use PDO;
use YouthSync\Http\Json;
use YouthSync\Http\Pagination;

final class DashboardService
{
    private const REPORT_TYPES = ['youth', 'programs', 'assistance', 'applications', 'attendance'];

    /** Filters each report type accepts (others are ignored and echoed as null). */
    private const REPORT_FILTERS = [
        'youth' => ['status' => ['active', 'archived']],
        'programs' => [
            'status' => ['draft', 'published', 'ongoing', 'completed', 'archived'],
            'kind' => ['program', 'event'],
        ],
        'assistance' => [
            'status' => ['draft', 'open', 'full', 'closed', 'archived'],
            'category' => ['scholarship', 'financial', 'other'],
        ],
        'applications' => [
            'status' => ['pending', 'approved', 'rejected', 'withdrawn', 'needs_resubmission'],
            'kind' => ['program', 'event', 'assistance'],
        ],
        'attendance' => [
            'status' => ['pending', 'present', 'absent', 'excused'],
            'kind' => ['program', 'event'],
        ],
    ];

    private const REPORT_FILTER_MESSAGES = [
        'youth' => ['status' => 'Youth report status must be active or archived.'],
        'programs' => ['status' => 'Program status is not valid.', 'kind' => 'Kind must be program or event.'],
        'assistance' => [
            'status' => 'Assistance status is not valid.',
            'category' => 'Category must be scholarship, financial or other.',
        ],
        'applications' => [
            'status' => 'Application status is not valid.',
            'kind' => 'Kind must be program, event or assistance.',
        ],
        'attendance' => ['status' => 'Attendance status is not valid.', 'kind' => 'Kind must be program or event.'],
    ];

    /** [document title, worksheet name] */
    private const REPORT_TITLES = [
        'youth' => ['Youth Report', 'Youth Report'],
        'programs' => ['Programs and Events Report', 'Programs Report'],
        'assistance' => ['Assistance Report', 'Assistance Report'],
        'applications' => ['Applications Report', 'Applications Report'],
        'attendance' => ['Attendance Report', 'Attendance Report'],
    ];

    /** Program / event / assistance link of an application (requires the applications scope join). */
    private const APPLICATION_TARGET_SQL = "CASE WHEN a.assistance_id IS NOT NULL THEN 'assistance' WHEN p.kind = 'event' THEN 'event' ELSE 'program' END";

    /**
     * Export columns per report type (key, header label, cell type).
     * Family income, e-mail, account and priority-reason fields are not exported.
     */
    private const REPORT_COLUMNS = [
        'youth' => [
            ['key' => 'code', 'label' => 'Code', 'type' => 'string'],
            ['key' => 'last_name', 'label' => 'Last name', 'type' => 'string'],
            ['key' => 'first_name', 'label' => 'First name', 'type' => 'string'],
            ['key' => 'middle_name', 'label' => 'Middle name', 'type' => 'string'],
            ['key' => 'birth_date', 'label' => 'Birth date', 'type' => 'date'],
            ['key' => 'age', 'label' => 'Age', 'type' => 'int'],
            ['key' => 'gender', 'label' => 'Gender', 'type' => 'string'],
            ['key' => 'civil_status', 'label' => 'Civil status', 'type' => 'string'],
            ['key' => 'address', 'label' => 'Address', 'type' => 'string'],
            ['key' => 'contact', 'label' => 'Contact number', 'type' => 'string'],
            ['key' => 'education_status', 'label' => 'Education status', 'type' => 'string'],
            ['key' => 'education', 'label' => 'Education level', 'type' => 'string'],
            ['key' => 'employment', 'label' => 'Employment', 'type' => 'string'],
            ['key' => 'guardian_name', 'label' => 'Guardian', 'type' => 'string'],
            ['key' => 'priority_level', 'label' => 'Priority', 'type' => 'string'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
            ['key' => 'added', 'label' => 'Date added', 'type' => 'date'],
        ],
        'programs' => [
            ['key' => 'id', 'label' => 'ID', 'type' => 'int'],
            ['key' => 'kind', 'label' => 'Kind', 'type' => 'string'],
            ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
            ['key' => 'category', 'label' => 'Category', 'type' => 'string'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
            ['key' => 'scheduled_on', 'label' => 'Scheduled on', 'type' => 'date'],
            ['key' => 'starts_at', 'label' => 'Starts', 'type' => 'string'],
            ['key' => 'ends_at', 'label' => 'Ends', 'type' => 'string'],
            ['key' => 'location', 'label' => 'Location', 'type' => 'string'],
            ['key' => 'max_participants', 'label' => 'Max participants', 'type' => 'int'],
            ['key' => 'registration_deadline', 'label' => 'Registration deadline', 'type' => 'date'],
            ['key' => 'registrations', 'label' => 'Registrations', 'type' => 'int'],
            ['key' => 'present', 'label' => 'Present', 'type' => 'int'],
        ],
        'assistance' => [
            ['key' => 'id', 'label' => 'ID', 'type' => 'int'],
            ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
            ['key' => 'category', 'label' => 'Category', 'type' => 'string'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
            ['key' => 'slots', 'label' => 'Slots', 'type' => 'int'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'number'],
            ['key' => 'deadline', 'label' => 'Deadline', 'type' => 'date'],
            ['key' => 'beneficiaries', 'label' => 'Beneficiaries', 'type' => 'int'],
            ['key' => 'awarded', 'label' => 'Awarded (approved + released)', 'type' => 'int'],
            ['key' => 'available', 'label' => 'Available slots', 'type' => 'int'],
            ['key' => 'created', 'label' => 'Created', 'type' => 'date'],
        ],
        'applications' => [
            ['key' => 'id', 'label' => 'ID', 'type' => 'int'],
            ['key' => 'submitted_at', 'label' => 'Submitted', 'type' => 'datetime'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
            ['key' => 'youth_code', 'label' => 'Youth code', 'type' => 'string'],
            ['key' => 'youth_name', 'label' => 'Youth name', 'type' => 'string'],
            ['key' => 'target_type', 'label' => 'Applied for', 'type' => 'string'],
            ['key' => 'target_name', 'label' => 'Program / assistance', 'type' => 'string'],
            ['key' => 'reviewed_at', 'label' => 'Reviewed', 'type' => 'datetime'],
            ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'string'],
        ],
        'attendance' => [
            ['key' => 'id', 'label' => 'ID', 'type' => 'int'],
            ['key' => 'program', 'label' => 'Program / event', 'type' => 'string'],
            ['key' => 'kind', 'label' => 'Kind', 'type' => 'string'],
            ['key' => 'scheduled_on', 'label' => 'Scheduled on', 'type' => 'date'],
            ['key' => 'youth_code', 'label' => 'Youth code', 'type' => 'string'],
            ['key' => 'youth_name', 'label' => 'Youth name', 'type' => 'string'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
            ['key' => 'source', 'label' => 'Source', 'type' => 'string'],
            ['key' => 'scanned_at', 'label' => 'Scanned at', 'type' => 'datetime'],
            ['key' => 'confirmed_at', 'label' => 'Confirmed at', 'type' => 'datetime'],
        ],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function dashboard(int $organizationId, int $userId, array $query): array
    {
        // Organization and user always come from the session; client-supplied ids are ignored.
        unset(
            $query['organization_id'],
            $query['orgId'],
            $query['organizationId'],
            $query['org_id'],
            $query['user_id'],
            $query['userId']
        );

        $youthTotals = $this->youthTotals($organizationId);
        $programByStatus = $this->groupedCount('programs', 'status', $organizationId);
        $programByKind = $this->groupedCount('programs', 'kind', $organizationId);
        $assistanceByStatus = $this->groupedCount('assistance_programs', 'status', $organizationId);
        $assistanceByCategory = $this->groupedCount('assistance_programs', 'category', $organizationId);
        $beneficiaryByStatus = $this->groupedCount('beneficiaries', 'status', $organizationId);
        $applicationByStatus = $this->groupedCount('applications', 'status', $organizationId);
        $attendanceByStatus = $this->groupedCount('attendance', 'status', $organizationId);
        $applicationTargets = $this->applicationTargetCounts($organizationId);

        $activeYouthWhere = 'organization_id = :org AND archived = 0';
        $activeParams = ['org' => $organizationId];

        return [
            'summary' => [
                'youth' => [
                    'total' => $youthTotals['total'],
                    'active' => $youthTotals['active'],
                    'archived' => $youthTotals['archived'],
                ],
                'programs' => [
                    'total' => $this->scalarCount('programs', $organizationId),
                    'draft' => $programByStatus['draft'] ?? 0,
                    'published' => $programByStatus['published'] ?? 0,
                    'ongoing' => $programByStatus['ongoing'] ?? 0,
                    'completed' => $programByStatus['completed'] ?? 0,
                    'archived' => $programByStatus['archived'] ?? 0,
                    'upcoming' => $this->upcomingProgramCount($organizationId),
                    // kind split: the programs table holds both programs and events.
                    'programs' => $programByKind['program'] ?? 0,
                    'events' => $programByKind['event'] ?? 0,
                ],
                'assistance' => [
                    'total' => $this->scalarCount('assistance_programs', $organizationId),
                    'open' => $assistanceByStatus['open'] ?? 0,
                    'active' => ($assistanceByStatus['draft'] ?? 0)
                        + ($assistanceByStatus['open'] ?? 0)
                        + ($assistanceByStatus['full'] ?? 0),
                ],
                'beneficiaries' => [
                    'total' => $this->scalarCount('beneficiaries', $organizationId),
                    'approved' => $beneficiaryByStatus['approved'] ?? 0,
                    'released' => $beneficiaryByStatus['released'] ?? 0,
                    'applied' => $beneficiaryByStatus['applied'] ?? 0,
                    'rejected' => $beneficiaryByStatus['rejected'] ?? 0,
                ],
                'applications' => [
                    'total' => $this->scalarCount('applications', $organizationId),
                    'pending' => $applicationByStatus['pending'] ?? 0,
                    'approved' => $applicationByStatus['approved'] ?? 0,
                    'rejected' => $applicationByStatus['rejected'] ?? 0,
                    'withdrawn' => $applicationByStatus['withdrawn'] ?? 0,
                    'needsResubmission' => $applicationByStatus['needs_resubmission'] ?? 0,
                    // Program/event registrations are applications with a program_id
                    // (rejected and withdrawn excluded); assistance applications carry an assistance_id.
                    'programRegistrations' => $applicationTargets['programRegistrations'],
                    'assistanceApplications' => $applicationTargets['assistanceApplications'],
                ],
                'notifications' => [
                    'unread' => $this->unreadNotificationCount($organizationId, $userId),
                ],
                'attendance' => [
                    'total' => $this->scalarCount('attendance', $organizationId),
                    'present' => $attendanceByStatus['present'] ?? 0,
                    'absent' => $attendanceByStatus['absent'] ?? 0,
                    'excused' => $attendanceByStatus['excused'] ?? 0,
                    'pending' => $attendanceByStatus['pending'] ?? 0,
                ],
            ],
            'highlights' => [
                'families' => $this->familyCount($organizationId),
                'unemployedYouth' => $this->countWhere(
                    'youth',
                    $activeYouthWhere . ' AND employment = :employment',
                    $activeParams + ['employment' => 'Unemployed']
                ),
                'unemployedParents' => $this->countWhere(
                    'youth',
                    $activeYouthWhere . ' AND guardian_employment = :employment',
                    $activeParams + ['employment' => 'Unemployed']
                ),
                'highPriorityYouth' => $this->countWhere(
                    'youth',
                    $activeYouthWhere . ' AND priority_level = :level',
                    $activeParams + ['level' => 'High']
                ),
                'scholarshipBeneficiaries' => $this->scholarshipBeneficiaryCount($organizationId),
                'upcomingPrograms' => $this->upcomingProgramCount($organizationId),
                'activeAssistance' => ($assistanceByStatus['draft'] ?? 0)
                    + ($assistanceByStatus['open'] ?? 0)
                    + ($assistanceByStatus['full'] ?? 0),
            ],
            'youth' => [
                'ageGroups' => $this->youthAgeGroups($organizationId),
                'gender' => $this->groupedCount('youth', 'gender', $organizationId, 'archived = 0'),
                'education' => $this->groupedCount('youth', 'education', $organizationId, 'archived = 0'),
                'employment' => $this->groupedCount('youth', 'employment', $organizationId, 'archived = 0'),
                'priority' => $this->groupedCount('youth', 'priority_level', $organizationId, 'archived = 0'),
            ],
            'programs' => [
                'byStatus' => $programByStatus,
                'byKind' => $programByKind,
                'upcoming' => $this->upcomingPrograms($organizationId),
            ],
            'assistance' => [
                'byStatus' => $assistanceByStatus,
                'byCategory' => $assistanceByCategory,
                'beneficiariesByStatus' => $beneficiaryByStatus,
                'beneficiariesByCategory' => $this->beneficiariesByCategory($organizationId),
                'awardedBeneficiariesByCategory' => $this->beneficiariesByCategory($organizationId, true),
                'slots' => $this->assistanceSlots($organizationId),
            ],
            'applications' => [
                'byStatus' => $applicationByStatus,
            ],
            'attendance' => [
                'byStatus' => $attendanceByStatus,
            ],
            'recent' => $this->recentActivity($organizationId, $userId),
            'newestYouth' => $this->newestYouth($organizationId),
        ];
    }

    /**
     * JSON report: chart aggregates for the session organization. Uses the
     * same filter scope as reportExport(), so `export.rowCount` (= totals.total)
     * equals the number of rows in the CSV / XLSX for the same filters.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function report(int $organizationId, array $query): array
    {
        ['type' => $type, 'filters' => $filters] = $this->reportRequest($query);
        $scope = $this->reportScope($type, $organizationId, $filters);

        $report = match ($type) {
            'youth' => $this->youthReport($scope, $filters),
            'programs' => $this->programsReport($organizationId, $scope, $filters),
            'assistance' => $this->assistanceReport($organizationId, $scope, $filters),
            'applications' => $this->applicationsReport($scope, $filters),
            'attendance' => $this->attendanceReport($scope, $filters),
        };
        $report['export'] = [
            'rowCount' => (int) $report['totals']['total'],
            'columns' => array_map(static fn (array $c): string => $c['label'], self::REPORT_COLUMNS[$type]),
            'formats' => ['csv', 'xlsx'],
        ];
        return $report;
    }

    /**
     * Detail rows for CSV / XLSX exports (one row per record in the report
     * scope). Same type/filter validation and WHERE clause as report().
     *
     * @param array<string, mixed> $query
     * @return array{type:string,title:string,sheetName:string,organization:string,filters:array<string, ?string>,columns:list<array{key:string,label:string,type:string}>,rows:list<array<string, mixed>>}
     */
    public function reportExport(int $organizationId, array $query): array
    {
        ['type' => $type, 'filters' => $filters] = $this->reportRequest($query);
        $scope = $this->reportScope($type, $organizationId, $filters);

        $orgStmt = $this->pdo->prepare('SELECT name FROM organizations WHERE id = :id LIMIT 1');
        $orgStmt->execute(['id' => $organizationId]);

        return [
            'type' => $type,
            'title' => self::REPORT_TITLES[$type][0],
            'sheetName' => self::REPORT_TITLES[$type][1],
            'organization' => (string) ($orgStmt->fetchColumn() ?: ''),
            'filters' => $filters,
            'columns' => self::REPORT_COLUMNS[$type],
            'rows' => $this->reportRows($type, $scope),
        ];
    }

    public static function isReportType(string $type): bool
    {
        return in_array($type, self::REPORT_TYPES, true);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{type:string, filters:array<string, ?string>}
     */
    private function reportRequest(array $query): array
    {
        unset($query['organization_id'], $query['orgId'], $query['organizationId'], $query['org_id']);

        $type = strtolower(trim((string) ($query['type'] ?? $query['report'] ?? '')));
        if ($type === '') {
            Json::validation(['type' => 'Select a report type.']);
        }
        if (!in_array($type, self::REPORT_TYPES, true)) {
            Json::validation(['type' => 'Report type is not valid.']);
        }

        [$from, $to] = $this->parseDateRange($query);
        $status = trim((string) ($query['status'] ?? ''));
        $category = trim((string) ($query['category'] ?? ''));
        $kind = trim((string) ($query['kind'] ?? ''));

        $applies = self::REPORT_FILTERS[$type];
        $errors = [];
        if ($status !== '' && !in_array($status, $applies['status'], true)) {
            $errors['status'] = self::REPORT_FILTER_MESSAGES[$type]['status'];
        }
        if (isset($applies['kind']) && $kind !== '' && !in_array($kind, $applies['kind'], true)) {
            $errors['kind'] = self::REPORT_FILTER_MESSAGES[$type]['kind'];
        }
        if (isset($applies['category']) && $category !== '' && !in_array($category, $applies['category'], true)) {
            $errors['category'] = self::REPORT_FILTER_MESSAGES[$type]['category'];
        }
        if ($errors) {
            Json::validation($errors);
        }

        return [
            'type' => $type,
            'filters' => [
                'type' => $type,
                'from' => $from,
                'to' => $to,
                'status' => $status !== '' ? $status : null,
                'category' => isset($applies['category']) && $category !== '' ? $category : null,
                'kind' => isset($applies['kind']) && $kind !== '' ? $kind : null,
            ],
        ];
    }

    /**
     * Single FROM/WHERE per report type, shared by every aggregate and by the
     * export row query. Always scoped to the session organization.
     *
     * @param array<string, ?string> $filters
     * @return array{from:string, where:string, params:array<string, mixed>}
     */
    private function reportScope(string $type, int $organizationId, array $filters): array
    {
        $params = ['org' => $organizationId];
        $status = $filters['status'];
        $kind = $filters['kind'];
        $category = $filters['category'];

        switch ($type) {
            case 'youth':
                $from = 'youth y';
                $where = ['y.organization_id = :org'];
                if ($status === 'active') {
                    $where[] = 'y.archived = 0';
                } elseif ($status === 'archived') {
                    $where[] = 'y.archived = 1';
                }
                $dateColumn = 'DATE(y.created_at)';
                break;
            case 'programs':
                $from = 'programs p';
                $where = ['p.organization_id = :org'];
                if ($status !== null) {
                    $where[] = 'p.status = :status';
                    $params['status'] = $status;
                }
                if ($kind !== null) {
                    $where[] = 'p.kind = :kind';
                    $params['kind'] = $kind;
                }
                $dateColumn = 'p.scheduled_on';
                break;
            case 'assistance':
                $from = 'assistance_programs ap';
                $where = ['ap.organization_id = :org'];
                if ($status !== null) {
                    $where[] = 'ap.status = :status';
                    $params['status'] = $status;
                }
                if ($category !== null) {
                    $where[] = 'ap.category = :category';
                    $params['category'] = $category;
                }
                $dateColumn = 'DATE(ap.created_at)';
                break;
            case 'applications':
                $from = 'applications a LEFT JOIN programs p ON p.id = a.program_id AND p.organization_id = a.organization_id';
                $where = ['a.organization_id = :org'];
                if ($status !== null) {
                    $where[] = 'a.status = :status';
                    $params['status'] = $status;
                }
                if ($kind === 'assistance') {
                    $where[] = 'a.assistance_id IS NOT NULL';
                } elseif ($kind !== null) {
                    $where[] = 'a.program_id IS NOT NULL AND p.kind = :kind';
                    $params['kind'] = $kind;
                }
                $dateColumn = 'DATE(a.submitted_at)';
                break;
            default:
                $from = 'attendance at INNER JOIN programs p ON p.id = at.program_id AND p.organization_id = at.organization_id';
                $where = ['at.organization_id = :org'];
                if ($status !== null) {
                    $where[] = 'at.status = :status';
                    $params['status'] = $status;
                }
                if ($kind !== null) {
                    $where[] = 'p.kind = :kind';
                    $params['kind'] = $kind;
                }
                $dateColumn = 'DATE(COALESCE(at.confirmed_at, at.created_at))';
                break;
        }
        $this->appendDate($where, $params, $dateColumn, $filters['from'], $filters['to']);

        return ['from' => $from, 'where' => implode(' AND ', $where), 'params' => $params];
    }

    /**
     * @return array{total: int, active: int, archived: int}
     */
    private function youthTotals(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(archived = 0), 0) AS active,
                    COALESCE(SUM(archived = 1), 0) AS archived
             FROM youth WHERE organization_id = :org'
        );
        $stmt->execute(['org' => $organizationId]);
        $row = $stmt->fetch() ?: [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function youthAgeGroups(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CASE
                WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 15 AND 17 THEN '15-17'
                WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 18 AND 24 THEN '18-24'
                WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 25 AND 30 THEN '25-30'
                ELSE 'other'
             END AS bucket,
             COUNT(*) AS total
             FROM youth
             WHERE organization_id = :org AND archived = 0
             GROUP BY bucket"
        );
        $stmt->execute(['org' => $organizationId]);
        $out = ['15-17' => 0, '18-24' => 0, '25-30' => 0, 'other' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) $row['bucket'];
            $out[$key] = (int) $row['total'];
        }
        return $out;
    }

    private function familyCount(int $organizationId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT guardian_name) FROM youth
             WHERE organization_id = :org AND archived = 0 AND guardian_name <> ''"
        );
        $stmt->execute(['org' => $organizationId]);
        return (int) $stmt->fetchColumn();
    }

    private function scholarshipBeneficiaryCount(int $organizationId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM beneficiaries b
             INNER JOIN assistance_programs p ON p.id = b.assistance_id AND p.organization_id = b.organization_id
             WHERE b.organization_id = :org
               AND p.category = 'scholarship'
               AND b.status IN ('approved', 'released')"
        );
        $stmt->execute(['org' => $organizationId]);
        return (int) $stmt->fetchColumn();
    }

    private function upcomingProgramCount(int $organizationId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM programs
             WHERE organization_id = :org
               AND status IN ('published', 'ongoing')
               AND scheduled_on >= CURDATE()"
        );
        $stmt->execute(['org' => $organizationId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcomingPrograms(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, name, kind, status, scheduled_on, location, max_participants
             FROM programs
             WHERE organization_id = :org
               AND status IN ('published', 'ongoing')
               AND scheduled_on >= CURDATE()
             ORDER BY scheduled_on ASC, id ASC
             LIMIT 5"
        );
        $stmt->execute(['org' => $organizationId]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $att = $this->pdo->prepare(
            "SELECT program_id, COALESCE(SUM(status = 'present'), 0) AS present_count
             FROM attendance
             WHERE organization_id = ? AND program_id IN ({$placeholders})
             GROUP BY program_id"
        );
        $att->execute(array_merge([$organizationId], $ids));
        $present = [];
        foreach ($att->fetchAll() as $row) {
            $present[(int) $row['program_id']] = (int) $row['present_count'];
        }

        $reg = $this->pdo->prepare(
            "SELECT program_id, COUNT(*) AS registered_count
             FROM applications
             WHERE organization_id = ? AND program_id IN ({$placeholders})
               AND status NOT IN ('rejected', 'withdrawn')
             GROUP BY program_id"
        );
        $reg->execute(array_merge([$organizationId], $ids));
        $registered = [];
        foreach ($reg->fetchAll() as $row) {
            $registered[(int) $row['program_id']] = (int) $row['registered_count'];
        }

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $items[] = [
                'id' => $id,
                'name' => $row['name'],
                'kind' => $row['kind'],
                'status' => $row['status'],
                'scheduledOn' => $row['scheduled_on'],
                'location' => $row['location'],
                'maxParticipants' => $row['max_participants'] !== null ? (int) $row['max_participants'] : null,
                'presentCount' => $present[$id] ?? 0,
                'registeredCount' => $registered[$id] ?? 0,
            ];
        }
        return $items;
    }

    /**
     * @return array<string, int>
     */
    private function beneficiariesByCategory(int $organizationId, bool $awardedOnly = false): array
    {
        $awarded = $awardedOnly ? " AND b.status IN ('approved', 'released')" : '';
        $stmt = $this->pdo->prepare(
            "SELECT p.category, COUNT(*) AS total
             FROM beneficiaries b
             INNER JOIN assistance_programs p ON p.id = b.assistance_id AND p.organization_id = b.organization_id
             WHERE b.organization_id = :org{$awarded}
             GROUP BY p.category"
        );
        $stmt->execute(['org' => $organizationId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['category']] = (int) $row['total'];
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assistanceSlots(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.name, p.category, p.status, p.slots,
                    COALESCE(SUM(b.status IN ('approved', 'released')), 0) AS filled
             FROM assistance_programs p
             LEFT JOIN beneficiaries b
               ON b.assistance_id = p.id AND b.organization_id = p.organization_id
             WHERE p.organization_id = :org
             GROUP BY p.id, p.name, p.category, p.status, p.slots
             ORDER BY p.id DESC"
        );
        $stmt->execute(['org' => $organizationId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $slots = $row['slots'] !== null ? (int) $row['slots'] : null;
            $filled = (int) $row['filled'];
            $items[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'category' => $row['category'],
                'status' => $row['status'],
                'slots' => $slots,
                'filled' => $filled,
                'available' => $slots === null ? null : max(0, $slots - $filled),
            ];
        }
        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentActivity(int $organizationId, int $userId): array
    {
        $sql = "
            (
                SELECT 'youth' AS source, id, created_at AS occurred_at,
                       CONCAT('Youth record added: ', first_name, ' ', last_name) AS description
                FROM youth WHERE organization_id = :o1
            )
            UNION ALL
            (
                SELECT 'program' AS source, id, created_at AS occurred_at,
                       CONCAT('Activity saved: ', name) AS description
                FROM programs WHERE organization_id = :o2
            )
            UNION ALL
            (
                SELECT 'application' AS source, id, submitted_at AS occurred_at,
                       CONCAT('Application #', id, ' submitted') AS description
                FROM applications WHERE organization_id = :o3
            )
            UNION ALL
            (
                SELECT 'attendance' AS source, id, COALESCE(confirmed_at, created_at) AS occurred_at,
                       CONCAT('Attendance #', id, ' recorded') AS description
                FROM attendance WHERE organization_id = :o4
            )
            UNION ALL
            (
                SELECT 'notification' AS source, id, created_at AS occurred_at,
                       CONCAT('Notification: ', title) AS description
                FROM notifications
                WHERE organization_id = :o5 AND youth_id IS NULL
                  AND (user_id IS NULL OR user_id = :u5)
            )
            ORDER BY occurred_at DESC, id DESC
            LIMIT 6
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'o1' => $organizationId,
            'o2' => $organizationId,
            'o3' => $organizationId,
            'o4' => $organizationId,
            'o5' => $organizationId,
            'u5' => $userId,
        ]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'source' => $row['source'],
                'id' => (int) $row['id'],
                'description' => $row['description'],
                'at' => $row['occurred_at'],
            ];
        }
        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function newestYouth(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, first_name, last_name, created_at
             FROM youth
             WHERE organization_id = :org AND archived = 0
             ORDER BY created_at DESC, id DESC
             LIMIT 3'
        );
        $stmt->execute(['org' => $organizationId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'firstName' => $row['first_name'],
                'lastName' => $row['last_name'],
                'createdAt' => $row['created_at'],
            ];
        }
        return $items;
    }

    /**
     * Applications split by target. A program/event registration is an application
     * row with a program_id that is still live (not rejected or withdrawn).
     *
     * @return array{programRegistrations: int, assistanceApplications: int}
     */
    private function applicationTargetCounts(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(program_id IS NOT NULL AND status NOT IN ('rejected', 'withdrawn')), 0) AS program_registrations,
                    COALESCE(SUM(assistance_id IS NOT NULL), 0) AS assistance_applications
             FROM applications
             WHERE organization_id = :org"
        );
        $stmt->execute(['org' => $organizationId]);
        $row = $stmt->fetch() ?: [];
        return [
            'programRegistrations' => (int) ($row['program_registrations'] ?? 0),
            'assistanceApplications' => (int) ($row['assistance_applications'] ?? 0),
        ];
    }

    private function unreadNotificationCount(int $organizationId, int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM notifications
             WHERE organization_id = :org
               AND youth_id IS NULL
               AND (user_id IS NULL OR user_id = :userId)
               AND is_read = 0'
        );
        $stmt->execute(['org' => $organizationId, 'userId' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{from:string, where:string, params:array<string, mixed>} $scope
     * @param array<string, ?string> $filters
     * @return array<string, mixed>
     */
    private function youthReport(array $scope, array $filters): array
    {
        $totalsStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(y.archived = 0), 0) AS active,
                    COALESCE(SUM(y.archived = 1), 0) AS archived
             FROM {$scope['from']} WHERE {$scope['where']}"
        );
        $totalsStmt->execute($scope['params']);
        $totals = $totalsStmt->fetch() ?: [];

        $ageGroups = ['15-17' => 0, '18-24' => 0, '25-30' => 0, 'other' => 0];
        $ageStmt = $this->pdo->prepare(
            "SELECT CASE
                WHEN TIMESTAMPDIFF(YEAR, y.birth_date, CURDATE()) BETWEEN 15 AND 17 THEN '15-17'
                WHEN TIMESTAMPDIFF(YEAR, y.birth_date, CURDATE()) BETWEEN 18 AND 24 THEN '18-24'
                WHEN TIMESTAMPDIFF(YEAR, y.birth_date, CURDATE()) BETWEEN 25 AND 30 THEN '25-30'
                ELSE 'other'
             END AS bucket,
             COUNT(*) AS total
             FROM {$scope['from']} WHERE {$scope['where']}
             GROUP BY bucket"
        );
        $ageStmt->execute($scope['params']);
        foreach ($ageStmt->fetchAll() as $row) {
            $ageGroups[(string) $row['bucket']] = (int) $row['total'];
        }

        $high = [];
        $highStmt = $this->pdo->prepare(
            "SELECT y.id, y.first_name, y.last_name, y.priority_level, y.priority_reasons
             FROM {$scope['from']}
             WHERE {$scope['where']} AND y.priority_level = 'High'
             ORDER BY y.id DESC
             LIMIT 50"
        );
        $highStmt->execute($scope['params']);
        foreach ($highStmt->fetchAll() as $row) {
            $reasons = $row['priority_reasons'];
            $decoded = is_string($reasons) ? json_decode($reasons, true) : $reasons;
            $firstReason = '';
            if (is_array($decoded) && $decoded !== []) {
                $first = reset($decoded);
                $firstReason = is_string($first) ? $first : '';
            }
            $high[] = [
                'id' => (int) $row['id'],
                'firstName' => $row['first_name'],
                'lastName' => $row['last_name'],
                'priorityLevel' => $row['priority_level'],
                'firstReason' => $firstReason,
            ];
        }

        return [
            'type' => 'youth',
            'filters' => $filters,
            'totals' => [
                'total' => (int) ($totals['total'] ?? 0),
                'active' => (int) ($totals['active'] ?? 0),
                'archived' => (int) ($totals['archived'] ?? 0),
            ],
            'series' => [
                'ageGroups' => $ageGroups,
                'gender' => $this->scopeGroup($scope, 'y.gender'),
                'education' => $this->scopeGroup($scope, 'y.education'),
                'employment' => $this->scopeGroup($scope, 'y.employment'),
                'priority' => $this->scopeGroup($scope, 'y.priority_level'),
            ],
            'highPriorityYouth' => $high,
        ];
    }

    /**
     * @param array{from:string, where:string, params:array<string, mixed>} $scope
     * @param array<string, ?string> $filters
     * @return array<string, mixed>
     */
    private function programsReport(int $organizationId, array $scope, array $filters): array
    {
        $totalsStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(p.kind = 'program'), 0) AS programs,
                    COALESCE(SUM(p.kind = 'event'), 0) AS events
             FROM {$scope['from']} WHERE {$scope['where']}"
        );
        $totalsStmt->execute($scope['params']);
        $totals = $totalsStmt->fetch() ?: [];

        // Registrations = program applications that are not rejected/withdrawn
        // (same rule as the dashboard), for the programs in scope.
        $regStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS registrations, COUNT(DISTINCT r.youth_id) AS participants
             FROM applications r
             WHERE r.organization_id = :regOrg
               AND r.status NOT IN ('rejected', 'withdrawn')
               AND r.program_id IN (SELECT p.id FROM {$scope['from']} WHERE {$scope['where']})"
        );
        $regStmt->execute($scope['params'] + ['regOrg' => $organizationId]);
        $reg = $regStmt->fetch() ?: [];

        $presentStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM attendance pa
             WHERE pa.organization_id = :orgAttend AND pa.status = 'present'
               AND pa.program_id IN (SELECT p.id FROM {$scope['from']} WHERE {$scope['where']})"
        );
        $presentStmt->execute($scope['params'] + ['orgAttend' => $organizationId]);

        return [
            'type' => 'programs',
            'filters' => $filters,
            'totals' => [
                'total' => (int) ($totals['total'] ?? 0),
                'programs' => (int) ($totals['programs'] ?? 0),
                'events' => (int) ($totals['events'] ?? 0),
                'registrations' => (int) ($reg['registrations'] ?? 0),
                'participants' => (int) ($reg['participants'] ?? 0),
                'presentAttendance' => (int) $presentStmt->fetchColumn(),
            ],
            'series' => [
                'byStatus' => $this->scopeGroup($scope, 'p.status'),
                'byKind' => $this->scopeGroup($scope, 'p.kind'),
            ],
        ];
    }

    /**
     * @param array{from:string, where:string, params:array<string, mixed>} $scope
     * @param array<string, ?string> $filters
     * @return array<string, mixed>
     */
    private function assistanceReport(int $organizationId, array $scope, array $filters): array
    {
        $total = $this->scopeCount($scope);

        // Beneficiaries of the assistance programs in scope (status/category/date filters apply).
        $inScope = "b.organization_id = :benOrg
               AND b.assistance_id IN (SELECT ap.id FROM {$scope['from']} WHERE {$scope['where']})";
        $benParams = $scope['params'] + ['benOrg' => $organizationId];
        $benStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(b.status IN ('approved', 'released')), 0) AS awarded
             FROM beneficiaries b WHERE {$inScope}"
        );
        $benStmt->execute($benParams);
        $ben = $benStmt->fetch() ?: [];

        $byBenStatus = [];
        $benStatusStmt = $this->pdo->prepare(
            "SELECT b.status AS bucket, COUNT(*) AS total FROM beneficiaries b WHERE {$inScope}
             GROUP BY b.status ORDER BY b.status"
        );
        $benStatusStmt->execute($benParams);
        foreach ($benStatusStmt->fetchAll() as $row) {
            $byBenStatus[(string) $row['bucket']] = (int) $row['total'];
        }

        $awardedByCategory = [];
        $awardedStmt = $this->pdo->prepare(
            "SELECT ap2.category AS bucket, COUNT(*) AS total
             FROM beneficiaries b
             INNER JOIN assistance_programs ap2 ON ap2.id = b.assistance_id AND ap2.organization_id = b.organization_id
             WHERE {$inScope} AND b.status IN ('approved', 'released')
             GROUP BY ap2.category ORDER BY ap2.category"
        );
        $awardedStmt->execute($benParams);
        foreach ($awardedStmt->fetchAll() as $row) {
            $awardedByCategory[(string) $row['bucket']] = (int) $row['total'];
        }

        $slots = [];
        foreach ($this->reportRows('assistance', $scope) as $row) {
            $slots[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'category' => $row['category'],
                'status' => $row['status'],
                'slots' => $row['slots'],
                'filled' => $row['awarded'],
                'available' => $row['available'],
            ];
        }

        return [
            'type' => 'assistance',
            'filters' => $filters,
            'totals' => [
                'total' => $total,
                'beneficiaries' => (int) ($ben['total'] ?? 0),
                'awarded' => (int) ($ben['awarded'] ?? 0),
            ],
            'series' => [
                'byStatus' => $this->scopeGroup($scope, 'ap.status'),
                'byCategory' => $this->scopeGroup($scope, 'ap.category'),
                'beneficiariesByStatus' => $byBenStatus,
                'awardedByCategory' => $awardedByCategory,
            ],
            'slots' => $slots,
        ];
    }

    /**
     * @param array{from:string, where:string, params:array<string, mixed>} $scope
     * @param array<string, ?string> $filters
     * @return array<string, mixed>
     */
    private function applicationsReport(array $scope, array $filters): array
    {
        $byTarget = ['program' => 0, 'event' => 0, 'assistance' => 0];
        foreach ($this->scopeGroup($scope, self::APPLICATION_TARGET_SQL) as $key => $count) {
            $byTarget[$key] = $count;
        }

        return [
            'type' => 'applications',
            'filters' => $filters,
            'totals' => [
                'total' => $this->scopeCount($scope),
            ],
            'series' => [
                'byStatus' => $this->scopeGroup($scope, 'a.status'),
                'byTarget' => $byTarget,
            ],
        ];
    }

    /**
     * @param array{from:string, where:string, params:array<string, mixed>} $scope
     * @param array<string, ?string> $filters
     * @return array<string, mixed>
     */
    private function attendanceReport(array $scope, array $filters): array
    {
        $byStatus = $this->scopeGroup($scope, 'at.status');

        return [
            'type' => 'attendance',
            'filters' => $filters,
            'totals' => [
                'total' => $this->scopeCount($scope),
                'present' => $byStatus['present'] ?? 0,
            ],
            'series' => [
                'byStatus' => $byStatus,
                'byKind' => $this->scopeGroup($scope, 'p.kind'),
            ],
        ];
    }

    /**
     * Export rows: one per record in scope. Columns follow REPORT_COLUMNS.
     * Counts per row use correlated sub-queries so joins never duplicate rows.
     *
     * @param array{from:string, where:string, params:array<string, mixed>} $scope
     * @return list<array<string, mixed>>
     */
    private function reportRows(string $type, array $scope): array
    {
        $sql = match ($type) {
            'youth' => "SELECT y.code, y.last_name, y.first_name, y.middle_name, y.birth_date,
                        TIMESTAMPDIFF(YEAR, y.birth_date, CURDATE()) AS age, y.gender, y.civil_status, y.address,
                        y.contact, y.education_status, y.education, y.employment, y.guardian_name, y.priority_level,
                        CASE WHEN y.archived = 1 THEN 'archived' ELSE 'active' END AS status,
                        DATE(y.created_at) AS added
                 FROM {$scope['from']} WHERE {$scope['where']}
                 ORDER BY y.last_name, y.first_name, y.id",
            'programs' => "SELECT p.id, p.kind, p.name, p.category, p.status, p.scheduled_on, p.starts_at, p.ends_at,
                        p.location, p.max_participants, p.registration_deadline,
                        (SELECT COUNT(*) FROM applications r
                          WHERE r.organization_id = p.organization_id AND r.program_id = p.id
                            AND r.status NOT IN ('rejected', 'withdrawn')) AS registrations,
                        (SELECT COUNT(*) FROM attendance pa
                          WHERE pa.organization_id = p.organization_id AND pa.program_id = p.id
                            AND pa.status = 'present') AS present
                 FROM {$scope['from']} WHERE {$scope['where']}
                 ORDER BY p.scheduled_on DESC, p.id DESC",
            'assistance' => "SELECT ap.id, ap.name, ap.category, ap.status, ap.slots, ap.amount, ap.deadline,
                        DATE(ap.created_at) AS created,
                        (SELECT COUNT(*) FROM beneficiaries b
                          WHERE b.organization_id = ap.organization_id AND b.assistance_id = ap.id) AS beneficiaries,
                        (SELECT COUNT(*) FROM beneficiaries b
                          WHERE b.organization_id = ap.organization_id AND b.assistance_id = ap.id
                            AND b.status IN ('approved', 'released')) AS awarded
                 FROM {$scope['from']} WHERE {$scope['where']}
                 ORDER BY ap.id DESC",
            'applications' => "SELECT a.id, a.submitted_at, a.status, y.code AS youth_code,
                        TRIM(CONCAT(COALESCE(y.first_name, ''), ' ', COALESCE(y.last_name, ''))) AS youth_name,
                        " . self::APPLICATION_TARGET_SQL . " AS target_type,
                        COALESCE(p.name, asp.name) AS target_name, a.reviewed_at, a.remarks
                 FROM {$scope['from']}
                 LEFT JOIN assistance_programs asp ON asp.id = a.assistance_id AND asp.organization_id = a.organization_id
                 LEFT JOIN youth y ON y.id = a.youth_id AND y.organization_id = a.organization_id
                 WHERE {$scope['where']}
                 ORDER BY a.submitted_at DESC, a.id DESC",
            default => "SELECT at.id, p.name AS program, p.kind, p.scheduled_on, y.code AS youth_code,
                        TRIM(CONCAT(COALESCE(y.first_name, ''), ' ', COALESCE(y.last_name, ''))) AS youth_name,
                        at.status, at.source, at.scanned_at, at.confirmed_at
                 FROM {$scope['from']}
                 LEFT JOIN youth y ON y.id = at.youth_id AND y.organization_id = at.organization_id
                 WHERE {$scope['where']}
                 ORDER BY p.scheduled_on DESC, at.id DESC",
        };
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($scope['params']);

        $columns = self::REPORT_COLUMNS[$type];
        $rows = [];
        foreach ($stmt->fetchAll() as $raw) {
            if ($type === 'assistance') {
                $slots = $raw['slots'] !== null ? (int) $raw['slots'] : null;
                $raw['available'] = $slots === null ? null : max(0, $slots - (int) $raw['awarded']);
            }
            $row = [];
            foreach ($columns as $column) {
                $value = $raw[$column['key']] ?? null;
                if ($value !== null && $column['type'] === 'int') {
                    $value = (int) $value;
                } elseif ($value !== null && $column['type'] === 'number') {
                    $value = (float) $value;
                } elseif ($value !== null) {
                    $value = (string) $value;
                }
                $row[$column['key']] = $value;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array{from:string, where:string, params:array<string, mixed>} $scope
     */
    private function scopeCount(array $scope): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$scope['from']} WHERE {$scope['where']}");
        $stmt->execute($scope['params']);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Grouped count over the report scope; $expression is a fixed SQL
     * expression from this class (never user input). Blank → "(blank)".
     *
     * @param array{from:string, where:string, params:array<string, mixed>} $scope
     * @return array<string, int>
     */
    private function scopeGroup(array $scope, string $expression): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT {$expression} AS bucket, COUNT(*) AS total
             FROM {$scope['from']} WHERE {$scope['where']}
             GROUP BY bucket ORDER BY bucket"
        );
        $stmt->execute($scope['params']);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['bucket'] ?? '');
            if ($key === '') {
                $key = '(blank)';
            }
            $out[$key] = ($out[$key] ?? 0) + (int) $row['total'];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: ?string, 1: ?string}
     */
    private function parseDateRange(array $query): array
    {
        $from = trim((string) ($query['from'] ?? $query['dateFrom'] ?? $query['date_from'] ?? ''));
        $to = trim((string) ($query['to'] ?? $query['dateTo'] ?? $query['date_to'] ?? ''));
        $errors = [];
        if ($from !== '' && !$this->isDate($from)) {
            $errors['from'] = 'Start date must be YYYY-MM-DD.';
        }
        if ($to !== '' && !$this->isDate($to)) {
            $errors['to'] = 'End date must be YYYY-MM-DD.';
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            $errors['to'] = 'End date must be on or after the start date.';
        }
        if ($errors) {
            Json::validation($errors);
        }
        return [$from !== '' ? $from : null, $to !== '' ? $to : null];
    }

    private function isDate(string $value): bool
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }

    /**
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    private function appendDate(array &$where, array &$params, string $column, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $where[] = "{$column} >= :dateFrom";
            $params['dateFrom'] = $from;
        }
        if ($to !== null) {
            $where[] = "{$column} <= :dateTo";
            $params['dateTo'] = $to;
        }
    }

    /**
     * @return array<string, int>
     */
    private function groupedCount(string $table, string $column, int $organizationId, string $extra = ''): array
    {
        $allowedTables = [
            'programs' => ['status', 'kind'],
            'assistance_programs' => ['status', 'category'],
            'beneficiaries' => ['status'],
            'applications' => ['status'],
            'attendance' => ['status'],
            'youth' => ['gender', 'education', 'employment', 'priority_level'],
        ];
        if (!isset($allowedTables[$table]) || !in_array($column, $allowedTables[$table], true)) {
            return [];
        }
        $where = 'organization_id = :org';
        if ($extra !== '') {
            $where .= ' AND ' . $extra;
        }
        return $this->groupedCountWhere($table, $column, $where, ['org' => $organizationId]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, int>
     */
    private function groupedCountWhere(string $table, string $column, string $sqlWhere, array $params): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT {$column} AS bucket, COUNT(*) AS total FROM {$table} WHERE {$sqlWhere} GROUP BY {$column}"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['bucket'] ?? '');
            if ($key === '') {
                $key = '(blank)';
            }
            $out[$key] = (int) $row['total'];
        }
        return $out;
    }

    private function scalarCount(string $table, int $organizationId): int
    {
        $allowed = [
            'programs',
            'assistance_programs',
            'beneficiaries',
            'applications',
            'attendance',
        ];
        if (!in_array($table, $allowed, true)) {
            return 0;
        }
        return $this->countWhere($table, 'organization_id = :org', ['org' => $organizationId]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countWhere(string $table, string $sqlWhere, array $params): int
    {
        $allowed = [
            'youth',
            'programs',
            'assistance_programs',
            'beneficiaries',
            'applications',
            'attendance',
            'notifications',
        ];
        if (!in_array($table, $allowed, true)) {
            return 0;
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$sqlWhere}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
    /**
     * Organization activity trail for the signed-in SK session.
     *
     * The organization is always the SkGuard session organization; client
     * organization_id / user_id style parameters are ignored. Filters mirror
     * /admin/activity: exact `action`, exact `category`, and `q` over the
     * description and actor name. The client IP is not exposed to SK users.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function activity(int $organizationId, array $query): array
    {
        unset(
            $query['organization_id'], $query['orgId'], $query['organizationId'], $query['org_id'],
            $query['user_id'], $query['userId']
        );

        $where = ['a.organization_id = :org'];
        $params = ['org' => $organizationId];

        $action = trim((string) ($query['action'] ?? ''));
        if ($action !== '' && $action !== 'all') {
            $where[] = 'a.action = :action';
            $params['action'] = mb_substr($action, 0, 32);
        }
        $category = trim((string) ($query['category'] ?? ''));
        if ($category !== '' && $category !== 'all') {
            $where[] = 'a.category = :category';
            $params['category'] = mb_substr($category, 0, 32);
        }
        $q = Pagination::search($query);
        if ($q !== '') {
            $where[] = Pagination::likeAny(['a.description', 'a.actor_name'], $q, 'q', $params);
        }

        $sqlWhere = implode(' AND ', $where);
        $paging = Pagination::fromQuery($query, 100, 100);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM activity_logs a WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $meta = $paging->meta($total);

        $stmt = $this->pdo->prepare(
            "SELECT a.*
             FROM activity_logs a
             WHERE {$sqlWhere}
             ORDER BY a.created_at DESC, a.id DESC
             {$paging->limitSql()}"
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'orgId' => (int) $row['organization_id'],
                'userId' => $row['user_id'] === null ? null : (int) $row['user_id'],
                'youthId' => $row['youth_id'] === null ? null : (int) $row['youth_id'],
                'actor' => $row['actor_name'],
                'user' => $row['actor_name'],
                'role' => $row['actor_role'],
                'actorRole' => $row['actor_role'],
                'action' => $row['action'],
                'category' => $row['category'],
                'description' => $row['description'],
                'entityType' => $row['entity_type'],
                'entityId' => $row['entity_id'] === null ? null : (int) $row['entity_id'],
                'at' => $row['created_at'],
                'createdAt' => $row['created_at'],
            ];
        }

        return [
            'items' => $items,
            ...$meta,
            'filters' => [
                'actions' => $this->activityValues($organizationId, 'action'),
                'categories' => $this->activityValues($organizationId, 'category'),
            ],
        ];
    }

    /**
     * Distinct action or category values recorded for this organization, for
     * the filter dropdowns. Column name is chosen from a fixed list.
     *
     * @return list<string>
     */
    private function activityValues(int $organizationId, string $column): array
    {
        if (!in_array($column, ['action', 'category'], true)) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT {$column} FROM activity_logs WHERE organization_id = :org ORDER BY {$column}"
        );
        $stmt->execute(['org' => $organizationId]);
        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

}
