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
    "SELECT id, organization_id, kind, name, scheduled_on
     FROM programs
     WHERE name LIKE 'PhaseThree Test%'"
)->fetchAll(PDO::FETCH_ASSOC);
if ($leftover) {
    fwrite(STDERR, "STOP: pre-existing PhaseThree test records found. No cleanup was performed.\n");
    foreach ($leftover as $row) {
        fwrite(STDERR, json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }
    exit(2);
}

$base = 'http://localhost/YouthSync_UI_Refresh/api';
$usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$orgsBefore = (int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$youthBefore = (int) $pdo->query('SELECT COUNT(*) FROM youth')->fetchColumn();

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

$sk1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p3_sk1.txt';
$sk2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p3_sk2.txt';
$sk4 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p3_sk4.txt';
$empty = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p3_empty.txt';
foreach ([$sk1, $sk2, $sk4, $empty] as $f) {
    @unlink($f);
}

$login = static fn (string $email): string => json_encode(['email' => $email, 'password' => 'YouthSync1!']);
$createdIds = [];
$createdYouthIds = [];
$tests = [];

$tests[] = req('unauth_list', 'GET', "$base/sk/programs", null, $empty);
$tests[] = req('login_sk1', 'POST', "$base/auth/login", $login('sk1@demo.test'), $sk1);
$tests[] = req('me_sk1', 'GET', "$base/auth/me", null, $sk1);
$tests[] = req('list_programs', 'GET', "$base/sk/programs", null, $sk1);

$programBody = json_encode([
    'kind' => 'program',
    'name' => 'PhaseThree Test Program',
    'category' => 'Leadership',
    'scheduledOn' => '2026-10-01',
    'startsAt' => '08:00',
    'endsAt' => '15:00',
    'location' => 'Barangay Hall',
    'description' => 'Phase 3 test program',
    'status' => 'draft',
    'organization_id' => 999,
    'orgId' => 4,
]);
$created = req('create_program', 'POST', "$base/sk/programs", $programBody, $sk1);
$tests[] = $created;
$programId = (int) (payload($created)['data']['id'] ?? 0);
if ($programId) {
    $createdIds[] = $programId;
}

$tests[] = req('get_program', 'GET', "$base/sk/programs/{$programId}", null, $sk1);
$tests[] = req('update_program', 'PATCH', "$base/sk/programs/{$programId}", json_encode([
    'name' => 'PhaseThree Test Program',
    'location' => 'Covered Court',
    'scheduledOn' => '2026-10-01',
    'kind' => 'program',
    'organization_id' => 4,
]), $sk1);
$tests[] = req('list_after_create', 'GET', "$base/sk/programs", null, $sk1);

$tests[] = req('invalid_required', 'POST', "$base/sk/programs", json_encode(['kind' => 'program']), $sk1);
$tests[] = req('invalid_date', 'POST', "$base/sk/programs", json_encode([
    'kind' => 'program',
    'name' => 'Bad Date Program',
    'scheduledOn' => 'not-a-date',
]), $sk1);
$tests[] = req('invalid_status', 'POST', "$base/sk/programs", json_encode([
    'kind' => 'program',
    'name' => 'Bad Status Program',
    'scheduledOn' => '2026-10-02',
    'status' => 'not-a-status',
]), $sk1);
$tests[] = req('invalid_times', 'POST', "$base/sk/programs", json_encode([
    'kind' => 'event',
    'name' => 'Bad Times Event',
    'scheduledOn' => '2026-10-03',
    'startsAt' => '15:00',
    'endsAt' => '08:00',
]), $sk1);
$tests[] = req('duplicate_program', 'POST', "$base/sk/programs", $programBody, $sk1);

$eventBody = json_encode([
    'name' => 'PhaseThree Test Event',
    'category' => 'Sports & Recreation',
    'scheduledOn' => '2026-10-15',
    'startsAt' => '09:00',
    'endsAt' => '12:00',
    'location' => 'Plaza',
    'status' => 'draft',
    'organization_id' => 2,
]);
$createdEvent = req('create_event', 'POST', "$base/sk/events", $eventBody, $sk1);
$tests[] = $createdEvent;
$eventId = (int) (payload($createdEvent)['data']['id'] ?? 0);
if ($eventId) {
    $createdIds[] = $eventId;
}

$tests[] = req('get_event', 'GET', "$base/sk/events/{$eventId}", null, $sk1);
$tests[] = req('update_event', 'PATCH', "$base/sk/events/{$eventId}", json_encode([
    'name' => 'PhaseThree Test Event',
    'location' => 'Gym',
    'scheduledOn' => '2026-10-15',
]), $sk1);
$tests[] = req('list_events', 'GET', "$base/sk/events", null, $sk1);
$tests[] = req('invalid_event', 'POST', "$base/sk/events", json_encode(['name' => '']), $sk1);
$tests[] = req('archive_program', 'POST', "$base/sk/programs/{$programId}/archive", null, $sk1);

$tests[] = req('login_sk2', 'POST', "$base/auth/login", $login('sk2@demo.test'), $sk2);
$tests[] = req('cross_get_program', 'GET', "$base/sk/programs/{$programId}", null, $sk2);
$tests[] = req('cross_update_program', 'PATCH', "$base/sk/programs/{$programId}", json_encode([
    'name' => 'Hacked',
    'scheduledOn' => '2026-10-01',
    'kind' => 'program',
]), $sk2);
$tests[] = req('cross_delete_program', 'DELETE', "$base/sk/programs/{$programId}", null, $sk2);
$tests[] = req('cross_get_event', 'GET', "$base/sk/events/{$eventId}", null, $sk2);
$tests[] = req('cross_nested_event', 'POST', "$base/sk/programs/{$programId}/events", json_encode([
    'name' => 'PhaseThree Nested Hijack',
    'scheduledOn' => '2026-10-20',
    'organization_id' => 1,
]), $sk2);
$tests[] = req('list_events_for_program', 'GET', "$base/sk/programs/{$programId}/events", null, $sk1);

$youthCreate = req('phase2_create_youth', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'PhaseThree',
    'lastName' => 'YouthIso',
    'birthDate' => '2004-01-01',
    'address' => 'Test address',
    'contact' => '09170009999',
    'interests' => ['Sports'],
]), $sk1);
$tests[] = $youthCreate;
$youthId = (int) (payload($youthCreate)['data']['youth']['id'] ?? 0);
if ($youthId) {
    $createdYouthIds[] = $youthId;
}
$tests[] = req('phase2_youth_list', 'GET', "$base/sk/youth", null, $sk1);
$tests[] = req('phase2_cross_youth', 'GET', "$base/sk/youth/{$youthId}", null, $sk2);

