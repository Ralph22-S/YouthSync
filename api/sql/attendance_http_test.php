<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();
$leftoverYouth = $pdo->query(
    "SELECT id, organization_id, first_name, last_name FROM youth
     WHERE first_name = 'PhaseFour' AND last_name LIKE 'Test%'"
)->fetchAll(PDO::FETCH_ASSOC);
$leftoverPrograms = $pdo->query(
    "SELECT id, organization_id, name FROM programs WHERE name LIKE 'PhaseFour Test%'"
)->fetchAll(PDO::FETCH_ASSOC);
if ($leftoverYouth || $leftoverPrograms) {
    fwrite(STDERR, "STOP: pre-existing PhaseFour test records found. No cleanup was performed.\n");
    exit(2);
}

$base = 'http://localhost/YouthSync_UI_Refresh/api';
$usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$orgsBefore = (int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$youthBefore = (int) $pdo->query('SELECT COUNT(*) FROM youth')->fetchColumn();
$programsBefore = (int) $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn();
$attendanceBefore = (int) $pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn();
$sessionsBefore = (int) $pdo->query('SELECT COUNT(*) FROM attendance_qr_tokens')->fetchColumn();

$qrBefore = [];
foreach ($pdo->query('SELECT id, qr_uses FROM organizations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $qrBefore[(int) $row['id']] = (int) $row['qr_uses'];
}

function req(string $name, string $method, string $url, ?string $json, string $cookie): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 20,
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
        return ['name' => $name, 'status' => 0, 'body' => $err];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($raw, $headerSize);
    curl_close($ch);
    return ['name' => $name, 'status' => $status, 'body' => $body];
}

function payload(array $t): array
{
    $decoded = json_decode($t['body'], true);
    return is_array($decoded) ? $decoded : [];
}

function attendanceIdFrom(array $t): int
{
    $data = payload($t)['data'] ?? [];
    return (int) ($data['attendance']['id'] ?? $data['id'] ?? 0);
}

$sk1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p4_sk1.txt';
$sk2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p4_sk2.txt';
$empty = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p4_empty.txt';
foreach ([$sk1, $sk2, $empty] as $f) {
    @unlink($f);
}

$login = static fn (string $email): string => json_encode(['email' => $email, 'password' => 'YouthSync1!']);
$createdProgramIds = [];
$createdYouthIds = [];
$createdAttendanceIds = [];
$createdSessionIds = [];
$tests = [];
$expect = [];

$tests[] = req('unauth_attendance', 'GET', "$base/sk/programs/1/attendance", null, $empty);
$expect['unauth_attendance'] = [401];

$tests[] = req('login_sk1', 'POST', "$base/auth/login", $login('sk1@demo.test'), $sk1);
$expect['login_sk1'] = [200];

$createdProgram = req('create_program', 'POST', "$base/sk/programs", json_encode([
    'kind' => 'program',
    'name' => 'PhaseFour Test Program',
    'category' => 'Leadership',
    'scheduledOn' => '2026-12-01',
    'startsAt' => '08:00',
    'endsAt' => '12:00',
    'location' => 'Barangay Hall',
    'status' => 'draft',
    'organization_id' => 999,
]), $sk1);
$tests[] = $createdProgram;
$expect['create_program'] = [201];
$programId = (int) (payload($createdProgram)['data']['id'] ?? 0);
if ($programId) {
    $createdProgramIds[] = $programId;
}

$tests[] = req('session_on_draft', 'POST', "$base/sk/programs/{$programId}/attendance/session", json_encode([
    'organization_id' => 2,
]), $sk1);
$expect['session_on_draft'] = [422];

$tests[] = req('publish_program', 'POST', "$base/sk/programs/{$programId}/status", json_encode([
    'status' => 'published',
]), $sk1);
$expect['publish_program'] = [200];

$sessionCreate = req('create_session', 'POST', "$base/sk/programs/{$programId}/attendance/session", json_encode([
    'organization_id' => 4,
    'orgId' => 2,
]), $sk1);
$tests[] = $sessionCreate;
$expect['create_session'] = [201, 200];
$sessionData = payload($sessionCreate)['data'] ?? [];
$sessionId = (int) ($sessionData['id'] ?? 0);
$sessionToken = (string) ($sessionData['token'] ?? '');
if ($sessionId) {
    $createdSessionIds[] = $sessionId;
}

$sessionGet = req('get_session', 'GET', "$base/sk/programs/{$programId}/attendance/session", null, $sk1);
$tests[] = $sessionGet;
$expect['get_session'] = [200];

$youthCreate = req('create_youth', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'PhaseFour',
    'lastName' => 'TestYouth',
    'birthDate' => '2005-03-03',
    'address' => 'Phase 4 test address',
    'contact' => '09170004401',
    'interests' => ['Sports'],
    'organization_id' => 2,
]), $sk1);
$tests[] = $youthCreate;
$expect['create_youth'] = [201];
$youthPayload = payload($youthCreate)['data']['youth'] ?? payload($youthCreate)['data'] ?? [];
$youthId = (int) ($youthPayload['id'] ?? 0);
$youthQr = (string) ($youthPayload['qrToken'] ?? '');
if ($youthId) {
    $createdYouthIds[] = $youthId;
}

