<?php
declare(strict_types=1);

/**
 * Notification History HTTP test (SK in-app inbox + SMS/email delivery history).
 *
 * Run from api/sql with Apache + MySQL up:
 *   php notification_history_http_test.php
 * Optional: set YOUTHSYNC_API_BASE to override http://localhost/YouthSync/api.
 *
 * Test rows are created through NotificationService (fake SMS/email transports,
 * so nothing is sent to Semaphore or Resend). Only the exact notification and
 * delivery IDs created here are deleted at the end. Activity log rows written by
 * the API for read/delete actions are audit records and are left in place.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$config = require dirname(__DIR__) . '/config/config.php';
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

// SmsTransport / EmailTransport interfaces live inside these files.
require_once dirname(__DIR__) . '/src/Notifications/SmsService.php';
require_once dirname(__DIR__) . '/src/Notifications/EmailService.php';

use YouthSync\Infra\SchemaPatches;
use YouthSync\Notifications\EmailService;
use YouthSync\Notifications\EmailTransport;
use YouthSync\Notifications\NotificationService;
use YouthSync\Notifications\SmsService;
use YouthSync\Notifications\SmsTransport;

final class NotifHistoryFakeSms implements SmsTransport
{
    public int $httpStatus = 200;
    public int $calls = 0;

    public function post(string $url, array $fields): array
    {
        $this->calls++;
        return ['httpStatus' => $this->httpStatus, 'body' => '[{"message_id":"nh-msg-' . $this->calls . '"}]'];
    }
}

final class NotifHistoryFakeEmail implements EmailTransport
{
    public int $httpStatus = 200;
    public int $calls = 0;

    public function post(string $url, array $headers, string $jsonBody): array
    {
        $this->calls++;
        return ['httpStatus' => $this->httpStatus, 'body' => '{"id":"nh-email-' . $this->calls . '"}'];
    }
}

const NH_TITLE = 'NotifHistoryTest';
const NH_EVENT = 'notification_history.test';
const NH_SMS_SECRET = 'nh-sms-secret-value';
const NH_EMAIL_SECRET = 're_nh_secret_value';

$pdo = youthsync_pdo();
SchemaPatches::apply($pdo);

$leftNotes = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE title LIKE :t');
$leftNotes->execute(['t' => NH_TITLE . '%']);
$leftSms = $pdo->prepare('SELECT COUNT(*) FROM sms_deliveries WHERE event_code = :c');
$leftSms->execute(['c' => NH_EVENT]);
$leftEmail = $pdo->prepare('SELECT COUNT(*) FROM email_deliveries WHERE event_code = :c');
$leftEmail->execute(['c' => NH_EVENT]);
if ((int) $leftNotes->fetchColumn() > 0 || (int) $leftSms->fetchColumn() > 0 || (int) $leftEmail->fetchColumn() > 0) {
    fwrite(STDERR, "STOP: pre-existing NotifHistoryTest rows found. No cleanup was performed.\n");
    exit(2);
}

$base = rtrim((string) (getenv('YOUTHSYNC_API_BASE') ?: 'http://localhost/YouthSync/api'), '/');
$skPassword = 'YouthSync1!';
$youthEmail = 'youth-auth-test@example.test';
$youthPassword = 'YouthAuthTest1!';
$adminEmail = 'admin@skmms.test';
$adminPassword = 'YouthSync1!';

$counts = static function () use ($pdo): array {
    return [
        'notifications' => (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(),
        'sms_deliveries' => (int) $pdo->query('SELECT COUNT(*) FROM sms_deliveries')->fetchColumn(),
        'email_deliveries' => (int) $pdo->query('SELECT COUNT(*) FROM email_deliveries')->fetchColumn(),
        'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'organizations' => (int) $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn(),
    ];
};
$before = $counts();

function nh_req(string $name, string $method, string $url, ?string $json, string $cookie): array
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

function nh_data(array $t): array
{
    $decoded = json_decode($t['body'], true);
    return is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
}

/** @return array<int, array<string, mixed>> keyed by id */
function nh_items(array $t): array
{
    $out = [];
    foreach ((nh_data($t)['items'] ?? []) as $item) {
        if (is_array($item) && isset($item['id'])) {
            $out[(int) $item['id']] = $item;
        }
    }
    return $out;
}

