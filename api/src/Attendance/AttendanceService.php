<?php
declare(strict_types=1);

namespace YouthSync\Attendance;

use PDO;
use YouthSync\Http\Json;
use YouthSync\Org\PlanLimits;

final class AttendanceService
{
    private const ELIGIBLE_STATUSES = ['published', 'ongoing', 'completed'];
    private const SESSION_HOURS = 12;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $orgRow
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createSession(int $organizationId, int $programId, int $userId, array $orgRow, array $input): array
    {
        unset($input['organization_id'], $input['orgId'], $input['organizationId']);
        $program = $this->requireProgram($organizationId, $programId);
        $this->assertEligible($program);

        $this->pdo->prepare(
            "UPDATE attendance_qr_tokens
             SET status = 'closed'
             WHERE organization_id = :org AND program_id = :program AND status = 'active'"
        )->execute(['org' => $organizationId, 'program' => $programId]);

        $plain = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain);
        $publicCode = 'ATT-' . strtoupper(bin2hex(random_bytes(4)));
        $hours = self::SESSION_HOURS;

        $stmt = $this->pdo->prepare(
            "INSERT INTO attendance_qr_tokens (
                organization_id, program_id, public_code, token_hash, created_by, expires_at, status
            ) VALUES (
                :organization_id, :program_id, :public_code, :token_hash, :created_by,
                DATE_ADD(NOW(), INTERVAL {$hours} HOUR), :status
            )"
        );
        $stmt->execute([
            'organization_id' => $organizationId,
            'program_id' => $programId,
            'public_code' => $publicCode,
            'token_hash' => $hash,
            'created_by' => $userId,
            'status' => 'active',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $payload = $this->publicSession($this->sessionRow($organizationId, $id));
        $payload['token'] = $plain;
        $payload['program'] = $this->publicProgram($program);
        $payload['qrRemaining'] = $this->qrRemaining($organizationId, $orgRow);
        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSession(int $organizationId, int $programId): array
    {
        $this->requireProgram($organizationId, $programId);
        $row = $this->activeSessionRow($organizationId, $programId);
        if ($row === null) {
            Json::error('NOT_FOUND', 'No active attendance session for this activity.', 404);
        }
        $payload = $this->publicSession($row);
        $payload['program'] = $this->publicProgram($this->requireProgram($organizationId, $programId));
        return $payload;
    }

    /**
     * @param array<string, mixed> $orgRow
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function scan(int $organizationId, array $orgRow, array $input): array
    {
        unset($input['organization_id'], $input['orgId'], $input['organizationId']);
        $this->assertQrAvailable($organizationId, $orgRow);

        $youthToken = trim((string) ($input['token'] ?? $input['youthToken'] ?? ''));
        $sessionToken = trim((string) ($input['sessionToken'] ?? $input['session_token'] ?? ''));
        $programIdIn = $input['programId'] ?? $input['program_id'] ?? null;

        $code = $this->parseYouthToken($youthToken);
        if ($code === null) {
            Json::validation(['token' => 'That is not a Youth QR code.']);
        }

        if ($sessionToken === '') {
            Json::validation(['sessionToken' => 'An attendance session token is required.']);
        }

        $session = $this->sessionByPlainToken($sessionToken);
        if ($session === null || (int) $session['organization_id'] !== $organizationId) {
            Json::error('NOT_FOUND', 'This attendance session does not exist in this organization.', 404);
        }
        if ($session['status'] !== 'active') {
            Json::error('SESSION_EXPIRED', 'This attendance session is invalid or has expired.', 422);
        }
        $fresh = $this->pdo->prepare(
            "SELECT id FROM attendance_qr_tokens
             WHERE id = :id AND status = 'active' AND expires_at > NOW()
             LIMIT 1"
        );
        $fresh->execute(['id' => (int) $session['id']]);
        if ($fresh->fetch() === false) {
            Json::error('SESSION_EXPIRED', 'This attendance session is invalid or has expired.', 422);
        }

        $programId = (int) $session['program_id'];
        if ($programIdIn !== null && $programIdIn !== '' && (int) $programIdIn !== $programId) {
            Json::error('NOT_FOUND', 'This attendance session does not match the selected activity.', 404);
        }

        $program = $this->requireProgram($organizationId, $programId);
        if (!in_array($program['status'], self::ELIGIBLE_STATUSES, true)) {
            Json::error(
                'VALIDATION_ERROR',
                'Attendance cannot be recorded for a draft or archived activity.',
                422
            );
        }

        $youth = $this->youthByCode($organizationId, $code);
        if ($youth === null) {
            Json::error('NOT_FOUND', "No youth in your barangay matches {$code}.", 404);
        }

        $this->consumeQr($organizationId);

        if ((int) $youth['archived'] === 1) {
            Json::error('VALIDATION_ERROR', 'Attendance cannot be recorded for an archived youth record.', 422);
        }

        $existing = $this->findAttendance($organizationId, $programId, (int) $youth['id']);
        if ($existing !== null && $existing['status'] === 'present') {
            $when = $this->formatStamp($existing['scanned_at'] ?? $existing['confirmed_at']);
            Json::error(
                'CONFLICT',
                "Attendance already recorded for {$youth['first_name']} {$youth['last_name']} at {$when}.",
                409
            );
        }

        if ($existing === null) {
            $ins = $this->pdo->prepare(
                'INSERT INTO attendance (
                    organization_id, program_id, youth_id, session_id, status, source, scanned_at
                ) VALUES (
                    :organization_id, :program_id, :youth_id, :session_id, :status, :source, NOW()
                )'
            );
            $ins->execute([
                'organization_id' => $organizationId,
                'program_id' => $programId,
                'youth_id' => (int) $youth['id'],
                'session_id' => (int) $session['id'],
                'status' => 'pending',
                'source' => 'qr',
            ]);
            $attendance = $this->requireAttendance($organizationId, (int) $this->pdo->lastInsertId());
            $created = true;
        } else {
            $this->pdo->prepare(
                'UPDATE attendance
                 SET session_id = :session_id, source = :source,
                     scanned_at = COALESCE(scanned_at, NOW())
                 WHERE id = :id AND organization_id = :org'
            )->execute([
                'session_id' => (int) $session['id'],
                'source' => 'qr',
                'id' => (int) $existing['id'],
                'org' => $organizationId,
            ]);
            $attendance = $this->requireAttendance($organizationId, (int) $existing['id']);
            $created = false;
        }

        return [
            'pending' => true,
            'created' => $created,
            'message' => "{$youth['first_name']} {$youth['last_name']} ({$code}) identified for {$program['name']}. Confirm to record attendance.",
            'attendance' => $this->publicAttendance($attendance, $youth, $program),
            'youth' => $this->publicYouth($youth),
            'program' => $this->publicProgram($program),
            'qrRemaining' => $this->qrRemaining($organizationId, $orgRow),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function confirm(int $organizationId, array $input): array
    {
        unset($input['organization_id'], $input['orgId'], $input['organizationId']);
        $id = (int) ($input['attendanceId'] ?? $input['attendance_id'] ?? $input['id'] ?? 0);
        if ($id < 1) {
            Json::validation(['attendanceId' => 'Attendance record is required.']);
        }
        $row = $this->requireAttendance($organizationId, $id);
        if ($row['status'] === 'present') {
            $when = $this->formatStamp($row['scanned_at'] ?? $row['confirmed_at']);
            Json::error('CONFLICT', "Attendance already recorded at {$when}.", 409);
        }

        $this->pdo->prepare(
            "UPDATE attendance
             SET status = 'present',
                 confirmed_at = NOW(),
                 scanned_at = COALESCE(scanned_at, NOW())
             WHERE id = :id AND organization_id = :org"
        )->execute([
            'id' => $id,
            'org' => $organizationId,
        ]);

        $attendance = $this->requireAttendance($organizationId, $id);
        $youth = $this->youthById($organizationId, (int) $attendance['youth_id']);
        $program = $this->requireProgram($organizationId, (int) $attendance['program_id']);
        $stamp = $this->formatStamp($attendance['scanned_at']);
        $name = $youth ? "{$youth['first_name']} {$youth['last_name']}" : 'Youth';

        return [
            'message' => "{$name} marked present at {$stamp}.",
            'attendance' => $this->publicAttendance($attendance, $youth, $program),
            'youth' => $youth ? $this->publicYouth($youth) : null,
            'program' => $this->publicProgram($program),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function manual(int $organizationId, array $input): array
    {
        unset($input['organization_id'], $input['orgId'], $input['organizationId']);
        $programId = (int) ($input['programId'] ?? $input['program_id'] ?? 0);
        $youthId = (int) ($input['youthId'] ?? $input['youth_id'] ?? 0);
        if ($programId < 1) {
            Json::validation(['programId' => 'Select an activity first.']);
        }
        if ($youthId < 1) {
            Json::validation(['youthId' => 'Select a youth record.']);
        }

        $program = $this->requireProgram($organizationId, $programId);
        $this->assertEligible($program);
        $youth = $this->youthById($organizationId, $youthId);
        if ($youth === null) {
            Json::error('NOT_FOUND', 'This youth record does not exist in this organization.', 404);
        }
        if ((int) $youth['archived'] === 1) {
            Json::error('VALIDATION_ERROR', 'Attendance cannot be recorded for an archived youth record.', 422);
        }

        $existing = $this->findAttendance($organizationId, $programId, $youthId);
        if ($existing !== null && $existing['status'] === 'present') {
            $when = $this->formatStamp($existing['scanned_at'] ?? $existing['confirmed_at']);
            Json::error(
                'CONFLICT',
                "Attendance already recorded for {$youth['first_name']} {$youth['last_name']} at {$when}.",
                409
            );
        }

        if ($existing === null) {
            $ins = $this->pdo->prepare(
                'INSERT INTO attendance (
                    organization_id, program_id, youth_id, session_id, status, source, scanned_at
                ) VALUES (
                    :organization_id, :program_id, :youth_id, NULL, :status, :source, NOW()
                )'
            );
            $ins->execute([
                'organization_id' => $organizationId,
                'program_id' => $programId,
                'youth_id' => $youthId,
                'status' => 'pending',
                'source' => 'manual',
            ]);
            $attendance = $this->requireAttendance($organizationId, (int) $this->pdo->lastInsertId());
        } else {
            $attendance = $this->requireAttendance($organizationId, (int) $existing['id']);
        }

        return [
            'pending' => true,
            'message' => "{$youth['first_name']} {$youth['last_name']} identified for {$program['name']}. Confirm to record attendance.",
            'attendance' => $this->publicAttendance($attendance, $youth, $program),
            'youth' => $this->publicYouth($youth),
            'program' => $this->publicProgram($program),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listForProgram(int $organizationId, int $programId): array
    {
        $program = $this->requireProgram($organizationId, $programId);
        $stmt = $this->pdo->prepare(
            'SELECT a.*, y.code, y.first_name, y.last_name, y.archived
             FROM attendance a
             INNER JOIN youth y ON y.id = a.youth_id
             WHERE a.organization_id = :org AND a.program_id = :program
             ORDER BY a.scanned_at DESC, a.id DESC'
        );
        $stmt->execute(['org' => $organizationId, 'program' => $programId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $youth = [
                'id' => (int) $row['youth_id'],
                'code' => $row['code'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'archived' => $row['archived'],
            ];
            $items[] = $this->publicAttendance($row, $youth, $program);
        }
        return ['items' => $items, 'program' => $this->publicProgram($program)];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $organizationId, int $id): array
    {
        $row = $this->requireAttendance($organizationId, $id);
        $youth = $this->youthById($organizationId, (int) $row['youth_id']);
        $program = $this->requireProgram($organizationId, (int) $row['program_id']);
        return $this->publicAttendance($row, $youth, $program);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateStatus(int $organizationId, int $id, array $input): array
    {
        unset($input['organization_id'], $input['orgId'], $input['organizationId']);
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        if (!in_array($status, ['present', 'absent', 'excused'], true)) {
            Json::validation(['status' => 'Attendance status must be present, absent, or excused.']);
        }

        $row = $this->requireAttendance($organizationId, $id);
        $program = $this->requireProgram($organizationId, (int) $row['program_id']);
        $this->assertEligible($program);

        if ($row['status'] === 'present' && $status === 'present') {
            $when = $this->formatStamp($row['scanned_at'] ?? $row['confirmed_at']);
            Json::error('CONFLICT', "Attendance already recorded at {$when}.", 409);
        }

        $this->pdo->prepare(
            'UPDATE attendance
             SET status = :status,
                 confirmed_at = IF(:is_present = 1, NOW(), confirmed_at),
                 scanned_at = COALESCE(scanned_at, NOW())
             WHERE id = :id AND organization_id = :org'
        )->execute([
            'status' => $status,
            'is_present' => $status === 'present' ? 1 : 0,
            'id' => $id,
            'org' => $organizationId,
        ]);

        return $this->get($organizationId, $id);
    }

    /**
     * @param array<string, mixed> $orgRow
     */
    private function assertQrAvailable(int $organizationId, array $orgRow): void
    {
        $plan = PlanLimits::effective($orgRow);
        $limit = $plan['qr'];
        if ($limit === null) {
            return;
        }
        $uses = $this->qrUses($organizationId);
        if ($uses >= $limit) {
            Json::error('PLAN_LIMIT', PlanLimits::qrLimitMessage($plan), 403);
        }
    }

    private function consumeQr(int $organizationId): void
    {
        $this->pdo->prepare(
            'UPDATE organizations SET qr_uses = qr_uses + 1 WHERE id = :id'
        )->execute(['id' => $organizationId]);
    }

    private function qrUses(int $organizationId): int
    {
        $stmt = $this->pdo->prepare('SELECT qr_uses FROM organizations WHERE id = :id');
        $stmt->execute(['id' => $organizationId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $orgRow
     */
    private function qrRemaining(int $organizationId, array $orgRow): ?int
    {
        $plan = PlanLimits::effective($orgRow);
        if ($plan['qr'] === null) {
            return null;
        }
        return max(0, $plan['qr'] - $this->qrUses($organizationId));
    }

    private function parseYouthToken(string $token): ?string
    {
        if (preg_match('/^YSYOUTH-(YTH-\d+)$/i', trim($token), $m) !== 1) {
            return null;
        }
        return strtoupper($m[1]);
    }

    /**
     * @param array<string, mixed> $program
     */
    private function assertEligible(array $program): void
    {
        if (!in_array($program['status'], self::ELIGIBLE_STATUSES, true)) {
            Json::error(
                'VALIDATION_ERROR',
                'Attendance can only be taken for published, ongoing, or completed activities.',
                422
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireProgram(int $organizationId, int $programId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM programs WHERE id = :id AND organization_id = :org LIMIT 1'
        );
        $stmt->execute(['id' => $programId, 'org' => $organizationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This program does not exist in this organization.', 404);
        }
        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function youthByCode(int $organizationId, string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM youth WHERE organization_id = :org AND code = :code LIMIT 1'
        );
        $stmt->execute(['org' => $organizationId, 'code' => $code]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function youthById(int $organizationId, int $youthId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM youth WHERE id = :id AND organization_id = :org LIMIT 1'
        );
        $stmt->execute(['id' => $youthId, 'org' => $organizationId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAttendance(int $organizationId, int $programId, int $youthId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM attendance
             WHERE organization_id = :org AND program_id = :program AND youth_id = :youth
             LIMIT 1'
        );
        $stmt->execute(['org' => $organizationId, 'program' => $programId, 'youth' => $youthId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireAttendance(int $organizationId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM attendance WHERE id = :id AND organization_id = :org LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'org' => $organizationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This attendance record does not exist in this organization.', 404);
        }
        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sessionByPlainToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM attendance_qr_tokens WHERE token_hash = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeSessionRow(int $organizationId, int $programId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM attendance_qr_tokens
             WHERE organization_id = :org
               AND program_id = :program
               AND status = 'active'
               AND expires_at > NOW()
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute(['org' => $organizationId, 'program' => $programId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionRow(int $organizationId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM attendance_qr_tokens WHERE id = :id AND organization_id = :org LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'org' => $organizationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This attendance session does not exist in this organization.', 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicSession(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'publicCode' => $row['public_code'],
            'programId' => (int) $row['program_id'],
            'status' => $row['status'],
            'expiresAt' => $row['expires_at'],
            'createdAt' => $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $youth
     * @param array<string, mixed> $program
     * @return array<string, mixed>
     */
    private function publicAttendance(array $row, ?array $youth, array $program): array
    {
        return [
            'id' => (int) $row['id'],
            'programId' => (int) $row['program_id'],
            'youthId' => (int) $row['youth_id'],
            'sessionId' => $row['session_id'] !== null ? (int) $row['session_id'] : null,
            'status' => $row['status'],
            'source' => $row['source'],
            'scannedAt' => $this->formatStamp($row['scanned_at'] ?? null),
            'confirmedAt' => $this->formatStamp($row['confirmed_at'] ?? null),
            'createdAt' => $row['created_at'],
            'youth' => $youth ? $this->publicYouth($youth) : null,
            'program' => $this->publicProgram($program),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicYouth(array $row): array
    {
        $code = (string) ($row['code'] ?? '');
        return [
            'id' => (int) $row['id'],
            'code' => $code,
            'qrToken' => $code !== '' ? 'YSYOUTH-' . $code : null,
            'firstName' => $row['first_name'] ?? $row['firstName'] ?? '',
            'lastName' => $row['last_name'] ?? $row['lastName'] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicProgram(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'kind' => $row['kind'],
            'name' => $row['name'],
            'status' => $row['status'],
            'scheduledOn' => $row['scheduled_on'] ?? $row['scheduledOn'] ?? null,
        ];
    }

    private function formatStamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string) $value);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i', $ts);
    }
}
