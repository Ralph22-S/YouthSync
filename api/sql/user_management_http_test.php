<?php
declare(strict_types=1);

/**
 * Safe user-management checks against the live YouthSync API.
 *
 * Creates labeled test accounts only. Does not delete existing users or
 * organizations and does not change demo account passwords.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = 'http://localhost/YouthSync/api';
$stamp = date('YmdHis');
$staffEmail = 'sk-usermgmt-' . $stamp . '@example.test';
$staffPassword = 'StaffMgmt1!';
$youthEmail = 'youth-usermgmt-' . $stamp . '@example.test';
$adminEmail = 'admin@skmms.test';
$adminPassword = 'YouthSync1!';
$skEmail = 'sk1@demo.test';
$skPassword = 'YouthSync1!';

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';
$pdo = youthsync_pdo();

$failed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}";
    if (!$ok && $detail !== '') {
        echo " — {$detail}";
    }
    echo "\n";
    if (!$ok) {
        $failed++;
    }
};

function um_req(string $method, string $url, ?string $json, string $cookie): array
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
        return ['status' => 0, 'body' => $err, 'json' => null];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($raw, $headerSize);
    curl_close($ch);
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
}

function um_detail(array $row): string
{
    $body = (string) $row['body'];
    $body = preg_replace('/"temporaryPassword"\s*:\s*"[^"]*"/', '"temporaryPassword":"[redacted]"', $body) ?? $body;
    $body = preg_replace('/"password_hash"\s*:\s*"[^"]*"/', '"password_hash":"[redacted]"', $body) ?? $body;
    $code = (string) ($row['json']['error']['code'] ?? '');
    $message = (string) ($row['json']['error']['message'] ?? '');
    $snippet = $message !== '' ? $message : substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 180);
    return 'status=' . $row['status'] . ($code !== '' ? " code={$code}" : '') . " {$snippet}";
}

function um_jar(string $name): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . '.txt';
    @unlink($path);
    return $path;
}

function um_data(array $row): array
{
    $data = $row['json']['data'] ?? null;
    return is_array($data) ? $data : [];
}

function um_ids(array $payload): array
{
    $ids = [];
    foreach (($payload['items'] ?? []) as $item) {
        if (isset($item['id'])) {
            $ids[] = (int) $item['id'];
        }
    }
    sort($ids);
    return $ids;
}

$sk = um_jar('youthsync_um_sk');
$staff = um_jar('youthsync_um_staff');
$admin = um_jar('youthsync_um_admin');
$youth = um_jar('youthsync_um_youth');
$anon = um_jar('youthsync_um_anon');
$reg = um_jar('youthsync_um_reg');

$r = um_req('POST', "$base/auth/login", json_encode(['email' => $skEmail, 'password' => $skPassword]), $sk);
$skMe = um_data($r);
$orgId = (int) ($skMe['organization']['id'] ?? 0);
$check('sk_login', $r['status'] === 200 && ($skMe['role'] ?? '') === 'SK_OFFICIAL' && $orgId > 0, um_detail($r));

$r = um_req('GET', "$base/sk/users", null, $sk);
$list = um_data($r);
$ownIds = um_ids($list);
$check('sk_user_list', $r['status'] === 200 && isset($list['items']) && $list['total'] >= 1, um_detail($r));

$r = um_req('GET', "$base/sk/users?organization_id=2&orgId=2", null, $sk);
$spoof = um_data($r);
$check('sk_list_ignores_client_organization', $r['status'] === 200 && um_ids($spoof) === $ownIds, um_detail($r));

$otherId = (int) $pdo->query(
    "SELECT u.id FROM users u
     INNER JOIN organization_users ou ON ou.user_id = u.id
     INNER JOIN roles r ON r.id = u.role_id
     WHERE r.code = 'SK_OFFICIAL' AND ou.organization_id <> " . (int) $orgId . "
     ORDER BY u.id ASC LIMIT 1"
)->fetchColumn();
$r = um_req('PATCH', "$base/sk/users/{$otherId}", json_encode(['firstName' => 'Nope', 'organization_id' => $orgId]), $sk);
$check('cross_organization_update_rejected', $otherId > 0 && $r['status'] === 404, um_detail($r));

$adminUserId = (int) $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($adminEmail))->fetchColumn();
$r = um_req('PATCH', "$base/sk/users/{$adminUserId}", json_encode(['firstName' => 'Nope']), $sk);
$check('sk_cannot_modify_system_admin', $r['status'] === 404, um_detail($r));

$r = um_req('POST', "$base/sk/users", json_encode([
    'firstName' => 'Staff',
    'lastName' => 'Mgmt',
    'email' => $staffEmail,
    'password' => $staffPassword,
    'role' => 'SYSTEM_ADMIN',
    'organization_id' => 2,
]), $sk);
$check('sk_cannot_create_system_admin', $r['status'] === 422, um_detail($r));

$r = um_req('POST', "$base/sk/users", json_encode([
    'firstName' => 'Staff',
    'lastName' => 'Mgmt',
    'email' => $staffEmail,
    'password' => $staffPassword,
    'role' => 'SK_OFFICIAL',
    'organization_id' => 2,
]), $sk);
$created = um_data($r);
$staffId = (int) ($created['id'] ?? 0);
$check(
    'sk_create_user',
    $r['status'] === 201 && $staffId > 0 && ($created['role'] ?? '') === 'SK_OFFICIAL' && !str_contains($r['body'], 'password_hash'),
    um_detail($r)
);

$membership = $pdo->prepare('SELECT organization_id, role_id FROM users u JOIN organization_users ou ON ou.user_id = u.id WHERE u.id = :id');
$membership->execute(['id' => $staffId]);
$member = $membership->fetch() ?: [];
$roleCode = (string) $pdo->query('SELECT code FROM roles WHERE id = ' . (int) ($member['role_id'] ?? 0))->fetchColumn();
$check('created_user_in_session_organization', (int) ($member['organization_id'] ?? 0) === $orgId && $roleCode === 'SK_OFFICIAL');

$r = um_req('POST', "$base/sk/users", json_encode([
    'firstName' => 'Staff',
    'lastName' => 'Duplicate',
    'email' => $staffEmail,
    'password' => $staffPassword,
]), $sk);
$check('duplicate_email_rejected', $r['status'] === 409, um_detail($r));

$r = um_req('GET', "$base/sk/users?q=" . rawurlencode($staffEmail) . "&status=active&organization_id=2", null, $sk);
$found = um_data($r);
$check(
    'sk_search_and_status_filter',
    $r['status'] === 200 && count($found['items'] ?? []) === 1 && (int) ($found['items'][0]['id'] ?? 0) === $staffId,
    um_detail($r)
);

$r = um_req('PATCH', "$base/sk/users/{$staffId}", json_encode([
    'firstName' => 'Staffed',
    'role' => 'SYSTEM_ADMIN',
    'organization_id' => 2,
]), $sk);
$check('sk_cannot_promote_on_update', $r['status'] === 422, um_detail($r));

$r = um_req('PATCH', "$base/sk/users/{$staffId}", json_encode(['firstName' => 'Staffed', 'organization_id' => 2]), $sk);
$updated = um_data($r);
$check('sk_update_user', $r['status'] === 200 && ($updated['firstName'] ?? '') === 'Staffed' && ($updated['role'] ?? '') === 'SK_OFFICIAL', um_detail($r));

$before = $pdo->prepare('SELECT password_hash, role_id, youth_id FROM users WHERE id = :id');
$before->execute(['id' => $staffId]);
$beforeRow = $before->fetch() ?: [];

$r = um_req('POST', "$base/sk/users/{$staffId}/toggle-active", '{}', $sk);
$toggled = um_data($r);
$check('sk_deactivate', $r['status'] === 200 && ($toggled['status'] ?? '') === 'inactive', um_detail($r));
$r = um_req('POST', "$base/auth/login", json_encode(['email' => $staffEmail, 'password' => $staffPassword]), $staff);
$check('inactive_staff_cannot_login', $r['status'] === 403, um_detail($r));
$r = um_req('GET', "$base/auth/me", null, $staff);
$check('inactive_staff_has_no_session', $r['status'] === 401, um_detail($r));

$r = um_req('POST', "$base/sk/users/{$staffId}/status", json_encode(['status' => 'active', 'organization_id' => 2]), $sk);
$check('sk_reactivate', $r['status'] === 200 && (um_data($r)['status'] ?? '') === 'active', um_detail($r));
$r = um_req('POST', "$base/auth/login", json_encode(['email' => $staffEmail, 'password' => $staffPassword]), $staff);
$check('reactivated_staff_can_login', $r['status'] === 200 && (um_data($r)['role'] ?? '') === 'SK_OFFICIAL', um_detail($r));
$r = um_req('POST', "$base/auth/logout", '{}', $staff);

$r = um_req('POST', "$base/sk/users/{$staffId}/reset-password", '{}', $sk);
$reset = um_data($r);
$temporary = (string) ($reset['temporaryPassword'] ?? '');
$check(
    'sk_reset_password',
    $r['status'] === 200
        && strlen($temporary) >= 8
        && !str_contains($r['body'], 'password_hash')
        && ($reset['role'] ?? '') === 'SK_OFFICIAL',
    um_detail($r)
);
$after = $pdo->prepare('SELECT password_hash, role_id, youth_id FROM users WHERE id = :id');
$after->execute(['id' => $staffId]);
$afterRow = $after->fetch() ?: [];
$check(
    'reset_updates_hash_only',
    password_verify($temporary, (string) ($afterRow['password_hash'] ?? ''))
        && !password_verify($staffPassword, (string) ($afterRow['password_hash'] ?? ''))
        && (int) ($afterRow['role_id'] ?? 0) === (int) ($beforeRow['role_id'] ?? 0)
        && (string) ($afterRow['youth_id'] ?? '') === (string) ($beforeRow['youth_id'] ?? '')
);
$r = um_req('POST', "$base/auth/login", json_encode(['email' => $staffEmail, 'password' => $staffPassword]), $staff);
$check('old_staff_password_rejected', $r['status'] === 401, um_detail($r));
$r = um_req('POST', "$base/auth/login", json_encode(['email' => $staffEmail, 'password' => $temporary]), $staff);
$check('new_staff_password_accepted', $r['status'] === 200, um_detail($r));
$r = um_req('POST', "$base/auth/logout", '{}', $staff);
$r = um_req('PATCH', "$base/sk/users/{$staffId}", json_encode(['password' => $staffPassword]), $sk);
$check('staff_password_restored_for_reuse', $r['status'] === 200, um_detail($r));

$r = um_req('GET', "$base/admin/users", null, $sk);
$check('sk_cannot_access_admin_users', $r['status'] === 403, um_detail($r));

$youthBody = [
    'firstName' => 'YouthMgmt',
    'lastName' => 'Test' . substr($stamp, -4),
    'birthDate' => '2005-04-12',
    'gender' => 'Prefer not to say',
    'address' => 'User management test address',
    'contact' => '09170001111',
    'email' => $youthEmail,
    'interests' => ['Sports'],
    'createAccount' => true,
    'accountEmail' => $youthEmail,
    'organization_id' => 2,
];
$r = um_req('POST', "$base/sk/youth", json_encode($youthBody), $sk);
$youthCreated = um_data($r);
$youthId = (int) ($youthCreated['youth']['id'] ?? 0);
$youthPassword = (string) ($youthCreated['temporaryPassword'] ?? '');
$check(
    'sk_create_youth_account',
    $r['status'] === 201 && $youthId > 0 && ($youthCreated['accountCreated'] ?? false) === true && strlen($youthPassword) >= 8,
    um_detail($r)
);
$youthUser = $pdo->prepare(
    "SELECT u.id, u.youth_id, u.status, u.password_hash, r.code AS role_code, ou.organization_id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     LEFT JOIN organization_users ou ON ou.user_id = u.id
     WHERE u.email = :email"
);
$youthUser->execute(['email' => $youthEmail]);
$youthRow = $youthUser->fetch() ?: [];
$youthUserId = (int) ($youthRow['id'] ?? 0);
$check(
    'youth_account_is_linked',
    $youthUserId > 0
        && ($youthRow['role_code'] ?? '') === 'YOUTH'
        && (int) ($youthRow['youth_id'] ?? 0) === $youthId
        && ($youthRow['status'] ?? '') === 'active'
        && password_verify($youthPassword, (string) ($youthRow['password_hash'] ?? ''))
        && (int) ($youthRow['organization_id'] ?? 0) === $orgId
);

$r = um_req('POST', "$base/auth/login", json_encode(['email' => $youthEmail, 'password' => 'wrong-password']), $youth);
$check('youth_wrong_password', $r['status'] === 401, um_detail($r));
$r = um_req('POST', "$base/auth/login", json_encode(['email' => $youthEmail, 'password' => $youthPassword]), $youth);
$check('youth_login', $r['status'] === 200 && (um_data($r)['role'] ?? '') === 'YOUTH', um_detail($r));
$r = um_req('GET', "$base/auth/me", null, $youth);
$me = um_data($r);
$check('youth_me', $r['status'] === 200 && ($me['role'] ?? '') === 'YOUTH' && (int) ($me['youth']['id'] ?? 0) === $youthId, um_detail($r));
$r = um_req('GET', "$base/youth/profile?youth_id=1&organization_id=2", null, $youth);
$profile = um_data($r);
$check('youth_profile_ignores_client_ids', $r['status'] === 200 && (int) ($profile['youth']['id'] ?? 0) === $youthId, um_detail($r));
$r = um_req('GET', "$base/sk/users", null, $youth);
$check('youth_cannot_access_sk_users', $r['status'] === 403, um_detail($r));
$r = um_req('GET', "$base/admin/users", null, $youth);
$check('youth_cannot_access_admin_users', $r['status'] === 403, um_detail($r));
$r = um_req('POST', "$base/auth/logout", '{}', $youth);
$check('youth_logout', $r['status'] === 200, um_detail($r));
$r = um_req('GET', "$base/auth/me", null, $youth);
$check('youth_me_after_logout', $r['status'] === 401, um_detail($r));

$r = um_req('POST', "$base/auth/login", json_encode(['email' => $adminEmail, 'password' => $adminPassword]), $admin);
$adminMe = um_data($r);
$check(
    'admin_login',
    $r['status'] === 200
        && ($adminMe['role'] ?? '') === 'SYSTEM_ADMIN'
        && array_key_exists('organization', $adminMe)
        && $adminMe['organization'] === null,
    um_detail($r)
);
$r = um_req('GET', "$base/auth/me", null, $admin);
$check('admin_me', $r['status'] === 200 && (um_data($r)['role'] ?? '') === 'SYSTEM_ADMIN', um_detail($r));
$r = um_req('GET', "$base/admin/users?q=" . rawurlencode($staffEmail) . "&role=sk_official&status=active", null, $admin);
$adminList = um_data($r);
$check(
    'admin_user_search',
    $r['status'] === 200 && count($adminList['items'] ?? []) === 1 && (int) ($adminList['items'][0]['id'] ?? 0) === $staffId,
    um_detail($r)
);
$r = um_req('GET', "$base/admin/users?q=" . rawurlencode($youthEmail) . "&role=youth", null, $admin);
$check('admin_youth_role_filter', $r['status'] === 200 && (int) (um_data($r)['items'][0]['id'] ?? 0) === $youthUserId, um_detail($r));

$hashBefore = (string) $pdo->query('SELECT password_hash FROM users WHERE id = ' . $staffId)->fetchColumn();
$roleBefore = (int) $pdo->query('SELECT role_id FROM users WHERE id = ' . $staffId)->fetchColumn();
$youthBefore = $pdo->query('SELECT youth_id FROM users WHERE id = ' . $staffId)->fetchColumn();
$r = um_req('POST', "$base/admin/users/{$staffId}/status", json_encode(['status' => 'inactive', 'role' => 'SYSTEM_ADMIN', 'organization_id' => 2]), $admin);
$adminStatus = um_data($r);
$check(
    'admin_deactivate',
    $r['status'] === 200 && ($adminStatus['status'] ?? '') === 'inactive' && ($adminStatus['active'] ?? true) === false && !str_contains($r['body'], 'password_hash'),
    um_detail($r)
);
$hashAfter = (string) $pdo->query('SELECT password_hash FROM users WHERE id = ' . $staffId)->fetchColumn();
$roleAfter = (int) $pdo->query('SELECT role_id FROM users WHERE id = ' . $staffId)->fetchColumn();
$youthAfter = $pdo->query('SELECT youth_id FROM users WHERE id = ' . $staffId)->fetchColumn();
$check('admin_status_does_not_change_secrets', $hashAfter === $hashBefore && $roleAfter === $roleBefore && (string) $youthAfter === (string) $youthBefore);
$r = um_req('POST', "$base/auth/login", json_encode(['email' => $staffEmail, 'password' => $staffPassword]), $staff);
$check('admin_deactivated_user_cannot_login', $r['status'] === 403, um_detail($r));
$r = um_req('POST', "$base/admin/users/{$staffId}/status", json_encode(['status' => 'active']), $admin);
$check('admin_reactivate', $r['status'] === 200 && (um_data($r)['status'] ?? '') === 'active', um_detail($r));
$r = um_req('POST', "$base/auth/login", json_encode(['email' => $staffEmail, 'password' => $staffPassword]), $staff);
$check('admin_reactivated_user_can_login', $r['status'] === 200, um_detail($r));
$r = um_req('POST', "$base/auth/logout", '{}', $staff);

$r = um_req('POST', "$base/admin/users/{$adminUserId}/status", json_encode(['status' => 'inactive']), $admin);
$check('admin_cannot_deactivate_self', $r['status'] === 422, um_detail($r));
$r = um_req('GET', "$base/admin/users", null, $anon);
$check('anonymous_admin_users_401', $r['status'] === 401, um_detail($r));
$r = um_req('POST', "$base/admin/users/{$staffId}/status", json_encode(['status' => 'inactive']), $sk);
$check('sk_cannot_call_admin_status', $r['status'] === 403, um_detail($r));
$r = um_req('POST', "$base/auth/login", json_encode(['email' => $youthEmail, 'password' => $youthPassword]), $youth);
$r = um_req('POST', "$base/admin/users/{$staffId}/status", json_encode(['status' => 'inactive']), $youth);
$check('youth_cannot_call_admin_status', $r['status'] === 403, um_detail($r));
$r = um_req('POST', "$base/auth/logout", '{}', $youth);

$r = um_req('GET', "$base/sk/users", null, $admin);
$check('admin_cannot_use_sk_user_routes', $r['status'] === 403, um_detail($r));
$r = um_req('POST', "$base/auth/logout", '{}', $admin);
$check('admin_logout', $r['status'] === 200, um_detail($r));
$r = um_req('GET', "$base/auth/me", null, $admin);
$check('admin_me_after_logout', $r['status'] === 401, um_detail($r));

$r = um_req('GET', "$base/auth/me", null, $sk);
$check('sk_me', $r['status'] === 200 && (int) (um_data($r)['organization']['id'] ?? 0) === $orgId, um_detail($r));
$r = um_req('POST', "$base/auth/logout", '{}', $sk);
$check('sk_logout', $r['status'] === 200, um_detail($r));
$r = um_req('GET', "$base/auth/me", null, $sk);
$check('sk_me_after_logout', $r['status'] === 401, um_detail($r));

$regBody = json_encode([
    'barangay' => 'UserMgmt' . $stamp,
    'municipality' => 'AuthTown',
    'province' => 'Laguna',
    'chairperson' => 'User Mgmt Chair',
    'email' => 'usermgmt' . $stamp . '@example.test',
    'contact' => '09181234567',
    'ownerName' => 'User Mgmt Owner',
    'ownerEmail' => 'skusermgmt' . $stamp . '@example.test',
    'ownerPassword' => 'UserMgmtReg1!',
    'confirmPassword' => 'UserMgmtReg1!',
    'acceptTerms' => true,
    'acceptPrivacy' => true,
]);
$r = um_req('POST', "$base/auth/register", $regBody, $reg);
$regData = um_data($r);
$check(
    'public_registration_creates_pending_org',
    $r['status'] === 201 && ($regData['organization']['status'] ?? '') === 'pending',
    um_detail($r)
);
$r = um_req('GET', "$base/auth/me", null, $reg);
$check('public_registration_does_not_login', $r['status'] === 401, um_detail($r));

echo "KEPT staff user id={$staffId} email={$staffEmail}\n";
echo "KEPT youth user id={$youthUserId} youth_id={$youthId} email={$youthEmail}\n";
echo "KEPT pending organization id=" . (int) ($regData['organization']['id'] ?? 0) . "\n";
echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
