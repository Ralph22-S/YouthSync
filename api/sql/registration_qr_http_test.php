<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();
$base = 'http://localhost/YouthSync/api';
$stamp = date('YmdHis');
$failures = 0;
$programIds = [];
$sessionIds = [];
$applicationIds = [];
$attendanceIds = [];

$ids = static function (string $table) use ($pdo): array {
    $rows = $pdo->query("SELECT id FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows ?: []);
};

$beforePrograms = $ids('programs');
$beforeApplications = $ids('applications');
$beforeAttendance = $ids('attendance');
$beforeSessions = $ids('attendance_qr_tokens');
$keptPrograms = $pdo->query('SELECT id FROM programs WHERE id IN (9, 13) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

$check = static function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok) {
        echo "PASS {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL {$name}" . ($detail !== '' ? " {$detail}" : '') . "\n";
};

$req = static function (string $method, string $url, ?string $json, string $cookie): array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'body' => $err];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'body' => substr($raw, $headerSize)];
};

$data = static function (array $response): array {
    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        return [];
    }
    $payload = $decoded['data'] ?? $decoded;
    return is_array($payload) ? $payload : [];
};

$detail = static function (array $response): string {
    return 'status=' . $response['status'] . ' body=' . substr($response['body'], 0, 320);
};

$cookie = static function (string $name) use ($stamp): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . $stamp . '.txt';
};

$attendanceCount = static function (int $programId, int $youthId) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE program_id = :program AND youth_id = :youth');
    $stmt->execute(['program' => $programId, 'youth' => $youthId]);
    return (int) $stmt->fetchColumn();
};

$anon = $cookie('ys_reg_anon_');
$admin = $cookie('ys_reg_admin_');
$sk = $cookie('ys_reg_sk_');
$sk2 = $cookie('ys_reg_sk2_');
$youth = $cookie('ys_reg_youth_');

