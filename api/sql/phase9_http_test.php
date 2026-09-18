<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();
$leftoverY = $pdo->query("SELECT id FROM youth WHERE first_name = 'PhaseNine'")->fetchAll();
$leftoverP = $pdo->query("SELECT id FROM programs WHERE name LIKE 'PhaseNine Test%'")->fetchAll();
if ($leftoverY || $leftoverP) {
    fwrite(STDERR, "STOP: pre-existing PhaseNine test records found. No cleanup was performed.\n");
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

$sk1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p9_sk1.txt';
$sk2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p9_sk2.txt';
$sk4 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p9_sk4.txt';
$empty = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p9_empty.txt';
foreach ([$sk1, $sk2, $sk4, $empty] as $f) {
    @unlink($f);
}

$login = static fn (string $email): string => json_encode(['email' => $email, 'password' => 'YouthSync1!']);
$youthIds = [];
$programIds = [];
$tests = [];
$expect = [];
$logic = [];

$tests[] = req('unauth_subscription', 'GET', "$base/sk/subscription", null, $empty);
$expect['unauth_subscription'] = [401];
$tests[] = req('unauth_usage', 'GET', "$base/sk/subscription/usage", null, $empty);
$expect['unauth_usage'] = [401];

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
$tests[] = req('phase8_dashboard', 'GET', "$base/sk/dashboard", null, $sk1);
$expect['phase8_dashboard'] = [200];
$tests[] = req('phase8_reports', 'GET', "$base/sk/reports?type=youth", null, $sk1);
$expect['phase8_reports'] = [200];

$sub = req('subscription_sk1', 'GET', "$base/sk/subscription?organization_id=2&orgId=999", null, $sk1);
$tests[] = $sub;
$expect['subscription_sk1'] = [200];
$subData = payload($sub)['data'] ?? [];
$logic['sk1_plan'] = ($subData['plan'] ?? '') === 'premium'
    && ($subData['planName'] ?? '') === 'Premium'
    && ($subData['status'] ?? '') === 'trial';
$sk1Limits = is_array($subData['limits'] ?? null) ? $subData['limits'] : [];
$logic['sk1_limits'] = array_key_exists('youth', $sk1Limits)
    && array_key_exists('accounts', $sk1Limits)
    && array_key_exists('programs', $sk1Limits)
    && array_key_exists('assistance', $sk1Limits)
    && array_key_exists('qr', $sk1Limits)
    && $sk1Limits['youth'] === null
    && $sk1Limits['programs'] === null;
$logic['sk1_usage_present'] = isset($subData['usage']['youth'], $subData['usage']['accounts'], $subData['usage']['programs'], $subData['usage']['assistance'], $subData['usage']['qr'])
    && is_int($subData['usage']['youth'])
    && is_int($subData['usage']['accounts'])
    && is_int($subData['usage']['qr']);
$sk1Remaining = is_array($subData['remaining'] ?? null) ? $subData['remaining'] : [];
$logic['sk1_remaining_unlimited'] = array_key_exists('youth', $sk1Remaining)
    && array_key_exists('qr', $sk1Remaining)
    && $sk1Remaining['youth'] === null
    && $sk1Remaining['qr'] === null;
$logic['no_sensitive'] = !str_contains($sub['body'], 'password_hash')
    && !str_contains($sub['body'], 'YouthSync1!')
    && !str_contains($sub['body'], 'C:\\xampp');

$usageBefore = req('usage_sk1_before', 'GET', "$base/sk/subscription/usage", null, $sk1);
$tests[] = $usageBefore;
$expect['usage_sk1_before'] = [200];
$usageBeforeData = payload($usageBefore)['data'] ?? [];
$youthBefore = (int) ($usageBeforeData['usage']['youth'] ?? 0);

$createdYouth = req('create_youth', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'PhaseNine',
    'lastName' => 'TestA',
    'birthDate' => '2006-03-03',
    'address' => 'Phase 9 test address',
    'contact' => '09190009901',
    'interests' => ['Education & training'],
    'organization_id' => 9,
]), $sk1);
$tests[] = $createdYouth;
$expect['create_youth'] = [201];
$youthId = (int) (payload($createdYouth)['data']['youth']['id'] ?? 0);
if ($youthId) {
    $youthIds[] = $youthId;
}

$usageAfter = req('usage_sk1_after', 'GET', "$base/sk/subscription/usage?organization_id=2", null, $sk1);
$tests[] = $usageAfter;
$expect['usage_sk1_after'] = [200];
$usageAfterData = payload($usageAfter)['data'] ?? [];
$logic['youth_usage_delta'] = (int) ($usageAfterData['usage']['youth'] ?? -1) === $youthBefore + 1;
$logic['usage_spoof_ignored'] = ($usageAfterData['plan'] ?? '') === 'premium';
$logic['accounts_usage'] = (int) ($usageAfterData['usage']['accounts'] ?? 0) >= 1;
$logic['qr_usage_int'] = is_int($usageAfterData['usage']['qr'] ?? null);
$logic['assistance_usage_int'] = is_int($usageAfterData['usage']['assistance'] ?? null);

