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
$leftover = $pdo->query(
    "SELECT id FROM notifications WHERE title LIKE 'PhaseSeven%'"
)->fetchAll();
if ($leftover) {
    fwrite(STDERR, "STOP: pre-existing PhaseSeven test records found. No cleanup was performed.\n");
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

function member(PDO $pdo, string $email): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id AS user_id, ou.organization_id
         FROM users u
         INNER JOIN organization_users ou ON ou.user_id = u.id
         WHERE u.email = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : ['user_id' => 0, 'organization_id' => 0];
}

function notificationRow(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

$sk1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p7_sk1.txt';
$sk2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p7_sk2.txt';
$empty = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p7_empty.txt';
foreach ([$sk1, $sk2, $empty] as $f) {
    @unlink($f);
}

$login = static fn (string $email): string => json_encode(['email' => $email, 'password' => 'YouthSync1!']);
$createdIds = [];
$tests = [];
$expect = [];
$logic = [];

$tests[] = req('unauth_notifications', 'GET', "$base/sk/notifications", null, $empty);
$expect['unauth_notifications'] = [401];

$tests[] = req('login_sk1', 'POST', "$base/auth/login", $login('sk1@demo.test'), $sk1);
$expect['login_sk1'] = [200];
$tests[] = req('list_notifications', 'GET', "$base/sk/notifications", null, $sk1);
$expect['list_notifications'] = [200];
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

$sk1m = member($pdo, 'sk1@demo.test');
$sk2m = member($pdo, 'sk2@demo.test');
$org1 = (int) $sk1m['organization_id'];
$user1 = (int) $sk1m['user_id'];
$org2 = (int) $sk2m['organization_id'];
$user2 = (int) $sk2m['user_id'];
$logic['members_loaded'] = $org1 > 0 && $user1 > 0 && $org2 > 0 && $user2 > 0 && $org1 !== $org2;

$notifications = new NotificationService($pdo);
$youthIdOrg1 = (int) $pdo->query(
    'SELECT id FROM youth WHERE organization_id = ' . (int) $org1 . ' ORDER BY id ASC LIMIT 1'
)->fetchColumn();

$createdA = $notifications->create($org1, [
    'organization_id' => 999,
    'user_id' => 999,
    'created_by' => 999,
    'type' => 'applications',
    'title' => 'PhaseSeven Test A',
    'message' => 'PhaseSeven body A',
    'relatedEntityType' => 'application',
    'relatedEntityId' => 1,
], null, null);
$idA = (int) ($createdA['id'] ?? 0);
if ($idA) {
    $createdIds[] = $idA;
}

$createdB = $notifications->create($org1, [
    'organization_id' => $org2,
    'user_id' => $user2,
    'type' => 'system',
    'title' => 'PhaseSeven Test B',
    'message' => 'PhaseSeven body B',
]);
$idB = (int) ($createdB['id'] ?? 0);
if ($idB) {
    $createdIds[] = $idB;
}

$createdPrivate = $notifications->notifySkUser($org1, $user1, [
    'type' => 'system',
    'title' => 'PhaseSeven Test Private SK1',
    'message' => 'PhaseSeven private',
]);
$idPrivate = (int) ($createdPrivate['id'] ?? 0);
if ($idPrivate) {
    $createdIds[] = $idPrivate;
}

$idYouth = 0;
if ($youthIdOrg1 > 0) {
    $createdYouth = $notifications->notifyYouth($org1, $youthIdOrg1, [
        'type' => 'applications',
        'title' => 'PhaseSeven Test Youth Hidden',
        'message' => 'PhaseSeven youth inbox only',
    ]);
    $idYouth = (int) ($createdYouth['id'] ?? 0);
    if ($idYouth) {
        $createdIds[] = $idYouth;
    }
}

$rowA = $idA ? notificationRow($pdo, $idA) : null;
$logic['created_a'] = $idA > 0 && $rowA !== null;
$logic['org_server_derived'] = $rowA !== null && (int) $rowA['organization_id'] === $org1;
$logic['user_server_derived'] = $rowA !== null && $rowA['user_id'] === null;
$logic['initially_unread'] = $rowA !== null && (int) $rowA['is_read'] === 0 && $rowA['read_at'] === null;

$listAfterCreate = req('list_after_create', 'GET', "$base/sk/notifications", null, $sk1);
$tests[] = $listAfterCreate;
$expect['list_after_create'] = [200];
$listData = payload($listAfterCreate)['data'] ?? [];
$items = is_array($listData['items'] ?? null) ? $listData['items'] : [];
$idsListed = array_map(static fn ($n) => (int) ($n['id'] ?? 0), $items);
$logic['list_contains_a'] = in_array($idA, $idsListed, true);
$logic['list_contains_b'] = in_array($idB, $idsListed, true);
$logic['list_hides_youth'] = $idYouth === 0 || !in_array($idYouth, $idsListed, true);
$logic['unread_count_ge_3'] = (int) ($listData['unreadCount'] ?? 0) >= 3;
$logic['no_sensitive'] = !str_contains($listAfterCreate['body'], 'password_hash')
    && !str_contains($listAfterCreate['body'], 'YouthSync1!')
    && !str_contains($listAfterCreate['body'], 'C:\\xampp');

$page1 = req('list_page1', 'GET', "$base/sk/notifications?perPage=1&page=1", null, $sk1);
$tests[] = $page1;
$expect['list_page1'] = [200];
$pageData = payload($page1)['data'] ?? [];
$logic['pagination'] = (int) ($pageData['perPage'] ?? 0) === 1
    && (int) ($pageData['page'] ?? 0) === 1
    && (int) ($pageData['total'] ?? 0) >= 3
    && count($pageData['items'] ?? []) === 1;

$getA = req('get_own', 'GET', "$base/sk/notifications/{$idA}", null, $sk1);
$tests[] = $getA;
$expect['get_own'] = [200];
$got = payload($getA)['data'] ?? [];
$logic['get_fields'] = (int) ($got['id'] ?? 0) === $idA
    && ($got['title'] ?? '') === 'PhaseSeven Test A'
    && array_key_exists('message', $got)
    && array_key_exists('isRead', $got)
    && array_key_exists('type', $got)
    && ($got['isRead'] ?? true) === false;

$unreadBeforeRead = (int) ($listData['unreadCount'] ?? 0);
$readA = req('mark_read', 'PATCH', "$base/sk/notifications/{$idA}/read", json_encode([
    'organization_id' => $org2,
    'user_id' => $user2,
    'created_by' => $user2,
]), $sk1);
$tests[] = $readA;
$expect['mark_read'] = [200];
$readPayload = payload($readA)['data'] ?? [];
$rowAAfter = $idA ? notificationRow($pdo, $idA) : null;
$logic['marked_read'] = ($readPayload['isRead'] ?? false) === true
    && !empty($readPayload['readAt'])
    && $rowAAfter !== null
    && (int) $rowAAfter['is_read'] === 1
    && $rowAAfter['read_at'] !== null
    && (int) $rowAAfter['organization_id'] === $org1
    && $rowAAfter['user_id'] === null;

$listAfterRead = req('list_after_read', 'GET', "$base/sk/notifications", null, $sk1);
$tests[] = $listAfterRead;
$expect['list_after_read'] = [200];
$unreadAfterRead = (int) (payload($listAfterRead)['data']['unreadCount'] ?? -1);
$logic['unread_decremented'] = $unreadAfterRead === $unreadBeforeRead - 1;

$createdC = $notifications->create($org2, [
    'organization_id' => $org1,
    'user_id' => $user1,
    'title' => 'PhaseSeven Test SK2 A',
    'message' => 'PhaseSeven org2',
    'type' => 'system',
]);
$idC = (int) ($createdC['id'] ?? 0);
if ($idC) {
    $createdIds[] = $idC;
}
$createdD = $notifications->notifySkUser($org2, $user2, [
    'title' => 'PhaseSeven Test SK2 Private',
    'message' => 'PhaseSeven org2 private',
    'type' => 'system',
]);
$idD = (int) ($createdD['id'] ?? 0);
if ($idD) {
    $createdIds[] = $idD;
}

$readAll = req('read_all', 'POST', "$base/sk/notifications/read-all", json_encode([
    'organization_id' => $org2,
    'user_id' => $user2,
]), $sk1);
$tests[] = $readAll;
$expect['read_all'] = [200];

$rowBAfter = $idB ? notificationRow($pdo, $idB) : null;
$rowPrivateAfter = $idPrivate ? notificationRow($pdo, $idPrivate) : null;
$rowCAfter = $idC ? notificationRow($pdo, $idC) : null;
$rowDAfter = $idD ? notificationRow($pdo, $idD) : null;
$logic['read_all_own'] = $rowBAfter !== null && (int) $rowBAfter['is_read'] === 1
    && $rowPrivateAfter !== null && (int) $rowPrivateAfter['is_read'] === 1;
$logic['read_all_spares_other_org'] = $rowCAfter !== null && (int) $rowCAfter['is_read'] === 0
    && $rowDAfter !== null && (int) $rowDAfter['is_read'] === 0;

$listAllRead = req('list_after_read_all', 'GET', "$base/sk/notifications", null, $sk1);
$tests[] = $listAllRead;
$expect['list_after_read_all'] = [200];
$logic['unread_zero_sk1'] = (int) (payload($listAllRead)['data']['unreadCount'] ?? -1) === 0;

$tests[] = req('login_sk2', 'POST', "$base/auth/login", $login('sk2@demo.test'), $sk2);
$expect['login_sk2'] = [200];

$tests[] = req('sk2_get_sk1', 'GET', "$base/sk/notifications/{$idA}", null, $sk2);
$expect['sk2_get_sk1'] = [404];
$tests[] = req('sk2_read_sk1', 'PATCH', "$base/sk/notifications/{$idA}/read", json_encode([
    'organization_id' => $org1,
    'user_id' => $user1,
]), $sk2);
$expect['sk2_read_sk1'] = [404];
$tests[] = req('sk2_delete_sk1', 'DELETE', "$base/sk/notifications/{$idA}", null, $sk2);
$expect['sk2_delete_sk1'] = [404];

$sk2List = req('sk2_list', 'GET', "$base/sk/notifications", null, $sk2);
$tests[] = $sk2List;
$expect['sk2_list'] = [200];
$sk2Ids = array_map(
    static fn ($n) => (int) ($n['id'] ?? 0),
    payload($sk2List)['data']['items'] ?? []
);
$logic['sk2_cannot_list_sk1'] = !in_array($idA, $sk2Ids, true) && !in_array($idB, $sk2Ids, true);
$logic['sk2_sees_own'] = in_array($idC, $sk2Ids, true);
$logic['sk2_unread_untouched'] = (int) (payload($sk2List)['data']['unreadCount'] ?? 0) >= 2;

$deleteOwn = req('delete_own', 'DELETE', "$base/sk/notifications/{$idA}", null, $sk1);
$tests[] = $deleteOwn;
$expect['delete_own'] = [200];
$logic['delete_payload'] = (payload($deleteOwn)['data']['deleted'] ?? false) === true;
$createdIds = array_values(array_filter($createdIds, static fn ($id) => $id !== $idA));

$getDeleted = req('get_deleted', 'GET', "$base/sk/notifications/{$idA}", null, $sk1);
$tests[] = $getDeleted;
$expect['get_deleted'] = [404];
$logic['db_deleted'] = notificationRow($pdo, $idA) === null;

$failed = [];
foreach ($tests as $t) {
    $name = $t['name'];
    $okStatus = in_array($t['status'], $expect[$name] ?? [], true);
    $decoded = payload($t);
    $okEnvelope = $t['status'] >= 200 && $t['status'] < 300
        ? (($decoded['success'] ?? false) === true)
        : (($decoded['success'] ?? true) === false && isset($decoded['error']['code']));
    $line = sprintf(
        "%s status=%d expected=%s envelope=%s",
        $name,
        $t['status'],
        implode('|', $expect[$name] ?? []),
        $okEnvelope ? 'ok' : 'bad'
    );
    echo $line . PHP_EOL;
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

if ($createdIds) {
    $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
    $del = $pdo->prepare("DELETE FROM notifications WHERE id IN ({$placeholders})");
    $del->execute(array_values($createdIds));
}
$strays = $pdo->prepare("SELECT id FROM notifications WHERE title LIKE 'PhaseSeven%'");
$strays->execute();
$strayIds = $strays->fetchAll(PDO::FETCH_COLUMN);
if ($strayIds) {
    $placeholders = implode(',', array_fill(0, count($strayIds), '?'));
    $del = $pdo->prepare("DELETE FROM notifications WHERE id IN ({$placeholders})");
    $del->execute(array_values($strayIds));
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