$tests[] = req('login_sk4', 'POST', "$base/auth/login", $login('sk4@demo.test'), $sk4);
$freeOne = req('free_program_one', 'POST', "$base/sk/programs", json_encode([
    'kind' => 'program',
    'name' => 'PhaseThree Test Program Free',
    'scheduledOn' => '2026-11-01',
]), $sk4);
$tests[] = $freeOne;
$freeId = (int) (payload($freeOne)['data']['id'] ?? 0);
if ($freeId) {
    $createdIds[] = $freeId;
}
$tests[] = req('free_program_two', 'POST', "$base/sk/programs", json_encode([
    'kind' => 'event',
    'name' => 'PhaseThree Test Event Free',
    'scheduledOn' => '2026-11-02',
]), $sk4);
if ($freeId) {
    $tests[] = req("cleanup_program_{$freeId}", 'DELETE', "$base/sk/programs/{$freeId}", null, $sk4);
    $createdIds = array_values(array_filter($createdIds, static fn ($id) => (int) $id !== $freeId));
}

$tests[] = req('logout', 'POST', "$base/auth/logout", null, $sk1);
$tests[] = req('me_after_logout', 'GET', "$base/auth/me", null, $sk1);
$tests[] = req('relogin', 'POST', "$base/auth/login", $login('sk1@demo.test'), $sk1);

foreach ($createdIds as $id) {
    $tests[] = req("cleanup_program_{$id}", 'DELETE', "$base/sk/programs/{$id}", null, $sk1);
}
foreach ($createdYouthIds as $id) {
    $tests[] = req("cleanup_youth_{$id}", 'DELETE', "$base/sk/youth/{$id}", null, $sk1);
}

$tests[] = req('get_deleted_program', 'GET', "$base/sk/programs/{$programId}", null, $sk1);
$tests[] = req('get_deleted_event', 'GET', "$base/sk/events/{$eventId}", null, $sk1);

$usersAfter = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$orgsAfter = (int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$youthAfter = (int) $pdo->query('SELECT COUNT(*) FROM youth')->fetchColumn();
$left = $pdo->query(
    "SELECT id, name FROM programs WHERE name LIKE 'PhaseThree Test%'"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($tests as $t) {
    $hasHash = stripos($t['body'], 'password_hash') !== false;
    echo $t['name'] . "\t" . $t['status'] . "\thash=" . ($hasHash ? 'YES' : 'no') . PHP_EOL;
    echo $t['body'] . PHP_EOL . PHP_EOL;
}

$update = null;
$create = payload($created);
foreach ($tests as $t) {
    if ($t['name'] === 'update_program') {
        $update = payload($t);
    }
}
echo "program_id={$programId}\n";
echo "event_id={$eventId}\n";
echo "create_org_spoof_ignored=" . ((($create['data']['id'] ?? 0) && $programId) ? 'yes' : 'no') . PHP_EOL;
echo "update_location=" . ($update['data']['location'] ?? '') . PHP_EOL;
echo "users_before={$usersBefore} users_after={$usersAfter}\n";
echo "orgs_before={$orgsBefore} orgs_after={$orgsAfter}\n";
echo "youth_before={$youthBefore} youth_after={$youthAfter}\n";
echo "leftover_phasetthree=" . count($left) . PHP_EOL;
