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
$eventIds = [];
$sessionIds = [];
$attendanceIds = [];
$createdYouthId = 0;
$createdUserId = 0;
$createdEmail = "att-http-{$stamp}@example.test";
$qrUsesBefore = null;
$orgId = 0;

$ids = static function (string $table) use ($pdo): array {
    $rows = $pdo->query("SELECT id FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows ?: []);
};

$beforePrograms = $ids('programs');
$beforeAttendance = $ids('attendance');
$beforeSessions = $ids('attendance_qr_tokens');
$beforeYouth = $ids('youth');
$beforeUsers = $ids('users');
$beforeApplications = $ids('applications');
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
    return 'status=' . $response['status'] . ' body=' . substr($response['body'], 0, 280);
};

$cookie = static function (string $name) use ($stamp): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . $stamp . '.txt';
};

$anon = $cookie('ys_att_anon_');
$admin = $cookie('ys_att_admin_');
$sk = $cookie('ys_att_sk_');
$sk2 = $cookie('ys_att_sk2_');
$youth = $cookie('ys_att_youth_');
$youthB = $cookie('ys_att_youthb_');

try {
    $anonGet = $req('GET', "$base/youth/attendance?youth_id=16&organization_id=2", null, $anon);
    $check('anonymous_youth_attendance_401', $anonGet['status'] === 401, $detail($anonGet));
    $anonSk = $req('GET', "$base/sk/programs/9/attendance", null, $anon);
    $check('anonymous_sk_attendance_401', $anonSk['status'] === 401, $detail($anonSk));

    $adminLogin = $req('POST', "$base/auth/login", json_encode([
        'email' => 'admin@skmms.test',
        'password' => 'YouthSync1!',
    ]), $admin);
    $check('admin_login', $adminLogin['status'] === 200, $detail($adminLogin));
    $adminSk = $req('GET', "$base/sk/programs/9/attendance", null, $admin);
    $check('admin_sk_attendance_403', $adminSk['status'] === 403, $detail($adminSk));
    $adminYouth = $req('GET', "$base/youth/attendance", null, $admin);
    $check('admin_youth_attendance_403', $adminYouth['status'] === 403, $detail($adminYouth));

    $youthLogin = $req('POST', "$base/auth/login", json_encode([
        'email' => 'youth-auth-test@example.test',
        'password' => 'YouthAuthTest1!',
    ]), $youth);
    $youthMe = $data($req('GET', "$base/auth/me", null, $youth));
    $youthId = (int) ($youthMe['youth']['id'] ?? 0);
    $check('youth_login', $youthLogin['status'] === 200 && $youthId > 0, $detail($youthLogin));
    $youthSk = $req('POST', "$base/sk/attendance/scan", json_encode(['token' => 'YSYOUTH-YTH-1', 'sessionToken' => 'nope']), $youth);
    $check('youth_cannot_scan', $youthSk['status'] === 403, $detail($youthSk));
    $youthManual = $req('POST', "$base/sk/attendance/manual", json_encode(['programId' => 9, 'youthId' => $youthId]), $youth);
    $check('youth_cannot_manage_attendance', $youthManual['status'] === 403, $detail($youthManual));

    $codeStmt = $pdo->prepare('SELECT code FROM youth WHERE id = :id');
    $codeStmt->execute(['id' => $youthId]);
    $youthCode = (string) $codeStmt->fetchColumn();
    $check('youth_code_present', $youthCode !== '');

    $skLogin = $req('POST', "$base/auth/login", json_encode([
        'email' => 'sk1@demo.test',
        'password' => 'YouthSync1!',
    ]), $sk);
    $skMe = $data($req('GET', "$base/auth/me", null, $sk));
    $orgId = (int) ($skMe['organization']['id'] ?? $skMe['organizationId'] ?? 0);
    $check('sk_login', $skLogin['status'] === 200 && $orgId > 0, $detail($skLogin));
    $qrStmt = $pdo->prepare('SELECT qr_uses FROM organizations WHERE id = :id');
    $qrStmt->execute(['id' => $orgId]);
    $qrUsesBefore = (int) $qrStmt->fetchColumn();

    $sk2Login = $req('POST', "$base/auth/login", json_encode([
        'email' => 'sk2@demo.test',
        'password' => 'YouthSync1!',
    ]), $sk2);
    $check('sk2_login', $sk2Login['status'] === 200, $detail($sk2Login));

    $date = '2026-10-07';
    $programName = "AttHttp Program {$stamp}";
    $created = $req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => $programName,
        'category' => 'Sports & Recreation',
        'scheduledOn' => $date,
        'startsAt' => '08:00',
        'endsAt' => '12:00',
        'location' => 'Covered Court',
        'description' => 'Attendance HTTP test',
        'status' => 'draft',
        'organization_id' => 2,
    ]), $sk);
    $programId = (int) ($data($created)['id'] ?? 0);
    if ($programId > 0) {
        $programIds[] = $programId;
    }
    $check('create_program', $created['status'] === 201 && $programId > 0, $detail($created));
    $draftSession = $req('POST', "$base/sk/programs/{$programId}/attendance/session", json_encode([
        'organization_id' => 2,
    ]), $sk);
    $check('draft_session_rejected', $draftSession['status'] === 422, $detail($draftSession));

    $published = $req('POST', "$base/sk/programs/{$programId}/status", json_encode([
        'status' => 'published',
        'organization_id' => 2,
    ]), $sk);
    $check('publish_program', $published['status'] === 200, $detail($published));

    $session = $req('POST', "$base/sk/programs/{$programId}/attendance/session", json_encode([
        'organization_id' => 999,
    ]), $sk);
    $sessionData = $data($session);
    $sessionId = (int) ($sessionData['id'] ?? 0);
    $sessionToken = (string) ($sessionData['token'] ?? '');
    if ($sessionId > 0) {
        $sessionIds[] = $sessionId;
    }
    $check('start_session', $session['status'] === 201 && $sessionToken !== '' && str_starts_with((string) ($sessionData['publicCode'] ?? ''), 'ATT-'), $detail($session));

    $scan = $req('POST', "$base/sk/attendance/scan", json_encode([
        'token' => 'YSYOUTH-' . $youthCode,
        'sessionToken' => $sessionToken,
        'programId' => $programId,
        'organization_id' => 2,
        'youth_id' => 99999,
    ]), $sk);
    $scanData = $data($scan);
    $attendanceId = (int) ($scanData['attendance']['id'] ?? 0);
    if ($attendanceId > 0) {
        $attendanceIds[] = $attendanceId;
    }
    $check('scan_pending', in_array($scan['status'], [200, 201], true)
        && ($scanData['attendance']['status'] ?? '') === 'pending'
        && (int) ($scanData['attendance']['youthId'] ?? 0) === $youthId, $detail($scan));

    $confirm = $req('POST', "$base/sk/attendance/confirm", json_encode([
        'attendanceId' => $attendanceId,
        'organization_id' => 2,
        'youth_id' => 1,
    ]), $sk);
    $check('confirm_present', $confirm['status'] === 200 && ($data($confirm)['attendance']['status'] ?? '') === 'present', $detail($confirm));

    $rowStmt = $pdo->prepare('SELECT organization_id, program_id, youth_id, status, source FROM attendance WHERE id = :id');
    $rowStmt->execute(['id' => $attendanceId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('row_exists', (int) ($row['organization_id'] ?? 0) === $orgId
        && (int) ($row['program_id'] ?? 0) === $programId
        && (int) ($row['youth_id'] ?? 0) === $youthId
        && ($row['status'] ?? '') === 'present'
        && ($row['source'] ?? '') === 'qr', json_encode($row));

    $duplicate = $req('POST', "$base/sk/attendance/scan", json_encode([
        'token' => 'YSYOUTH-' . $youthCode,
        'sessionToken' => $sessionToken,
        'programId' => $programId,
    ]), $sk);
    $check('duplicate_scan_rejected', $duplicate['status'] === 409, $detail($duplicate));

    $own = $req('GET', "$base/youth/attendance?youth_id=1&organization_id=2", null, $youth);
    $ownItems = $data($own)['items'] ?? [];
    $sawOwn = false;
    $onlyOwn = true;
    if (is_array($ownItems)) {
        foreach ($ownItems as $item) {
            if ((int) ($item['youthId'] ?? 0) !== $youthId) {
                $onlyOwn = false;
            }
            if ((int) ($item['id'] ?? 0) === $attendanceId
                && ($item['status'] ?? '') === 'present'
                && ($item['program']['name'] ?? '') === $programName
                && ($item['program']['location'] ?? '') === 'Covered Court') {
                $sawOwn = true;
            }
        }
    }
    $check('youth_sees_own', $own['status'] === 200 && $sawOwn && $onlyOwn, $detail($own));

    $createdYouth = $req('POST', "$base/sk/youth", json_encode([
        'firstName' => 'AttHttp',
        'lastName' => 'Other' . $stamp,
        'birthDate' => '2006-04-04',
        'gender' => 'Female',
        'address' => 'Attendance test address',
        'contact' => '09170001111',
        'civilStatus' => 'Single',
        'educationStatus' => 'Currently Studying',
        'education' => 'Senior High School',
        'employment' => 'Student',
        'interests' => ['Sports'],
        'createAccount' => true,
        'accountEmail' => $createdEmail,
        'organization_id' => 2,
    ]), $sk);
    $createdData = $data($createdYouth);
    $createdYouthId = (int) ($createdData['youth']['id'] ?? 0);
    $createdUserId = (int) ($createdData['user']['id'] ?? 0);
    $temporary = (string) ($createdData['temporaryPassword'] ?? $createdData['temporary_password'] ?? '');
    $check('second_youth_created', $createdYouth['status'] === 201 && $createdYouthId > 0 && $temporary !== '', 'status=' . $createdYouth['status']);

    $otherLogin = $req('POST', "$base/auth/login", json_encode([
        'email' => $createdEmail,
        'password' => $temporary,
    ]), $youthB);
    $otherList = $req('GET', "$base/youth/attendance?youth_id={$youthId}&organization_id={$orgId}", null, $youthB);
    $otherItems = $data($otherList)['items'] ?? [];
    $leaked = false;
    if (is_array($otherItems)) {
        foreach ($otherItems as $item) {
            if ((int) ($item['id'] ?? 0) === $attendanceId || (int) ($item['youthId'] ?? 0) === $youthId) {
                $leaked = true;
            }
        }
    }
    $check('other_youth_cannot_see', $otherLogin['status'] === 200 && $otherList['status'] === 200 && !$leaked, $detail($otherList));

    $foreign = $req('GET', "$base/sk/programs/{$programId}/attendance?organization_id={$orgId}", null, $sk2);
    $check('other_org_cannot_list', $foreign['status'] === 404, $detail($foreign));
    $foreignScan = $req('POST', "$base/sk/attendance/scan", json_encode([
        'token' => 'YSYOUTH-' . $youthCode,
        'sessionToken' => $sessionToken,
        'programId' => $programId,
        'organization_id' => $orgId,
    ]), $sk2);
    $check('other_org_cannot_scan', $foreignScan['status'] === 404, $detail($foreignScan));
    $foreignPatch = $req('PATCH', "$base/sk/attendance/{$attendanceId}", json_encode([
        'status' => 'absent',
        'organization_id' => $orgId,
    ]), $sk2);
    $check('other_org_cannot_update', $foreignPatch['status'] === 404, $detail($foreignPatch));

    $manual = $req('POST', "$base/sk/attendance/manual", json_encode([
        'programId' => $programId,
        'youthId' => $createdYouthId,
        'organization_id' => 2,
        'youth_id' => $youthId,
    ]), $sk);
    $manualData = $data($manual);
    $manualId = (int) ($manualData['attendance']['id'] ?? 0);
    if ($manualId > 0) {
        $attendanceIds[] = $manualId;
    }
    $check('manual_pending', $manual['status'] === 201
        && ($manualData['attendance']['status'] ?? '') === 'pending'
        && ($manualData['attendance']['source'] ?? '') === 'manual'
        && (int) ($manualData['attendance']['youthId'] ?? 0) === $createdYouthId, $detail($manual));
    $manualConfirm = $req('POST', "$base/sk/attendance/confirm", json_encode(['attendanceId' => $manualId]), $sk);
    $check('manual_confirm', $manualConfirm['status'] === 200 && ($data($manualConfirm)['attendance']['status'] ?? '') === 'present', $detail($manualConfirm));
    $manualAgain = $req('POST', "$base/sk/attendance/manual", json_encode([
        'programId' => $programId,
        'youthId' => $createdYouthId,
    ]), $sk);
    $check('duplicate_manual_rejected', $manualAgain['status'] === 409, $detail($manualAgain));

    $status = $req('PATCH', "$base/sk/attendance/{$manualId}", json_encode([
        'status' => 'absent',
        'organization_id' => 2,
        'youth_id' => $youthId,
    ]), $sk);
    $check('status_absent', $status['status'] === 200 && ($data($status)['status'] ?? $data($status)['attendance']['status'] ?? '') === 'absent', $detail($status));
    $listed = $req('GET', "$base/sk/programs/{$programId}/attendance", null, $sk);
    $listedAbsent = false;
    foreach (($data($listed)['items'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $manualId && ($item['status'] ?? '') === 'absent') {
            $listedAbsent = true;
        }
    }
    $check('status_persists', $listed['status'] === 200 && $listedAbsent, $detail($listed));

    $eventName = "AttHttp Event {$stamp}";
    $eventCreated = $req('POST', "$base/sk/events", json_encode([
        'name' => $eventName,
        'category' => 'Sports & Recreation',
        'scheduledOn' => $date,
        'startsAt' => '13:00',
        'endsAt' => '16:00',
        'location' => 'Plaza',
        'status' => 'published',
        'organization_id' => 2,
    ]), $sk);
    $eventId = (int) ($data($eventCreated)['id'] ?? 0);
    if ($eventId > 0) {
        $eventIds[] = $eventId;
    }
    $check('create_event', $eventCreated['status'] === 201 && ($data($eventCreated)['kind'] ?? '') === 'event', $detail($eventCreated));
    $eventSession = $req('POST', "$base/sk/programs/{$eventId}/attendance/session", '{}', $sk);
    $eventSessionData = $data($eventSession);
    $eventSessionId = (int) ($eventSessionData['id'] ?? 0);
    if ($eventSessionId > 0) {
        $sessionIds[] = $eventSessionId;
    }
    $check('event_session', $eventSession['status'] === 201 && ($eventSessionData['token'] ?? '') !== '', $detail($eventSession));
    $eventManual = $req('POST', "$base/sk/attendance/manual", json_encode([
        'programId' => $eventId,
        'youthId' => $youthId,
    ]), $sk);
    $eventAttendanceId = (int) ($data($eventManual)['attendance']['id'] ?? 0);
    if ($eventAttendanceId > 0) {
        $attendanceIds[] = $eventAttendanceId;
    }
    $eventConfirm = $req('POST', "$base/sk/attendance/confirm", json_encode(['attendanceId' => $eventAttendanceId]), $sk);
    $eventRow = $pdo->prepare('SELECT a.status, p.kind FROM attendance a INNER JOIN programs p ON p.id = a.program_id WHERE a.id = :id');
    $eventRow->execute(['id' => $eventAttendanceId]);
    $eventSaved = $eventRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('event_attendance', $eventConfirm['status'] === 200 && ($eventSaved['status'] ?? '') === 'present' && ($eventSaved['kind'] ?? '') === 'event', $detail($eventConfirm));

    $selfName = "AttHttp Self Program {$stamp}";
    $selfCreated = $req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => $selfName,
        'category' => 'Leadership',
        'scheduledOn' => $date,
        'startsAt' => '09:00',
        'endsAt' => '11:00',
        'location' => 'Hall',
        'status' => 'published',
        'organization_id' => 2,
        'program_id' => 1,
    ]), $sk);
    $selfProgramId = (int) ($data($selfCreated)['id'] ?? 0);
    if ($selfProgramId > 0) {
        $programIds[] = $selfProgramId;
    }
    $selfSession = $req('POST', "$base/sk/programs/{$selfProgramId}/attendance/session", '{}', $sk);
    $selfSessionData = $data($selfSession);
    $selfSessionId = (int) ($selfSessionData['id'] ?? 0);
    $selfCode = (string) ($selfSessionData['publicCode'] ?? '');
    if ($selfSessionId > 0) {
        $sessionIds[] = $selfSessionId;
    }
    $check('self_session', $selfSession['status'] === 201 && preg_match('/^ATT-[A-F0-9]{8}$/', $selfCode) === 1, $detail($selfSession));

    $anonCheck = $req('POST', "$base/youth/attendance/check-in", json_encode(['code' => $selfCode, 'youth_id' => $youthId]), $anon);
    $check('anon_checkin_401', $anonCheck['status'] === 401, $detail($anonCheck));
    $adminCheck = $req('POST', "$base/youth/attendance/check-in", json_encode(['code' => $selfCode]), $admin);
    $check('admin_checkin_403', $adminCheck['status'] === 403, $detail($adminCheck));
    $skCheck = $req('POST', "$base/youth/attendance/check-in", json_encode(['code' => $selfCode]), $sk);
    $check('sk_checkin_403', $skCheck['status'] === 403, $detail($skCheck));

    $spoof = $req('POST', "$base/youth/attendance/check-in", json_encode([
        'code' => $selfCode,
        'youth_id' => $youthId,
        'organization_id' => 2,
        'program_id' => $programId,
        'user_id' => 1,
        'role' => 'SK_OFFICIAL',
    ]), $youthB);
    $spoofRow = $data($spoof)['attendance'] ?? [];
    $spoofId = (int) ($spoofRow['id'] ?? 0);
    if ($spoofId > 0) {
        $attendanceIds[] = $spoofId;
    }
    $check('spoofed_ids_ignored', $spoof['status'] === 200
        && (int) ($spoofRow['youthId'] ?? 0) === $createdYouthId
        && (int) ($spoofRow['programId'] ?? 0) === $selfProgramId
        && ($spoofRow['status'] ?? '') === 'present'
        && ($spoofRow['source'] ?? '') === 'self', $detail($spoof));

    $selfIn = $req('POST', "$base/youth/attendance/check-in", json_encode([
        'code' => $selfCode,
        'youth_id' => $createdYouthId,
        'organization_id' => 999,
        'program_id' => $eventId,
    ]), $youth);
    $selfAtt = $data($selfIn)['attendance'] ?? [];
    $selfAttId = (int) ($selfAtt['id'] ?? 0);
    if ($selfAttId > 0) {
        $attendanceIds[] = $selfAttId;
    }
    $check('self_checkin', $selfIn['status'] === 200
        && ($data($selfIn)['message'] ?? '') === 'Attendance recorded successfully.'
        && (int) ($selfAtt['youthId'] ?? 0) === $youthId
        && (int) ($selfAtt['programId'] ?? 0) === $selfProgramId
        && ($selfAtt['program']['kind'] ?? '') === 'program'
        && ($selfAtt['status'] ?? '') === 'present', $detail($selfIn));
    $selfList = $req('GET', "$base/youth/attendance", null, $youth);
    $sawSelf = false;
    foreach (($data($selfList)['items'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $selfAttId) {
            $sawSelf = true;
        }
    }
    $check('self_checkin_listed', $selfList['status'] === 200 && $sawSelf, $detail($selfList));
    $skSelf = $req('GET', "$base/sk/programs/{$selfProgramId}/attendance", null, $sk);
    $skSawPresent = false;
    foreach (($data($skSelf)['items'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $selfAttId
            && ($item['status'] ?? '') === 'present'
            && ($item['source'] ?? '') === 'self'
            && (int) ($item['youthId'] ?? 0) === $youthId) {
            $skSawPresent = true;
        }
    }
    $check('sk_list_self_present', $skSelf['status'] === 200 && $skSawPresent, $detail($skSelf));
    $again = $req('POST', "$base/youth/attendance/check-in", json_encode(['code' => $selfCode]), $youth);
    $check('self_duplicate_409', $again['status'] === 409 && str_contains($again['body'], 'ATTENDANCE_ALREADY_RECORDED'), $detail($again));

    $pdo->prepare("UPDATE attendance_qr_tokens SET status = 'closed' WHERE id = :id")->execute(['id' => $selfSessionId]);
    $closed = $req('POST', "$base/youth/attendance/check-in", json_encode(['code' => $selfCode]), $youth);
    $check('closed_session_rejected', $closed['status'] === 422, $detail($closed));

    $expiredSession = $req('POST', "$base/sk/programs/{$selfProgramId}/attendance/session", '{}', $sk);
    $expiredData = $data($expiredSession);
    $expiredId = (int) ($expiredData['id'] ?? 0);
    $expiredCode = (string) ($expiredData['publicCode'] ?? '');
    if ($expiredId > 0) {
        $sessionIds[] = $expiredId;
    }
    $pdo->prepare('UPDATE attendance_qr_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id')->execute(['id' => $expiredId]);
    $expired = $req('POST', "$base/youth/attendance/check-in", json_encode(['code' => $expiredCode]), $youth);
    $check('expired_session_rejected', $expired['status'] === 422, $detail($expired));
    $unknown = $req('POST', "$base/youth/attendance/check-in", json_encode(['code' => 'ATT-00000000']), $youth);
    $check('unknown_code_rejected', $unknown['status'] === 404, $detail($unknown));

    $foreignProgram = $req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => "AttHttp Foreign {$stamp}",
        'category' => 'Leadership',
        'scheduledOn' => $date,
        'status' => 'published',
    ]), $sk2);
    $foreignProgramId = (int) ($data($foreignProgram)['id'] ?? 0);
    if ($foreignProgramId > 0) {
        $programIds[] = $foreignProgramId;
    }
    $foreignSession = $req('POST', "$base/sk/programs/{$foreignProgramId}/attendance/session", '{}', $sk2);
    $foreignCode = (string) ($data($foreignSession)['publicCode'] ?? '');
    $foreignSessionId = (int) ($data($foreignSession)['id'] ?? 0);
    if ($foreignSessionId > 0) {
        $sessionIds[] = $foreignSessionId;
    }
    $foreignCheck = $req('POST', "$base/youth/attendance/check-in", json_encode([
        'code' => $foreignCode,
        'organization_id' => 2,
        'program_id' => $foreignProgramId,
    ]), $youth);
    $check('other_org_code_rejected', $foreignSession['status'] === 201 && $foreignCheck['status'] === 404, $detail($foreignCheck));

    $selfEvent = $req('POST', "$base/sk/events", json_encode([
        'name' => "AttHttp Self Event {$stamp}",
        'category' => 'Sports & Recreation',
        'scheduledOn' => $date,
        'startsAt' => '14:00',
        'endsAt' => '15:00',
        'location' => 'Court',
        'status' => 'published',
        'organization_id' => 2,
    ]), $sk);
    $selfEventId = (int) ($data($selfEvent)['id'] ?? 0);
    if ($selfEventId > 0) {
        $eventIds[] = $selfEventId;
    }
    $selfEventSession = $req('POST', "$base/sk/programs/{$selfEventId}/attendance/session", '{}', $sk);
    $selfEventCode = (string) ($data($selfEventSession)['publicCode'] ?? '');
    $selfEventSessionId = (int) ($data($selfEventSession)['id'] ?? 0);
    if ($selfEventSessionId > 0) {
        $sessionIds[] = $selfEventSessionId;
    }
    $eventCheck = $req('POST', "$base/youth/attendance/check-in", json_encode([
        'code' => $selfEventCode,
        'program_id' => $selfProgramId,
    ]), $youth);
    $eventCheckAtt = $data($eventCheck)['attendance'] ?? [];
    $eventCheckId = (int) ($eventCheckAtt['id'] ?? 0);
    if ($eventCheckId > 0) {
        $attendanceIds[] = $eventCheckId;
    }
    $check('event_self_checkin', $eventCheck['status'] === 200
        && ($eventCheckAtt['program']['kind'] ?? '') === 'event'
        && (int) ($eventCheckAtt['programId'] ?? 0) === $selfEventId
        && ($eventCheckAtt['status'] ?? '') === 'present'
        && ($eventCheckAtt['source'] ?? '') === 'self', $detail($eventCheck));
    $skEvent = $req('GET', "$base/sk/programs/{$selfEventId}/attendance", null, $sk);
    $skEventPresent = false;
    foreach (($data($skEvent)['items'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $eventCheckId && ($item['status'] ?? '') === 'present' && ($item['source'] ?? '') === 'self') {
            $skEventPresent = true;
        }
    }
    $check('sk_list_event_self_present', $skEvent['status'] === 200 && $skEventPresent, $detail($skEvent));

    $programsStill = $req('GET', "$base/sk/programs?perPage=5", null, $sk);
    $appsStill = $req('GET', "$base/sk/applications?perPage=5", null, $sk);
    $assistStill = $req('GET', "$base/sk/assistance?perPage=5", null, $sk);
    $youthApps = $req('GET', "$base/youth/applications?perPage=5", null, $youth);
    $check('programs_still_work', $programsStill['status'] === 200, $detail($programsStill));
    $check('applications_still_work', $appsStill['status'] === 200 && $youthApps['status'] === 200, $detail($appsStill) . ' ' . $detail($youthApps));
    $check('requirements_still_work', $assistStill['status'] === 200, $detail($assistStill));

    $logout = $req('POST', "$base/auth/logout", '{}', $sk);
    $after = $req('GET', "$base/sk/programs/{$programId}/attendance", null, $sk);
    $check('logout_blocks_attendance', $logout['status'] === 200 && $after['status'] === 401, $detail($after));
} finally {
    foreach ($attendanceIds as $id) {
        $pdo->prepare('DELETE FROM attendance WHERE id = :id')->execute(['id' => $id]);
    }
    foreach ($sessionIds as $id) {
        $pdo->prepare('DELETE FROM attendance_qr_tokens WHERE id = :id')->execute(['id' => $id]);
    }
    foreach (array_merge($eventIds, $programIds) as $id) {
        $pdo->prepare('DELETE FROM programs WHERE id = :id AND name LIKE :name')->execute([
            'id' => $id,
            'name' => 'AttHttp %',
        ]);
    }
    if ($createdUserId > 0) {
        $pdo->prepare('DELETE FROM organization_users WHERE user_id = :id')->execute(['id' => $createdUserId]);
        $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :email')->execute([
            'id' => $createdUserId,
            'email' => $createdEmail,
        ]);
    }
    if ($createdYouthId > 0) {
        $pdo->prepare('DELETE FROM youth WHERE id = :id AND first_name = :first')->execute([
            'id' => $createdYouthId,
            'first' => 'AttHttp',
        ]);
    }
    if ($orgId > 0 && $qrUsesBefore !== null) {
        $pdo->prepare('UPDATE organizations SET qr_uses = :uses WHERE id = :id')->execute([
            'uses' => $qrUsesBefore,
            'id' => $orgId,
        ]);
    }

    $same = static function (array $before, array $after): bool {
        return $before === $after;
    };
    $check('cleanup_programs', $same($beforePrograms, $ids('programs')));
    $check('cleanup_attendance', $same($beforeAttendance, $ids('attendance')));
    $check('cleanup_sessions', $same($beforeSessions, $ids('attendance_qr_tokens')));
    $check('cleanup_youth', $same($beforeYouth, $ids('youth')));
    $check('cleanup_users', $same($beforeUsers, $ids('users')));
    $check('cleanup_applications', $same($beforeApplications, $ids('applications')));
    $keptAfter = $pdo->query('SELECT id FROM programs WHERE id IN (9, 13) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $check('kept_demo_programs', $keptPrograms === $keptAfter);
    if ($orgId > 0 && $qrUsesBefore !== null) {
        $qrStmt = $pdo->prepare('SELECT qr_uses FROM organizations WHERE id = :id');
        $qrStmt->execute(['id' => $orgId]);
        $check('qr_uses_restored', (int) $qrStmt->fetchColumn() === $qrUsesBefore);
    }

    foreach ([$anon, $admin, $sk, $sk2, $youth, $youthB] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if ($failures > 0) {
    echo "FAILED {$failures}\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
