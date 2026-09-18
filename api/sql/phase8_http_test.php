<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'YouthSync\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use YouthSync\Notifications\NotificationService;

$pdo = youthsync_pdo();
$leftoverY = $pdo->query("SELECT id FROM youth WHERE first_name = 'PhaseEight'")->fetchAll();
$leftoverP = $pdo->query("SELECT id FROM programs WHERE name LIKE 'PhaseEight Test%'")->fetchAll();
$leftoverA = $pdo->query("SELECT id FROM assistance_programs WHERE name LIKE 'PhaseEight Test%'")->fetchAll();
$leftoverN = $pdo->query("SELECT id FROM notifications WHERE title LIKE 'PhaseEight%'")->fetchAll();
if ($leftoverY || $leftoverP || $leftoverA || $leftoverN) {
    fwrite(STDERR, "STOP: pre-existing PhaseEight test records found. No cleanup was performed.\n");
    exit(2);
}

$base = 'http://localhost/YouthSync_UI_Refresh/api';
$counts = static function () use ($pdo): array {
    return [
        'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'orgs' => (int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn(),
        'youth' => (int) $pdo->query('SELECT COUNT(*) FROM youth')->fetchColumn(),
        'programs' => (int) $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn(),
        'attendance' => (int) $pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),
        'assistance' => (int) $pdo->query('SELECT COUNT(*) FROM assistance_programs')->fetchColumn(),
        'beneficiaries' => (int) $pdo->query('SELECT COUNT(*) FROM beneficiaries')->fetchColumn(),
        'applications' => (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn(),
        'submissions' => (int) $pdo->query('SELECT COUNT(*) FROM application_submissions')->fetchColumn(),
        'notifications' => (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(),
    ];
};
$before = $counts();

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

$sk1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p8_sk1.txt';
$sk2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p8_sk2.txt';
$empty = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p8_empty.txt';
foreach ([$sk1, $sk2, $empty] as $f) {
    @unlink($f);
}

$login = static fn (string $email): string => json_encode(['email' => $email, 'password' => 'YouthSync1!']);
$youthIds = [];
$programIds = [];
$assistIds = [];
$appIds = [];
$attIds = [];
$noteIds = [];
$tests = [];
$expect = [];
$logic = [];

$tests[] = req('unauth_dashboard', 'GET', "$base/sk/dashboard", null, $empty);
$expect['unauth_dashboard'] = [401];
$tests[] = req('unauth_reports', 'GET', "$base/sk/reports?type=youth", null, $empty);
$expect['unauth_reports'] = [401];

$tests[] = req('login_sk1', 'POST', "$base/auth/login", $login('sk1@demo.test'), $sk1);
$expect['login_sk1'] = [200];
$tests[] = req('phase1_me', 'GET', "$base/auth/me", null, $sk1);
$expect['phase1_me'] = [200];
$tests[] = req('phase2_youth', 'GET', "$base/sk/youth", null, $sk1);
$expect['phase2_youth'] = [200];
$tests[] = req('phase3_programs', 'GET', "$base/sk/programs", null, $sk1);
$expect['phase3_programs'] = [200];
$tests[] = req('phase5_assistance', 'GET', "$base/sk/assistance", null, $sk1);
$expect['phase5_assistance'] = [200];
$tests[] = req('phase6_applications', 'GET', "$base/sk/applications", null, $sk1);
$expect['phase6_applications'] = [200];
$tests[] = req('phase7_notifications', 'GET', "$base/sk/notifications", null, $sk1);
$expect['phase7_notifications'] = [200];

$baselineDash = req('dashboard_baseline', 'GET', "$base/sk/dashboard", null, $sk1);
$tests[] = $baselineDash;
$expect['dashboard_baseline'] = [200];
$baseSummary = payload($baselineDash)['data']['summary'] ?? [];
$logic['baseline_numeric'] = is_int($baseSummary['youth']['total'] ?? null)
    && is_int($baseSummary['programs']['total'] ?? null)
    && is_int($baseSummary['applications']['total'] ?? null)
    && is_int($baseSummary['notifications']['unread'] ?? null)
    && is_int($baseSummary['attendance']['present'] ?? null);
$logic['baseline_sections'] = isset(payload($baselineDash)['data']['youth'], payload($baselineDash)['data']['programs'], payload($baselineDash)['data']['assistance'], payload($baselineDash)['data']['applications'], payload($baselineDash)['data']['attendance'], payload($baselineDash)['data']['recent'], payload($baselineDash)['data']['highlights']);

$youthBody = static fn (string $last, string $birth, string $contact, string $gender): string => json_encode([
    'firstName' => 'PhaseEight',
    'lastName' => $last,
    'birthDate' => $birth,
    'gender' => $gender,
    'address' => 'Phase 8 test address',
    'contact' => $contact,
    'interests' => ['Education & training'],
    'employment' => 'Unemployed',
    'organization_id' => 9,
]);

$y1 = req('create_youth_a', 'POST', "$base/sk/youth", $youthBody('TestA', '2008-01-15', '09180008801', 'Female'), $sk1);
$tests[] = $y1;
$expect['create_youth_a'] = [201];
$youthA = (int) (payload($y1)['data']['youth']['id'] ?? payload($y1)['data']['id'] ?? 0);
if ($youthA) {
    $youthIds[] = $youthA;
}
$y2 = req('create_youth_b', 'POST', "$base/sk/youth", $youthBody('TestB', '2009-06-01', '09180008802', 'Male'), $sk1);
$tests[] = $y2;
$expect['create_youth_b'] = [201];
$youthB = (int) (payload($y2)['data']['youth']['id'] ?? payload($y2)['data']['id'] ?? 0);
if ($youthB) {
    $youthIds[] = $youthB;
}
$tests[] = req('archive_youth_b', 'POST', "$base/sk/youth/{$youthB}/archive", null, $sk1);
$expect['archive_youth_b'] = [200];

$prog = req('create_program', 'POST', "$base/sk/programs", json_encode([
    'kind' => 'program',
    'name' => 'PhaseEight Test Program',
    'scheduledOn' => '2026-12-01',
    'location' => 'Covered Court',
    'status' => 'draft',
    'organization_id' => 4,
]), $sk1);
$tests[] = $prog;
$expect['create_program'] = [201];
$programId = (int) (payload($prog)['data']['id'] ?? 0);
if ($programId) {
    $programIds[] = $programId;
}
$tests[] = req('publish_program', 'POST', "$base/sk/programs/{$programId}/status", json_encode([
    'status' => 'published',
    'organization_id' => 2,
]), $sk1);
$expect['publish_program'] = [200];

$evt = req('create_event', 'POST', "$base/sk/events", json_encode([
    'name' => 'PhaseEight Test Event',
    'scheduledOn' => '2026-11-15',
    'kind' => 'event',
    'status' => 'draft',
]), $sk1);
$tests[] = $evt;
$expect['create_event'] = [201];
$eventId = (int) (payload($evt)['data']['id'] ?? 0);
if ($eventId) {
    $programIds[] = $eventId;
}

$assist = req('create_assistance', 'POST', "$base/sk/assistance", json_encode([
    'name' => 'PhaseEight Test Scholarship',
    'category' => 'scholarship',
    'status' => 'open',
    'slots' => 3,
    'deadline' => '2026-12-31',
    'organization_id' => 4,
]), $sk1);
$tests[] = $assist;
$expect['create_assistance'] = [201];
$assistId = (int) (payload($assist)['data']['id'] ?? 0);
if ($assistId) {
    $assistIds[] = $assistId;
}

$createdApp = req('create_application', 'POST', "$base/sk/applications", json_encode([
    'assistanceId' => $assistId,
    'youthId' => $youthA,
    'remarks' => 'PhaseEight apply A',
    'organization_id' => 2,
]), $sk1);
$tests[] = $createdApp;
$expect['create_application'] = [201];
$appId = (int) (payload($createdApp)['data']['id'] ?? 0);
if ($appId) {
    $appIds[] = $appId;
}

$manual = req('attendance_manual', 'POST', "$base/sk/attendance/manual", json_encode([
    'programId' => $programId,
    'youthId' => $youthA,
    'organization_id' => 2,
]), $sk1);
$tests[] = $manual;
$expect['attendance_manual'] = [201];
$attId = (int) (payload($manual)['data']['attendance']['id'] ?? 0);
$confirm = req('attendance_confirm', 'POST', "$base/sk/attendance/confirm", json_encode([
    'attendanceId' => $attId,
    'organization_id' => 2,
]), $sk1);
$tests[] = $confirm;
$expect['attendance_confirm'] = [200];
if ($attId) {
    $attIds[] = $attId;
}

$org1 = 0;
$me = payload(req('me_for_org', 'GET', "$base/auth/me", null, $sk1));
$org1 = (int) ($me['data']['organization']['id'] ?? $me['data']['user']['organizationId'] ?? 0);
if ($org1 < 1) {
    $org1 = (int) $pdo->query(
        "SELECT ou.organization_id FROM users u INNER JOIN organization_users ou ON ou.user_id = u.id WHERE u.email = 'sk1@demo.test' LIMIT 1"
    )->fetchColumn();
}
$notes = new NotificationService($pdo);
$createdNote = $notes->notifySk($org1, [
    'organization_id' => 999,
    'user_id' => 999,
    'type' => 'system',
    'title' => 'PhaseEight Dashboard Notice',
    'message' => 'PhaseEight controlled notification',
]);
$noteId = (int) ($createdNote['id'] ?? 0);
if ($noteId) {
    $noteIds[] = $noteId;
}

$dash = req('dashboard_after', 'GET', "$base/sk/dashboard?organization_id=2&orgId=2", null, $sk1);
$tests[] = $dash;
$expect['dashboard_after'] = [200];
$afterData = payload($dash)['data'] ?? [];
$afterSummary = $afterData['summary'] ?? [];
$logic['youth_delta'] = (($afterSummary['youth']['total'] ?? -1) === (($baseSummary['youth']['total'] ?? 0) + 2))
    && (($afterSummary['youth']['active'] ?? -1) === (($baseSummary['youth']['active'] ?? 0) + 1))
    && (($afterSummary['youth']['archived'] ?? -1) === (($baseSummary['youth']['archived'] ?? 0) + 1));
$logic['program_delta'] = ($afterSummary['programs']['total'] ?? -1) === (($baseSummary['programs']['total'] ?? 0) + 2)
    && ($afterSummary['programs']['published'] ?? 0) >= 1
    && ($afterSummary['programs']['upcoming'] ?? 0) >= 1;
$logic['assistance_delta'] = ($afterSummary['assistance']['total'] ?? -1) === (($baseSummary['assistance']['total'] ?? 0) + 1)
    && ($afterSummary['assistance']['open'] ?? 0) >= 1;
$logic['application_delta'] = ($afterSummary['applications']['total'] ?? -1) === (($baseSummary['applications']['total'] ?? 0) + 1)
    && ($afterSummary['applications']['pending'] ?? 0) >= 1;
$logic['attendance_present'] = ($afterSummary['attendance']['present'] ?? 0) === (($baseSummary['attendance']['present'] ?? 0) + 1);
$logic['unread_increased'] = ($afterSummary['notifications']['unread'] ?? 0) >= (($baseSummary['notifications']['unread'] ?? 0) + 1);
$logic['no_sensitive'] = !str_contains($dash['body'], 'password_hash')
    && !str_contains($dash['body'], 'YouthSync1!')
    && !str_contains($dash['body'], 'C:\\xampp');

$dashPlain = req('dashboard_plain', 'GET', "$base/sk/dashboard", null, $sk1);
$tests[] = $dashPlain;
$expect['dashboard_plain'] = [200];
$logic['org_query_ignored'] = (payload($dashPlain)['data']['summary']['youth']['total'] ?? null)
    === ($afterSummary['youth']['total'] ?? -2);

$youthReport = req('report_youth', 'GET', "$base/sk/reports?type=youth&organization_id=2", null, $sk1);
$tests[] = $youthReport;
$expect['report_youth'] = [200];
$youthRep = payload($youthReport)['data'] ?? [];
$logic['report_youth_totals'] = ($youthRep['type'] ?? '') === 'youth'
    && ($youthRep['totals']['total'] ?? -1) === ($afterSummary['youth']['total'] ?? -2)
    && ($youthRep['totals']['archived'] ?? -1) === ($afterSummary['youth']['archived'] ?? -2);

$tests[] = req('report_youth_path', 'GET', "$base/sk/reports/youth", null, $sk1);
$expect['report_youth_path'] = [200];
$tests[] = req('report_programs', 'GET', "$base/sk/reports?type=programs", null, $sk1);
$expect['report_programs'] = [200];
$tests[] = req('report_assistance', 'GET', "$base/sk/reports?type=assistance", null, $sk1);
$expect['report_assistance'] = [200];
$tests[] = req('report_applications', 'GET', "$base/sk/reports?type=applications", null, $sk1);
$expect['report_applications'] = [200];
$attReport = req('report_attendance', 'GET', "$base/sk/reports?type=attendance", null, $sk1);
$tests[] = $attReport;
$expect['report_attendance'] = [200];
$logic['report_attendance_present'] = (payload($attReport)['data']['totals']['present'] ?? 0)
    === ($afterSummary['attendance']['present'] ?? -1);

$futureYouth = req('report_youth_future', 'GET', "$base/sk/reports?type=youth&from=2099-01-01&to=2099-12-31", null, $sk1);
$tests[] = $futureYouth;
$expect['report_youth_future'] = [200];
$logic['date_filter'] = (payload($futureYouth)['data']['totals']['total'] ?? -1) === 0;

$tests[] = req('report_invalid_type', 'GET', "$base/sk/reports?type=subscription", null, $sk1);
$expect['report_invalid_type'] = [422];
$tests[] = req('report_missing_type', 'GET', "$base/sk/reports", null, $sk1);
$expect['report_missing_type'] = [422];
$tests[] = req('report_invalid_date', 'GET', "$base/sk/reports?type=youth&from=13-13-2026", null, $sk1);
$expect['report_invalid_date'] = [422];

$tests[] = req('login_sk2', 'POST', "$base/auth/login", $login('sk2@demo.test'), $sk2);
$expect['login_sk2'] = [200];
$sk2Dash = req('sk2_dashboard', 'GET', "$base/sk/dashboard?organization_id=1", null, $sk2);
$tests[] = $sk2Dash;
$expect['sk2_dashboard'] = [200];
$sk2Summary = payload($sk2Dash)['data']['summary'] ?? [];
$logic['sk2_youth_isolated'] = ($sk2Summary['youth']['total'] ?? -1) !== ($afterSummary['youth']['total'] ?? -2)
    && ($sk2Summary['youth']['total'] ?? 0) === ($sk2Summary['youth']['total'] ?? 0);
$newest = payload($sk2Dash)['data']['newestYouth'] ?? [];
$sk2Names = json_encode($newest);
$logic['sk2_no_phaseeight_youth'] = !str_contains((string) $sk2Names, 'PhaseEight');
$logic['sk2_no_phaseeight_programs'] = ($sk2Summary['programs']['total'] ?? 0) < ($afterSummary['programs']['total'] ?? 99)
    && !str_contains($sk2Dash['body'], 'PhaseEight Test Program');
$logic['sk2_spoof_ignored'] = ($sk2Summary['youth']['total'] ?? -1) !== ($afterSummary['youth']['total'] ?? -3);

$sk2YouthRep = req('sk2_report_youth', 'GET', "$base/sk/reports?type=youth&organization_id=1", null, $sk2);
$tests[] = $sk2YouthRep;
$expect['sk2_report_youth'] = [200];
$logic['sk2_report_isolated'] = (payload($sk2YouthRep)['data']['totals']['total'] ?? -1)
    === ($sk2Summary['youth']['total'] ?? -2);

$failed = [];
foreach ($tests as $t) {
    $name = $t['name'];
    $okStatus = in_array($t['status'], $expect[$name] ?? [], true);
    $decoded = payload($t);
    $okEnvelope = $t['status'] >= 200 && $t['status'] < 300
        ? (($decoded['success'] ?? false) === true)
        : (($decoded['success'] ?? true) === false && isset($decoded['error']['code']));
    echo sprintf(
        "%s status=%d expected=%s envelope=%s\n",
        $name,
        $t['status'],
        implode('|', $expect[$name] ?? []),
        $okEnvelope ? 'ok' : 'bad'
    );
    if (!$okStatus || !$okEnvelope) {
        echo '  body=' . $t['body'] . PHP_EOL;
        $failed[] = $name;
    }
}

echo PHP_EOL . 'logic checks:' . PHP_EOL;
foreach ($logic as $name => $ok) {
    echo $name . '=' . ($ok ? 'ok' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = 'logic:' . $name;
    }
}

foreach ($noteIds as $id) {
    $pdo->prepare('DELETE FROM notifications WHERE id = :id')->execute(['id' => $id]);
}
if ($appIds) {
    foreach ($appIds as $id) {
        $subs = $pdo->prepare('SELECT id FROM application_submissions WHERE application_id = :id');
        $subs->execute(['id' => $id]);
        foreach ($subs->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $pdo->prepare('DELETE FROM application_submissions WHERE id = :id')->execute(['id' => (int) $sid]);
        }
        $pdo->prepare('DELETE FROM notifications WHERE related_entity_type = :t AND related_entity_id = :id')
            ->execute(['t' => 'application', 'id' => $id]);
        $pdo->prepare('DELETE FROM applications WHERE id = :id')->execute(['id' => $id]);
    }
}
foreach ($attIds as $id) {
    $pdo->prepare('DELETE FROM attendance WHERE id = :id')->execute(['id' => $id]);
}
foreach ($assistIds as $id) {
    req("cleanup_assistance_{$id}", 'DELETE', "$base/sk/assistance/{$id}", null, $sk1);
}
foreach ($programIds as $id) {
    req("cleanup_program_{$id}", 'DELETE', "$base/sk/programs/{$id}", null, $sk1);
}
foreach ($youthIds as $id) {
    req("cleanup_youth_{$id}", 'DELETE', "$base/sk/youth/{$id}", null, $sk1);
}

$strayNotes = $pdo->query("SELECT id FROM notifications WHERE title LIKE 'PhaseEight%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($strayNotes as $id) {
    $pdo->prepare('DELETE FROM notifications WHERE id = :id')->execute(['id' => (int) $id]);
}

$after = $counts();
echo PHP_EOL . 'counts_before=' . json_encode($before) . PHP_EOL;
echo 'counts_after=' . json_encode($after) . PHP_EOL;
$countsMatch = $before === $after;
echo 'counts_match=' . ($countsMatch ? 'ok' : 'FAIL') . PHP_EOL;
if (!$countsMatch) {
    $failed[] = 'counts';
}

foreach ([$sk1, $sk2, $empty] as $f) {
    @unlink($f);
}

if ($failed) {
    echo PHP_EOL . 'FAILED=' . implode(',', $failed) . PHP_EOL;
    echo 'RESULT=FAIL' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'RESULT=PASS' . PHP_EOL;