$scan = req('scan_valid', 'POST', "$base/sk/attendance/scan", json_encode([
    'token' => $youthQr,
    'sessionToken' => $sessionToken,
    'programId' => $programId,
    'organization_id' => 2,
]), $sk1);
$tests[] = $scan;
$expect['scan_valid'] = [201, 200];
$attendanceId = attendanceIdFrom($scan);
if ($attendanceId) {
    $createdAttendanceIds[] = $attendanceId;
}

$list = req('list_attendance', 'GET', "$base/sk/programs/{$programId}/attendance", null, $sk1);
$tests[] = $list;
$expect['list_attendance'] = [200];

$tests[] = req('get_attendance', 'GET', "$base/sk/attendance/{$attendanceId}", null, $sk1);
$expect['get_attendance'] = [200];

$tests[] = req('confirm_attendance', 'POST', "$base/sk/attendance/confirm", json_encode([
    'attendanceId' => $attendanceId,
    'organization_id' => 9,
]), $sk1);
$expect['confirm_attendance'] = [200];

$scanDuplicate = req('scan_duplicate', 'POST', "$base/sk/attendance/scan", json_encode([
    'token' => $youthQr,
    'sessionToken' => $sessionToken,
    'programId' => $programId,
]), $sk1);
$tests[] = $scanDuplicate;
$expect['scan_duplicate'] = [409];
if ($id = attendanceIdFrom($scanDuplicate)) {
    $createdAttendanceIds[] = $id;
}

$scanInvalid = req('scan_invalid_qr', 'POST', "$base/sk/attendance/scan", json_encode([
    'token' => 'NOT-A-YOUTH-QR',
    'sessionToken' => $sessionToken,
]), $sk1);
$tests[] = $scanInvalid;
$expect['scan_invalid_qr'] = [422, 404];
if ($id = attendanceIdFrom($scanInvalid)) {
    $createdAttendanceIds[] = $id;
}

$scanUnknown = req('scan_unknown_youth', 'POST', "$base/sk/attendance/scan", json_encode([
    'token' => 'YSYOUTH-YTH-999999',
    'sessionToken' => $sessionToken,
]), $sk1);
$tests[] = $scanUnknown;
$expect['scan_unknown_youth'] = [404, 422];
if ($id = attendanceIdFrom($scanUnknown)) {
    $createdAttendanceIds[] = $id;
}

