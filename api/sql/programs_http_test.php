<?php
declare(strict_types=1);

/**
 * Safe Programs / Events HTTP check.
 *
 * Uses existing demo SK, admin, and youth sessions. Creates programs and events,
 * then deletes only those exact rows.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = 'http://localhost/YouthSync/api';
$adminEmail = 'admin@skmms.test';
$skEmail = 'sk1@demo.test';
$sk2Email = 'sk2@demo.test';
$youthEmail = 'youth-auth-test@example.test';
$demoPassword = 'YouthSync1!';
$youthPassword = 'YouthAuthTest1!';
$stamp = date('YmdHis');
$date = '2026-12-06';

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';
$pdo = youthsync_pdo();

$failed = 0;
$createdIds = [];

$check = static function (string $name, bool $ok, string $detail = '') use (&$failed): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}";
    if (!$ok && $detail !== '') {
        echo ' — ' . $detail;
    }
    echo "\n";
    if (!$ok) {
        $failed++;
    }
};

function prog_req(string $method, string $url, ?string $json, string $cookie): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 30,
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
        return ['status' => 0, 'body' => $err, 'json' => null];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $headerSize);
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
}

function prog_data(array $row): array
{
    $data = $row['json']['data'] ?? null;
    return is_array($data) ? $data : [];
}

function prog_detail(array $row): string
{
    $code = (string) ($row['json']['error']['code'] ?? '');
    $message = (string) ($row['json']['error']['message'] ?? '');
    return 'status=' . $row['status'] . ($code !== '' ? " code={$code}" : '') . ' ' . substr($message, 0, 180);
}

function prog_jar(): string
{
    $path = tempnam(sys_get_temp_dir(), 'ysprog');
    return $path === false ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ysprog-' . bin2hex(random_bytes(4)) : $path;
}

function prog_ids(PDO $pdo): array
{
    return array_map('intval', $pdo->query('SELECT id FROM programs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
}

$beforeIds = prog_ids($pdo);
$beforeCount = count($beforeIds);
$beforeEvents = (int) $pdo->query("SELECT COUNT(*) FROM programs WHERE kind = 'event'")->fetchColumn();
echo "programs_before={$beforeCount} events_before={$beforeEvents}\n";

$admin = prog_jar();
$sk = prog_jar();
$sk2 = prog_jar();
$youth = prog_jar();
$anon = prog_jar();

try {
    $anonList = prog_req('GET', "$base/sk/programs", null, $anon);
    $check('anonymous_programs_401', $anonList['status'] === 401, prog_detail($anonList));
    $anonEvents = prog_req('GET', "$base/sk/events", null, $anon);
    $check('anonymous_events_401', $anonEvents['status'] === 401, prog_detail($anonEvents));

    $adminLogin = prog_req('POST', "$base/auth/login", json_encode(['email' => $adminEmail, 'password' => $demoPassword]), $admin);
    $check('admin_login', $adminLogin['status'] === 200, prog_detail($adminLogin));
    $adminPrograms = prog_req('GET', "$base/sk/programs", null, $admin);
    $check('admin_programs_403', $adminPrograms['status'] === 403, prog_detail($adminPrograms));
    $adminEvents = prog_req('POST', "$base/sk/events", json_encode(['name' => 'Nope', 'scheduledOn' => $date]), $admin);
    $check('admin_events_403', $adminEvents['status'] === 403, prog_detail($adminEvents));

    $youthLogin = prog_req('POST', "$base/auth/login", json_encode(['email' => $youthEmail, 'password' => $youthPassword]), $youth);
    $check('youth_login', $youthLogin['status'] === 200, prog_detail($youthLogin));
    $youthPrograms = prog_req('GET', "$base/sk/programs", null, $youth);
    $check('youth_programs_403', $youthPrograms['status'] === 403, prog_detail($youthPrograms));
    $youthEvents = prog_req('GET', "$base/sk/events", null, $youth);
    $check('youth_events_403', $youthEvents['status'] === 403, prog_detail($youthEvents));

    $skLogin = prog_req('POST', "$base/auth/login", json_encode(['email' => $skEmail, 'password' => $demoPassword]), $sk);
    $check('sk_login', $skLogin['status'] === 200, prog_detail($skLogin));
    $me = prog_req('GET', "$base/auth/me", null, $sk);
    $orgId = (int) (prog_data($me)['organization']['id'] ?? 0);
    $check('sk_org', $me['status'] === 200 && $orgId > 0, prog_detail($me));

    $sk2Login = prog_req('POST', "$base/auth/login", json_encode(['email' => $sk2Email, 'password' => $demoPassword]), $sk2);
    $check('sk2_login', $sk2Login['status'] === 200, prog_detail($sk2Login));
    $me2 = prog_req('GET', "$base/auth/me", null, $sk2);
    $org2 = (int) (prog_data($me2)['organization']['id'] ?? 0);
    $check('sk2_other_org', $org2 > 0 && $org2 !== $orgId, prog_detail($me2));

    $programName = "ProgEvt Program {$stamp}";
    $created = prog_req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => $programName,
        'category' => 'Leadership',
        'scheduledOn' => $date,
        'startsAt' => '08:00',
        'endsAt' => '15:00',
        'location' => 'Barangay Hall',
        'description' => 'Programs HTTP test',
        'status' => 'draft',
        'organization_id' => $org2,
        'orgId' => 999,
    ]), $sk);
    $program = prog_data($created);
    $programId = (int) ($program['id'] ?? 0);
    if ($programId > 0) {
        $createdIds[] = $programId;
    }
    $check('create_program_201', $created['status'] === 201 && $programId > 0 && ($program['kind'] ?? '') === 'program', prog_detail($created));
    $check('create_no_password_hash', stripos($created['body'], 'password_hash') === false);

    $rowStmt = $pdo->prepare('SELECT organization_id, kind, name, status, location FROM programs WHERE id = :id');
    $rowStmt->execute(['id' => $programId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('create_scoped_to_session_org', (int) ($row['organization_id'] ?? 0) === $orgId, json_encode($row));

    $listed = prog_req('GET', "$base/sk/programs?organization_id={$org2}", null, $sk);
    $items = prog_data($listed)['items'] ?? [];
    $found = false;
    if (is_array($items)) {
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $programId) {
                $found = true;
            }
        }
    }
    $check('list_includes_created', $listed['status'] === 200 && $found, prog_detail($listed));

    $shown = prog_req('GET', "$base/sk/programs/{$programId}", null, $sk);
    $check('read_program', $shown['status'] === 200 && (prog_data($shown)['name'] ?? '') === $programName, prog_detail($shown));

    $updated = prog_req('PATCH', "$base/sk/programs/{$programId}", json_encode([
        'name' => $programName,
        'location' => 'Covered Court',
        'scheduledOn' => $date,
        'kind' => 'program',
        'organization_id' => $org2,
    ]), $sk);
    $check('update_program', $updated['status'] === 200 && (prog_data($updated)['location'] ?? '') === 'Covered Court', prog_detail($updated));
    $rowStmt->execute(['id' => $programId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('update_kept_org', (int) ($row['organization_id'] ?? 0) === $orgId && ($row['location'] ?? '') === 'Covered Court', json_encode($row));

    $status = prog_req('POST', "$base/sk/programs/{$programId}/status", json_encode([
        'status' => 'published',
        'organization_id' => $org2,
    ]), $sk);
    $check('status_published', $status['status'] === 200 && (prog_data($status)['status'] ?? '') === 'published', prog_detail($status));

    $archived = prog_req('POST', "$base/sk/programs/{$programId}/archive", json_encode(['organization_id' => $org2]), $sk);
    $check('archive_program', $archived['status'] === 200 && (prog_data($archived)['status'] ?? '') === 'archived', prog_detail($archived));

    $restored = prog_req('POST', "$base/sk/programs/{$programId}/status", json_encode(['status' => 'draft']), $sk);
    $check('restore_to_draft', $restored['status'] === 200 && (prog_data($restored)['status'] ?? '') === 'draft', prog_detail($restored));

    $invalid = prog_req('POST', "$base/sk/programs", json_encode(['kind' => 'program']), $sk);
    $check('invalid_program_422', $invalid['status'] === 422, prog_detail($invalid));
    $duplicate = prog_req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => $programName,
        'scheduledOn' => $date,
        'status' => 'draft',
    ]), $sk);
    $check('duplicate_program_409', $duplicate['status'] === 409, prog_detail($duplicate));

    $eventName = "ProgEvt Event {$stamp}";
    $eventCreated = prog_req('POST', "$base/sk/events", json_encode([
        'name' => $eventName,
        'category' => 'Sports & Recreation',
        'scheduledOn' => '2026-12-07',
        'startsAt' => '09:00',
        'endsAt' => '12:00',
        'location' => 'Plaza',
        'status' => 'draft',
        'kind' => 'program',
        'organization_id' => $org2,
    ]), $sk);
    $event = prog_data($eventCreated);
    $eventId = (int) ($event['id'] ?? 0);
    if ($eventId > 0) {
        $createdIds[] = $eventId;
    }
    $check('create_event_201', $eventCreated['status'] === 201 && $eventId > 0 && ($event['kind'] ?? '') === 'event', prog_detail($eventCreated));
    $rowStmt->execute(['id' => $eventId]);
    $eventRow = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('event_scoped_to_session_org', (int) ($eventRow['organization_id'] ?? 0) === $orgId && ($eventRow['kind'] ?? '') === 'event', json_encode($eventRow));

    $eventShown = prog_req('GET', "$base/sk/events/{$eventId}", null, $sk);
    $check('read_event', $eventShown['status'] === 200 && (prog_data($eventShown)['name'] ?? '') === $eventName, prog_detail($eventShown));
    $eventList = prog_req('GET', "$base/sk/events?organization_id={$org2}", null, $sk);
    $eventItems = prog_data($eventList)['items'] ?? [];
    $eventFound = false;
    if (is_array($eventItems)) {
        foreach ($eventItems as $item) {
            if ((int) ($item['id'] ?? 0) === $eventId) {
                $eventFound = true;
            }
        }
    }
    $check('list_events', $eventList['status'] === 200 && $eventFound, prog_detail($eventList));

    $eventUpdated = prog_req('PATCH', "$base/sk/events/{$eventId}", json_encode([
        'name' => $eventName,
        'location' => 'Gym',
        'scheduledOn' => '2026-12-07',
        'organization_id' => $org2,
    ]), $sk);
    $check('update_event', $eventUpdated['status'] === 200 && (prog_data($eventUpdated)['location'] ?? '') === 'Gym', prog_detail($eventUpdated));

    $eventStatus = prog_req('POST', "$base/sk/programs/{$eventId}/status", json_encode(['status' => 'published']), $sk);
    $check('event_status_published', $eventStatus['status'] === 200 && (prog_data($eventStatus)['status'] ?? '') === 'published', prog_detail($eventStatus));
    $eventArchive = prog_req('POST', "$base/sk/programs/{$eventId}/archive", '{}', $sk);
    $check('event_archive', $eventArchive['status'] === 200 && (prog_data($eventArchive)['status'] ?? '') === 'archived', prog_detail($eventArchive));
    $eventRestore = prog_req('POST', "$base/sk/programs/{$eventId}/status", json_encode(['status' => 'draft']), $sk);
    $check('event_restore_draft', $eventRestore['status'] === 200 && (prog_data($eventRestore)['status'] ?? '') === 'draft', prog_detail($eventRestore));

    $badEvent = prog_req('POST', "$base/sk/events", json_encode(['name' => '']), $sk);
    $check('invalid_event_422', $badEvent['status'] === 422, prog_detail($badEvent));

    $crossGet = prog_req('GET', "$base/sk/programs/{$programId}", null, $sk2);
    $check('other_sk_cannot_read_program', $crossGet['status'] === 404, prog_detail($crossGet));
    $crossEvent = prog_req('GET', "$base/sk/events/{$eventId}", null, $sk2);
    $check('other_sk_cannot_read_event', $crossEvent['status'] === 404, prog_detail($crossEvent));
    $crossUpdate = prog_req('PATCH', "$base/sk/programs/{$programId}", json_encode([
        'name' => 'Hacked',
        'scheduledOn' => $date,
        'kind' => 'program',
        'organization_id' => $orgId,
    ]), $sk2);
    $check('other_sk_cannot_update', $crossUpdate['status'] === 404, prog_detail($crossUpdate));
    $crossDelete = prog_req('DELETE', "$base/sk/events/{$eventId}", null, $sk2);
    $check('other_sk_cannot_delete_event', $crossDelete['status'] === 404, prog_detail($crossDelete));
    $rowStmt->execute(['id' => $programId]);
    $still = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('spoof_did_not_change_program', ($still['name'] ?? '') === $programName && (int) ($still['organization_id'] ?? 0) === $orgId, json_encode($still));

    $deletedProgram = prog_req('DELETE', "$base/sk/programs/{$programId}", null, $sk);
    $check('delete_program', $deletedProgram['status'] === 200, prog_detail($deletedProgram));
    $deletedEvent = prog_req('DELETE', "$base/sk/events/{$eventId}", null, $sk);
    $check('delete_event', $deletedEvent['status'] === 200, prog_detail($deletedEvent));
    $gone = prog_req('GET', "$base/sk/programs/{$programId}", null, $sk);
    $check('deleted_program_404', $gone['status'] === 404, prog_detail($gone));
    $goneEvent = prog_req('GET', "$base/sk/events/{$eventId}", null, $sk);
    $check('deleted_event_404', $goneEvent['status'] === 404, prog_detail($goneEvent));
} finally {
    foreach ($createdIds as $id) {
        $pdo->prepare(
            "DELETE FROM notifications WHERE related_entity_type = 'program' AND related_entity_id = :id"
        )->execute(['id' => $id]);
        $pdo->prepare(
            "DELETE FROM sms_deliveries WHERE event_code = 'program.published' AND event_id = :id"
        )->execute(['id' => $id]);
        $pdo->prepare(
            "DELETE FROM email_deliveries WHERE event_code = 'program.published' AND event_id = :id"
        )->execute(['id' => $id]);
        $stmt = $pdo->prepare(
            "DELETE FROM programs WHERE id = :id AND name LIKE 'ProgEvt %'"
        );
        $stmt->execute(['id' => $id]);
    }
    foreach ([$admin, $sk, $sk2, $youth, $anon] as $cookie) {
        @unlink($cookie);
    }
}

$afterIds = prog_ids($pdo);
$afterCount = count($afterIds);
$afterEvents = (int) $pdo->query("SELECT COUNT(*) FROM programs WHERE kind = 'event'")->fetchColumn();
$left = $pdo->prepare("SELECT id, name FROM programs WHERE name LIKE :name");
$left->execute(['name' => "ProgEvt %{$stamp}%"]);
$leftover = $left->fetchAll(PDO::FETCH_ASSOC);

$check('program_count_restored', $beforeCount === $afterCount && $beforeIds === $afterIds, "before={$beforeCount} after={$afterCount}");
$check('event_count_restored', $beforeEvents === $afterEvents, "before={$beforeEvents} after={$afterEvents}");
$check('no_test_rows_left', $leftover === [], json_encode($leftover));

echo "programs_after={$afterCount} events_after={$afterEvents}\n";
echo "created_ids=" . implode(',', $createdIds) . "\n";
echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
