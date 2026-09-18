<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();
$leftoverA = $pdo->query(
    "SELECT id FROM assistance_programs WHERE name LIKE 'PhaseSix Test%'"
)->fetchAll();
$leftoverY = $pdo->query(
    "SELECT id FROM youth WHERE first_name = 'PhaseSix' AND last_name LIKE 'Test%'"
)->fetchAll();
$leftoverApp = $pdo->query(
    "SELECT id FROM applications WHERE remarks LIKE 'PhaseSix%'"
)->fetchAll();
if ($leftoverA || $leftoverY || $leftoverApp) {
    fwrite(STDERR, "STOP: pre-existing PhaseSix test records found. No cleanup was performed.\n");
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

$sk1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p6_sk1.txt';
$sk2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p6_sk2.txt';
$empty = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p6_empty.txt';
foreach ([$sk1, $sk2, $empty] as $f) {
    @unlink($f);
}

$login = static fn (string $email): string => json_encode(['email' => $email, 'password' => 'YouthSync1!']);
$youthIds = [];
$assistIds = [];
$appIds = [];
$benIds = [];
$subIds = [];
$tests = [];
$expect = [];

$tests[] = req('unauth_applications', 'GET', "$base/sk/applications", null, $empty);
$expect['unauth_applications'] = [401];

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
$tests[] = req('list_applications', 'GET', "$base/sk/applications", null, $sk1);
$expect['list_applications'] = [200];

$youthBody = static fn (string $last, string $birth, string $contact): string => json_encode([
    'firstName' => 'PhaseSix',
    'lastName' => $last,
    'birthDate' => $birth,
    'address' => 'Phase 6 test address',
    'contact' => $contact,
    'interests' => ['Education & training'],
    'organization_id' => 9,
]);

$y1 = req('create_youth_a', 'POST', "$base/sk/youth", $youthBody('TestA', '2005-01-01', '09170006601'), $sk1);
$tests[] = $y1;
$expect['create_youth_a'] = [201];
$youthA = (int) (payload($y1)['data']['youth']['id'] ?? 0);
if ($youthA) {
    $youthIds[] = $youthA;
}
$y2 = req('create_youth_b', 'POST', "$base/sk/youth", $youthBody('TestB', '2004-02-02', '09170006602'), $sk1);
$tests[] = $y2;
$expect['create_youth_b'] = [201];
$youthB = (int) (payload($y2)['data']['youth']['id'] ?? 0);
if ($youthB) {
    $youthIds[] = $youthB;
}
$y3 = req('create_youth_c', 'POST', "$base/sk/youth", $youthBody('TestC', '2003-03-03', '09170006603'), $sk1);
$tests[] = $y3;
$expect['create_youth_c'] = [201];
$youthC = (int) (payload($y3)['data']['youth']['id'] ?? 0);
if ($youthC) {
    $youthIds[] = $youthC;
}
$y4 = req('create_youth_d', 'POST', "$base/sk/youth", $youthBody('TestD', '2002-04-04', '09170006604'), $sk1);
$tests[] = $y4;
$expect['create_youth_d'] = [201];
$youthD = (int) (payload($y4)['data']['youth']['id'] ?? 0);
if ($youthD) {
    $youthIds[] = $youthD;
}

$assist = req('create_assistance', 'POST', "$base/sk/assistance", json_encode([
    'name' => 'PhaseSix Test Scholarship',
    'category' => 'scholarship',
    'typeId' => 1,
    'status' => 'open',
    'slots' => 1,
    'amount' => 3000,
    'deadline' => '2026-12-31',
    'organization_id' => 4,
]), $sk1);
$tests[] = $assist;
$expect['create_assistance'] = [201];
$assistId = (int) (payload($assist)['data']['id'] ?? 0);
if ($assistId) {
    $assistIds[] = $assistId;
}
$assist2 = req('create_assistance_two', 'POST', "$base/sk/assistance", json_encode([
    'name' => 'PhaseSix Test Aid',
    'category' => 'financial',
    'status' => 'open',
    'slots' => 10,
]), $sk1);
$tests[] = $assist2;
$expect['create_assistance_two'] = [201];
$assistId2 = (int) (payload($assist2)['data']['id'] ?? 0);
if ($assistId2) {
    $assistIds[] = $assistId2;
}

$created = req('create_application', 'POST', "$base/sk/applications", json_encode([
    'assistanceId' => $assistId,
    'youthId' => $youthA,
    'remarks' => 'PhaseSix apply A',
    'submissions' => [
        [
            'requirementId' => (int) ((payload($assist)['data']['requirementItems'][0]['id'] ?? 0)),
            'fileName' => 'barangay.pdf',
            'fileType' => 'application/pdf',
            'fileSize' => 1024,
            'fileRef' => 'phasesix-doc-1',
        ],
    ],
    'organization_id' => 2,
    'reviewed_by' => 99,
]), $sk1);
$tests[] = $created;
$expect['create_application'] = [201];
$appA = (int) (payload($created)['data']['id'] ?? 0);
if ($appA) {
    $appIds[] = $appA;
}
$subA = (int) (payload($created)['data']['submissions'][0]['id'] ?? 0);
if ($subA) {
    $subIds[] = $subA;
}

$tests[] = req('get_application', 'GET', "$base/sk/applications/{$appA}", null, $sk1);
$expect['get_application'] = [200];
$tests[] = req('list_after_create', 'GET', "$base/sk/applications?assistanceId={$assistId}", null, $sk1);
$expect['list_after_create'] = [200];
$tests[] = req('duplicate_application', 'POST', "$base/sk/applications", json_encode([
    'assistanceId' => $assistId,
    'youthId' => $youthA,
]), $sk1);
$expect['duplicate_application'] = [409];
$tests[] = req('invalid_assistance', 'POST', "$base/sk/applications", json_encode([
    'assistanceId' => 999999,
    'youthId' => $youthA,
]), $sk1);
$expect['invalid_assistance'] = [404];
$tests[] = req('invalid_youth', 'POST', "$base/sk/applications", json_encode([
    'assistanceId' => $assistId,
    'youthId' => 999999,
]), $sk1);
$expect['invalid_youth'] = [404];

$tests[] = req('archive_youth_d', 'POST', "$base/sk/youth/{$youthD}/archive", null, $sk1);
$expect['archive_youth_d'] = [200];
$tests[] = req('archived_youth_apply', 'POST', "$base/sk/applications", json_encode([
    'assistanceId' => $assistId2,
    'youthId' => $youthD,
]), $sk1);
$expect['archived_youth_apply'] = [422];

$tests[] = req('patch_application', 'PATCH', "$base/sk/applications/{$appA}", json_encode([
    'remarks' => 'PhaseSix patched',
    'organization_id' => 2,
    'reviewed_by' => 2,
]), $sk1);
$expect['patch_application'] = [200];

$approve = req('approve_application', 'POST', "$base/sk/applications/{$appA}/status", json_encode([
    'status' => 'approved',
    'remarks' => 'PhaseSix approved',
    'reviewed_by' => 2,
    'organization_id' => 4,
]), $sk1);
$tests[] = $approve;
$expect['approve_application'] = [200];

$appB = req('create_application_b', 'POST', "$base/sk/applications", json_encode([
    'assistanceId' => $assistId,
    'youthId' => $youthB,
    'remarks' => 'PhaseSix apply B',
]), $sk1);
$tests[] = $appB;
$expect['create_application_b'] = [201];
$appBId = (int) (payload($appB)['data']['id'] ?? 0);
if ($appBId) {
    $appIds[] = $appBId;
}

$tests[] = req('approve_full', 'POST', "$base/sk/applications/{$appBId}/status", json_encode([
    'status' => 'approved',
]), $sk1);
$expect['approve_full'] = [422];

$tests[] = req('reject_application', 'POST', "$base/sk/applications/{$appBId}/status", json_encode([
    'status' => 'rejected',
    'remarks' => 'PhaseSix rejected',
]), $sk1);
$expect['reject_application'] = [200];

$appC = req('create_application_c', 'POST', "$base/sk/applications", json_encode([
    'assistanceId' => $assistId2,
    'youthId' => $youthC,
    'remarks' => 'PhaseSix apply C',
]), $sk1);
$tests[] = $appC;
$expect['create_application_c'] = [201];
$appCId = (int) (payload($appC)['data']['id'] ?? 0);
if ($appCId) {
    $appIds[] = $appCId;
}
$tests[] = req('withdraw_application', 'POST', "$base/sk/applications/{$appCId}/status", json_encode([
    'status' => 'withdrawn',
]), $sk1);
$expect['withdraw_application'] = [200];
$tests[] = req('invalid_status', 'POST', "$base/sk/applications/{$appCId}/status", json_encode([
    'status' => 'not-a-status',
]), $sk1);
$expect['invalid_status'] = [422];

$review = req('review_requirement', 'PATCH', "$base/sk/application-requirements/{$subA}", json_encode([
    'status' => 'verified',
    'remarks' => 'Looks good',
    'organization_id' => 2,
    'reviewed_by' => 9,
]), $sk1);
$tests[] = $review;
$expect['review_requirement'] = [200];

$tests[] = req('login_sk2', 'POST', "$base/auth/login", $login('sk2@demo.test'), $sk2);
$expect['login_sk2'] = [200];
$tests[] = req('cross_get', 'GET', "$base/sk/applications/{$appA}", null, $sk2);
$expect['cross_get'] = [404];
$tests[] = req('cross_patch', 'PATCH', "$base/sk/applications/{$appA}", json_encode(['remarks' => 'hack']), $sk2);
$expect['cross_patch'] = [404];
$tests[] = req('cross_status', 'POST', "$base/sk/applications/{$appA}/status", json_encode([
    'status' => 'rejected',
    'remarks' => 'hack',
    'organization_id' => 1,
]), $sk2);
$expect['cross_status'] = [404];
$tests[] = req('cross_requirement', 'PATCH', "$base/sk/application-requirements/{$subA}", json_encode([
    'status' => 'verified',
]), $sk2);
$expect['cross_requirement'] = [404];

$tests[] = req('logout', 'POST', "$base/auth/logout", null, $sk1);
$expect['logout'] = [200];
$tests[] = req('me_after_logout', 'GET', "$base/auth/me", null, $sk1);
$expect['me_after_logout'] = [401];
$tests[] = req('relogin', 'POST', "$base/auth/login", $login('sk1@demo.test'), $sk1);
$expect['relogin'] = [200];

$assistDetail = req('assistance_after_approve', 'GET', "$base/sk/assistance/{$assistId}", null, $sk1);
$tests[] = $assistDetail;
$expect['assistance_after_approve'] = [200];
foreach (payload($assistDetail)['data']['beneficiaries'] ?? [] as $b) {
    if (!empty($b['id'])) {
        $benIds[] = (int) $b['id'];
    }
}

foreach (array_unique($subIds) as $id) {
    $pdo->prepare('DELETE FROM application_submissions WHERE id = :id')->execute(['id' => $id]);
}
$leftSubs = $pdo->prepare('SELECT id FROM application_submissions WHERE application_id = :id');
foreach ($appIds as $id) {
    $leftSubs->execute(['id' => $id]);
    foreach ($leftSubs->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $pdo->prepare('DELETE FROM application_submissions WHERE id = :id')->execute(['id' => (int) $sid]);
    }
}
foreach ($appIds as $id) {
    $pdo->prepare('DELETE FROM applications WHERE id = :id')->execute(['id' => $id]);
}
foreach (array_unique($benIds) as $id) {
    $tests[] = req("cleanup_beneficiary_{$id}", 'DELETE', "$base/sk/beneficiaries/{$id}", null, $sk1);
    $expect["cleanup_beneficiary_{$id}"] = [200];
}
foreach ($assistIds as $id) {
    $tests[] = req("cleanup_assistance_{$id}", 'DELETE', "$base/sk/assistance/{$id}", null, $sk1);
    $expect["cleanup_assistance_{$id}"] = [200];
}
foreach ($youthIds as $id) {
    $tests[] = req("cleanup_youth_{$id}", 'DELETE', "$base/sk/youth/{$id}", null, $sk1);
    $expect["cleanup_youth_{$id}"] = [200];
}

$after = $counts();
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
    if (str_contains($t['body'], 'C:\\') || str_contains($t['body'], '/xampp/')) {
        $failed[] = $t['name'] . ' leaked filesystem path';
    }
}