$plainSub = req('subscription_sk1_plain', 'GET', "$base/sk/subscription", null, $sk1);
$tests[] = $plainSub;
$expect['subscription_sk1_plain'] = [200];
$logic['org_query_ignored'] = (payload($plainSub)['data']['plan'] ?? '') === ($subData['plan'] ?? 'x')
    && (payload($plainSub)['data']['usage']['youth'] ?? -2) === (int) ($usageAfterData['usage']['youth'] ?? -3);

$tests[] = req('login_sk2', 'POST', "$base/auth/login", $login('sk2@demo.test'), $sk2);
$expect['login_sk2'] = [200];
$sk2Sub = req('subscription_sk2', 'GET', "$base/sk/subscription?organization_id=1", null, $sk2);
$tests[] = $sk2Sub;
$expect['subscription_sk2'] = [200];
$sk2Data = payload($sk2Sub)['data'] ?? [];
$sk2Limits = is_array($sk2Data['limits'] ?? null) ? $sk2Data['limits'] : [];
$logic['sk2_isolated'] = ($sk2Data['plan'] ?? '') === 'basic'
    && ($sk2Data['status'] ?? '') === 'active'
    && ($sk2Limits['youth'] ?? 0) === 250
    && ($sk2Limits['accounts'] ?? 0) === 3
    && array_key_exists('programs', $sk2Limits)
    && $sk2Limits['programs'] === null
    && ($sk2Limits['assistance'] ?? 0) === 20
    && ($sk2Limits['qr'] ?? 0) === 3
    && ($sk2Data['plan'] ?? '') !== ($subData['plan'] ?? 'premium-mismatch');
$sk2Usage = req('usage_sk2', 'GET', "$base/sk/subscription/usage?organization_id=1", null, $sk2);
$tests[] = $sk2Usage;
$expect['usage_sk2'] = [200];
$logic['sk2_usage_isolated'] = (payload($sk2Usage)['data']['plan'] ?? '') === 'basic'
    && (payload($sk2Usage)['data']['usage']['youth'] ?? -1) !== (int) ($usageAfterData['usage']['youth'] ?? -2);

$tests[] = req('login_sk4', 'POST', "$base/auth/login", $login('sk4@demo.test'), $sk4);
$expect['login_sk4'] = [200];
$sk4Usage = req('usage_sk4', 'GET', "$base/sk/subscription/usage", null, $sk4);
$tests[] = $sk4Usage;
$expect['usage_sk4'] = [200];
$sk4Data = payload($sk4Usage)['data'] ?? [];
$logic['sk4_free_limits'] = ($sk4Data['plan'] ?? '') === 'free'
    && ($sk4Data['limits']['youth'] ?? 0) === 20
    && ($sk4Data['limits']['programs'] ?? 0) === 1
    && ($sk4Data['limits']['assistance'] ?? 0) === 1
    && ($sk4Data['limits']['qr'] ?? 0) === 1
    && ($sk4Data['limits']['accounts'] ?? 0) === 1;

$programBody = json_encode([
    'kind' => 'program',
    'name' => 'PhaseNine Test Program',
    'scheduledOn' => '2026-12-01',
    'location' => 'Hall',
    'status' => 'draft',
    'organization_id' => 1,
]);
$remainingPrograms = $sk4Data['remaining']['programs'] ?? null;
if ($remainingPrograms === 0) {
    $blocked = req('plan_limit_program', 'POST', "$base/sk/programs", $programBody, $sk4);
    $tests[] = $blocked;
    $expect['plan_limit_program'] = [403];
    $logic['plan_limit_code'] = (payload($blocked)['error']['code'] ?? '') === 'PLAN_LIMIT';
} else {
    $first = req('create_free_program', 'POST', "$base/sk/programs", $programBody, $sk4);
    $tests[] = $first;
    $expect['create_free_program'] = [201];
    $pid = (int) (payload($first)['data']['id'] ?? 0);
    if ($pid) {
        $programIds[] = $pid;
    }
    $blocked = req('plan_limit_program', 'POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => 'PhaseNine Test Program Two',
        'scheduledOn' => '2026-12-02',
        'status' => 'draft',
    ]), $sk4);
    $tests[] = $blocked;
    $expect['plan_limit_program'] = [403];
    $logic['plan_limit_code'] = (payload($blocked)['error']['code'] ?? '') === 'PLAN_LIMIT';
}

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

foreach ($programIds as $id) {
    req("cleanup_program_{$id}", 'DELETE', "$base/sk/programs/{$id}", null, $sk4);
}
foreach ($youthIds as $id) {
    req("cleanup_youth_{$id}", 'DELETE', "$base/sk/youth/{$id}", null, $sk1);
}

$after = $counts();
echo PHP_EOL . 'counts_before=' . json_encode($before) . PHP_EOL;
echo 'counts_after=' . json_encode($after) . PHP_EOL;
$countsMatch = $before === $after;
echo 'counts_match=' . ($countsMatch ? 'ok' : 'FAIL') . PHP_EOL;
if (!$countsMatch) {
    $failed[] = 'counts';
}

foreach ([$sk1, $sk2, $sk4, $empty] as $f) {
    @unlink($f);
}

if ($failed) {
    echo PHP_EOL . 'FAILED=' . implode(',', $failed) . PHP_EOL;
    echo 'RESULT=FAIL' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'RESULT=PASS' . PHP_EOL;