function nh_member(PDO $pdo, string $email): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id AS user_id, ou.organization_id
         FROM users u
         INNER JOIN organization_users ou ON ou.user_id = u.id AND ou.status = \'active\'
         WHERE u.email = :email
         ORDER BY ou.is_owner DESC, ou.id ASC
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : ['user_id' => 0, 'organization_id' => 0];
}

function nh_row(PDO $pdo, string $table, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Recursively collect forbidden keys present in a decoded payload. */
function nh_forbidden_keys(mixed $value, array &$found): void
{
    if (!is_array($value)) {
        return;
    }
    $forbidden = ['password', 'password_hash', 'passwordhash', 'token', 'remember_token', 'apikey',
        'api_key', 'secret', 'authorization', 'semaphore_api_key', 'brevo_api_key'];
    foreach ($value as $key => $child) {
        if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
            $found[] = $key;
        }
        nh_forbidden_keys($child, $found);
    }
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
$cookies = [
    'anon' => $tmp . 'ys_nh_anon.txt',
    'sk1' => $tmp . 'ys_nh_sk1.txt',
    'sk1b' => $tmp . 'ys_nh_sk1b.txt',
    'sk2' => $tmp . 'ys_nh_sk2.txt',
    'youth' => $tmp . 'ys_nh_youth.txt',
    'admin' => $tmp . 'ys_nh_admin.txt',
];
foreach ($cookies as $f) {
    @unlink($f);
}
$login = static fn (string $email, string $password): string => json_encode(['email' => $email, 'password' => $password]);

$tests = [];
$expect = [];
$logic = [];
$featureBodies = [];
$created = ['notifications' => [], 'sms_deliveries' => [], 'email_deliveries' => []];

$run = static function (string $name, string $method, string $path, ?string $json, string $cookie, array $codes, bool $feature = true) use (&$tests, &$expect, &$featureBodies, $base): array {
    $t = nh_req($name, $method, $base . $path, $json, $cookie);
    $tests[] = $t;
    $expect[$name] = $codes;
    if ($feature) {
        $featureBodies[$name] = $t['body'];
    }
    return $t;
};

$sk1m = nh_member($pdo, 'sk1@demo.test');
$sk2m = nh_member($pdo, 'sk2@demo.test');
$org1 = (int) $sk1m['organization_id'];
$user1 = (int) $sk1m['user_id'];
$org2 = (int) $sk2m['organization_id'];
$user2 = (int) $sk2m['user_id'];
$logic['members_loaded'] = $org1 > 0 && $user1 > 0 && $org2 > 0 && $user2 > 0 && $org1 !== $org2;
if (!$logic['members_loaded']) {
    fwrite(STDERR, "STOP: sk1@demo.test / sk2@demo.test memberships not found.\n");
    exit(2);
}

// ---------------------------------------------------------------- B: anonymous
$run('anon_notifications', 'GET', '/sk/notifications', null, $cookies['anon'], [401]);
$run('anon_sms_history', 'GET', '/sk/deliveries/sms', null, $cookies['anon'], [401]);
$run('anon_email_history', 'GET', '/sk/deliveries/email', null, $cookies['anon'], [401]);

// ---------------------------------------------------------------- test data (real service)
$service = new NotificationService($pdo);
$noteA = $service->notifySk($org1, [
    'organization_id' => $org2,
    'user_id' => $user2,
    'type' => 'applications',
    'title' => NH_TITLE . ' Org-wide',
    'message' => 'NotifHistoryTest message for the whole SK inbox.',
    'link' => '/sk/notifications',
    'related' => 'NotifHistoryTest related',
    'relatedEntityType' => 'application',
    'relatedEntityId' => 1,
]);
$idA = (int) ($noteA['id'] ?? 0);
$idA > 0 && $created['notifications'][] = $idA;

$noteP = $service->notifySkUser($org1, $user1, [
    'type' => 'system',
    'title' => NH_TITLE . ' Private SK1',
    'message' => 'NotifHistoryTest addressed to sk1 only.',
]);
$idP = (int) ($noteP['id'] ?? 0);
$idP > 0 && $created['notifications'][] = $idP;

$noteO2 = $service->notifySk($org2, [
    'type' => 'system',
    'title' => NH_TITLE . ' Org2',
    'message' => 'NotifHistoryTest for organization 2.',
]);
$idO2 = (int) ($noteO2['id'] ?? 0);
$idO2 > 0 && $created['notifications'][] = $idO2;

$rowA = $idA ? nh_row($pdo, 'notifications', $idA) : null;
$logic['a_created_unread_org_from_server'] = $rowA !== null
    && (int) $rowA['organization_id'] === $org1
    && $rowA['user_id'] === null
    && $rowA['youth_id'] === null
    && (int) $rowA['is_read'] === 0;

// Delivery rows through the real NotificationService write path, with fake transports.
$fakeSms = new NotifHistoryFakeSms();
$fakeEmail = new NotifHistoryFakeEmail();
$deliveries = new NotificationService(
    $pdo,
    new SmsService(NH_SMS_SECRET, 'YOUTHSYNC', 'https://api.semaphore.co', $fakeSms),
    new EmailService(NH_EMAIL_SECRET, 'noreply@example.test', 'YouthSync', 'https://api.brevo.com', $fakeEmail)
);
$smsSent = $deliveries->deliverSmsOnce($org1, NH_EVENT, $idA, '09170000001', 'NotifHistoryTest SMS', $idA);
$fakeSms->httpStatus = 500;
$smsFailed = $deliveries->deliverSmsOnce($org1, NH_EVENT, $idA, '09170000002', 'NotifHistoryTest SMS', $idA);
$fakeSms->httpStatus = 200;
$smsOrg2 = $deliveries->deliverSmsOnce($org2, NH_EVENT, $idO2, '09170000003', 'NotifHistoryTest SMS', $idO2);
$emailSent = $deliveries->deliverEmailOnce($org1, NH_EVENT, $idA, 'nh-sent@example.test', 'NotifHistoryTest', 'Body', 'Related', $idA);
$fakeEmail->httpStatus = 500;
$emailFailed = $deliveries->deliverEmailOnce($org1, NH_EVENT, $idA, 'nh-failed@example.test', 'NotifHistoryTest', 'Body', 'Related', $idA);
$fakeEmail->httpStatus = 200;
$emailOrg2 = $deliveries->deliverEmailOnce($org2, NH_EVENT, $idO2, 'nh-org2@example.test', 'NotifHistoryTest', 'Body', 'Related', $idO2);

foreach ([$smsSent, $smsFailed, $smsOrg2] as $r) {
    if (!empty($r['id'])) {
        $created['sms_deliveries'][] = (int) $r['id'];
    }
}
foreach ([$emailSent, $emailFailed, $emailOrg2] as $r) {
    if (!empty($r['id'])) {
        $created['email_deliveries'][] = (int) $r['id'];
    }
}
// A pending row (attempt recorded, provider never answered) to prove statuses are shown as stored.
$pdo->prepare(
    'INSERT INTO sms_deliveries (organization_id, notification_id, event_code, event_id, recipient, provider, status)
     VALUES (:org, :nid, :code, :eid, :recipient, \'semaphore\', \'pending\')'
)->execute(['org' => $org1, 'nid' => $idA, 'code' => NH_EVENT, 'eid' => $idA, 'recipient' => '09170000004']);
$smsPendingId = (int) $pdo->lastInsertId();
$smsPendingId > 0 && $created['sms_deliveries'][] = $smsPendingId;

$smsSentId = (int) ($smsSent['id'] ?? 0);
$smsFailedId = (int) ($smsFailed['id'] ?? 0);
$smsOrg2Id = (int) ($smsOrg2['id'] ?? 0);
$emailSentId = (int) ($emailSent['id'] ?? 0);
$emailFailedId = (int) ($emailFailed['id'] ?? 0);
$emailOrg2Id = (int) ($emailOrg2['id'] ?? 0);
$logic['deliveries_recorded'] = $smsSentId > 0 && $smsFailedId > 0 && $smsOrg2Id > 0 && $smsPendingId > 0
    && $emailSentId > 0 && $emailFailedId > 0 && $emailOrg2Id > 0
    && ($smsSent['status'] ?? '') === 'sent' && ($smsFailed['status'] ?? '') === 'failed'
    && ($emailSent['status'] ?? '') === 'sent' && ($emailFailed['status'] ?? '') === 'failed';

// ---------------------------------------------------------------- logins
$run('login_sk1', 'POST', '/auth/login', $login('sk1@demo.test', $skPassword), $cookies['sk1'], [200], false);
$run('login_sk2', 'POST', '/auth/login', $login('sk2@demo.test', $skPassword), $cookies['sk2'], [200], false);
$run('login_youth', 'POST', '/auth/login', $login($youthEmail, $youthPassword), $cookies['youth'], [200], false);
$run('login_admin', 'POST', '/auth/login', $login($adminEmail, $adminPassword), $cookies['admin'], [200], false);

// ---------------------------------------------------------------- B: role policy
$run('youth_sk_notifications_403', 'GET', '/sk/notifications', null, $cookies['youth'], [403]);
$run('youth_sms_history_403', 'GET', '/sk/deliveries/sms', null, $cookies['youth'], [403]);
$run('youth_email_history_403', 'GET', '/sk/deliveries/email', null, $cookies['youth'], [403]);
$run('youth_read_sk_note_403', 'POST', "/sk/notifications/{$idA}/read", '{}', $cookies['youth'], [403]);
$youthInbox = $run('youth_own_inbox', 'GET', '/youth/notifications?perPage=100', null, $cookies['youth'], [200]);
$logic['youth_inbox_hides_sk_notes'] = !isset(nh_items($youthInbox)[$idA]) && !isset(nh_items($youthInbox)[$idP]);
$run('admin_sk_notifications_403', 'GET', '/sk/notifications', null, $cookies['admin'], [403]);
$run('admin_sms_history_403', 'GET', '/sk/deliveries/sms', null, $cookies['admin'], [403]);
$run('admin_email_history_403', 'GET', '/sk/deliveries/email', null, $cookies['admin'], [403]);

// ---------------------------------------------------------------- A: in-app list
$q = '&q=' . rawurlencode(NH_TITLE);
$list1 = $run('sk1_list', 'GET', '/sk/notifications?perPage=100' . $q, null, $cookies['sk1'], [200]);
$items1 = nh_items($list1);
$a = $items1[$idA] ?? [];
$logic['a_listed_for_sk1'] = $a !== [];
$logic['private_listed_for_sk1'] = isset($items1[$idP]);
$logic['org2_hidden_from_sk1'] = !isset($items1[$idO2]);
$logic['a_fields'] = ($a['title'] ?? '') === NH_TITLE . ' Org-wide'
    && ($a['message'] ?? '') !== ''
    && ($a['type'] ?? '') === 'applications'
    && ($a['category'] ?? '') === 'applications'
    && ($a['isRead'] ?? null) === false
    && array_key_exists('readAt', $a) && $a['readAt'] === null
    && !empty($a['createdAt'])
    && ($a['related'] ?? '') === 'NotifHistoryTest related'
    && ($a['relatedEntityType'] ?? '') === 'application'
    && ($a['relatedEntityId'] ?? 0) === 1
    && ($a['link'] ?? '') === '/sk/notifications';
$listData1 = nh_data($list1);
$logic['list_shape'] = isset($listData1['page'], $listData1['perPage'], $listData1['total'], $listData1['pages'], $listData1['unreadCount']);
$unreadBefore = (int) ($listData1['unreadCount'] ?? -1);

$pageOne = $run('sk1_list_page_size_1', 'GET', '/sk/notifications?perPage=1&page=1' . $q, null, $cookies['sk1'], [200]);
$pageOneData = nh_data($pageOne);
$logic['in_app_pagination'] = count($pageOneData['items'] ?? []) === 1
    && (int) ($pageOneData['total'] ?? 0) === 2
    && (int) ($pageOneData['pages'] ?? 0) === 2;

$unreadList = $run('sk1_unread_only', 'GET', '/sk/notifications?unreadOnly=1&perPage=100' . $q, null, $cookies['sk1'], [200]);
$logic['unread_filter_has_a'] = isset(nh_items($unreadList)[$idA]);

// Mark read the way the UI does (POST), with spoofed tenant fields in the body.
$read = $run('sk1_mark_read', 'POST', "/sk/notifications/{$idA}/read", json_encode([
    'organization_id' => $org2, 'user_id' => $user2, 'is_read' => 0,
]), $cookies['sk1'], [200]);
$readData = nh_data($read);
$rowAfterRead = nh_row($pdo, 'notifications', $idA);
$logic['mark_read_response'] = ($readData['isRead'] ?? false) === true && !empty($readData['readAt']);
$logic['mark_read_db'] = $rowAfterRead !== null
    && (int) $rowAfterRead['is_read'] === 1
    && $rowAfterRead['read_at'] !== null
    && (int) $rowAfterRead['organization_id'] === $org1
    && $rowAfterRead['user_id'] === null;

$list2 = $run('sk1_list_after_read', 'GET', '/sk/notifications?perPage=100' . $q, null, $cookies['sk1'], [200]);
$logic['list_shows_read'] = (nh_items($list2)[$idA]['isRead'] ?? null) === true;
$logic['unread_count_dropped'] = (int) (nh_data($list2)['unreadCount'] ?? -1) === $unreadBefore - 1;
$readOnly = $run('sk1_read_only', 'GET', '/sk/notifications?readOnly=1&perPage=100' . $q, null, $cookies['sk1'], [200]);
$logic['read_filter'] = isset(nh_items($readOnly)[$idA]) && !isset(nh_items($readOnly)[$idP]);
$unreadOnly2 = $run('sk1_unread_after_read', 'GET', '/sk/notifications?unreadOnly=1&perPage=100' . $q, null, $cookies['sk1'], [200]);
$logic['unread_filter_excludes_read'] = !isset(nh_items($unreadOnly2)[$idA]) && isset(nh_items($unreadOnly2)[$idP]);

// ---------------------------------------------------------------- E: persistence (fresh session)
$run('login_sk1_fresh', 'POST', '/auth/login', $login('sk1@demo.test', $skPassword), $cookies['sk1b'], [200], false);
$fresh = $run('sk1_fresh_get_a', 'GET', "/sk/notifications/{$idA}", null, $cookies['sk1b'], [200]);
$logic['read_persists_new_session'] = (nh_data($fresh)['isRead'] ?? null) === true;

// ---------------------------------------------------------------- B: other organization
$run('sk2_get_sk1_note_404', 'GET', "/sk/notifications/{$idA}", null, $cookies['sk2'], [404]);
$run('sk2_read_sk1_note_404', 'POST', "/sk/notifications/{$idP}/read", '{}', $cookies['sk2'], [404]);
$run('sk2_delete_sk1_note_404', 'DELETE', "/sk/notifications/{$idA}", null, $cookies['sk2'], [404]);
$sk2List = $run('sk2_list', 'GET', '/sk/notifications?perPage=100' . $q, null, $cookies['sk2'], [200]);
$sk2Items = nh_items($sk2List);
$logic['sk2_sees_only_own_org'] = isset($sk2Items[$idO2]) && !isset($sk2Items[$idA]) && !isset($sk2Items[$idP]);
$rowPAfter = nh_row($pdo, 'notifications', $idP);
$logic['sk2_could_not_modify'] = nh_row($pdo, 'notifications', $idA) !== null
    && $rowPAfter !== null && (int) $rowPAfter['is_read'] === 0;

// ---------------------------------------------------------------- C: delivery history
$sms1 = $run('sk1_sms_history', 'GET', '/sk/deliveries/sms?perPage=100&q=' . rawurlencode(NH_EVENT), null, $cookies['sk1'], [200]);
$smsItems = nh_items($sms1);
$smsData = nh_data($sms1);
$logic['sms_has_provider_flag'] = is_bool($smsData['providerConfigured'] ?? null);
$logic['sms_sent_row'] = ($smsItems[$smsSentId]['status'] ?? '') === 'sent'
    && ($smsItems[$smsSentId]['recipient'] ?? '') === '09170000001'
    && ($smsItems[$smsSentId]['provider'] ?? '') === 'semaphore'
    && !empty($smsItems[$smsSentId]['providerReference'])
    && !empty($smsItems[$smsSentId]['sentAt'])
    && ($smsItems[$smsSentId]['notificationId'] ?? 0) === $idA
    && ($smsItems[$smsSentId]['eventCode'] ?? '') === NH_EVENT;
$logic['sms_failed_row'] = ($smsItems[$smsFailedId]['status'] ?? '') === 'failed'
    && ($smsItems[$smsFailedId]['errorMessage'] ?? '') === 'SMS provider rejected the message.'
    && array_key_exists('sentAt', $smsItems[$smsFailedId] ?? [])
    && $smsItems[$smsFailedId]['sentAt'] === null;
$logic['sms_pending_row'] = ($smsItems[$smsPendingId]['status'] ?? '') === 'pending'
    && array_key_exists('sentAt', $smsItems[$smsPendingId] ?? [])
    && $smsItems[$smsPendingId]['sentAt'] === null;
$logic['sms_hides_other_org'] = !isset($smsItems[$smsOrg2Id]);
$smsSpoof = $run('sk1_sms_history_spoof_org', 'GET', "/sk/deliveries/sms?perPage=100&organization_id={$org2}&orgId={$org2}&q=" . rawurlencode(NH_EVENT), null, $cookies['sk1'], [200]);
$logic['sms_ignores_client_org'] = !isset(nh_items($smsSpoof)[$smsOrg2Id]) && isset(nh_items($smsSpoof)[$smsSentId]);
$smsPage = $run('sk1_sms_history_page', 'GET', '/sk/deliveries/sms?perPage=1&page=2&q=' . rawurlencode(NH_EVENT), null, $cookies['sk1'], [200]);
$smsPageData = nh_data($smsPage);
$logic['sms_pagination'] = count($smsPageData['items'] ?? []) === 1
    && (int) ($smsPageData['page'] ?? 0) === 2
    && (int) ($smsPageData['total'] ?? 0) === 3
    && (int) ($smsPageData['pages'] ?? 0) === 3;

$email1 = $run('sk1_email_history', 'GET', '/sk/deliveries/email?perPage=100&q=' . rawurlencode(NH_EVENT), null, $cookies['sk1'], [200]);
$emailItems = nh_items($email1);
$logic['email_has_provider_flag'] = is_bool(nh_data($email1)['providerConfigured'] ?? null);
$logic['email_sent_row'] = ($emailItems[$emailSentId]['status'] ?? '') === 'sent'
    && ($emailItems[$emailSentId]['email'] ?? '') === 'nh-sent@example.test'
    && ($emailItems[$emailSentId]['provider'] ?? '') === 'brevo'
    && !empty($emailItems[$emailSentId]['providerReference'])
    && !empty($emailItems[$emailSentId]['sentAt'])
    && ($emailItems[$emailSentId]['notificationId'] ?? 0) === $idA;
$logic['email_failed_row'] = ($emailItems[$emailFailedId]['status'] ?? '') === 'failed'
    && ($emailItems[$emailFailedId]['errorMessage'] ?? '') === 'Email provider rejected the message.'
    && array_key_exists('sentAt', $emailItems[$emailFailedId] ?? [])
    && $emailItems[$emailFailedId]['sentAt'] === null;
$logic['email_hides_other_org'] = !isset($emailItems[$emailOrg2Id]);

$sms2 = $run('sk2_sms_history', 'GET', '/sk/deliveries/sms?perPage=100&q=' . rawurlencode(NH_EVENT), null, $cookies['sk2'], [200]);
$logic['sk2_sms_only_own_org'] = isset(nh_items($sms2)[$smsOrg2Id])
    && !isset(nh_items($sms2)[$smsSentId]) && !isset(nh_items($sms2)[$smsFailedId]);
$email2 = $run('sk2_email_history', 'GET', '/sk/deliveries/email?perPage=100&q=' . rawurlencode(NH_EVENT), null, $cookies['sk2'], [200]);
$logic['sk2_email_only_own_org'] = isset(nh_items($email2)[$emailOrg2Id])
    && !isset(nh_items($email2)[$emailSentId]);

// Stored statuses are untouched by reading the history.
$logic['statuses_unchanged_in_db'] = (nh_row($pdo, 'sms_deliveries', $smsFailedId)['status'] ?? '') === 'failed'
    && (nh_row($pdo, 'sms_deliveries', $smsPendingId)['status'] ?? '') === 'pending'
    && (nh_row($pdo, 'email_deliveries', $emailFailedId)['status'] ?? '') === 'failed';

// ---------------------------------------------------------------- A: delete
$del = $run('sk1_delete_a', 'DELETE', "/sk/notifications/{$idA}", null, $cookies['sk1'], [200]);
$logic['delete_response'] = (nh_data($del)['deleted'] ?? false) === true;
$logic['delete_db'] = nh_row($pdo, 'notifications', $idA) === null;
if ($logic['delete_db']) {
    $created['notifications'] = array_values(array_filter($created['notifications'], static fn ($id) => $id !== $idA));
}
$run('sk1_get_deleted_404', 'GET', "/sk/notifications/{$idA}", null, $cookies['sk1'], [404]);
$afterDelete = $run('sk1_list_after_delete', 'GET', '/sk/notifications?perPage=100' . $q, null, $cookies['sk1'], [200]);
$logic['deleted_not_listed'] = !isset(nh_items($afterDelete)[$idA]) && isset(nh_items($afterDelete)[$idP]);
$afterDeleteFresh = $run('sk1_fresh_list_after_delete', 'GET', '/sk/notifications?perPage=100' . $q, null, $cookies['sk1b'], [200]);
$logic['delete_persists_new_session'] = !isset(nh_items($afterDeleteFresh)[$idA]);

// ---------------------------------------------------------------- D: no secrets in feature responses
$secretValues = ['YouthSync1!', $youthPassword, NH_SMS_SECRET, NH_EMAIL_SECRET, 'Bearer '];
foreach (['semaphore_api_key', 'brevo_api_key'] as $key) {
    $value = (string) ($config[$key] ?? '');
    if ($value !== '') {
        $secretValues[] = $value;
    }
}
$leaks = [];
foreach ($featureBodies as $name => $body) {
    foreach ($secretValues as $secret) {
        if (str_contains($body, $secret)) {
            $leaks[] = $name . ':value';
        }
    }
    if (str_contains($body, 'password_hash') || str_contains($body, 'C:\\xampp')) {
        $leaks[] = $name . ':text';
    }
    $keys = [];
    nh_forbidden_keys(json_decode($body, true), $keys);
    if ($keys) {
        $leaks[] = $name . ':keys(' . implode('|', array_unique($keys)) . ')';
    }
}
$logic['no_secrets_in_responses'] = $leaks === [];
if ($leaks) {
    echo 'leaks=' . implode(',', $leaks) . PHP_EOL;
}

// ---------------------------------------------------------------- report
$failed = [];
foreach ($tests as $t) {
    $name = $t['name'];
    $okStatus = in_array($t['status'], $expect[$name] ?? [], true);
    $decoded = json_decode($t['body'], true);
    $decoded = is_array($decoded) ? $decoded : [];
    $okEnvelope = $t['status'] >= 200 && $t['status'] < 300
        ? (($decoded['success'] ?? false) === true)
        : (($decoded['success'] ?? true) === false && isset($decoded['error']['code']));
    echo sprintf(
        '%s status=%d expected=%s envelope=%s',
        $name,
        $t['status'],
        implode('|', $expect[$name] ?? []),
        $okEnvelope ? 'ok' : 'bad'
    ) . PHP_EOL;
    if (!$okStatus || !$okEnvelope) {
        echo '  body=' . substr($t['body'], 0, 300) . PHP_EOL;
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

// ---------------------------------------------------------------- cleanup: exact IDs only
echo PHP_EOL . 'created_ids=' . json_encode($created) . PHP_EOL;
foreach ($created as $table => $ids) {
    if ($ids === []) {
        continue;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})")->execute(array_values($ids));
}

$auditIds = [];
$allNoteIds = array_values(array_filter([$idA, $idP, $idO2]));
if ($allNoteIds) {
    $placeholders = implode(',', array_fill(0, count($allNoteIds), '?'));
    $audit = $pdo->prepare(
        "SELECT id FROM activity_logs WHERE entity_type = 'notification' AND entity_id IN ({$placeholders})"
    );
    $audit->execute($allNoteIds);
    $auditIds = array_map('intval', $audit->fetchAll(PDO::FETCH_COLUMN));
}
echo 'activity_log_ids_left_as_audit=' . json_encode($auditIds) . PHP_EOL;

$strayNotes = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE title LIKE :t');
$strayNotes->execute(['t' => NH_TITLE . '%']);
$straySms = $pdo->prepare('SELECT COUNT(*) FROM sms_deliveries WHERE event_code = :c');
$straySms->execute(['c' => NH_EVENT]);
$strayEmail = $pdo->prepare('SELECT COUNT(*) FROM email_deliveries WHERE event_code = :c');
$strayEmail->execute(['c' => NH_EVENT]);
$strays = (int) $strayNotes->fetchColumn() + (int) $straySms->fetchColumn() + (int) $strayEmail->fetchColumn();
echo 'test_rows_left=' . $strays . PHP_EOL;
if ($strays !== 0) {
    $failed[] = 'cleanup';
}

$after = $counts();
echo 'counts_before=' . json_encode($before) . PHP_EOL;
echo 'counts_after=' . json_encode($after) . PHP_EOL;
$countsMatch = $before === $after;
echo 'counts_match=' . ($countsMatch ? 'ok' : 'FAIL') . PHP_EOL;
if (!$countsMatch) {
    $failed[] = 'counts';
}

foreach ($cookies as $f) {
    @unlink($f);
}

$total = count($tests) + count($logic);
$failCount = count($failed);
echo PHP_EOL . sprintf('http_checks=%d logic_checks=%d failed=%d', count($tests), count($logic), $failCount) . PHP_EOL;
if ($failed) {
    echo 'FAILED=' . implode(',', $failed) . PHP_EOL;
    echo 'RESULT=FAIL' . PHP_EOL;
    exit(1);
}

echo 'RESULT=PASS' . PHP_EOL;
