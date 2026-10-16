<?php
declare(strict_types=1);

/**
 * Publishing a program or event reminds active youth in that organization only.
 * Creates activities, then deletes those activities and the reminder rows they created.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = rtrim((string) (getenv('YOUTHSYNC_API_BASE') ?: 'http://localhost/YouthSync/api'), '/');
$stamp = date('YmdHis');
$date = '2026-12-20';

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

function er_req(string $method, string $url, ?string $json, string $cookie): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 60,
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
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $size);
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
}

function er_data(array $row): array
{
    $data = $row['json']['data'] ?? null;
    return is_array($data) ? $data : [];
}

$jar = static function (): string {
    return tempnam(sys_get_temp_dir(), 'ys_er_');
};
$sk = $jar();
$youth = $jar();

$noticeCount = static function (int $programId) use ($pdo): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications
         WHERE related_entity_type = 'program' AND related_entity_id = :id"
    );
    $stmt->execute(['id' => $programId]);
    return (int) $stmt->fetchColumn();
};

try {
    $login = er_req('POST', "$base/auth/login", json_encode([
        'email' => 'sk1@demo.test',
        'password' => 'YouthSync1!',
    ]), $sk);
    $check('sk_login', $login['status'] === 200, (string) $login['status']);
    $me = er_req('GET', "$base/auth/me", null, $sk);
    $orgId = (int) (er_data($me)['organization']['id'] ?? 0);
    $check('sk_org', $orgId > 0, (string) $orgId);

    $youthCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM youth WHERE organization_id = ' . $orgId . ' AND archived = 0'
    )->fetchColumn();
    $check('org_has_youth', $youthCount > 0, (string) $youthCount);

    $name = "Remind Program {$stamp}";
    $created = er_req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => $name,
        'category' => 'Leadership',
        'scheduledOn' => $date,
        'location' => 'Hall',
        'status' => 'draft',
        'organization_id' => 999,
        'youth_id' => 1,
    ]), $sk);
    $programId = (int) (er_data($created)['id'] ?? 0);
    if ($programId > 0) {
        $createdIds[] = $programId;
    }
    $check('draft_created', $created['status'] === 201 && $programId > 0, (string) $created['status']);
    $check('draft_sends_nothing', $noticeCount($programId) === 0, (string) $noticeCount($programId));

    $published = er_req('POST', "$base/sk/programs/{$programId}/status", json_encode([
        'status' => 'published',
        'organization_id' => 999,
        'youth_id' => 1,
    ]), $sk);
    $check('publish_ok', $published['status'] === 200 && (er_data($published)['status'] ?? '') === 'published');
    $check('reminded_each_active_youth', $noticeCount($programId) === $youthCount, $noticeCount($programId) . ' vs ' . $youthCount);

    $other = (int) $pdo->query(
        "SELECT COUNT(*) FROM notifications n
         INNER JOIN youth y ON y.id = n.youth_id
         WHERE n.related_entity_id = {$programId}
           AND n.related_entity_type = 'program'
           AND y.organization_id <> {$orgId}"
    )->fetchColumn();
    $check('other_org_not_reminded', $other === 0, (string) $other);

    $sample = $pdo->prepare(
        "SELECT title, message, link, type, organization_id
         FROM notifications
         WHERE related_entity_type = 'program' AND related_entity_id = :id
         ORDER BY id LIMIT 1"
    );
    $sample->execute(['id' => $programId]);
    $row = $sample->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(
        'reminder_copy',
        ($row['type'] ?? '') === 'programs'
            && ($row['title'] ?? '') === "New program: {$name}"
            && str_contains((string) ($row['message'] ?? ''), $date)
            && ($row['link'] ?? '') === "/youth/programs/{$programId}"
            && (int) ($row['organization_id'] ?? 0) === $orgId,
        json_encode($row)
    );

    $again = er_req('POST', "$base/sk/programs/{$programId}/status", json_encode(['status' => 'published']), $sk);
    $check('second_publish_does_not_repeat', $again['status'] === 200 && $noticeCount($programId) === $youthCount);

    $youthLogin = er_req('POST', "$base/auth/login", json_encode([
        'email' => 'youth-auth-test@example.test',
        'password' => 'YouthAuthTest1!',
    ]), $youth);
    $inbox = er_req('GET', "$base/youth/notifications?perPage=100", null, $youth);
    $items = er_data($inbox)['items'] ?? [];
    $seen = false;
    if (is_array($items)) {
        foreach ($items as $item) {
            if ((int) ($item['relatedEntityId'] ?? 0) === $programId && ($item['title'] ?? '') === "New program: {$name}") {
                $seen = true;
            }
        }
    }
    $check('youth_inbox_shows_reminder', $youthLogin['status'] === 200 && $inbox['status'] === 200 && $seen, (string) $inbox['status']);

    $eventName = "Remind Event {$stamp}";
    $event = er_req('POST', "$base/sk/events", json_encode([
        'kind' => 'event',
        'name' => $eventName,
        'category' => 'Sports',
        'scheduledOn' => $date,
        'location' => 'Court',
        'status' => 'published',
    ]), $sk);
    $eventId = (int) (er_data($event)['id'] ?? 0);
    if ($eventId > 0) {
        $createdIds[] = $eventId;
    }
    $eventRow = $pdo->prepare(
        "SELECT title, link FROM notifications
         WHERE related_entity_type = 'program' AND related_entity_id = :id
         ORDER BY id LIMIT 1"
    );
    $eventRow->execute(['id' => $eventId]);
    $eventNote = $eventRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(
        'event_publish_reminds',
        $event['status'] === 201
            && $noticeCount($eventId) === $youthCount
            && ($eventNote['title'] ?? '') === "New event: {$eventName}"
            && ($eventNote['link'] ?? '') === "/youth/events/{$eventId}",
        (string) $event['status'] . ' ' . json_encode($eventNote)
    );
} catch (Throwable $e) {
    $check('exception', false, $e->getMessage());
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
        $pdo->prepare(
            "DELETE FROM programs WHERE id = :id AND name LIKE 'Remind %'"
        )->execute(['id' => $id]);
    }
    @unlink($sk);
    @unlink($youth);
}

$left = (int) $pdo->query(
    "SELECT COUNT(*) FROM programs WHERE name LIKE " . $pdo->quote("Remind %{$stamp}%")
)->fetchColumn();
$check('fixtures_removed', $left === 0, (string) $left);
echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