$approveData = payload($approve)['data'] ?? [];
if (($approveData['status'] ?? '') !== 'approved') {
    $failed[] = 'approve did not set status approved';
}
if ((int) ($approveData['reviewedBy']['id'] ?? 0) !== 1) {
    $failed[] = 'reviewed_by did not come from session user 1';
}
if (($approveData['reviewedAt'] ?? '') === '') {
    $failed[] = 'reviewed_at was not populated';
}
$listItems = payload(array_values(array_filter($tests, static fn ($t) => $t['name'] === 'list_after_create'))[0] ?? ['body' => '{}'])['data']['items'] ?? [];
$found = false;
foreach ($listItems as $item) {
    if ((int) ($item['id'] ?? 0) === $appA) {
        $found = true;
    }
}
if ($appA && !$found) {
    $failed[] = 'list did not include created application';
}
$assistBens = payload($assistDetail)['data']['beneficiaries'] ?? [];
$benOk = false;
foreach ($assistBens as $b) {
    if ((int) ($b['youthId'] ?? 0) === $youthA && ($b['status'] ?? '') === 'approved') {
        $benOk = true;
    }
}
if (!$benOk) {
    $failed[] = 'approval did not create/update Phase 5 beneficiary';
}

foreach ($before as $key => $n) {
    if ($after[$key] !== $n) {
        $failed[] = "{$key} count changed {$n} -> {$after[$key]}";
    }
}
$left = $pdo->query("SELECT id FROM youth WHERE first_name = 'PhaseSix'")->fetchAll();
$leftP = $pdo->query("SELECT id FROM assistance_programs WHERE name LIKE 'PhaseSix Test%'")->fetchAll();
if ($left || $leftP) {
    $failed[] = 'leftover PhaseSix test records remain';
}

echo "application_id={$appA}\n";
echo "assistance_id={$assistId}\n";
echo "youth_a={$youthA}\n";
foreach ($before as $key => $n) {
    echo "{$key}_before={$n} {$key}_after={$after[$key]}\n";
}

if ($failed) {
    echo "RESULT=FAIL\n";
    foreach ($failed as $f) {
        echo "FAIL: {$f}\n";
    }
    exit(1);
}

echo "RESULT=PASS\n";