try {
    $anonReg = $req('POST', "$base/youth/programs/register", json_encode(['code' => 'REG-AAAAAAAA']), $anon);
    $check('anonymous_register_401', $anonReg['status'] === 401, $detail($anonReg));

    $adminLogin = $req('POST', "$base/auth/login", json_encode([
        'email' => 'admin@skmms.test',
        'password' => 'YouthSync1!',
    ]), $admin);
    $check('admin_login', $adminLogin['status'] === 200, $detail($adminLogin));
    $adminReg = $req('POST', "$base/youth/programs/register", json_encode(['code' => 'REG-AAAAAAAA']), $admin);
    $check('admin_register_403', $adminReg['status'] === 403, $detail($adminReg));

    $youthLogin = $req('POST', "$base/auth/login", json_encode([
        'email' => 'youth-auth-test@example.test',
        'password' => 'YouthAuthTest1!',
    ]), $youth);
    $check('youth_login', $youthLogin['status'] === 200, $detail($youthLogin));
    $me = $data($req('GET', "$base/auth/me", null, $youth));
    $youthId = (int) ($me['youth']['id'] ?? 0);
    $check('youth_identity', $youthId > 0, json_encode($me));

    $skLogin = $req('POST', "$base/auth/login", json_encode([
        'email' => 'sk1@demo.test',
        'password' => 'YouthSync1!',
    ]), $sk);
    $check('sk_login', $skLogin['status'] === 200, $detail($skLogin));
    $skReg = $req('POST', "$base/youth/programs/register", json_encode(['code' => 'REG-AAAAAAAA']), $sk);
    $check('sk_register_403', $skReg['status'] === 403, $detail($skReg));

    $sk2Login = $req('POST', "$base/auth/login", json_encode([
        'email' => 'sk2@demo.test',
        'password' => 'YouthSync1!',
    ]), $sk2);
    $check('sk2_login', $sk2Login['status'] === 200, $detail($sk2Login));

    $draft = $req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => "RegQr Draft {$stamp}",
        'category' => 'Sports & Recreation',
        'scheduledOn' => '2026-12-20',
        'startsAt' => '08:00',
        'endsAt' => '12:00',
        'location' => 'Draft Court',
        'description' => 'Registration QR draft',
        'status' => 'draft',
        'organization_id' => 2,
    ]), $sk);
    $draftId = (int) ($data($draft)['id'] ?? 0);
    $draftCode = (string) ($data($draft)['registrationCode'] ?? '');
    if ($draftId > 0) {
        $programIds[] = $draftId;
    }
    $check('draft_code_not_id', $draft['status'] === 201
        && preg_match('/^REG-[A-F0-9]{8}$/', $draftCode) === 1
        && $draftCode !== (string) $draftId
        && !str_contains($draftCode, (string) $draftId), $detail($draft));
    $draftTry = $req('POST', "$base/youth/programs/register", json_encode([
        'code' => $draftCode,
        'program_id' => 9,
        'youth_id' => 1,
        'organization_id' => 2,
    ]), $youth);
    $check('draft_rejected', $draftTry['status'] === 422
        && str_contains($draftTry['body'], 'Invalid or unavailable registration code.'), $detail($draftTry));

    $created = $req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => "RegQr Program {$stamp}",
        'category' => 'Sports & Recreation',
        'scheduledOn' => '2026-12-31',
        'startsAt' => '08:00',
        'endsAt' => '12:00',
        'location' => 'Registration Court',
        'description' => 'Registration QR program',
        'status' => 'draft',
    ]), $sk);
    $programId = (int) ($data($created)['id'] ?? 0);
    $regCode = (string) ($data($created)['registrationCode'] ?? '');
    if ($programId > 0) {
        $programIds[] = $programId;
    }
    $published = $req('POST', "$base/sk/programs/{$programId}/status", json_encode(['status' => 'published']), $sk);
    $shown = $req('GET', "$base/sk/programs/{$programId}", null, $sk);
    $shownCode = (string) ($data($shown)['registrationCode'] ?? '');
    $check('published_registration_code', $published['status'] === 200
        && $shown['status'] === 200
        && $shownCode === $regCode
        && preg_match('/^REG-[A-F0-9]{8}$/', $shownCode) === 1, $detail($shown));

    $beforeRows = $attendanceCount($programId, $youthId);
    $registered = $req('POST', "$base/youth/programs/register", json_encode([
        'code' => strtolower($regCode),
        'program_id' => $draftId,
        'youth_id' => 1,
        'organization_id' => 99,
        'user_id' => 1,
        'role' => 'SK_OFFICIAL',
    ]), $youth);
    $application = $data($registered)['application'] ?? [];
    $applicationId = (int) ($application['id'] ?? 0);
    if ($applicationId > 0) {
        $applicationIds[] = $applicationId;
    }
    $check('register_by_code', $registered['status'] === 201
        && ($data($registered)['message'] ?? '') === 'Registration successful.'
        && (int) ($application['youthId'] ?? 0) === $youthId
        && (int) ($application['programId'] ?? 0) === $programId
        && ($application['status'] ?? '') === 'pending'
        && $attendanceCount($programId, $youthId) === $beforeRows, $detail($registered));

    $listed = $req('GET', "$base/youth/applications?perPage=100", null, $youth);
    $sawApp = false;
    foreach (($data($listed)['items'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $applicationId && (int) ($item['programId'] ?? 0) === $programId) {
            $sawApp = true;
        }
    }
    $check('youth_lists_registration', $listed['status'] === 200 && $sawApp, $detail($listed));
    $youthAtt = $req('GET', "$base/youth/attendance", null, $youth);
    $attForProgram = false;
    foreach (($data($youthAtt)['items'] ?? []) as $item) {
        if ((int) ($item['programId'] ?? 0) === $programId) {
            $attForProgram = true;
        }
    }
    $check('registration_creates_no_attendance', $youthAtt['status'] === 200 && !$attForProgram, $detail($youthAtt));

    $duplicate = $req('POST', "$base/youth/programs/register", json_encode(['code' => $regCode]), $youth);
    $check('duplicate_register_409', $duplicate['status'] === 409
        && str_contains($duplicate['body'], 'ALREADY_REGISTERED')
        && str_contains($duplicate['body'], 'Already registered for this program.'), $detail($duplicate));

    $unknown = $req('POST', "$base/youth/programs/register", json_encode(['code' => 'REG-00000000']), $youth);
    $check('invalid_code_rejected', $unknown['status'] === 404
        && str_contains($unknown['body'], 'Invalid or unavailable registration code.'), $detail($unknown));

    $foreign = $req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => "RegQr Foreign {$stamp}",
        'category' => 'Sports & Recreation',
        'scheduledOn' => '2026-12-28',
        'startsAt' => '08:00',
        'endsAt' => '12:00',
        'location' => 'Other Court',
        'description' => 'Other organization',
        'status' => 'draft',
    ]), $sk2);
    $foreignId = (int) ($data($foreign)['id'] ?? 0);
    $foreignCode = (string) ($data($foreign)['registrationCode'] ?? '');
    if ($foreignId > 0) {
        $programIds[] = $foreignId;
    }
    $foreignPublish = $req('POST', "$base/sk/programs/{$foreignId}/status", json_encode(['status' => 'published']), $sk2);
    $foreignTry = $req('POST', "$base/youth/programs/register", json_encode(['code' => $foreignCode]), $youth);
    $check('other_org_code_rejected', $foreignPublish['status'] === 200 && $foreignTry['status'] === 404
        && str_contains($foreignTry['body'], 'Invalid or unavailable registration code.'), $detail($foreignTry));

    $session = $req('POST', "$base/sk/programs/{$programId}/attendance/session", '{}', $sk);
    $sessionData = $data($session);
    $attCode = (string) ($sessionData['publicCode'] ?? '');
    $sessionId = (int) ($sessionData['id'] ?? 0);
    if ($sessionId > 0) {
        $sessionIds[] = $sessionId;
    }
    $check('attendance_code_separate', $session['status'] === 201
        && preg_match('/^ATT-[A-F0-9]{8}$/', $attCode) === 1
        && $attCode !== $regCode, $detail($session));

    $regAsAtt = $req('POST', "$base/youth/attendance/check-in", json_encode(['code' => $regCode]), $youth);
    $check('registration_code_is_not_attendance', in_array($regAsAtt['status'], [404, 422], true)
        && str_contains($regAsAtt['body'], 'attendance code'), $detail($regAsAtt));

    $checked = $req('POST', "$base/youth/attendance/check-in", json_encode([
        'code' => $attCode,
        'youth_id' => 1,
        'organization_id' => 99,
        'program_id' => $draftId,
        'user_id' => 1,
        'role' => 'SYSTEM_ADMIN',
    ]), $youth);
    $attendance = $data($checked)['attendance'] ?? [];
    $attendanceId = (int) ($attendance['id'] ?? 0);
    if ($attendanceId > 0) {
        $attendanceIds[] = $attendanceId;
    }
    $check('attendance_present', $checked['status'] === 200
        && ($attendance['status'] ?? '') === 'present'
        && ($attendance['source'] ?? '') === 'self'
        && (int) ($attendance['youthId'] ?? 0) === $youthId
        && (int) ($attendance['programId'] ?? 0) === $programId, $detail($checked));

    $dupAtt = $req('POST', "$base/youth/attendance/check-in", json_encode(['code' => $attCode]), $youth);
    $check('duplicate_attendance_409', $dupAtt['status'] === 409
        && str_contains($dupAtt['body'], 'ATTENDANCE_ALREADY_RECORDED'), $detail($dupAtt));

    $skList = $req('GET', "$base/sk/programs/{$programId}/attendance", null, $sk);
    $skPresent = false;
    foreach (($data($skList)['items'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $attendanceId
            && ($item['status'] ?? '') === 'present'
            && ($item['source'] ?? '') === 'self') {
            $skPresent = true;
        }
    }
    $check('sk_sees_present', $skList['status'] === 200 && $skPresent, $detail($skList));

    $pdo->prepare("UPDATE attendance_qr_tokens SET status = 'closed' WHERE id = :id")->execute(['id' => $sessionId]);
    $closed = $req('POST', "$base/youth/attendance/check-in", json_encode(['code' => $attCode]), $youth);
    $check('closed_attendance_rejected', $closed['status'] === 422, $detail($closed));

    $event = $req('POST', "$base/sk/programs", json_encode([
        'kind' => 'event',
        'name' => "RegQr Event {$stamp}",
        'category' => 'Sports & Recreation',
        'scheduledOn' => '2026-12-30',
        'startsAt' => '13:00',
        'endsAt' => '16:00',
        'location' => 'Event Court',
        'description' => 'Registration QR event',
        'status' => 'draft',
    ]), $sk);
    $eventId = (int) ($data($event)['id'] ?? 0);
    $eventCode = (string) ($data($event)['registrationCode'] ?? '');
    if ($eventId > 0) {
        $programIds[] = $eventId;
    }
    $eventPublish = $req('POST', "$base/sk/programs/{$eventId}/status", json_encode(['status' => 'published']), $sk);
    $eventReg = $req('POST', "$base/youth/programs/register", json_encode(['code' => $eventCode]), $youth);
    $eventApp = $data($eventReg)['application'] ?? [];
    $eventAppId = (int) ($eventApp['id'] ?? 0);
    if ($eventAppId > 0) {
        $applicationIds[] = $eventAppId;
    }
    $check('event_register', $eventPublish['status'] === 200 && $eventReg['status'] === 201
        && ($data($eventReg)['program']['kind'] ?? '') === 'event'
        && (int) ($eventApp['programId'] ?? 0) === $eventId
        && $attendanceCount($eventId, $youthId) === 0, $detail($eventReg));
    $eventSession = $req('POST', "$base/sk/programs/{$eventId}/attendance/session", '{}', $sk);
    $eventAttCode = (string) ($data($eventSession)['publicCode'] ?? '');
    $eventSessionId = (int) ($data($eventSession)['id'] ?? 0);
    if ($eventSessionId > 0) {
        $sessionIds[] = $eventSessionId;
    }
    $eventCheck = $req('POST', "$base/youth/attendance/check-in", json_encode(['code' => $eventAttCode]), $youth);
    $eventAttendance = $data($eventCheck)['attendance'] ?? [];
    $eventAttendanceId = (int) ($eventAttendance['id'] ?? 0);
    if ($eventAttendanceId > 0) {
        $attendanceIds[] = $eventAttendanceId;
    }
    $check('event_attendance_present', $eventCheck['status'] === 200
        && $eventAttCode !== $eventCode
        && ($eventAttendance['status'] ?? '') === 'present'
        && ($eventAttendance['source'] ?? '') === 'self', $detail($eventCheck));
} finally {
    if ($applicationIds) {
        $in = implode(',', array_map('intval', $applicationIds));
        $pdo->exec("DELETE FROM application_submissions WHERE application_id IN ({$in})");
        $pdo->exec("DELETE FROM applications WHERE id IN ({$in})");
    }
    if ($attendanceIds) {
        $in = implode(',', array_map('intval', $attendanceIds));
        $pdo->exec("DELETE FROM attendance WHERE id IN ({$in})");
    }
    if ($sessionIds) {
        $in = implode(',', array_map('intval', $sessionIds));
        $pdo->exec("DELETE FROM attendance_qr_tokens WHERE id IN ({$in})");
    }
    if ($programIds) {
        $in = implode(',', array_map('intval', $programIds));
        $pdo->exec("DELETE FROM attendance WHERE program_id IN ({$in})");
        $pdo->exec("DELETE FROM attendance_qr_tokens WHERE program_id IN ({$in})");
        $pdo->exec("DELETE FROM application_submissions WHERE application_id IN (SELECT id FROM applications WHERE program_id IN ({$in}))");
        $pdo->exec("DELETE FROM applications WHERE program_id IN ({$in})");
        $pdo->exec("DELETE FROM programs WHERE id IN ({$in}) AND name LIKE 'RegQr %'");
    }
    foreach ([$anon, $admin, $sk, $sk2, $youth] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

$afterPrograms = $ids('programs');
$afterApplications = $ids('applications');
$afterAttendance = $ids('attendance');
$afterSessions = $ids('attendance_qr_tokens');
$keptAfter = $pdo->query('SELECT id FROM programs WHERE id IN (9, 13) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$check('cleanup_programs', $beforePrograms === $afterPrograms);
$check('cleanup_applications', $beforeApplications === $afterApplications);
$check('cleanup_attendance', $beforeAttendance === $afterAttendance);
$check('cleanup_sessions', $beforeSessions === $afterSessions);
$check('kept_demo_programs', $keptPrograms === $keptAfter);

if ($failures > 0) {
    echo "FAILED {$failures}\n";
    exit(1);
}
echo "ALL PASSED\n";