if ($sessionId) {
    $exp = $pdo->prepare('UPDATE attendance_qr_tokens SET expires_at = :exp WHERE id = :id');
    $exp->execute(['exp' => '2000-01-01 00:00:00', 'id' => $sessionId]);
}
$scanExpired = req('scan_expired_session', 'POST', "$base/sk/attendance/scan", json_encode([
    'token' => $youthQr,
    'sessionToken' => $sessionToken,
]), $sk1);
$tests[] = $scanExpired;
$expect['scan_expired_session'] = [422, 404];
if ($id = attendanceIdFrom($scanExpired)) {
    $createdAttendanceIds[] = $id;
}

$scanBogus = req('scan_bogus_session', 'POST', "$base/sk/attendance/scan", json_encode([
    'token' => $youthQr,
    'sessionToken' => bin2hex(random_bytes(32)),
]), $sk1);
$tests[] = $scanBogus;
$expect['scan_bogus_session'] = [404, 422];
if ($id = attendanceIdFrom($scanBogus)) {
    $createdAttendanceIds[] = $id;
}

$tests[] = req('login_sk2', 'POST', "$base/auth/login", $login('sk2@demo.test'), $sk2);
$expect['login_sk2'] = [200];

$tests[] = req('cross_list', 'GET', "$base/sk/programs/{$programId}/attendance", null, $sk2);
$expect['cross_list'] = [404];
$tests[] = req('cross_session', 'GET', "$base/sk/programs/{$programId}/attendance/session", null, $sk2);
$expect['cross_session'] = [404];
$tests[] = req('cross_create_session', 'POST', "$base/sk/programs/{$programId}/attendance/session", json_encode([
    'organization_id' => 1,
]), $sk2);
$expect['cross_create_session'] = [404];
$tests[] = req('cross_get_attendance', 'GET', "$base/sk/attendance/{$attendanceId}", null, $sk2);
$expect['cross_get_attendance'] = [404];

$otherYouthA = req('create_other_youth_a', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'PhaseFour',
    'lastName' => 'TestOtherA',
    'birthDate' => '2004-07-07',
    'address' => 'Other org address',
    'contact' => '09170004402',
    'interests' => ['Sports'],
]), $sk2);
$tests[] = $otherYouthA;
$expect['create_other_youth_a'] = [201];
$otherAId = (int) (payload($otherYouthA)['data']['youth']['id'] ?? 0);
if ($otherAId) {
    $createdYouthIds[] = $otherAId;
}

$otherYouth = req('create_other_youth', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'PhaseFour',
    'lastName' => 'TestOther',
    'birthDate' => '2004-08-08',
    'address' => 'Other org address 2',
    'contact' => '09170004403',
    'interests' => ['Sports'],
]), $sk2);
$tests[] = $otherYouth;
$expect['create_other_youth'] = [201];
$otherPayload = payload($otherYouth)['data']['youth'] ?? payload($otherYouth)['data'] ?? [];
$otherYouthId = (int) ($otherPayload['id'] ?? 0);
$otherQr = (string) ($otherPayload['qrToken'] ?? '');
if ($otherYouthId) {
    $createdYouthIds[] = $otherYouthId;
}

$refreshSession = req('recreate_session', 'POST', "$base/sk/programs/{$programId}/attendance/session", json_encode([]), $sk1);
$tests[] = $refreshSession;
$expect['recreate_session'] = [201, 200];
$freshToken = (string) (payload($refreshSession)['data']['token'] ?? '');
$freshSessionId = (int) (payload($refreshSession)['data']['id'] ?? 0);
if ($freshSessionId) {
    $createdSessionIds[] = $freshSessionId;
}

// Youth codes are unique per organization, not globally. Do not scan another
// org's sequential code (e.g. YTH-0002) — it can match SK1's own youth.
$foreignQr = 'YSYOUTH-YTH-9999';
$crossScan = req('cross_scan_other_youth', 'POST', "$base/sk/attendance/scan", json_encode([
    'token' => $foreignQr,
    'sessionToken' => $freshToken,
    'organization_id' => 2,
]), $sk1);
$tests[] = $crossScan;
$expect['cross_scan_other_youth'] = [404, 422, 403];
$crossScanAttendanceId = attendanceIdFrom($crossScan);
if ($crossScanAttendanceId) {
    $createdAttendanceIds[] = $crossScanAttendanceId;
}

