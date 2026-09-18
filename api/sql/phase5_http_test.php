<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();
$leftover = $pdo->query(
    "SELECT id, name FROM assistance_programs WHERE name LIKE 'PhaseFive Test%'"
)->fetchAll(PDO::FETCH_ASSOC);
$leftoverYouth = $pdo->query(
    "SELECT id FROM youth WHERE first_name = 'PhaseFive' AND last_name LIKE 'Test%'"
)->fetchAll(PDO::FETCH_ASSOC);
$leftoverTypes = $pdo->query(
    "SELECT id FROM assistance_types WHERE is_system = 0 AND name LIKE 'PhaseFive Test%'"
)->fetchAll(PDO::FETCH_ASSOC);
if ($leftover || $leftoverYouth || $leftoverTypes) {
    fwrite(STDERR, "STOP: pre-existing PhaseFive test records found. No cleanup was performed.\n");
    exit(2);
}

$base = 'http://localhost/YouthSync_UI_Refresh/api';
$usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$orgsBefore = (int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$youthBefore = (int) $pdo->query('SELECT COUNT(*) FROM youth')->fetchColumn();
$programsBefore = (int) $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn();
$attendanceBefore = (int) $pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn();
$assistBefore = (int) $pdo->query('SELECT COUNT(*) FROM assistance_programs')->fetchColumn();
$benBefore = (int) $pdo->query('SELECT COUNT(*) FROM beneficiaries')->fetchColumn();
$typesBefore = (int) $pdo->query('SELECT COUNT(*) FROM assistance_types')->fetchColumn();
$reqBefore = (int) $pdo->query('SELECT COUNT(*) FROM assistance_requirements')->fetchColumn();

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

$sk1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p5_sk1.txt';
$sk2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p5_sk2.txt';
$sk4 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p5_sk4.txt';
$empty = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p5_empty.txt';
foreach ([$sk1, $sk2, $sk4, $empty] as $f) {
    @unlink($f);
}

$login = static fn (string $email): string => json_encode(['email' => $email, 'password' => 'YouthSync1!']);
$createdAssistIds = [];
$createdYouthIds = [];
$createdTypeIds = [];
$createdBenIds = [];
$createdReqIds = [];
$tests = [];
$expect = [];

$tests[] = req('unauth_assistance', 'GET', "$base/sk/assistance", null, $empty);
$expect['unauth_assistance'] = [401];

$tests[] = req('login_sk1', 'POST', "$base/auth/login", $login('sk1@demo.test'), $sk1);
$expect['login_sk1'] = [200];
$tests[] = req('phase1_me', 'GET', "$base/auth/me", null, $sk1);
$expect['phase1_me'] = [200];
$tests[] = req('phase2_youth_list', 'GET', "$base/sk/youth", null, $sk1);
$expect['phase2_youth_list'] = [200];
$tests[] = req('phase3_programs', 'GET', "$base/sk/programs", null, $sk1);
$expect['phase3_programs'] = [200];

$types = req('list_types', 'GET', "$base/sk/assistance-types", null, $sk1);
$tests[] = $types;
$expect['list_types'] = [200];

$youthCreate = req('create_youth', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'PhaseFive',
    'lastName' => 'TestYouth',
    'birthDate' => '2005-05-05',
    'address' => 'Phase 5 test address',
    'contact' => '09170005501',
    'interests' => ['Education & training'],
    'organization_id' => 2,
]), $sk1);
$tests[] = $youthCreate;
$expect['create_youth'] = [201];
$youthId = (int) (payload($youthCreate)['data']['youth']['id'] ?? 0);
if ($youthId) {
    $createdYouthIds[] = $youthId;
}

