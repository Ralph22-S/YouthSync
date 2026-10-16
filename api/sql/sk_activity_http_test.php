<?php
declare(strict_types=1);

/**
 * Audit log HTTP test: GET /sk/activity, GET /admin/activity, and the
 * activity rows written by real API actions (auth events and SK changes).
 *
 * Run from api/sql with Apache + MySQL up:
 *   php sk_activity_http_test.php
 * Optional: set YOUTHSYNC_API_BASE to override http://localhost/YouthSync/api.
 *
 * Real API and real database; nothing is faked. Every record the test creates
 * (youth, program, assistance type, SK staff account, password reset tokens)
 * is tracked by exact id and deleted at the end. activity_logs rows written by
 * these actions are the audit trail under test and are left in place; their
 * ids are printed so they can be identified. No SMS or email is sent: none of
 * these actions notify anyone, and the forgot-password request is skipped when
 * an email provider key is configured.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$config = require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/src/Admin/ActivityLog.php';

use YouthSync\Admin\ActivityLog;

$base = rtrim((string) (getenv('YOUTHSYNC_API_BASE') ?: 'http://localhost/YouthSync/api'), '/');
$pdo = youthsync_pdo();

const SKA_PASSWORD = 'YouthSync1!';
const SKA_YOUTH_EMAIL = 'youth-auth-test@example.test';
const SKA_YOUTH_PASSWORD = 'YouthAuthTest1!';

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
$cookies = [];
foreach (['anon', 'admin', 'sk', 'sk2', 'youth', 'staff', 'fail'] as $who) {
    $cookies[$who] = $tmp . 'ys_ska_' . $who . '.txt';
    @unlink($cookies[$who]);
}

function ska_req(string $method, string $url, ?string $json, string $cookie): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 25,
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
        return ['status' => 0, 'body' => $err, 'data' => []];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($raw, $headerSize);
    curl_close($ch);
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'data' => is_array($decoded) ? $decoded : []];
}

function ska_data(array $r): array
{
    $d = $r['data']['data'] ?? [];
    return is_array($d) ? $d : [];
}

function ska_cookie_values(string $jar): array
{
    $values = [];
    foreach (@file($jar, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $parts = explode("\t", $line);
        if (count($parts) >= 7 && strlen($parts[6]) >= 16) {
            $values[] = $parts[6];
        }
    }
    return $values;
}

/** Recursively collects array keys that look like credentials. */
function ska_secret_keys(mixed $value, string $path = ''): array
{
    $found = [];
    if (!is_array($value)) {
        return $found;
    }
    foreach ($value as $key => $child) {
        if (is_string($key) && preg_match('/password|passwd|token|session|api_?key|secret|hash|cookie/i', $key)) {
            $found[] = $path . $key;
        }
        $found = array_merge($found, ska_secret_keys($child, $path . $key . '.'));
    }
    return $found;
}

$failed = 0;
$passed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed, &$passed): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}" . ($detail !== '' && !$ok ? ' :: ' . substr($detail, 0, 500) : '') . "\n";
    $ok ? $passed++ : $failed++;
};