$crossSession = req('cross_use_sk1_session', 'POST', "$base/sk/attendance/scan", json_encode([
    'token' => $otherQr,
    'sessionToken' => $freshToken,
    'organization_id' => 1,
]), $sk2);
$tests[] = $crossSession;
$expect['cross_use_sk1_session'] = [404, 403];
$crossSessionAttendanceId = attendanceIdFrom($crossSession);
if ($crossSessionAttendanceId) {
    $createdAttendanceIds[] = $crossSessionAttendanceId;
}

$tests[] = req('logout', 'POST', "$base/auth/logout", null, $sk1);
$expect['logout'] = [200];
$tests[] = req('me_after_logout', 'GET', "$base/auth/me", null, $sk1);
$expect['me_after_logout'] = [401];
$tests[] = req('relogin', 'POST', "$base/auth/login", $login('sk1@demo.test'), $sk1);
$expect['relogin'] = [200];

$uniqueAttendanceIds = array_values(array_unique(array_filter($createdAttendanceIds)));
if ($uniqueAttendanceIds) {
    $in = implode(',', array_map('intval', $uniqueAttendanceIds));
    $pdo->exec("DELETE FROM attendance WHERE id IN ({$in})");
}
$uniqueSessionIds = array_values(array_unique(array_filter($createdSessionIds)));
if ($uniqueSessionIds) {
    $in = implode(',', array_map('intval', $uniqueSessionIds));
    $pdo->exec("DELETE FROM attendance_qr_tokens WHERE id IN ({$in})");
}

foreach ($createdProgramIds as $id) {
    $tests[] = req("cleanup_program_{$id}", 'DELETE', "$base/sk/programs/{$id}", null, $sk1);
    $expect["cleanup_program_{$id}"] = [200];
}
$sk2YouthIds = array_values(array_filter([$otherAId ?? 0, $otherYouthId ?? 0]));
foreach ($createdYouthIds as $id) {
    $cookie = in_array($id, $sk2YouthIds, true) ? $sk2 : $sk1;
    $tests[] = req("cleanup_youth_{$id}", 'DELETE', "$base/sk/youth/{$id}", null, $cookie);
    $expect["cleanup_youth_{$id}"] = [200];
}

foreach ($qrBefore as $orgId => $uses) {
    $stmt = $pdo->prepare('UPDATE organizations SET qr_uses = :uses WHERE id = :id');
    $stmt->execute(['uses' => $uses, 'id' => $orgId]);
}