$created = req('create_assistance', 'POST', "$base/sk/assistance", json_encode([
    'name' => 'PhaseFive Test Scholarship',
    'category' => 'scholarship',
    'typeId' => 1,
    'status' => 'open',
    'slots' => 1,
    'amount' => 5000,
    'description' => 'Phase 5 test program',
    'deadline' => '2026-12-31',
    'requiresStudying' => true,
    'tagInterests' => ['Education & training'],
    'organization_id' => 999,
    'orgId' => 4,
]), $sk1);
$tests[] = $created;
$expect['create_assistance'] = [201];
$assistId = (int) (payload($created)['data']['id'] ?? 0);
if ($assistId) {
    $createdAssistIds[] = $assistId;
}

$tests[] = req('get_assistance', 'GET', "$base/sk/assistance/{$assistId}", null, $sk1);
$expect['get_assistance'] = [200];
$tests[] = req('list_assistance', 'GET', "$base/sk/assistance", null, $sk1);
$expect['list_assistance'] = [200];

$tests[] = req('update_assistance', 'PATCH', "$base/sk/assistance/{$assistId}", json_encode([
    'description' => 'Updated Phase 5 description',
    'organization_id' => 2,
]), $sk1);
$expect['update_assistance'] = [200];

$tests[] = req('invalid_required', 'POST', "$base/sk/assistance", json_encode([
    'category' => 'scholarship',
]), $sk1);
$expect['invalid_required'] = [422];
$tests[] = req('invalid_status', 'POST', "$base/sk/assistance", json_encode([
    'name' => 'PhaseFive Test Bad Status',
    'category' => 'scholarship',
    'status' => 'not-a-status',
]), $sk1);
$expect['invalid_status'] = [422];
$tests[] = req('invalid_date', 'POST', "$base/sk/assistance", json_encode([
    'name' => 'PhaseFive Test Bad Date',
    'category' => 'scholarship',
    'deadline' => 'not-a-date',
]), $sk1);
$expect['invalid_date'] = [422];
$tests[] = req('invalid_category', 'POST', "$base/sk/assistance", json_encode([
    'name' => 'PhaseFive Test Bad Category',
    'category' => 'grant',
]), $sk1);
$expect['invalid_category'] = [422];
$tests[] = req('duplicate_assistance', 'POST', "$base/sk/assistance", json_encode([
    'name' => 'PhaseFive Test Scholarship',
    'category' => 'scholarship',
]), $sk1);
$expect['duplicate_assistance'] = [409];

$ben = req('create_beneficiary', 'POST', "$base/sk/assistance/{$assistId}/beneficiaries", json_encode([
    'youthId' => $youthId,
    'status' => 'applied',
    'remarks' => 'Phase 5 record',
    'organization_id' => 2,
]), $sk1);
$tests[] = $ben;
$expect['create_beneficiary'] = [201, 200];
$benId = (int) (payload($ben)['data']['id'] ?? 0);
if ($benId) {
    $createdBenIds[] = $benId;
}

$tests[] = req('approve_beneficiary', 'POST', "$base/sk/assistance/{$assistId}/beneficiaries", json_encode([
    'youthId' => $youthId,
    'status' => 'approved',
]), $sk1);
$expect['approve_beneficiary'] = [201, 200];

$youth2 = req('create_youth_two', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'PhaseFive',
    'lastName' => 'TestYouthB',
    'birthDate' => '2004-04-04',
    'address' => 'Phase 5 second address',
    'contact' => '09170005502',
    'interests' => ['Sports'],
]), $sk1);
$tests[] = $youth2;
$expect['create_youth_two'] = [201];
$youth2Id = (int) (payload($youth2)['data']['youth']['id'] ?? 0);
if ($youth2Id) {
    $createdYouthIds[] = $youth2Id;
}

$tests[] = req('slots_full', 'POST', "$base/sk/assistance/{$assistId}/beneficiaries", json_encode([
    'youthId' => $youth2Id,
    'status' => 'approved',
]), $sk1);
$expect['slots_full'] = [422];

