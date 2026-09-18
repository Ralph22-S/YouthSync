<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();
$leftover = $pdo->query("SELECT id FROM users WHERE email LIKE 'phase10_%@demo.test'")->fetchAll();
if ($leftover) {
    fwrite(STDERR, "STOP: pre-existing Phase 10 test users found. No cleanup was performed.\n");
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

$stamp = (string) time();
$emailA = "phase10_{$stamp}_a@demo.test";
$emailB = "phase10_{$stamp}_b@demo.test";
$sk1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p10_sk1.txt';
$sk2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p10_sk2.txt';
$sk4 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p10_sk4.txt';
$createdJar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p10_new.txt';
$empty = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p10_empty.txt';
foreach ([$sk1, $sk2, $sk4, $createdJar, $empty] as $f) {
    @unlink($f);
}

$login = static fn (string $email, string $password = 'YouthSync1!'): string => json_encode([
    'email' => $email,
    'password' => $password,
]);
$createdIds = [];
$tests = [];
$expect = [];
$logic = [];

$tests[] = req('unauth_users', 'GET', "$base/sk/users", null, $empty);
$expect['unauth_users'] = [401];

$tests[] = req('login_sk1', 'POST', "$base/auth/login", $login('sk1@demo.test'), $sk1);
$expect['login_sk1'] = [200];
$tests[] = req('phase1_me', 'GET', "$base/auth/me", null, $sk1);
$expect['phase1_me'] = [200];
$tests[] = req('phase2_youth', 'GET', "$base/sk/youth", null, $sk1);
$expect['phase2_youth'] = [200];
$tests[] = req('phase3_programs', 'GET', "$base/sk/programs", null, $sk1);
$expect['phase3_programs'] = [200];
$tests[] = req('phase4_attendance', 'POST', "$base/sk/attendance/scan", json_encode(['token' => '']), $sk1);
$expect['phase4_attendance'] = [422];
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
$tests[] = req('phase9_subscription', 'GET', "$base/sk/subscription", null, $sk1);
$expect['phase9_subscription'] = [200];
$tests[] = req('phase9_usage', 'GET', "$base/sk/subscription/usage", null, $sk1);
$expect['phase9_usage'] = [200];

$list = req('list_users', 'GET', "$base/sk/users?organization_id=2", null, $sk1);
$tests[] = $list;
$expect['list_users'] = [200];
$listItems = payload($list)['data']['items'] ?? [];
$sk1Id = 0;
foreach ($listItems as $item) {
    if (($item['email'] ?? '') === 'sk1@demo.test') {
        $sk1Id = (int) ($item['id'] ?? 0);
    }
}
$logic['list_contains_self'] = $sk1Id > 0;
$logic['list_no_hash'] = !str_contains($list['body'], 'password_hash')
    && !str_contains($list['body'], 'temporaryPassword');

$created = req('create_user', 'POST', "$base/sk/users", json_encode([
    'firstName' => 'PhaseTen',
    'lastName' => 'Alpha',
    'email' => $emailA,
    'role' => 'SK_OFFICIAL',
    'role_id' => 99,
    'organization_id' => 2,
    'orgId' => 2,
    'isOwner' => true,
]), $sk1);
$tests[] = $created;
$expect['create_user'] = [201];
$createdData = payload($created)['data'] ?? [];
$newId = (int) ($createdData['id'] ?? 0);
if ($newId) {
    $createdIds[] = $newId;
}
$temp = (string) ($createdData['temporaryPassword'] ?? '');
$logic['created_fields'] = $newId > 0
    && ($createdData['email'] ?? '') === $emailA
    && ($createdData['role'] ?? '') === 'SK_OFFICIAL'
    && ($createdData['isOwner'] ?? true) === false
    && $temp !== ''
    && strlen($temp) >= 8
    && ($createdData['mustChangePassword'] ?? false) === true;
$logic['create_no_hash'] = !str_contains($created['body'], 'password_hash');

$got = req('get_user', 'GET', "$base/sk/users/{$newId}", null, $sk1);
$tests[] = $got;
$expect['get_user'] = [200];
$gotData = payload($got)['data'] ?? [];
$logic['get_no_temp'] = !array_key_exists('temporaryPassword', $gotData)
    && !str_contains($got['body'], 'password_hash')
    && ($gotData['email'] ?? '') === $emailA;

$updated = req('update_user', 'PATCH', "$base/sk/users/{$newId}", json_encode([
    'lastName' => 'Beta',
    'organization_id' => 2,
    'role' => 'SK_OFFICIAL',
]), $sk1);
$tests[] = $updated;
$expect['update_user'] = [200];
$logic['updated_name'] = (payload($updated)['data']['lastName'] ?? '') === 'Beta';

$dup = req('duplicate_email', 'POST', "$base/sk/users", json_encode([
    'firstName' => 'Dup',
    'lastName' => 'User',
    'email' => $emailA,
]), $sk1);
$tests[] = $dup;
$expect['duplicate_email'] = [409];

$badRole = req('invalid_role', 'POST', "$base/sk/users", json_encode([
    'firstName' => 'Bad',
    'lastName' => 'Role',
    'email' => $emailB,
    'role' => 'YOUTH',
]), $sk1);
$tests[] = $badRole;
$expect['invalid_role'] = [422];

$priv = req('privileged_role', 'POST', "$base/sk/users", json_encode([
    'firstName' => 'Bad',
    'lastName' => 'Admin',
    'email' => $emailB,
    'role' => 'SUPER_ADMIN',
]), $sk1);
$tests[] = $priv;
$expect['privileged_role'] = [422];

$loginNew = req('login_created', 'POST', "$base/auth/login", $login($emailA, $temp), $createdJar);
$tests[] = $loginNew;
$expect['login_created'] = [200];
$meNew = req('me_created', 'GET', "$base/auth/me", null, $createdJar);
$tests[] = $meNew;
$expect['me_created'] = [200];
$orgNew = (int) (payload($meNew)['data']['organization']['id'] ?? 0);
$orgSk1 = (int) (payload(req('me_sk1_org', 'GET', "$base/auth/me", null, $sk1))['data']['organization']['id'] ?? 0);
$logic['created_org'] = $orgNew > 0 && $orgNew === $orgSk1 && $orgNew !== 2;

$reset = req('reset_password', 'POST', "$base/sk/users/{$newId}/reset-password", '{}', $sk1);
$tests[] = $reset;
$expect['reset_password'] = [200];
$temp2 = (string) (payload($reset)['data']['temporaryPassword'] ?? '');
$logic['reset_temp'] = $temp2 !== '' && $temp2 !== $temp;
$logic['get_after_reset_no_temp'] = !str_contains(
    req('get_after_reset', 'GET', "$base/sk/users/{$newId}", null, $sk1)['body'],
    'temporaryPassword'
);

$selfOff = req('deactivate_self', 'POST', "$base/sk/users/{$sk1Id}/status", json_encode(['status' => 'inactive']), $sk1);
$tests[] = $selfOff;
$expect['deactivate_self'] = [422];

$deact = req('deactivate_user', 'POST', "$base/sk/users/{$newId}/status", json_encode([
    'status' => 'inactive',
    'organization_id' => 9,
]), $sk1);
$tests[] = $deact;
$expect['deactivate_user'] = [200];
$logic['deactivated'] = (payload($deact)['data']['status'] ?? '') === 'inactive';

@unlink($createdJar);
$loginInactive = req('login_inactive', 'POST', "$base/auth/login", $login($emailA, $temp2), $createdJar);
$tests[] = $loginInactive;
$expect['login_inactive'] = [403];

$react = req('reactivate_user', 'POST', "$base/sk/users/{$newId}/toggle-active", '{}', $sk1);
$tests[] = $react;
$expect['reactivate_user'] = [200];

$tests[] = req('login_sk2', 'POST', "$base/auth/login", $login('sk2@demo.test'), $sk2);
$expect['login_sk2'] = [200];
$tests[] = req('sk2_get_sk1_user', 'GET', "$base/sk/users/{$newId}", null, $sk2);
$expect['sk2_get_sk1_user'] = [404];
$tests[] = req('sk2_update_sk1_user', 'PATCH', "$base/sk/users/{$newId}", json_encode(['lastName' => 'Hack']), $sk2);
$expect['sk2_update_sk1_user'] = [404];
$tests[] = req('sk2_status_sk1_user', 'POST', "$base/sk/users/{$newId}/status", json_encode(['status' => 'inactive']), $sk2);
$expect['sk2_status_sk1_user'] = [404];
$sk2List = req('sk2_list', 'GET', "$base/sk/users?organization_id=1", null, $sk2);
$tests[] = $sk2List;
$expect['sk2_list'] = [200];
$sk2Emails = array_map(static fn ($u) => $u['email'] ?? '', payload($sk2List)['data']['items'] ?? []);
$logic['sk2_isolated'] = !in_array($emailA, $sk2Emails, true)
    && in_array('sk2@demo.test', $sk2Emails, true);

$tests[] = req('login_sk4', 'POST', "$base/auth/login", $login('sk4@demo.test'), $sk4);
$expect['login_sk4'] = [200];
$limit = req('plan_limit_free', 'POST', "$base/sk/users", json_encode([
    'firstName' => 'PhaseTen',
    'lastName' => 'FreeLimit',
    'email' => $emailB,
    'organization_id' => 1,
]), $sk4);
$tests[] = $limit;
$expect['plan_limit_free'] = [403];
$logic['plan_limit_code'] = (payload($limit)['error']['code'] ?? '') === 'PLAN_LIMIT';

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

foreach ($createdIds as $id) {
    req("cleanup_user_{$id}", 'DELETE', "$base/sk/users/{$id}", null, $sk1);
}
$left = $pdo->query("SELECT id FROM users WHERE email LIKE 'phase10_%@demo.test'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($left as $id) {
    $id = (int) $id;
    $pdo->prepare('DELETE FROM organization_users WHERE user_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
}

$after = $counts();
echo PHP_EOL . 'counts_before=' . json_encode($before) . PHP_EOL;
echo 'counts_after=' . json_encode($after) . PHP_EOL;
$countsMatch = $before === $after;
echo 'counts_match=' . ($countsMatch ? 'ok' : 'FAIL') . PHP_EOL;
if (!$countsMatch) {
    $failed[] = 'counts';
}

foreach ([$sk1, $sk2, $sk4, $createdJar, $empty] as $f) {
    @unlink($f);
}

if ($failed) {
    echo PHP_EOL . 'FAILED=' . implode(',', $failed) . PHP_EOL;
    echo 'RESULT=FAIL' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'RESULT=PASS' . PHP_EOL;