$usersAfter = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$orgsAfter = (int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$youthAfter = (int) $pdo->query('SELECT COUNT(*) FROM youth')->fetchColumn();
$programsAfter = (int) $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn();
$attendanceAfter = (int) $pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn();
$sessionsAfter = (int) $pdo->query('SELECT COUNT(*) FROM attendance_qr_tokens')->fetchColumn();

$failed = [];
foreach ($tests as $t) {
    $hasHash = stripos($t['body'], 'password_hash') !== false
        || stripos($t['body'], 'token_hash') !== false;
    echo $t['name'] . "\t" . $t['status'] . "\thash=" . ($hasHash ? 'YES' : 'no') . PHP_EOL;
    echo $t['body'] . PHP_EOL . PHP_EOL;

    $allowed = $expect[$t['name']] ?? null;
    if ($allowed !== null && !in_array($t['status'], $allowed, true)) {
        $failed[] = $t['name'] . ' expected ' . implode('/', $allowed) . ' got ' . $t['status'];
    }
    if ($hasHash) {
        $failed[] = $t['name'] . ' leaked hash/secret field';
    }
    if ($t['name'] === 'get_session') {
        $data = payload($t)['data'] ?? [];
        if (isset($data['token']) || isset($data['token_hash'])) {
            $failed[] = 'get_session exposed session secret';
        }
    }
    if ($t['name'] === 'create_session') {
        $data = payload($t)['data'] ?? [];
        if (isset($data['token_hash'])) {
            $failed[] = 'create_session exposed token_hash';
        }
        if (($data['token'] ?? '') === '' || strlen((string) $data['token']) < 32) {
            $failed[] = 'create_session missing secure token';
        }
        if (in_array((string) ($data['token'] ?? ''), ['123456', 'ATTENDANCE-1', 'QR-1'], true)) {
            $failed[] = 'create_session used a predictable token';
        }
    }
    if ($t['name'] === 'list_attendance') {
        $items = payload($t)['data']['items'] ?? [];
        $found = false;
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $attendanceId) {
                $found = true;
            }
        }
        if (!$found) {
            $failed[] = 'list_attendance did not include scanned record';
        }
    }
    if ($t['name'] === 'cross_scan_other_youth') {
        $attachedYouth = (int) (payload($t)['data']['attendance']['youthId']
            ?? payload($t)['data']['youth']['id']
            ?? 0);
        if ($t['status'] === 201 || $t['status'] === 200) {
            $failed[] = 'foreign/nonexistent QR created attendance for an SK1 youth';
        }
        if ($otherYouthId > 0 && $attachedYouth === $otherYouthId) {
            $failed[] = 'scan attached the other organization\'s youth row';
        }
    }
    if ($t['name'] === 'create_program') {
        if ($programId < 1) {
            $failed[] = 'create_program did not return an id (org spoof may have been trusted)';
        }
    }
}

if ($usersAfter !== $usersBefore) {
    $failed[] = "users count changed {$usersBefore} -> {$usersAfter}";
}
if ($orgsAfter !== $orgsBefore) {
    $failed[] = "organizations count changed {$orgsBefore} -> {$orgsAfter}";
}
if ($youthAfter !== $youthBefore) {
    $failed[] = "youth count changed {$youthBefore} -> {$youthAfter}";
}
if ($programsAfter !== $programsBefore) {
    $failed[] = "programs count changed {$programsBefore} -> {$programsAfter}";
}
if ($attendanceAfter !== $attendanceBefore) {
    $failed[] = "attendance count changed {$attendanceBefore} -> {$attendanceAfter}";
}
if ($sessionsAfter !== $sessionsBefore) {
    $failed[] = "attendance_qr_tokens count changed {$sessionsBefore} -> {$sessionsAfter}";
}

$leftP = $pdo->query("SELECT id FROM programs WHERE name LIKE 'PhaseFour Test%'")->fetchAll();
$leftY = $pdo->query("SELECT id FROM youth WHERE first_name = 'PhaseFour' AND last_name LIKE 'Test%'")->fetchAll();
if ($leftP || $leftY) {
    $failed[] = 'leftover PhaseFour test records remain';
}

echo "program_id={$programId}\n";
echo "youth_id={$youthId}\n";
echo "attendance_id={$attendanceId}\n";
echo "session_id={$sessionId}\n";
echo "users_before={$usersBefore} users_after={$usersAfter}\n";
echo "orgs_before={$orgsBefore} orgs_after={$orgsAfter}\n";
echo "youth_before={$youthBefore} youth_after={$youthAfter}\n";
echo "programs_before={$programsBefore} programs_after={$programsAfter}\n";
echo "attendance_before={$attendanceBefore} attendance_after={$attendanceAfter}\n";

if ($failed) {
    echo "RESULT=FAIL\n";
    foreach ($failed as $f) {
        echo "FAIL: {$f}\n";
    }
    exit(1);
}

echo "RESULT=PASS\n";