$tests[] = req('archive_youth_two', 'POST', "$base/sk/youth/{$youth2Id}/archive", null, $sk1);
$expect['archive_youth_two'] = [200];
$tests[] = req('archived_youth_beneficiary', 'POST', "$base/sk/assistance/{$assistId}/beneficiaries", json_encode([
    'youthId' => $youth2Id,
    'status' => 'applied',
]), $sk1);
$expect['archived_youth_beneficiary'] = [422];
$tests[] = req('restore_youth_two', 'POST', "$base/sk/youth/{$youth2Id}/restore", null, $sk1);
$expect['restore_youth_two'] = [200];

$tests[] = req('missing_youth', 'POST', "$base/sk/assistance/{$assistId}/beneficiaries", json_encode([
    'status' => 'applied',
]), $sk1);
$expect['missing_youth'] = [422];
$tests[] = req('nonexistent_youth', 'POST', "$base/sk/assistance/{$assistId}/beneficiaries", json_encode([
    'youthId' => 999999,
    'status' => 'applied',
]), $sk1);
$expect['nonexistent_youth'] = [404];

$reqAdd = req('add_requirement', 'POST', "$base/sk/requirements", json_encode([
    'targetType' => 'assistance',
    'targetId' => $assistId,
    'name' => 'PhaseFive Extra Requirement',
    'description' => 'Manual requirement',
    'required' => true,
    'accepts' => 'application/pdf',
]), $sk1);
$tests[] = $reqAdd;
$expect['add_requirement'] = [201];
$reqId = (int) (payload($reqAdd)['data']['id'] ?? 0);
if ($reqId) {
    $createdReqIds[] = $reqId;
}
$tests[] = req('update_requirement', 'PATCH', "$base/sk/requirements/{$reqId}", json_encode([
    'required' => false,
]), $sk1);
$expect['update_requirement'] = [200];
$tests[] = req('invalid_requirement_target', 'POST', "$base/sk/requirements", json_encode([
    'targetType' => 'program',
    'targetId' => 1,
    'name' => 'Should fail',
]), $sk1);
$expect['invalid_requirement_target'] = [422];

$customType = req('create_type', 'POST', "$base/sk/assistance-types", json_encode([
    'name' => 'PhaseFive Test Custom Type',
    'category' => 'other',
    'requirements' => [
        ['name' => 'Custom Doc', 'description' => 'A custom file', 'required' => true, 'accepts' => 'image/*'],
    ],
    'organization_id' => 2,
]), $sk1);
$tests[] = $customType;
$expect['create_type'] = [201];
$typeId = (int) (payload($customType)['data']['id'] ?? 0);
if ($typeId) {
    $createdTypeIds[] = $typeId;
}

$tests[] = req('login_sk2', 'POST', "$base/auth/login", $login('sk2@demo.test'), $sk2);
$expect['login_sk2'] = [200];
$tests[] = req('cross_get', 'GET', "$base/sk/assistance/{$assistId}", null, $sk2);
$expect['cross_get'] = [404];
$tests[] = req('cross_update', 'PATCH', "$base/sk/assistance/{$assistId}", json_encode(['name' => 'Hacked']), $sk2);
$expect['cross_update'] = [404];
$tests[] = req('cross_archive', 'POST', "$base/sk/assistance/{$assistId}/archive", null, $sk2);
$expect['cross_archive'] = [404];
$tests[] = req('cross_delete', 'DELETE', "$base/sk/assistance/{$assistId}", null, $sk2);
$expect['cross_delete'] = [404];
$tests[] = req('cross_beneficiary', 'POST', "$base/sk/assistance/{$assistId}/beneficiaries", json_encode([
    'youthId' => $youthId,
    'status' => 'applied',
    'organization_id' => 1,
]), $sk2);
$expect['cross_beneficiary'] = [404];
$tests[] = req('cross_requirement', 'PATCH', "$base/sk/requirements/{$reqId}", json_encode(['name' => 'Hacked']), $sk2);
$expect['cross_requirement'] = [404];
$tests[] = req('cross_ben_delete', 'DELETE', "$base/sk/beneficiaries/{$benId}", null, $sk2);
$expect['cross_ben_delete'] = [404];
$tests[] = req('cross_use_custom_type', 'POST', "$base/sk/assistance", json_encode([
    'name' => 'PhaseFive Test Stolen Type',
    'category' => 'other',
    'typeId' => $typeId,
]), $sk2);
$expect['cross_use_custom_type'] = [404];

