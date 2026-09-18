<?php
declare(strict_types=1);

namespace YouthSync\Dashboard;

use DateTimeImmutable;
use PDO;
use YouthSync\Http\Json;

final class DashboardService
{
    private const REPORT_TYPES = ['youth', 'programs', 'assistance', 'applications', 'attendance'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function dashboard(int $organizationId, int $userId, array $query): array
    {
        unset($query['organization_id'], $query['orgId'], $query['organizationId'], $query['org_id']);

        $youthTotals = $this->youthTotals($organizationId);
        $programByStatus = $this->groupedCount('programs', 'status', $organizationId);
        $programByKind = $this->groupedCount('programs', 'kind', $organizationId);
        $assistanceByStatus = $this->groupedCount('assistance_programs', 'status', $organizationId);
        $assistanceByCategory = $this->groupedCount('assistance_programs', 'category', $organizationId);
        $beneficiaryByStatus = $this->groupedCount('beneficiaries', 'status', $organizationId);
        $applicationByStatus = $this->groupedCount('applications', 'status', $organizationId);
        $attendanceByStatus = $this->groupedCount('attendance', 'status', $organizationId);

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
                'slots' => $this->assistanceSlots($organizationId),
            ],
            'applications' => [
                'byStatus' => $applicationByStatus,
            ],
            'attendance' => [
                'byStatus' => $attendanceByStatus,
            ],
            'recent' => $this->recentActivity($organizationId),
            'newestYouth' => $this->newestYouth($organizationId),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function report(int $organizationId, array $query): array
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

        $filters = [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'status' => $status !== '' ? $status : null,
            'category' => $category !== '' ? $category : null,
            'kind' => $kind !== '' ? $kind : null,
        ];

        return match ($type) {
            'youth' => $this->youthReport($organizationId, $from, $to, $status, $filters),
            'programs' => $this->programsReport($organizationId, $from, $to, $status, $kind, $filters),
            'assistance' => $this->assistanceReport($organizationId, $from, $to, $status, $category, $filters),
            'applications' => $this->applicationsReport($organizationId, $from, $to, $status, $filters),
            'attendance' => $this->attendanceReport($organizationId, $from, $to, $status, $kind, $filters),
            default => Json::validation(['type' => 'Report type is not valid.']),
        };
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
            ];
        }
        return $items;
    }

    /**
     * @return array<string, int>
     */
    private function beneficiariesByCategory(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.category, COUNT(*) AS total
             FROM beneficiaries b
             INNER JOIN assistance_programs p ON p.id = b.assistance_id AND p.organization_id = b.organization_id
             WHERE b.organization_id = :org
             GROUP BY p.category'
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
    private function recentActivity(int $organizationId): array
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
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function youthReport(int $organizationId, ?string $from, ?string $to, string $status, array $filters): array
    {
        if ($status !== '' && !in_array($status, ['active', 'archived'], true)) {
            Json::validation(['status' => 'Youth report status must be active or archived.']);
        }

        [$where, $params] = $this->orgWhere($organizationId);
        if ($status === 'active') {
            $where[] = 'archived = 0';
        } elseif ($status === 'archived') {
            $where[] = 'archived = 1';
        }
        $this->appendDate($where, $params, 'DATE(created_at)', $from, $to);
        $sqlWhere = implode(' AND ', $where);

        $totalsStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(archived = 0), 0) AS active,
                    COALESCE(SUM(archived = 1), 0) AS archived
             FROM youth WHERE {$sqlWhere}"
        );
        $totalsStmt->execute($params);
        $totals = $totalsStmt->fetch() ?: [];

        $high = [];
        $highStmt = $this->pdo->prepare(
            "SELECT id, first_name, last_name, priority_level, priority_reasons
             FROM youth
             WHERE {$sqlWhere} AND priority_level = 'High'
             ORDER BY id DESC
             LIMIT 50"
        );
        $highStmt->execute($params);
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
                'ageGroups' => $this->youthAgeGroupsFiltered($sqlWhere, $params),
                'gender' => $this->groupedCountWhere('youth', 'gender', $sqlWhere, $params),
                'education' => $this->groupedCountWhere('youth', 'education', $sqlWhere, $params),
                'employment' => $this->groupedCountWhere('youth', 'employment', $sqlWhere, $params),
                'priority' => $this->groupedCountWhere('youth', 'priority_level', $sqlWhere, $params),
            ],
            'highPriorityYouth' => $high,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, int>
     */
    private function youthAgeGroupsFiltered(string $sqlWhere, array $params): array
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
             WHERE {$sqlWhere}
             GROUP BY bucket"
        );
        $stmt->execute($params);
        $out = ['15-17' => 0, '18-24' => 0, '25-30' => 0, 'other' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['bucket']] = (int) $row['total'];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function programsReport(
        int $organizationId,
        ?string $from,
        ?string $to,
        string $status,
        string $kind,
        array $filters
    ): array {
        if ($status !== '' && !in_array($status, ['draft', 'published', 'ongoing', 'completed', 'archived'], true)) {
            Json::validation(['status' => 'Program status is not valid.']);
        }
        if ($kind !== '' && !in_array($kind, ['program', 'event'], true)) {
            Json::validation(['kind' => 'Kind must be program or event.']);
        }

        [$where, $params] = $this->orgWhere($organizationId);
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($kind !== '') {
            $where[] = 'kind = :kind';
            $params['kind'] = $kind;
        }
        $this->appendDate($where, $params, 'scheduled_on', $from, $to);
        $sqlWhere = implode(' AND ', $where);

        $totalStmt = $this->pdo->prepare("SELECT COUNT(*) FROM programs WHERE {$sqlWhere}");
        $totalStmt->execute($params);

        $presentStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM attendance a
             INNER JOIN programs p ON p.id = a.program_id AND p.organization_id = a.organization_id
             WHERE a.organization_id = :orgAttend AND a.status = 'present'
               AND p.id IN (SELECT id FROM programs WHERE {$sqlWhere})"
        );
        $presentStmt->execute($params + ['orgAttend' => $organizationId]);

        return [
            'type' => 'programs',
            'filters' => $filters,
            'totals' => [
                'total' => (int) $totalStmt->fetchColumn(),
                'presentAttendance' => (int) $presentStmt->fetchColumn(),
            ],
            'series' => [
                'byStatus' => $this->groupedCountWhere('programs', 'status', $sqlWhere, $params),
                'byKind' => $this->groupedCountWhere('programs', 'kind', $sqlWhere, $params),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function assistanceReport(
        int $organizationId,
        ?string $from,
        ?string $to,
        string $status,
        string $category,
        array $filters
    ): array {
        if ($status !== '' && !in_array($status, ['draft', 'open', 'full', 'closed', 'archived'], true)) {
            Json::validation(['status' => 'Assistance status is not valid.']);
        }

        [$where, $params] = $this->orgWhere($organizationId);
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($category !== '') {
            $where[] = 'category = :category';
            $params['category'] = $category;
        }
        $this->appendDate($where, $params, 'DATE(created_at)', $from, $to);
        $sqlWhere = implode(' AND ', $where);

        $totalStmt = $this->pdo->prepare("SELECT COUNT(*) FROM assistance_programs WHERE {$sqlWhere}");
        $totalStmt->execute($params);

        $benWhere = ['b.organization_id = :orgB'];
        $benParams = ['orgB' => $organizationId];
        if ($category !== '') {
            $benWhere[] = 'p.category = :category';
            $benParams['category'] = $category;
        }
        $benSql = implode(' AND ', $benWhere);
        $benStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM beneficiaries b
             INNER JOIN assistance_programs p ON p.id = b.assistance_id AND p.organization_id = b.organization_id
             WHERE {$benSql}"
        );
        $benStmt->execute($benParams);

        return [
            'type' => 'assistance',
            'filters' => $filters,
            'totals' => [
                'total' => (int) $totalStmt->fetchColumn(),
                'beneficiaries' => (int) $benStmt->fetchColumn(),
            ],
            'series' => [
                'byStatus' => $this->groupedCountWhere('assistance_programs', 'status', $sqlWhere, $params),
                'byCategory' => $this->groupedCountWhere('assistance_programs', 'category', $sqlWhere, $params),
            ],
            'slots' => $this->assistanceSlots($organizationId),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function applicationsReport(
        int $organizationId,
        ?string $from,
        ?string $to,
        string $status,
        array $filters
    ): array {
        $allowed = ['pending', 'approved', 'rejected', 'withdrawn', 'needs_resubmission'];
        if ($status !== '' && !in_array($status, $allowed, true)) {
            Json::validation(['status' => 'Application status is not valid.']);
        }

        [$where, $params] = $this->orgWhere($organizationId);
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $this->appendDate($where, $params, 'DATE(submitted_at)', $from, $to);
        $sqlWhere = implode(' AND ', $where);

        $totalStmt = $this->pdo->prepare("SELECT COUNT(*) FROM applications WHERE {$sqlWhere}");
        $totalStmt->execute($params);

        return [
            'type' => 'applications',
            'filters' => $filters,
            'totals' => [
                'total' => (int) $totalStmt->fetchColumn(),
            ],
            'series' => [
                'byStatus' => $this->groupedCountWhere('applications', 'status', $sqlWhere, $params),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function attendanceReport(
        int $organizationId,
        ?string $from,
        ?string $to,
        string $status,
        string $kind,
        array $filters
    ): array {
        if ($status !== '' && !in_array($status, ['pending', 'present', 'absent', 'excused'], true)) {
            Json::validation(['status' => 'Attendance status is not valid.']);
        }
        if ($kind !== '' && !in_array($kind, ['program', 'event'], true)) {
            Json::validation(['kind' => 'Kind must be program or event.']);
        }

        $where = ['a.organization_id = :org'];
        $params = ['org' => $organizationId];
        if ($status !== '') {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }
        if ($kind !== '') {
            $where[] = 'p.kind = :kind';
            $params['kind'] = $kind;
        }
        $this->appendDate($where, $params, 'DATE(COALESCE(a.confirmed_at, a.created_at))', $from, $to);
        $sqlWhere = implode(' AND ', $where);

        $totalStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM attendance a
             INNER JOIN programs p ON p.id = a.program_id AND p.organization_id = a.organization_id
             WHERE {$sqlWhere}"
        );
        $totalStmt->execute($params);

        $seriesStmt = $this->pdo->prepare(
            "SELECT a.status AS bucket, COUNT(*) AS total
             FROM attendance a
             INNER JOIN programs p ON p.id = a.program_id AND p.organization_id = a.organization_id
             WHERE {$sqlWhere}
             GROUP BY a.status"
        );
        $seriesStmt->execute($params);
        $byStatus = [];
        foreach ($seriesStmt->fetchAll() as $row) {
            $byStatus[(string) $row['bucket']] = (int) $row['total'];
        }

        return [
            'type' => 'attendance',
            'filters' => $filters,
            'totals' => [
                'total' => (int) $totalStmt->fetchColumn(),
                'present' => $byStatus['present'] ?? 0,
            ],
            'series' => [
                'byStatus' => $byStatus,
            ],
        ];
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
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function orgWhere(int $organizationId): array
    {
        return [['organization_id = :org'], ['org' => $organizationId]];
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
}
