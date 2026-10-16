<?php
declare(strict_types=1);

/**
 * Safe SK account profile check.
 *
 * Creates one organization and owner, then deletes only those rows.
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
$ownerPassword = 'SkAcctTest1!';
$stamp = date('YmdHis');

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';
$pdo = youthsync_pdo();

$failed = 0;
$orgId = 0;
$userId = 0;
$orgEmail = "sk-acct-org-{$stamp}@example.test";
$ownerEmail = "sk-acct-owner-{$stamp}@example.test";
$renamedEmail = "sk-acct-renamed-{$stamp}@example.test";

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

function acct_req(string $method, string $url, ?string $json, string $cookie): array
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

function acct_data(array $row): array
{
    $data = $row['json']['data'] ?? null;
    return is_array($data) ? $data : [];
}

function acct_detail(array $row): string
{
    $code = (string) ($row['json']['error']['code'] ?? '');
    $message = (string) ($row['json']['error']['message'] ?? '');
    return 'status=' . $row['status'] . ($code !== '' ? " code={$code}" : '') . ' ' . $message;
}

function acct_jar(): string
{
    $path = tempnam(sys_get_temp_dir(), 'ysacct');
    return $path === false ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ysacct-' . bin2hex(random_bytes(4)) : $path;
}

$admin = acct_jar();
$sk = acct_jar();
$owner = acct_jar();
$youth = acct_jar();
$anon = acct_jar();

$demoSk = $pdo->prepare('SELECT id, first_name, last_name, email, status, role_id FROM users WHERE email = :email LIMIT 1');
$demoSk->execute(['email' => $skEmail]);
$demoBefore = $demoSk->fetch(PDO::FETCH_ASSOC) ?: [];

try {
    $anonGet = acct_req('GET', "$base/sk/account", null, $anon);
    $check('anonymous_account_rejected', $anonGet['status'] === 401, acct_detail($anonGet));

    $adminLogin = acct_req('POST', "$base/auth/login", json_encode(['email' => $adminEmail, 'password' => $demoPassword]), $admin);
    $check('admin_login', $adminLogin['status'] === 200, acct_detail($adminLogin));
    $adminMe = acct_req('GET', "$base/auth/me", null, $admin);
    $check('admin_me', $adminMe['status'] === 200 && (acct_data($adminMe)['role'] ?? '') === 'SYSTEM_ADMIN', acct_detail($adminMe));
    $adminAccount = acct_req('GET', "$base/sk/account", null, $admin);
    $check('admin_sk_account_forbidden', $adminAccount['status'] === 403, acct_detail($adminAccount));

    $created = acct_req('POST', "$base/admin/organizations", json_encode([
        'barangay' => "Acct{$stamp}",
        'municipality' => 'Accountville',
        'province' => 'Laguna',
        'chairperson' => 'Account Chair',
        'email' => $orgEmail,
        'contact' => '09170004401',
        'ownerName' => 'Account Owner',
        'ownerEmail' => $ownerEmail,
        'ownerPassword' => $ownerPassword,
    ]), $admin);
    $orgId = (int) (acct_data($created)['id'] ?? 0);
    $check('created_test_organization', $created['status'] === 201 && $orgId > 0, acct_detail($created));
    if ($orgId > 0) {
        $idStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $idStmt->execute(['email' => $ownerEmail]);
        $userId = (int) $idStmt->fetchColumn();
    }

    $youthLogin = acct_req('POST', "$base/auth/login", json_encode(['email' => $youthEmail, 'password' => $youthPassword]), $youth);
    $check('youth_login', $youthLogin['status'] === 200, acct_detail($youthLogin));
    $youthMe = acct_req('GET', "$base/auth/me", null, $youth);
    $check('youth_me', $youthMe['status'] === 200 && (acct_data($youthMe)['role'] ?? '') === 'YOUTH', acct_detail($youthMe));
    $youthAccount = acct_req('PATCH', "$base/sk/account", json_encode(['name' => 'Nope Person', 'email' => 'nope@example.test']), $youth);
    $check('youth_sk_account_forbidden', $youthAccount['status'] === 403, acct_detail($youthAccount));

    $skLogin = acct_req('POST', "$base/auth/login", json_encode(['email' => $skEmail, 'password' => $demoPassword]), $sk);
    $check('existing_sk_login', $skLogin['status'] === 200, acct_detail($skLogin));

    $ownerLogin = acct_req('POST', "$base/auth/login", json_encode(['email' => $ownerEmail, 'password' => $ownerPassword]), $owner);
    $check('owner_login', $ownerLogin['status'] === 200, acct_detail($ownerLogin));
    $loaded = acct_req('GET', "$base/sk/account", null, $owner);
    $loadedData = acct_data($loaded);
    $check(
        'sk_get_own_account',
        $loaded['status'] === 200
            && (int) ($loadedData['id'] ?? 0) === $userId
            && ($loadedData['email'] ?? '') === $ownerEmail
            && ($loadedData['name'] ?? '') === 'Account Owner'
            && !str_contains((string) $loaded['body'], 'password_hash'),
        acct_detail($loaded)
    );

    $invalid = acct_req('PATCH', "$base/sk/account", json_encode(['name' => 'Onlyone', 'email' => 'not-an-email']), $owner);
    $check('invalid_account_rejected', $invalid['status'] === 422, acct_detail($invalid));

    $duplicate = acct_req('PATCH', "$base/sk/account", json_encode(['name' => 'Account Owner', 'email' => $skEmail]), $owner);
    $check('duplicate_email_rejected', $duplicate['status'] === 409, acct_detail($duplicate));

    $beforeHash = '';
    $beforeRole = '';
    $beforeStatus = '';
    $beforeOrg = 0;
    if ($userId > 0) {
        $rowStmt = $pdo->prepare(
            'SELECT u.password_hash, u.status, r.code, ou.organization_id
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             INNER JOIN organization_users ou ON ou.user_id = u.id
             WHERE u.id = :id'
        );
        $rowStmt->execute(['id' => $userId]);
        $before = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $beforeHash = (string) ($before['password_hash'] ?? '');
        $beforeRole = (string) ($before['code'] ?? '');
        $beforeStatus = (string) ($before['status'] ?? '');
        $beforeOrg = (int) ($before['organization_id'] ?? 0);
    }

    $patched = acct_req('PATCH', "$base/sk/account", json_encode([
        'name' => 'Updated Owner',
        'email' => $renamedEmail,
        'user_id' => (int) ($demoBefore['id'] ?? 0),
        'organization_id' => 2,
        'role' => 'SYSTEM_ADMIN',
        'status' => 'inactive',
        'youth_id' => 16,
        'password' => 'HackedPass1!',
        'password_hash' => 'plaintext-hash',
    ]), $owner);
    $patchedData = acct_data($patched);
    $check(
        'sk_patch_own_account',
        $patched['status'] === 200
            && ($patchedData['name'] ?? '') === 'Updated Owner'
            && ($patchedData['email'] ?? '') === $renamedEmail
            && (int) ($patchedData['id'] ?? 0) === $userId
            && !str_contains((string) $patched['body'], 'password_hash'),
        acct_detail($patched)
    );

    $again = acct_req('GET', "$base/sk/account", null, $owner);
    $check(
        'saved_account_persists',
        $again['status'] === 200 && (acct_data($again)['name'] ?? '') === 'Updated Owner' && (acct_data($again)['email'] ?? '') === $renamedEmail,
        acct_detail($again)
    );

    if ($userId > 0) {
        $rowStmt = $pdo->prepare(
            'SELECT u.password_hash, u.status, u.email, r.code, ou.organization_id
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             INNER JOIN organization_users ou ON ou.user_id = u.id
             WHERE u.id = :id'
        );
        $rowStmt->execute(['id' => $userId]);
        $after = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $check('role_unchanged', ($after['code'] ?? '') === $beforeRole && $beforeRole === 'SK_OFFICIAL');
        $check('status_unchanged', ($after['status'] ?? '') === $beforeStatus && $beforeStatus === 'active');
        $check('organization_unchanged', (int) ($after['organization_id'] ?? 0) === $beforeOrg && $beforeOrg === $orgId);
        $check('password_hash_unchanged', ($after['password_hash'] ?? '') === $beforeHash && $beforeHash !== '' && password_verify($ownerPassword, (string) $after['password_hash']));
        $check('email_saved_in_database', ($after['email'] ?? '') === $renamedEmail);
    }

    $demoSk->execute(['email' => $skEmail]);
    $demoAfter = $demoSk->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(
        'spoofed_user_id_did_not_change_other_account',
        ($demoAfter['first_name'] ?? null) === ($demoBefore['first_name'] ?? null)
            && ($demoAfter['last_name'] ?? null) === ($demoBefore['last_name'] ?? null)
            && ($demoAfter['email'] ?? null) === $skEmail
    );

    $relogin = acct_req('POST', "$base/auth/login", json_encode(['email' => $renamedEmail, 'password' => $ownerPassword]), acct_jar());
    $check('password_still_authenticates', $relogin['status'] === 200, acct_detail($relogin));
    $oldLogin = acct_req('POST', "$base/auth/login", json_encode(['email' => $ownerEmail, 'password' => $ownerPassword]), acct_jar());
    $check('old_email_no_longer_logs_in', $oldLogin['status'] === 401, acct_detail($oldLogin));
} finally {
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM organization_users WHERE user_id = :id')->execute(['id' => $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :email')->execute(['id' => $userId, 'email' => $renamedEmail]);
        $leftUser = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id');
        $leftUser->execute(['id' => $userId]);
        $check('cleanup_user', (int) $leftUser->fetchColumn() === 0);
    }
    if ($orgId > 0) {
        $pdo->prepare('DELETE FROM organization_users WHERE organization_id = :id')->execute(['id' => $orgId]);
        $pdo->prepare('DELETE FROM activity_logs WHERE organization_id = :id')->execute(['id' => $orgId]);
        $pdo->prepare('DELETE FROM organizations WHERE id = :id AND email = :email')->execute(['id' => $orgId, 'email' => $orgEmail]);
        $leftOrg = $pdo->prepare('SELECT COUNT(*) FROM organizations WHERE id = :id');
        $leftOrg->execute(['id' => $orgId]);
        $check('cleanup_organization', (int) $leftOrg->fetchColumn() === 0);
    }
    foreach ([$admin, $sk, $owner, $youth, $anon] as $jar) {
        if (is_file($jar)) {
            @unlink($jar);
        }
    }
}

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