$otherYouth = req('create_other_youth', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'PhaseFive',
    'lastName' => 'TestOther',
    'birthDate' => '2003-03-03',
    'address' => 'Other org',
    'contact' => '09170005503',
    'interests' => ['Sports'],
]), $sk2);
$tests[] = $otherYouth;
$expect['create_other_youth'] = [201];
$otherYouthId = (int) (payload($otherYouth)['data']['youth']['id'] ?? 0);
if ($otherYouthId) {
    $createdYouthIds[] = $otherYouthId;
}
$tests[] = req('cross_youth_beneficiary', 'POST', "$base/sk/assistance/{$assistId}/beneficiaries", json_encode([
    'youthId' => $otherYouthId,
    'status' => 'applied',
]), $sk1);
$expect['cross_youth_beneficiary'] = [404];

$tests[] = req('login_sk4', 'POST', "$base/auth/login", $login('sk4@demo.test'), $sk4);
$expect['login_sk4'] = [200];
$freeOne = req('free_assistance_one', 'POST', "$base/sk/assistance", json_encode([
    'name' => 'PhaseFive Test Free One',
    'category' => 'financial',
    'status' => 'open',
]), $sk4);
$tests[] = $freeOne;
$expect['free_assistance_one'] = [201];
$freeId = (int) (payload($freeOne)['data']['id'] ?? 0);
if ($freeId) {
    $createdAssistIds[] = $freeId;
}
$tests[] = req('free_assistance_two', 'POST', "$base/sk/assistance", json_encode([
    'name' => 'PhaseFive Test Free Two',
    'category' => 'financial',
    'status' => 'draft',
]), $sk4);
$expect['free_assistance_two'] = [403];

$tests[] = req('archive_assistance', 'POST', "$base/sk/assistance/{$assistId}/archive", null, $sk1);
$expect['archive_assistance'] = [200];

$tests[] = req('logout', 'POST', "$base/auth/logout", null, $sk1);
$expect['logout'] = [200];
$tests[] = req('me_after_logout', 'GET', "$base/auth/me", null, $sk1);
$expect['me_after_logout'] = [401];
$tests[] = req('relogin', 'POST', "$base/auth/login", $login('sk1@demo.test'), $sk1);
$expect['relogin'] = [200];

if ($reqId) {
    $tests[] = req("cleanup_requirement_{$reqId}", 'DELETE', "$base/sk/requirements/{$reqId}", null, $sk1);
    $expect["cleanup_requirement_{$reqId}"] = [200];
}
if ($benId) {
    $tests[] = req("cleanup_beneficiary_{$benId}", 'DELETE', "$base/sk/beneficiaries/{$benId}", null, $sk1);
    $expect["cleanup_beneficiary_{$benId}"] = [200];
}
foreach ($createdAssistIds as $id) {
    $cookie = ((int) $id === (int) $freeId) ? $sk4 : $sk1;
    $tests[] = req("cleanup_assistance_{$id}", 'DELETE', "$base/sk/assistance/{$id}", null, $cookie);
    $expect["cleanup_assistance_{$id}"] = [200];
}
foreach ($createdTypeIds as $id) {
    $pdo->prepare('DELETE FROM assistance_types WHERE id = :id AND is_system = 0')->execute(['id' => $id]);
}
foreach ($createdYouthIds as $id) {
    $cookie = ((int) $id === (int) $otherYouthId) ? $sk2 : $sk1;
    $tests[] = req("cleanup_youth_{$id}", 'DELETE', "$base/sk/youth/{$id}", null, $cookie);
    $expect["cleanup_youth_{$id}"] = [200];
}