$rowsAfter = static function (int $afterId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM activity_logs WHERE id > :id ORDER BY id ASC');
    $stmt->execute(['id' => $afterId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};
$findRow = static function (array $rows, string $action, ?int $userId, string $contains = ''): ?array {
    foreach ($rows as $row) {
        if ($row['action'] !== $action) {
            continue;
        }
        if ($userId !== null && (int) $row['user_id'] !== $userId) {
            continue;
        }
        if ($contains !== '' && !str_contains((string) $row['description'], $contains)) {
            continue;
        }
        return $row;
    }
    return null;
};
$maxId = static function (string $table) use ($pdo): int {
    return (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
};

$startActivityId = $maxId('activity_logs');
$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$created = ['youth' => [], 'programs' => [], 'assistance_types' => [], 'users' => [], 'reset_tokens' => []];
$secretsUsed = [SKA_PASSWORD, SKA_YOUTH_PASSWORD];
echo "activity_logs max id before test: {$startActivityId}\n";

// ---- authorization --------------------------------------------------------
$anon = ska_req('GET', "$base/sk/activity", null, $cookies['anon']);
$check('anon_sk_activity_401', $anon['status'] === 401, (string) $anon['status']);
$anonAdmin = ska_req('GET', "$base/admin/activity", null, $cookies['anon']);
$check('anon_admin_activity_401', $anonAdmin['status'] === 401, (string) $anonAdmin['status']);

$adminLogin = ska_req('POST', "$base/auth/login", json_encode(['email' => 'admin@skmms.test', 'password' => SKA_PASSWORD]), $cookies['admin']);
$check('admin_login', $adminLogin['status'] === 200, (string) $adminLogin['status']);
$adminUserId = (int) (ska_data($adminLogin)['user']['id'] ?? 0);

$beforeSkLogin = $maxId('activity_logs');
$skLogin = ska_req('POST', "$base/auth/login", json_encode(['email' => 'sk1@demo.test', 'password' => SKA_PASSWORD]), $cookies['sk']);
$check('sk_login', $skLogin['status'] === 200 && str_contains($skLogin['body'], 'SK_OFFICIAL'), (string) $skLogin['status']);
$orgId = (int) (ska_data($skLogin)['organization']['id'] ?? 0);
$actorId = (int) (ska_data($skLogin)['user']['id'] ?? 0);
$check('sk_identity', $orgId > 0 && $actorId > 0, "org={$orgId} user={$actorId}");

$sk2Login = ska_req('POST', "$base/auth/login", json_encode(['email' => 'sk2@demo.test', 'password' => SKA_PASSWORD]), $cookies['sk2']);
$org2Id = (int) (ska_data($sk2Login)['organization']['id'] ?? 0);
$check('sk2_login_other_org', $sk2Login['status'] === 200 && $org2Id > 0 && $org2Id !== $orgId, "org2={$org2Id}");

$youthLogin = ska_req('POST', "$base/auth/login", json_encode(['email' => SKA_YOUTH_EMAIL, 'password' => SKA_YOUTH_PASSWORD]), $cookies['youth']);
$youthUserId = (int) (ska_data($youthLogin)['user']['id'] ?? 0);
$check('youth_login', $youthLogin['status'] === 200 && $youthUserId > 0, (string) $youthLogin['status']);

$r = ska_req('GET', "$base/sk/activity", null, $cookies['youth']);
$check('youth_sk_activity_403', $r['status'] === 403, (string) $r['status']);
$r = ska_req('GET', "$base/sk/activity", null, $cookies['admin']);
$check('admin_sk_activity_403', $r['status'] === 403, (string) $r['status']);
$r = ska_req('GET', "$base/admin/activity", null, $cookies['sk']);
$check('sk_admin_activity_403', $r['status'] === 403, (string) $r['status']);
$r = ska_req('GET', "$base/admin/activity", null, $cookies['youth']);
$check('youth_admin_activity_403', $r['status'] === 403, (string) $r['status']);

$skList = ska_req('GET', "$base/sk/activity?perPage=10", null, $cookies['sk']);
$skData = ska_data($skList);
$check(
    'sk_activity_200_shape',
    $skList['status'] === 200 && is_array($skData['items'] ?? null) && isset($skData['page'], $skData['perPage'], $skData['total'], $skData['pages'])
        && is_array($skData['filters']['actions'] ?? null) && is_array($skData['filters']['categories'] ?? null),
    substr($skList['body'], 0, 300)
);
$first = $skData['items'][0] ?? [];
$check(
    'sk_activity_item_fields',
    isset($first['id'], $first['action'], $first['category'], $first['description'], $first['createdAt']) && !array_key_exists('ip', $first),
    json_encode($first)
);

// ---- login success / failure ----------------------------------------------
$rows = $rowsAfter($beforeSkLogin);
$loginRow = $findRow($rows, 'login', $actorId);
$check(
    'login_success_recorded',
    $loginRow !== null && (int) $loginRow['organization_id'] === $orgId && $loginRow['category'] === 'auth'
        && $loginRow['actor_role'] === 'SK_OFFICIAL' && $loginRow['ip'] !== '',
    json_encode($loginRow)
);
$youthLoginRow = $findRow($rows, 'login', $youthUserId);
$check(
    'youth_login_recorded_with_youth_org',
    $youthLoginRow !== null && (int) $youthLoginRow['organization_id'] > 0 && $youthLoginRow['actor_role'] === 'YOUTH',
    json_encode($youthLoginRow)
);
$r = ska_req('GET', "$base/sk/activity?action=login&perPage=50", null, $cookies['sk']);
$loginIds = array_map(static fn ($i) => (int) $i['id'], ska_data($r)['items'] ?? []);
$check('login_row_returned_by_sk_activity', $loginRow !== null && in_array((int) $loginRow['id'], $loginIds, true), json_encode(array_slice($loginIds, 0, 10)));

$wrongPassword = 'Wrong-' . $suffix . '-Pass!';
$unknownEmail = 'nobody.' . $suffix . '@example.test';
$secretsUsed[] = $wrongPassword;
$beforeFail = $maxId('activity_logs');
$bad = ska_req('POST', "$base/auth/login", json_encode(['email' => 'sk1@demo.test', 'password' => $wrongPassword]), $cookies['fail']);
$check('wrong_password_401', $bad['status'] === 401, (string) $bad['status']);
$unknown = ska_req('POST', "$base/auth/login", json_encode(['email' => $unknownEmail, 'password' => $wrongPassword]), $cookies['fail']);
$check('unknown_account_401', $unknown['status'] === 401, (string) $unknown['status']);
$rows = $rowsAfter($beforeFail);
$badRow = $findRow($rows, 'login.failed', $actorId);
$check(
    'failed_login_known_account_recorded',
    $badRow !== null && (int) $badRow['organization_id'] === $orgId && $badRow['category'] === 'auth'
        && str_contains($badRow['description'], 'incorrect password'),
    json_encode($rows)
);
$unknownRow = null;
foreach ($rows as $row) {
    if ($row['action'] === 'login.failed' && $row['user_id'] === null) {
        $unknownRow = $row;
    }
}
$check('failed_login_unknown_account_recorded_without_ids', $unknownRow !== null && $unknownRow['organization_id'] === null, json_encode($rows));
$leak = false;
foreach ($rows as $row) {
    $text = implode(' ', array_map('strval', $row));
    if (str_contains($text, $wrongPassword) || str_contains($text, $unknownEmail) || str_contains($text, 'sk1@demo.test')) {
        $leak = true;
    }
}
$check('failed_login_rows_store_no_password_or_email', !$leak, json_encode($rows));
$r = ska_req('GET', "$base/sk/activity?action=login.failed&perPage=100", null, $cookies['sk']);
$failIds = array_map(static fn ($i) => (int) $i['id'], ska_data($r)['items'] ?? []);
$check(
    'sk_sees_own_failed_login_not_unknown_account',
    $badRow !== null && in_array((int) $badRow['id'], $failIds, true) && ($unknownRow === null || !in_array((int) $unknownRow['id'], $failIds, true)),
    json_encode($failIds)
);
$r = ska_req('GET', "$base/admin/activity?action=login.failed&perPage=50", null, $cookies['admin']);
$adminFailIds = array_map(static fn ($i) => (int) $i['id'], ska_data($r)['items'] ?? []);
$check('admin_sees_unknown_account_failure', $unknownRow !== null && in_array((int) $unknownRow['id'], $adminFailIds, true), json_encode($adminFailIds));

// ---- SK state changes create rows -------------------------------------------
$beforeActions = $maxId('activity_logs');
$youthCreate = ska_req('POST', "$base/sk/youth?organization_id={$org2Id}&user_id={$adminUserId}", json_encode([
    'firstName' => 'ActLog',
    'lastName' => 'Youth' . $suffix,
    'birthDate' => '2005-03-12',
    'gender' => 'Male',
    'address' => 'Purok 1',
    'contact' => '0917' . substr((string) random_int(10000000, 99999999), 0, 7),
    'email' => "actlog.{$suffix}@example.com",
    'interests' => ['Sports'],
    'employment' => 'Student',
    'educationStatus' => 'Currently Studying',
    'education' => 'College',
    'organization_id' => $org2Id,
    'organizationId' => $org2Id,
    'user_id' => $adminUserId,
    'userId' => $adminUserId,
]), $cookies['sk']);
$youthId = (int) (ska_data($youthCreate)['youth']['id'] ?? ska_data($youthCreate)['id'] ?? 0);
if ($youthId > 0) {
    $created['youth'][] = $youthId;
}
$check('youth_created', $youthCreate['status'] === 201 && $youthId > 0, substr($youthCreate['body'], 0, 300));
$youthOrg = $pdo->prepare('SELECT organization_id FROM youth WHERE id = :id');
$youthOrg->execute(['id' => $youthId]);
$check('youth_created_in_session_org_despite_spoof', (int) $youthOrg->fetchColumn() === $orgId);

$r = ska_req('PUT', "$base/sk/youth/{$youthId}", json_encode(['address' => 'Purok 2 updated', 'organization_id' => $org2Id]), $cookies['sk']);
$check('youth_updated', $r['status'] === 200, (string) $r['status']);
$r = ska_req('POST', "$base/sk/youth/{$youthId}/archive", '{}', $cookies['sk']);
$check('youth_archived', $r['status'] === 200, (string) $r['status']);
$r = ska_req('POST', "$base/sk/youth/{$youthId}/restore", '{}', $cookies['sk']);
$check('youth_restored', $r['status'] === 200, (string) $r['status']);

$programCreate = ska_req('POST', "$base/sk/programs", json_encode([
    'name' => 'ActLog Program ' . $suffix,
    'category' => 'Sports',
    'status' => 'draft',
    'scheduledOn' => date('Y-m-d', strtotime('+7 days')),
    'location' => 'Covered court',
    'organization_id' => $org2Id,
]), $cookies['sk']);
$programId = (int) (ska_data($programCreate)['id'] ?? 0);
if ($programId > 0) {
    $created['programs'][] = $programId;
}
$check('program_created', $programCreate['status'] === 201 && $programId > 0, substr($programCreate['body'], 0, 300));
$r = ska_req('POST', "$base/sk/programs/{$programId}/status", json_encode(['status' => 'published']), $cookies['sk']);
$check('program_published', $r['status'] === 200, (string) $r['status'] . ' ' . substr($r['body'], 0, 200));

$typeCreate = ska_req('POST', "$base/sk/assistance-types", json_encode([
    'name' => 'ActLog Type ' . $suffix,
    'category' => 'other',
    'organization_id' => $org2Id,
]), $cookies['sk']);
$typeId = (int) (ska_data($typeCreate)['id'] ?? 0);
if ($typeId > 0) {
    $created['assistance_types'][] = $typeId;
}
$check('assistance_type_created', $typeCreate['status'] === 201 && $typeId > 0, substr($typeCreate['body'], 0, 300));

$rows = $rowsAfter($beforeActions);
$expect = [
    'youth.created' => $youthId,
    'youth.updated' => $youthId,
    'youth.archived' => $youthId,
    'youth.restored' => $youthId,
    'program.created' => $programId,
    'program.published' => $programId,
    'assistance.type_created' => $typeId,
];
foreach ($expect as $action => $entityId) {
    $row = null;
    foreach ($rows as $candidate) {
        if ($candidate['action'] === $action && (int) $candidate['entity_id'] === $entityId) {
            $row = $candidate;
        }
    }
    $check(
        "recorded_{$action}",
        $row !== null && (int) $row['organization_id'] === $orgId && (int) $row['user_id'] === $actorId && $row['actor_role'] === 'SK_OFFICIAL',
        json_encode($row ?? array_column($rows, 'action'))
    );
}

$r = ska_req('GET', "$base/sk/activity?action=youth.created&q=" . rawurlencode('Youth' . $suffix), null, $cookies['sk']);
$items = ska_data($r)['items'] ?? [];
$check(
    'created_row_returned_with_timestamp',
    $r['status'] === 200 && count($items) === 1 && (int) $items[0]['entityId'] === $youthId
        && (int) $items[0]['orgId'] === $orgId && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $items[0]['createdAt']) === 1,
    substr($r['body'], 0, 400)
);

// ---- search / filters / pagination -----------------------------------------
$r = ska_req('GET', "$base/sk/activity?perPage=100&q=" . rawurlencode($suffix), null, $cookies['sk']);
$items = ska_data($r)['items'] ?? [];
$allMatch = $items !== [];
foreach ($items as $item) {
    if (stripos($item['description'] . ' ' . $item['actor'], $suffix) === false) {
        $allMatch = false;
    }
}
$searchActions = array_column($items, 'action');
$check(
    'text_search',
    $r['status'] === 200 && $allMatch && count(array_intersect(['youth.created', 'program.created', 'program.published', 'assistance.type_created'], $searchActions)) === 4,
    json_encode($searchActions)
);

$r = ska_req('GET', "$base/sk/activity?perPage=100&action=youth.updated", null, $cookies['sk']);
$items = ska_data($r)['items'] ?? [];
$check('action_filter', $items !== [] && count(array_filter($items, static fn ($i) => $i['action'] !== 'youth.updated')) === 0, 'count=' . count($items));

$r = ska_req('GET', "$base/sk/activity?perPage=100&category=auth", null, $cookies['sk']);
$items = ska_data($r)['items'] ?? [];
$check('category_filter', $items !== [] && count(array_filter($items, static fn ($i) => $i['category'] !== 'auth')) === 0, 'count=' . count($items));

$r = ska_req('GET', "$base/sk/activity?perPage=100&category=program&action=program.published&q=" . rawurlencode($suffix), null, $cookies['sk']);
$items = ska_data($r)['items'] ?? [];
$check('combined_filters', count($items) === 1 && (int) $items[0]['entityId'] === $programId, substr($r['body'], 0, 300));

$r = ska_req('GET', "$base/sk/activity?category=application&action=youth.created", null, $cookies['sk']);
$check('category_and_action_are_independent', (int) (ska_data($r)['total'] ?? -1) === 0, substr($r['body'], 0, 200));

$p1 = ska_data(ska_req('GET', "$base/sk/activity?perPage=2&page=1", null, $cookies['sk']));
$p2 = ska_data(ska_req('GET', "$base/sk/activity?perPage=2&page=2", null, $cookies['sk']));
$ids1 = array_map(static fn ($i) => (int) $i['id'], $p1['items'] ?? []);
$ids2 = array_map(static fn ($i) => (int) $i['id'], $p2['items'] ?? []);
$check(
    'pagination',
    count($ids1) === 2 && count($ids2) === 2 && array_intersect($ids1, $ids2) === [] && min($ids1) > max($ids2)
        && (int) $p1['total'] === (int) $p2['total'] && (int) $p1['pages'] === (int) ceil($p1['total'] / 2) && (int) $p2['page'] === 2,
    json_encode([$ids1, $ids2, $p1['total'] ?? null])
);
$far = ska_data(ska_req('GET', "$base/sk/activity?perPage=2&page=999999", null, $cookies['sk']));
// Search/pagination contract: a page past the end is an empty page (not the last page, not an error).
$check(
    'pagination_past_end_returns_empty_page',
    (int) ($far['page'] ?? 0) === 999999 && ($far['items'] ?? null) === []
        && (int) ($far['total'] ?? -1) === (int) ($p1['total'] ?? -2) && (int) ($far['pages'] ?? 0) === (int) ($p1['pages'] ?? -1)
        && (int) ($far['totalPages'] ?? 0) === (int) ($p1['pages'] ?? -1),
    json_encode([$far['page'] ?? null, count($far['items'] ?? []), $far['total'] ?? null, $p1['pages'] ?? null])
);
$cap = ska_data(ska_req('GET', "$base/sk/activity?perPage=5000", null, $cookies['sk']));
$check('per_page_capped', (int) ($cap['perPage'] ?? 0) === 100 && count($cap['items'] ?? []) <= 100);

// ---- tenant isolation / spoofing -------------------------------------------
$r = ska_req('GET', "$base/sk/activity?perPage=100&q=" . rawurlencode($suffix), null, $cookies['sk2']);
$check('tenant_isolation_search', $r['status'] === 200 && (int) (ska_data($r)['total'] ?? -1) === 0, substr($r['body'], 0, 200));
$r = ska_req('GET', "$base/sk/activity?perPage=100&organization_id={$orgId}&orgId={$orgId}&organizationId={$orgId}&user_id={$actorId}", null, $cookies['sk2']);
$items = ska_data($r)['items'] ?? [];
$check('sk2_spoofed_query_sees_only_org2', $r['status'] === 200 && count(array_filter($items, static fn ($i) => (int) $i['orgId'] !== $org2Id)) === 0, 'count=' . count($items));
$plain = ska_data(ska_req('GET', "$base/sk/activity?perPage=1", null, $cookies['sk']));
$spoof = ska_data(ska_req('GET', "$base/sk/activity?perPage=1&organization_id={$org2Id}&user_id={$adminUserId}&userId={$adminUserId}", null, $cookies['sk']));
$check('sk1_spoofed_query_ignored', (int) ($plain['total'] ?? -1) === (int) ($spoof['total'] ?? -2) && ($plain['items'][0]['id'] ?? 0) === ($spoof['items'][0]['id'] ?? -1), json_encode([$plain['total'] ?? null, $spoof['total'] ?? null]));
$sk2Filters = ska_data(ska_req('GET', "$base/sk/activity?perPage=1", null, $cookies['sk2']))['filters']['actions'] ?? [];
$check('filter_options_are_org_scoped', !in_array('assistance.type_created', $sk2Filters, true) || $org2Id === $orgId, json_encode($sk2Filters));

// ---- password change / logout / reset (throwaway staff account) ------------
$staffEmail = 'actlog.staff.' . $suffix . '@example.test';
$staffPassword = 'StaffTemp-' . $suffix . '1!';
$staffNewPassword = 'StaffNew-' . $suffix . '2!';
$staffResetPassword = 'StaffReset-' . $suffix . '3!';
array_push($secretsUsed, $staffPassword, $staffNewPassword, $staffResetPassword);
$beforeStaff = $maxId('activity_logs');
$maxUserBefore = $maxId('users');
$staffCreate = ska_req('POST', "$base/sk/users", json_encode([
    'firstName' => 'ActLog',
    'lastName' => 'Staff' . $suffix,
    'email' => $staffEmail,
    'password' => $staffPassword,
    'role' => 'SK_OFFICIAL',
]), $cookies['sk']);
$staffId = (int) (ska_data($staffCreate)['id'] ?? 0);
if ($staffId > 0) {
    $created['users'][] = $staffId;
}
$check('staff_created', $staffCreate['status'] === 201 && $staffId > 0, substr($staffCreate['body'], 0, 300));

// Password change / reset only ever touch the throwaway account created
// above: the id must be new in this run and its email must be the generated one.
$staffRow = $pdo->prepare('SELECT email FROM users WHERE id = :id');
$staffRow->execute(['id' => $staffId]);
$staffIsOurs = $staffId > 0 && $staffId > $maxUserBefore && (string) $staffRow->fetchColumn() === $staffEmail;
$check('staff_account_is_test_created', $staffIsOurs, "id={$staffId}");
$emailConfigured = trim((string) ($config['brevo_api_key'] ?? '')) !== '';
$staffSessionValues = [];
if ($staffIsOurs) {
    $r = ska_req('POST', "$base/auth/login", json_encode(['email' => $staffEmail, 'password' => $staffPassword]), $cookies['staff']);
    $check('staff_login', $r['status'] === 200, (string) $r['status']);
    $staffSessionValues = ska_cookie_values($cookies['staff']);
    $r = ska_req('POST', "$base/auth/change-password", json_encode(['currentPassword' => $staffPassword, 'newPassword' => $staffNewPassword]), $cookies['staff']);
    $check('staff_change_password', $r['status'] === 200, (string) $r['status'] . ' ' . substr($r['body'], 0, 200));
    $r = ska_req('POST', "$base/auth/logout", '{}', $cookies['staff']);
    $check('staff_logout', $r['status'] === 200, (string) $r['status']);

    if (!$emailConfigured) {
        $r = ska_req('POST', "$base/auth/forgot-password", json_encode(['email' => $staffEmail]), $cookies['anon']);
        $check('forgot_password_generic_200', $r['status'] === 200, (string) $r['status']);
    } else {
        echo "SKIP forgot_password (an email provider key is configured; not sending a real email)\n";
    }
    $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (:u, :h, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
    )->execute(['u' => $staffId, 'h' => hash('sha256', $rawToken)]);
    $secretsUsed[] = $rawToken;
    $r = ska_req('POST', "$base/auth/reset-password", json_encode(['token' => $rawToken, 'newPassword' => $staffResetPassword]), $cookies['anon']);
    $check('reset_password', $r['status'] === 200, (string) $r['status'] . ' ' . substr($r['body'], 0, 200));
    $tokenIds = $pdo->prepare('SELECT id FROM password_reset_tokens WHERE user_id = :u');
    $tokenIds->execute(['u' => $staffId]);
    $created['reset_tokens'] = array_map('intval', $tokenIds->fetchAll(PDO::FETCH_COLUMN));
}

$rows = $rowsAfter($beforeStaff);
foreach (array_filter([
    'user.created' => null,
    'login' => $staffId,
    'password.changed' => $staffId,
    'logout' => $staffId,
    'password.reset_requested' => $emailConfigured ? false : $staffId,
    'password.reset' => $staffId,
], static fn ($v) => $v !== false && $staffIsOurs) as $action => $userId) {
    $row = $findRow($rows, $action, $userId);
    $okCategory = $row !== null && ($action === 'user.created' ? $row['category'] === 'user' : $row['category'] === 'auth');
    $check("recorded_{$action}", $row !== null && (int) $row['organization_id'] === $orgId && $okCategory, json_encode($row ?? array_column($rows, 'action')));
}

// ---- no credentials stored or returned -------------------------------------
$allNew = $rowsAfter($startActivityId);
$secretHits = [];
foreach ($allNew as $row) {
    $text = implode(' | ', array_map('strval', $row));
    foreach (array_merge($secretsUsed, $staffSessionValues, ska_cookie_values($cookies['sk'])) as $secret) {
        if ($secret !== '' && str_contains($text, $secret)) {
            $secretHits[] = "row {$row['id']} contains a credential value";
        }
    }
    if (preg_match('/\$2[aby]\$|\$argon2|password_hash|api_?key|secret|token|session|cookie/i', $text)) {
        $secretHits[] = "row {$row['id']}: " . $row['description'];
    }
}
$check('activity_rows_store_no_credentials', $secretHits === [], implode('; ', $secretHits));

$responses = [
    ska_req('GET', "$base/sk/activity?perPage=100", null, $cookies['sk']),
    ska_req('GET', "$base/sk/activity?perPage=100&category=auth", null, $cookies['sk']),
    ska_req('GET', "$base/admin/activity?perPage=200", null, $cookies['admin']),
    ska_req('GET', "$base/admin/activity?perPage=200&category=auth", null, $cookies['admin']),
];
$respHits = [];
foreach ($responses as $resp) {
    $respHits = array_merge($respHits, ska_secret_keys($resp['data']));
    foreach ($secretsUsed as $secret) {
        if (str_contains($resp['body'], $secret)) {
            $respHits[] = 'credential value in response';
        }
    }
    if (preg_match('/\$2[aby]\$|\$argon2/', $resp['body'])) {
        $respHits[] = 'password hash in response';
    }
}
$check('activity_responses_expose_no_credentials', $respHits === [], implode('; ', array_unique($respHits)));

// ---- ActivityLog failure is reported, not swallowed -------------------------
// ActivityLog appends to api/logs/app.log; if that file is not writable by
// this CLI user (e.g. owned by the web server user) it falls back to
// error_log(), which is pointed at a temp file here so the check can read it.
$logFile = dirname(__DIR__) . '/logs/app.log';
$logSizeBefore = is_file($logFile) ? (int) filesize($logFile) : 0;
$fallbackLog = $tmp . 'ys_ska_error_log_' . $suffix . '.txt';
@unlink($fallbackLog);
$previousErrorLog = ini_set('error_log', $fallbackLog);
$beforeFailure = $maxId('activity_logs');
$missingUser = $maxId('users') + 1000000;
ActivityLog::record($pdo, [
    'organizationId' => $orgId,
    'userId' => $missingUser,
    'actorName' => 'ActLog failure probe',
    'action' => 'test.write_failure',
    'category' => 'test',
    'description' => 'ActLog failure probe ' . $suffix,
]);
clearstatcache();
$logTail = is_file($logFile) ? (string) file_get_contents($logFile, false, null, $logSizeBefore) : '';
$logTail .= is_file($fallbackLog) ? (string) file_get_contents($fallbackLog) : '';
ini_set('error_log', $previousErrorLog === false ? '' : (string) $previousErrorLog);
@unlink($fallbackLog);
$check('failed_write_creates_no_row', $maxId('activity_logs') === $beforeFailure);
$check(
    'failed_write_logged_to_app_log',
    str_contains($logTail, 'ActivityLog write failed (action=test.write_failure, category=test)') && !str_contains($logTail, 'ActLog failure probe'),
    trim($logTail)
);

// ---- admin activity regression ------------------------------------------------
$a = ska_req('GET', "$base/admin/activity?perPage=5", null, $cookies['admin']);
$ad = ska_data($a);
$check('admin_activity_200_shape', $a['status'] === 200 && isset($ad['items'], $ad['page'], $ad['perPage'], $ad['total'], $ad['pages']) && count($ad['items']) === 5, substr($a['body'], 0, 200));
$a2 = ska_data(ska_req('GET', "$base/admin/activity?perPage=5&page=2", null, $cookies['admin']));
$check('admin_activity_pagination', array_intersect(array_column($ad['items'] ?? [], 'id'), array_column($a2['items'] ?? [], 'id')) === [] && (int) ($a2['page'] ?? 0) === 2);
$a = ska_data(ska_req('GET', "$base/admin/activity?perPage=100&action=youth.created&q=" . rawurlencode($suffix), null, $cookies['admin']));
$check('admin_activity_action_and_search', count($a['items'] ?? []) === 1 && (int) $a['items'][0]['entityId'] === $youthId, json_encode($a['items'] ?? []));
$a = ska_data(ska_req('GET', "$base/admin/activity?perPage=100&category=auth", null, $cookies['admin']));
$check('admin_activity_category', ($a['items'] ?? []) !== [] && count(array_filter($a['items'], static fn ($i) => $i['category'] !== 'auth')) === 0);
$a = ska_data(ska_req('GET', "$base/admin/organizations/{$orgId}/activity?perPage=100", null, $cookies['admin']));
$check('admin_org_activity_isolated', ($a['items'] ?? []) !== [] && count(array_filter($a['items'], static fn ($i) => (int) $i['orgId'] !== $orgId)) === 0);

// ---- cleanup: exact ids only ---------------------------------------------------
foreach ($created['youth'] as $id) {
    $r = ska_req('DELETE', "$base/sk/youth/{$id}", null, $cookies['sk']);
    $check("cleanup_youth_{$id}", $r['status'] === 200, (string) $r['status']);
}
foreach ($created['programs'] as $id) {
    $r = ska_req('DELETE', "$base/sk/programs/{$id}", null, $cookies['sk']);
    $check("cleanup_program_{$id}", $r['status'] === 200, (string) $r['status']);
}
foreach ($created['reset_tokens'] as $id) {
    $pdo->prepare('DELETE FROM password_reset_tokens WHERE id = :id AND user_id = :u')->execute(['id' => $id, 'u' => $staffId]);
}
foreach ($created['users'] as $id) {
    $r = ska_req('DELETE', "$base/sk/users/{$id}", null, $cookies['sk']);
    $check("cleanup_staff_user_{$id}", $r['status'] === 200, (string) $r['status'] . ' ' . substr($r['body'], 0, 200));
}
foreach ($created['assistance_types'] as $id) {
    $pdo->prepare('DELETE FROM assistance_types WHERE id = :id AND organization_id = :org AND is_system = 0')->execute(['id' => $id, 'org' => $orgId]);
}
$left = [];
foreach ([['youth', $created['youth']], ['programs', $created['programs']], ['users', $created['users']], ['assistance_types', $created['assistance_types']], ['password_reset_tokens', $created['reset_tokens']]] as [$table, $ids]) {
    foreach ($ids as $id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id = :id");
        $stmt->execute(['id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $left[] = "{$table}#{$id}";
        }
    }
}
$check('cleanup_complete', $left === [], implode(',', $left));

foreach ($cookies as $jar) {
    @unlink($jar);
}
$endActivityId = $maxId('activity_logs');
echo 'Created and deleted: youth=' . json_encode($created['youth']) . ' programs=' . json_encode($created['programs'])
    . ' assistance_types=' . json_encode($created['assistance_types']) . ' users=' . json_encode($created['users'])
    . ' password_reset_tokens=' . json_encode($created['reset_tokens']) . "\n";
echo 'Audit rows written during this run (kept): activity_logs ids ' . ($startActivityId + 1) . "-{$endActivityId}\n";
echo "Summary: {$passed} passed, {$failed} failed\n";
echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
