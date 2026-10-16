<?php
declare(strict_types=1);

/**
 * Safe Organization Management check.
 *
 * Creates one admin organization and one public registration, then deletes
 * only those organizations and their owner accounts.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = 'http://localhost/YouthSync/api';
$adminEmail = 'admin@skmms.test';
$skEmail = 'sk1@demo.test';
$youthEmail = 'youth-auth-test@example.test';
$demoPassword = 'YouthSync1!';
$youthPassword = 'YouthAuthTest1!';
$stamp = date('YmdHis');
$ownerPassword = 'OrgMgmtTest1!';

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';
$pdo = youthsync_pdo();

$failed = 0;
$created = [];
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

function om_req(string $method, string $url, ?string $json, string $cookie): array
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
        return ['status' => 0, 'body' => $err, 'json' => null, 'headers' => ''];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $header = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null, 'headers' => $header];
}

function om_data(array $row): array
{
    $data = $row['json']['data'] ?? null;
    return is_array($data) ? $data : [];
}

function om_detail(array $row): string
{
    $code = (string) ($row['json']['error']['code'] ?? '');
    $message = (string) ($row['json']['error']['message'] ?? '');
    return 'status=' . $row['status'] . ($code !== '' ? " code={$code}" : '') . ' ' . $message;
}

function om_jar(): string
{
    $path = tempnam(sys_get_temp_dir(), 'ysom');
    return $path === false ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ysom-' . bin2hex(random_bytes(4)) : $path;
}

$cleanup = static function () use ($pdo, &$created): void {
    foreach ($created as $row) {
        $orgId = (int) ($row['orgId'] ?? 0);
        $userId = (int) ($row['userId'] ?? 0);
        $orgEmail = (string) ($row['orgEmail'] ?? '');
        $userEmail = (string) ($row['userEmail'] ?? '');
        if ($userId > 0 && $userEmail !== '') {
            $pdo->prepare('DELETE FROM organization_users WHERE user_id = :id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :email')->execute(['id' => $userId, 'email' => $userEmail]);
        }
        if ($orgId > 0 && $orgEmail !== '') {
            $pdo->prepare('DELETE FROM organization_users WHERE organization_id = :id')->execute(['id' => $orgId]);
            $pdo->prepare('DELETE FROM activity_logs WHERE organization_id = :id')->execute(['id' => $orgId]);
            $pdo->prepare('DELETE FROM organizations WHERE id = :id AND email = :email')->execute(['id' => $orgId, 'email' => $orgEmail]);
        }
    }
};

$admin = om_jar();
$sk = om_jar();
$youth = om_jar();
$anon = om_jar();
$reg = om_jar();
$owner = om_jar();

try {
    $anonList = om_req('GET', "$base/admin/organizations", null, $anon);
    $check('anonymous_admin_orgs_rejected', $anonList['status'] === 401, om_detail($anonList));
    $anonSk = om_req('PATCH', "$base/sk/organization", '{}', $anon);
    $check('anonymous_sk_profile_rejected', $anonSk['status'] === 401, om_detail($anonSk));

    $login = om_req('POST', "$base/auth/login", json_encode(['email' => $adminEmail, 'password' => $demoPassword]), $admin);
    $check('admin_login', $login['status'] === 200, om_detail($login));
    $me = om_req('GET', "$base/auth/me", null, $admin);
    $check('admin_me', $me['status'] === 200 && (om_data($me)['role'] ?? '') === 'SYSTEM_ADMIN', om_detail($me));

    $list = om_req('GET', "$base/admin/organizations?perPage=5", null, $admin);
    $check('admin_list_organizations', $list['status'] === 200 && is_array(om_data($list)['items'] ?? null), om_detail($list));

    $badPlan = om_req('GET', "$base/admin/organizations?plan=not-a-plan", null, $admin);
    $check('invalid_plan_rejected', $badPlan['status'] === 422, om_detail($badPlan));

    $invalid = om_req('POST', "$base/admin/organizations", json_encode([
        'barangay' => '',
        'municipality' => 'Nowhere',
        'email' => 'not-an-email',
        'contact' => '123',
    ]), $admin);
    $check('admin_create_validates_fields', $invalid['status'] === 422, om_detail($invalid));

    $adminOrgEmail = "om-admin-{$stamp}@example.test";
    $adminOwnerEmail = "om-admin-owner-{$stamp}@example.test";
    $adminBarangay = "OmgtAdmin{$stamp}";
    $createdAdmin = om_req('POST', "$base/admin/organizations", json_encode([
        'barangay' => $adminBarangay,
        'municipality' => 'Testville',
        'province' => 'Laguna',
        'chairperson' => 'Admin Created Chair',
        'email' => $adminOrgEmail,
        'contact' => '09170001001',
        'ownerName' => 'Admin Owner',
        'ownerEmail' => $adminOwnerEmail,
        'ownerPassword' => $ownerPassword,
    ]), $admin);
    $adminData = om_data($createdAdmin);
    $adminOrgId = (int) ($adminData['id'] ?? 0);
    $check('admin_create_organization', $createdAdmin['status'] === 201 && $adminOrgId > 0, om_detail($createdAdmin));
    $check('admin_create_omits_password_hash', !str_contains((string) $createdAdmin['body'], 'password_hash'));

    $adminUserId = 0;
    if ($adminOrgId > 0) {
        $userStmt = $pdo->prepare('SELECT id, password_hash, role_id FROM users WHERE email = :email LIMIT 1');
        $userStmt->execute(['email' => $adminOwnerEmail]);
        $userRow = $userStmt->fetch() ?: [];
        $adminUserId = (int) ($userRow['id'] ?? 0);
        $created[] = ['orgId' => $adminOrgId, 'orgEmail' => $adminOrgEmail, 'userId' => $adminUserId, 'userEmail' => $adminOwnerEmail];
        $orgStmt = $pdo->prepare(
            'SELECT status, plan, sub_status, cycle, DATEDIFF(expires_at, CURDATE()) AS days
             FROM organizations WHERE id = :id AND email = :email'
        );
        $orgStmt->execute(['id' => $adminOrgId, 'email' => $adminOrgEmail]);
        $orgRow = $orgStmt->fetch() ?: [];
        $role = '';
        if ($adminUserId > 0) {
            $roleStmt = $pdo->prepare('SELECT code FROM roles WHERE id = :id');
            $roleStmt->execute(['id' => (int) ($userRow['role_id'] ?? 0)]);
            $role = (string) $roleStmt->fetchColumn();
        }
        $check('admin_org_active_premium_trial', ($orgRow['status'] ?? '') === 'active' && ($orgRow['plan'] ?? '') === 'premium' && ($orgRow['sub_status'] ?? '') === 'trial' && (int) ($orgRow['days'] ?? -1) === 7, json_encode($orgRow));
        $check('admin_owner_is_sk_official', $role === 'SK_OFFICIAL');
        $check('admin_owner_password_hashed', $adminUserId > 0 && ($userRow['password_hash'] ?? '') !== $ownerPassword && password_verify($ownerPassword, (string) ($userRow['password_hash'] ?? '')));
        $member = $pdo->prepare('SELECT status, is_owner FROM organization_users WHERE user_id = :uid AND organization_id = :org');
        $member->execute(['uid' => $adminUserId, 'org' => $adminOrgId]);
        $membership = $member->fetch() ?: [];
        $check('admin_owner_membership', ($membership['status'] ?? '') === 'active' && (int) ($membership['is_owner'] ?? 0) === 1);
    }

    $dup = om_req('POST', "$base/admin/organizations", json_encode([
        'barangay' => $adminBarangay,
        'municipality' => 'Testville',
        'province' => 'Laguna',
        'chairperson' => 'Other',
        'email' => "om-admin-dup-{$stamp}@example.test",
        'contact' => '09170001002',
        'ownerName' => 'Other Owner',
        'ownerEmail' => "om-admin-dup-owner-{$stamp}@example.test",
        'ownerPassword' => $ownerPassword,
    ]), $admin);
    $check('duplicate_barangay_rejected', $dup['status'] === 409, om_detail($dup));

    $found = om_req('GET', "$base/admin/organizations?q=" . rawurlencode($adminBarangay), null, $admin);
    $foundIds = array_map(static fn ($item) => (int) ($item['id'] ?? 0), om_data($found)['items'] ?? []);
    $check('admin_search_finds_created', $found['status'] === 200 && in_array($adminOrgId, $foundIds, true), om_detail($found));

    $detail = om_req('GET', "$base/admin/organizations/{$adminOrgId}", null, $admin);
    $check('admin_detail', $detail['status'] === 200 && (int) (om_data($detail)['id'] ?? 0) === $adminOrgId && !str_contains((string) $detail['body'], 'password_hash'), om_detail($detail));

    $badStatus = om_req('POST', "$base/admin/organizations/{$adminOrgId}/status", json_encode(['status' => 'deleted']), $admin);
    $check('invalid_status_rejected', $badStatus['status'] === 422, om_detail($badStatus));

    $inactive = om_req('POST', "$base/admin/organizations/{$adminOrgId}/status", json_encode(['status' => 'inactive', 'note' => '']), $admin);
    $check('admin_set_inactive', $inactive['status'] === 200 && (om_data($inactive)['status'] ?? '') === 'inactive', om_detail($inactive));
    $restored = om_req('POST', "$base/admin/organizations/{$adminOrgId}/status", json_encode(['status' => 'active']), $admin);
    $check('admin_restore_active', $restored['status'] === 200 && (om_data($restored)['status'] ?? '') === 'active', om_detail($restored));

    $logout = om_req('POST', "$base/auth/logout", '{}', $admin);
    $check('admin_logout', $logout['status'] === 200, om_detail($logout));

    $pubEmail = "om-pub-{$stamp}@example.test";
    $pubOwnerEmail = "om-pub-owner-{$stamp}@example.test";
    $pubBarangay = "OmgtPub{$stamp}";
    $registered = om_req('POST', "$base/auth/register", json_encode([
        'barangay' => $pubBarangay,
        'municipality' => 'Testborough',
        'province' => 'Laguna',
        'chairperson' => 'Public Chair',
        'email' => $pubEmail,
        'contact' => '09170001003',
        'ownerName' => 'Public Owner',
        'ownerEmail' => $pubOwnerEmail,
        'ownerPassword' => $ownerPassword,
        'confirmPassword' => $ownerPassword,
        'acceptTerms' => true,
        'acceptPrivacy' => true,
    ]), $reg);
    $regData = om_data($registered);
    $pubOrgId = (int) ($regData['organization']['id'] ?? 0);
    $check('public_register', $registered['status'] === 201 && ($regData['organization']['status'] ?? '') === 'pending', om_detail($registered));
    $check('public_register_no_session', !str_contains(strtolower((string) $registered['headers']), 'youthsync_session'));
    $check('public_register_omits_secrets', !str_contains((string) $registered['body'], 'password_hash') && !str_contains((string) $registered['body'], $ownerPassword));
    $regMe = om_req('GET', "$base/auth/me", null, $reg);
    $check('public_register_did_not_sign_in', $regMe['status'] === 401, om_detail($regMe));

    $alias = om_req('POST', "$base/org/register", json_encode(['acceptTerms' => false]), $anon);
    $check('org_register_alias_validates', $alias['status'] === 422, om_detail($alias));

    $pubUserId = 0;
    if ($pubOrgId > 0) {
        $userStmt = $pdo->prepare(
            'SELECT u.id, u.password_hash, u.status AS user_status, r.code
             FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.email = :email LIMIT 1'
        );
        $userStmt->execute(['email' => $pubOwnerEmail]);
        $userRow = $userStmt->fetch() ?: [];
        $pubUserId = (int) ($userRow['id'] ?? 0);
        $created[] = ['orgId' => $pubOrgId, 'orgEmail' => $pubEmail, 'userId' => $pubUserId, 'userEmail' => $pubOwnerEmail];
        $orgStmt = $pdo->prepare('SELECT status, plan, sub_status FROM organizations WHERE id = :id AND email = :email');
        $orgStmt->execute(['id' => $pubOrgId, 'email' => $pubEmail]);
        $orgRow = $orgStmt->fetch() ?: [];
        $check('public_org_pending_free', ($orgRow['status'] ?? '') === 'pending' && ($orgRow['plan'] ?? '') === 'free' && ($orgRow['sub_status'] ?? '') === 'free', json_encode($orgRow));
        $check('public_owner_sk_official_active', ($userRow['code'] ?? '') === 'SK_OFFICIAL' && ($userRow['user_status'] ?? '') === 'active');
        $check('public_owner_password_hashed', $pubUserId > 0 && password_verify($ownerPassword, (string) ($userRow['password_hash'] ?? '')) && !str_contains((string) ($userRow['password_hash'] ?? ''), $ownerPassword));
        $member = $pdo->prepare('SELECT status, is_owner FROM organization_users WHERE user_id = :uid AND organization_id = :org');
        $member->execute(['uid' => $pubUserId, 'org' => $pubOrgId]);
        $membership = $member->fetch() ?: [];
        $check('public_owner_membership', ($membership['status'] ?? '') === 'active' && (int) ($membership['is_owner'] ?? 0) === 1);
        $logStmt = $pdo->prepare('SELECT description FROM activity_logs WHERE organization_id = :id');
        $logStmt->execute(['id' => $pubOrgId]);
        $logHit = false;
        foreach ($logStmt as $log) {
            if (str_contains((string) $log['description'], $ownerPassword)) {
                $logHit = true;
            }
        }
        $check('password_not_in_activity_logs', !$logHit);
    }

    $pendingLogin = om_req('POST', "$base/auth/login", json_encode(['email' => $pubOwnerEmail, 'password' => $ownerPassword]), $owner);
    $check('pending_login_forbidden', $pendingLogin['status'] === 403 && ($pendingLogin['json']['error']['code'] ?? '') === 'ORGANIZATION_PENDING', om_detail($pendingLogin));

    $admin2 = om_jar();
    $login = om_req('POST', "$base/auth/login", json_encode(['email' => $adminEmail, 'password' => $demoPassword]), $admin2);
    $check('admin_login_again', $login['status'] === 200, om_detail($login));
    $pendingList = om_req('GET', "$base/admin/organizations?status=pending&q=" . rawurlencode($pubBarangay), null, $admin2);
    $pendingIds = array_map(static fn ($item) => (int) ($item['id'] ?? 0), om_data($pendingList)['items'] ?? []);
    $check('admin_sees_pending', $pendingList['status'] === 200 && in_array($pubOrgId, $pendingIds, true), om_detail($pendingList));
    $approved = om_req('POST', "$base/admin/organizations/{$pubOrgId}/approve", '{}', $admin2);
    $approvedData = om_data($approved);
    $check('admin_approves', $approved['status'] === 200 && ($approvedData['status'] ?? '') === 'active' && ($approvedData['plan'] ?? '') === 'premium' && ($approvedData['subStatus'] ?? '') === 'trial', om_detail($approved));
    if ($pubOrgId > 0) {
        $trial = $pdo->prepare('SELECT DATEDIFF(expires_at, CURDATE()) AS days, cycle FROM organizations WHERE id = :id');
        $trial->execute(['id' => $pubOrgId]);
        $trialRow = $trial->fetch() ?: [];
        $check('approval_starts_seven_day_trial', (int) ($trialRow['days'] ?? -1) === 7 && ($trialRow['cycle'] ?? '') === 'trial', json_encode($trialRow));
    }

    $ownerLogin = om_req('POST', "$base/auth/login", json_encode(['email' => $pubOwnerEmail, 'password' => $ownerPassword]), $owner);
    $check('owner_login_after_approval', $ownerLogin['status'] === 200, om_detail($ownerLogin));
    $profile = om_req('GET', "$base/sk/organization", null, $owner);
    $profileData = om_data($profile);
    $check('sk_profile_loads', $profile['status'] === 200 && (int) ($profileData['id'] ?? 0) === $pubOrgId && !str_contains((string) $profile['body'], 'password_hash'), om_detail($profile));

    $otherOrg = (int) $pdo->query('SELECT id FROM organizations WHERE id NOT IN (' . (int) $pubOrgId . ',' . (int) $adminOrgId . ') ORDER BY id ASC LIMIT 1')->fetchColumn();
    $beforeOther = null;
    if ($otherOrg > 0) {
        $snap = $pdo->prepare('SELECT barangay, municipality, plan, sub_status, status FROM organizations WHERE id = :id');
        $snap->execute(['id' => $otherOrg]);
        $beforeOther = $snap->fetch() ?: null;
    }
    $patched = om_req('PATCH', "$base/sk/organization", json_encode([
        'organization_id' => $otherOrg > 0 ? $otherOrg : 2,
        'barangay' => $pubBarangay,
        'municipality' => 'Testborough',
        'province' => 'Laguna',
        'chairperson' => 'Updated Chair',
        'email' => $pubEmail,
        'contact' => '09170001009',
        'plan' => 'free',
        'status' => 'suspended',
        'subStatus' => 'cancelled',
    ]), $owner);
    $patchedData = om_data($patched);
    $check('sk_profile_updates', $patched['status'] === 200 && ($patchedData['chairperson'] ?? '') === 'Updated Chair' && ($patchedData['contact'] ?? '') === '09170001009', om_detail($patched));
    $check('sk_cannot_change_plan_or_status', ($patchedData['plan'] ?? '') === 'premium' && ($patchedData['status'] ?? '') === 'active' && ($patchedData['subStatus'] ?? '') === 'trial', json_encode([
        'plan' => $patchedData['plan'] ?? null,
        'status' => $patchedData['status'] ?? null,
        'sub' => $patchedData['subStatus'] ?? null,
    ]));
    $check('sk_ignores_other_organization_id', (int) ($patchedData['id'] ?? 0) === $pubOrgId);
    if ($beforeOther !== null) {
        $snap = $pdo->prepare('SELECT barangay, municipality, plan, sub_status, status FROM organizations WHERE id = :id');
        $snap->execute(['id' => $otherOrg]);
        $afterOther = $snap->fetch() ?: [];
        $check('other_organization_unchanged', $afterOther == $beforeOther);
    }

    $again = om_req('GET', "$base/sk/organization", null, $owner);
    $check('sk_profile_persists', (om_data($again)['chairperson'] ?? '') === 'Updated Chair' && (om_data($again)['contact'] ?? '') === '09170001009', om_detail($again));

    $youthLogin = om_req('POST', "$base/auth/login", json_encode(['email' => $youthEmail, 'password' => $youthPassword]), $youth);
    $check('youth_login', $youthLogin['status'] === 200, om_detail($youthLogin));
    $youthAdmin = om_req('GET', "$base/admin/organizations", null, $youth);
    $check('youth_admin_orgs_forbidden', $youthAdmin['status'] === 403, om_detail($youthAdmin));
    $youthSk = om_req('PATCH', "$base/sk/organization", json_encode(['chairperson' => 'Nope']), $youth);
    $check('youth_sk_profile_forbidden', $youthSk['status'] === 403, om_detail($youthSk));

    $skLogin = om_req('POST', "$base/auth/login", json_encode(['email' => $skEmail, 'password' => $demoPassword]), $sk);
    $check('existing_sk_login', $skLogin['status'] === 200, om_detail($skLogin));
    $skAdmin = om_req('GET', "$base/admin/organizations", null, $sk);
    $check('sk_admin_orgs_forbidden', $skAdmin['status'] === 403, om_detail($skAdmin));
    $adminSk = om_req('GET', "$base/sk/organization", null, $admin2);
    $check('admin_sk_profile_forbidden', $adminSk['status'] === 403, om_detail($adminSk));

    $logFile = dirname(__DIR__) . '/logs/app.log';
    $logText = is_file($logFile) ? (string) file_get_contents($logFile) : '';
    $check('password_not_in_app_log', !str_contains($logText, $ownerPassword));
} finally {
    $cleanup();
    foreach ([$admin, $sk, $youth, $anon, $reg, $owner, $admin2 ?? null] as $jar) {
        if (is_string($jar) && $jar !== '' && is_file($jar)) {
            @unlink($jar);
        }
    }
    $left = 0;
    foreach ($created as $row) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM organizations WHERE id = :id');
        $stmt->execute(['id' => (int) $row['orgId']]);
        $left += (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $stmt->execute(['email' => (string) $row['userEmail']]);
        $left += (int) $stmt->fetchColumn();
    }
    $check('cleanup_removed_test_rows', $left === 0, "left={$left}");
}

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