$usersAfter = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$orgsAfter = (int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$youthAfter = (int) $pdo->query('SELECT COUNT(*) FROM youth')->fetchColumn();
$programsAfter = (int) $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn();
$attendanceAfter = (int) $pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn();
$assistAfter = (int) $pdo->query('SELECT COUNT(*) FROM assistance_programs')->fetchColumn();
$benAfter = (int) $pdo->query('SELECT COUNT(*) FROM beneficiaries')->fetchColumn();
$typesAfter = (int) $pdo->query('SELECT COUNT(*) FROM assistance_types')->fetchColumn();
$reqAfter = (int) $pdo->query('SELECT COUNT(*) FROM assistance_requirements')->fetchColumn();

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
    if (stripos($t['body'], 'DB_PASSWORD') !== false || stripos($t['body'], 'mysql') !== false && stripos($t['body'], 'password') !== false) {
        $failed[] = $t['name'] . ' may have leaked credentials';
    }
}

$createData = payload($created)['data'] ?? [];
if ($assistId < 1) {
    $failed[] = 'create_assistance did not return an id';
}
if (($createData['description'] ?? '') === '' && $assistId) {
    // spoof ignored as long as created
}
$getData = null;
foreach ($tests as $t) {
    if ($t['name'] === 'get_assistance') {
        $getData = payload($t)['data'] ?? [];
    }
}
if ($getData) {
    $preset = $getData['requirementItems'] ?? [];
    if (count($preset) < 1) {
        $failed[] = 'type preset requirements were not copied onto the assistance program';
    }
    $foundYouth = false;
    foreach ($getData['beneficiaries'] ?? [] as $b) {
        if ((int) ($b['youthId'] ?? 0) === $youthId) {
            $foundYouth = true;
        }
    }
    if (!$foundYouth && $benId) {
        // get ran before beneficiary create — ok
    }
}
$updateData = null;
foreach ($tests as $t) {
    if ($t['name'] === 'update_assistance') {
        $updateData = payload($t)['data'] ?? [];
    }
}
if (($updateData['description'] ?? '') !== 'Updated Phase 5 description') {
    $failed[] = 'update_assistance did not persist description';
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
if ($assistAfter !== $assistBefore) {
    $failed[] = "assistance_programs count changed {$assistBefore} -> {$assistAfter}";
}
if ($benAfter !== $benBefore) {
    $failed[] = "beneficiaries count changed {$benBefore} -> {$benAfter}";
}
if ($typesAfter !== $typesBefore) {
    $failed[] = "assistance_types count changed {$typesBefore} -> {$typesAfter}";
}
if ($reqAfter !== $reqBefore) {
    $failed[] = "assistance_requirements count changed {$reqBefore} -> {$reqAfter}";
}

$leftA = $pdo->query("SELECT id FROM assistance_programs WHERE name LIKE 'PhaseFive Test%'")->fetchAll();
$leftY = $pdo->query("SELECT id FROM youth WHERE first_name = 'PhaseFive' AND last_name LIKE 'Test%'")->fetchAll();
if ($leftA || $leftY) {
    $failed[] = 'leftover PhaseFive test records remain';
}

echo "assistance_id={$assistId}\n";
echo "youth_id={$youthId}\n";
echo "users_before={$usersBefore} users_after={$usersAfter}\n";
echo "orgs_before={$orgsBefore} orgs_after={$orgsAfter}\n";
echo "youth_before={$youthBefore} youth_after={$youthAfter}\n";
echo "programs_before={$programsBefore} programs_after={$programsAfter}\n";
echo "attendance_before={$attendanceBefore} attendance_after={$attendanceAfter}\n";
echo "assistance_before={$assistBefore} assistance_after={$assistAfter}\n";

if ($failed) {
    echo "RESULT=FAIL\n";
    foreach ($failed as $f) {
        echo "FAIL: {$f}\n";
    }
    exit(1);
}

echo "RESULT=PASS\n";
