<?php
declare(strict_types=1);

/**
 * Safe Youth authentication checks against the live YouthSync API.
 *
 * Creates or reuses only youth-auth-test@example.test.
 * Does not delete rows and does not change SYSTEM_ADMIN, SK_OFFICIAL,
 * or any other Youth account's password.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = 'http://localhost/YouthSync/api';
$dedicatedEmail = 'youth-auth-test@example.test';
$p1 = 'YouthAuthTest1!';
$p2 = 'YouthAuthTest2!';
$p3 = 'YouthAuthTest3!';
$adminEmail = 'admin@skmms.test';
$adminPassword = 'YouthSync1!';
$skEmail = 'sk1@demo.test';
$skPassword = 'YouthSync1!';
$demoPassword = 'YouthSync1!';

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

function youth_req(string $method, string $url, ?string $json, string $cookie): array
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

function youth_detail(array $row): string
{
    $code = (string) ($row['json']['error']['code'] ?? '');
    $message = (string) ($row['json']['error']['message'] ?? '');
    $snippet = $message !== '' ? $message : substr(preg_replace('/\s+/', ' ', (string) $row['body']) ?? '', 0, 180);
    return 'status=' . $row['status'] . ($code !== '' ? " code={$code}" : '') . " {$snippet}";
}

function youth_jar(string $name): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . '.txt';
    @unlink($path);
    return $path;
}

function youth_data(array $row): array
{
    $data = $row['json']['data'] ?? null;
    return is_array($data) ? $data : [];
}

$orgId = (int) $pdo->query("SELECT id FROM organizations WHERE status = 'active' ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($orgId < 1) {
    fwrite(STDERR, "No active organization is available for the dedicated Youth account.\n");
    exit(1);
}

$roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'YOUTH' LIMIT 1")->fetchColumn();
if ($roleId < 1) {
    fwrite(STDERR, "YOUTH role is missing.\n");
    exit(1);
}

$userStmt = $pdo->prepare(
    'SELECT u.id, u.password_hash, u.youth_id, u.role_id, u.status, r.code AS role_code
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE u.email = :email
     LIMIT 1'
);
$userStmt->execute(['email' => $dedicatedEmail]);
$dedicated = $userStmt->fetch();

if ($dedicated === false) {
    $pdo->prepare(
        'INSERT INTO youth (
            organization_id, code, first_name, last_name, birth_date, gender, address, contact, email,
            archived, self_registered, priority_level, priority_score, added_at
         ) VALUES (
            :org, :code, :fn, :ln, :birth, :gender, :address, :contact, :email,
            0, 0, :level, 0, NOW()
         )'
    )->execute([
        'org' => $orgId,
        'code' => 'YAUTHTEST',
        'fn' => 'YouthAuth',
        'ln' => 'Test',
        'birth' => '2004-05-15',
        'gender' => 'Prefer not to say',
        'address' => 'Youth auth test address',
        'contact' => '09170000000',
        'email' => $dedicatedEmail,
        'level' => 'Medium',
    ]);
    $youthId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO users (email, password_hash, first_name, last_name, role_id, youth_id, status, must_change_password)
         VALUES (:email, :hash, :fn, :ln, :role, :youth, :status, 0)'
    )->execute([
        'email' => $dedicatedEmail,
        'hash' => password_hash($p1, PASSWORD_DEFAULT),
        'fn' => 'YouthAuth',
        'ln' => 'Test',
        'role' => $roleId,
        'youth' => $youthId,
        'status' => 'active',
    ]);
    $dedicatedUserId = (int) $pdo->lastInsertId();
    echo "CREATED dedicated Youth account {$dedicatedEmail} youth_id={$youthId} user_id={$dedicatedUserId}\n";
} else {
    if ((string) $dedicated['role_code'] !== 'YOUTH' || (string) $dedicated['status'] !== 'active' || (int) $dedicated['youth_id'] < 1) {
        fwrite(STDERR, "Dedicated email exists but is not an active YOUTH account. Stopping.\n");
        exit(1);
    }
    $dedicatedUserId = (int) $dedicated['id'];
    $youthId = (int) $dedicated['youth_id'];
    if (!password_verify($p1, (string) $dedicated['password_hash'])) {
        $pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id AND email = :email')
            ->execute([
                'hash' => password_hash($p1, PASSWORD_DEFAULT),
                'id' => $dedicatedUserId,
                'email' => $dedicatedEmail,
            ]);
        echo "REUSED dedicated Youth account {$dedicatedEmail}; restored its test password so this run can start.\n";
    } else {
        echo "REUSED dedicated Youth account {$dedicatedEmail} youth_id={$youthId} user_id={$dedicatedUserId}\n";
    }
}

$otherHashStmt = $pdo->prepare(
    "SELECT u.id, u.password_hash
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE r.code = 'YOUTH' AND u.email <> :email
     ORDER BY u.id ASC"
);
$otherHashStmt->execute(['email' => $dedicatedEmail]);
$otherHashes = [];
foreach ($otherHashStmt->fetchAll() as $row) {
    $otherHashes[(int) $row['id']] = (string) $row['password_hash'];
}

$demoEmail = null;
$demoStmt = $pdo->query(
    "SELECT u.email, u.password_hash
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE r.code = 'YOUTH' AND u.status = 'active' AND u.email <> " . $pdo->quote($dedicatedEmail)
);
foreach ($demoStmt->fetchAll() as $row) {
    if (password_verify($demoPassword, (string) $row['password_hash'])) {
        $demoEmail = (string) $row['email'];
        break;
    }
}
echo $demoEmail !== null
    ? "Existing Youth user {$demoEmail} matches the documented demo password and was not modified.\n"
    : "No other Youth account matched the documented demo password.\n";

$loginEmail = $dedicatedEmail;
$loginPassword = $p1;

$generic = 'If an account exists for that email, password reset instructions have been sent.';

$anon = youth_jar('youthsync_youth_anon');
$youth = youth_jar('youthsync_youth_session');
$sk = youth_jar('youthsync_youth_sk');
$admin = youth_jar('youthsync_youth_admin');
$resetJar = youth_jar('youthsync_youth_reset');
$reg = youth_jar('youthsync_youth_reg');

if ($demoEmail !== null) {
    $demoJar = youth_jar('youthsync_youth_demo');
    $demoUser = $pdo->prepare('SELECT youth_id FROM users WHERE email = :email LIMIT 1');
    $demoUser->execute(['email' => $demoEmail]);
    $demoYouthId = $demoUser->fetchColumn();
    $r = youth_req('POST', "$base/auth/login", json_encode(['email' => $demoEmail, 'password' => $demoPassword]), $demoJar);
    if ($demoYouthId === null || $demoYouthId === false || (int) $demoYouthId < 1) {
        $check('unlinked_demo_youth_rejected', $r['status'] === 403, youth_detail($r));
        $r = youth_req('GET', "$base/auth/me", null, $demoJar);
        $check('unlinked_demo_youth_has_no_session', $r['status'] === 401, youth_detail($r));
    } else {
        $check('linked_demo_youth_login', $r['status'] === 200 && (youth_data($r)['role'] ?? '') === 'YOUTH', youth_detail($r));
        $r = youth_req('POST', "$base/auth/logout", '{}', $demoJar);
        $check('linked_demo_youth_logout', $r['status'] === 200, youth_detail($r));
    }
}

$r = youth_req('POST', "$base/auth/login", json_encode(['email' => $loginEmail, 'password' => 'wrong-password']), $youth);
$check('wrong_youth_password_401', $r['status'] === 401 && ($r['json']['error']['code'] ?? '') === 'INVALID_CREDENTIALS', youth_detail($r));
$r = youth_req('GET', "$base/auth/me", null, $youth);
$check('wrong_password_creates_no_session', $r['status'] === 401, youth_detail($r));

$r = youth_req('POST', "$base/auth/login", json_encode(['email' => $loginEmail, 'password' => $loginPassword]), $youth);
$data = youth_data($r);
$check('correct_youth_password_200', $r['status'] === 200 && ($data['role'] ?? '') === 'YOUTH', youth_detail($r));

$r = youth_req('GET', "$base/auth/me", null, $youth);
$me = youth_data($r);
$ownYouthId = (int) ($me['youth']['id'] ?? 0);
$check('auth_me_youth', $r['status'] === 200 && ($me['role'] ?? '') === 'YOUTH' && $ownYouthId > 0, youth_detail($r));

$r = youth_req('GET', "$base/auth/me", null, $youth);
$me2 = youth_data($r);
$check('session_persists', $r['status'] === 200 && ($me2['role'] ?? '') === 'YOUTH' && (int) ($me2['youth']['id'] ?? 0) === $ownYouthId, youth_detail($r));

$r = youth_req('GET', "$base/youth/profile?youth_id=999999&organization_id=999999", null, $youth);
$profile = youth_data($r);
$check(
    'youth_guard_accepts_youth',
    $r['status'] === 200
        && ($profile['role'] ?? '') === 'YOUTH'
        && (int) ($profile['youth']['id'] ?? 0) === $ownYouthId
        && (int) ($profile['youth']['id'] ?? 0) !== 999999,
    youth_detail($r)
);

$r = youth_req('GET', "$base/youth/profile", null, $anon);
$check('youth_guard_rejects_anonymous', $r['status'] === 401, youth_detail($r));

$r = youth_req('GET', "$base/admin/organizations", null, $youth);
$check('youth_cannot_access_admin', $r['status'] === 403, youth_detail($r));
$r = youth_req('GET', "$base/sk/youth", null, $youth);
$check('youth_cannot_access_sk', $r['status'] === 403, youth_detail($r));

$r = youth_req('POST', "$base/auth/login", json_encode(['email' => $skEmail, 'password' => $skPassword]), $sk);
$skData = youth_data($r);
$skOrgId = (int) ($skData['organization']['id'] ?? 0);
$check('sk_login', $r['status'] === 200 && ($skData['role'] ?? '') === 'SK_OFFICIAL' && $skOrgId > 0, youth_detail($r));
$r = youth_req('GET', "$base/auth/me", null, $sk);
$check('sk_me', $r['status'] === 200 && (int) (youth_data($r)['organization']['id'] ?? 0) === $skOrgId, youth_detail($r));
$r = youth_req('GET', "$base/sk/youth?organization_id=999999", null, $sk);
$check('sk_guard_ignores_client_organization', $r['status'] === 200, youth_detail($r));
$r = youth_req('GET', "$base/admin/organizations", null, $sk);
$check('sk_cannot_access_admin', $r['status'] === 403, youth_detail($r));
$r = youth_req('GET', "$base/youth/profile", null, $sk);
$check('youth_guard_rejects_sk', $r['status'] === 403, youth_detail($r));

$r = youth_req('POST', "$base/auth/login", json_encode(['email' => $adminEmail, 'password' => $adminPassword]), $admin);
$adminData = youth_data($r);
$check(
    'admin_login',
    $r['status'] === 200 && ($adminData['role'] ?? '') === 'SYSTEM_ADMIN' && array_key_exists('organization', $adminData) && $adminData['organization'] === null,
    youth_detail($r)
);
$r = youth_req('GET', "$base/auth/me", null, $admin);
$adminMe = youth_data($r);
$check(
    'admin_me_organization_null',
    $r['status'] === 200 && ($adminMe['role'] ?? '') === 'SYSTEM_ADMIN' && array_key_exists('organization', $adminMe) && $adminMe['organization'] === null,
    youth_detail($r)
);
$r = youth_req('GET', "$base/admin/organizations", null, $admin);
$check('admin_guard_accepts_admin', $r['status'] === 200, youth_detail($r));
$r = youth_req('GET', "$base/youth/profile", null, $admin);
$check('youth_guard_rejects_admin', $r['status'] === 403, youth_detail($r));

$r = youth_req('POST', "$base/auth/logout", '{}', $youth);
$check('youth_logout', $r['status'] === 200, youth_detail($r));
$r = youth_req('GET', "$base/auth/me", null, $youth);
$check('youth_me_after_logout_401', $r['status'] === 401, youth_detail($r));
$r = youth_req('GET', "$base/youth/profile", null, $youth);
$check('invalidated_session_cannot_access_youth', $r['status'] === 401, youth_detail($r));

$dedicatedJar = youth_jar('youthsync_youth_dedicated');
$r = youth_req('POST', "$base/auth/login", json_encode(['email' => $dedicatedEmail, 'password' => $p1]), $dedicatedJar);
$check('dedicated_login', $r['status'] === 200 && (youth_data($r)['role'] ?? '') === 'YOUTH', youth_detail($r));
$beforeHashes = [];
foreach ($otherHashes as $id => $hash) {
    $beforeHashes[$id] = $hash;
}
$r = youth_req(
    'POST',
    "$base/auth/change-password",
    json_encode(['currentPassword' => $p1, 'newPassword' => $p2, 'youth_id' => 1, 'organization_id' => 1]),
    $dedicatedJar
);
$check('youth_change_password', $r['status'] === 200, youth_detail($r));
$unchanged = true;
$afterStmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
foreach ($beforeHashes as $id => $hash) {
    $afterStmt->execute(['id' => $id]);
    if ((string) $afterStmt->fetchColumn() !== $hash) {
        $unchanged = false;
    }
}
$check('change_password_does_not_touch_other_youth', $unchanged);
$ownStmt = $pdo->prepare('SELECT password_hash, role_id, youth_id FROM users WHERE id = :id');
$ownStmt->execute(['id' => $dedicatedUserId]);
$ownAfter = $ownStmt->fetch();
$check(
    'change_password_updates_only_hash',
    is_array($ownAfter)
        && password_verify($p2, (string) $ownAfter['password_hash'])
        && !password_verify($p1, (string) $ownAfter['password_hash'])
        && (int) $ownAfter['role_id'] === $roleId
        && (int) $ownAfter['youth_id'] === $youthId
);
$r = youth_req('POST', "$base/auth/logout", '{}', $dedicatedJar);
$check('dedicated_logout_after_change', $r['status'] === 200, youth_detail($r));
$r = youth_req('POST', "$base/auth/login", json_encode(['email' => $dedicatedEmail, 'password' => $p1]), $dedicatedJar);
$check('old_password_rejected_after_change', $r['status'] === 401, youth_detail($r));
$r = youth_req('POST', "$base/auth/login", json_encode(['email' => $dedicatedEmail, 'password' => $p2]), $dedicatedJar);
$check('new_password_accepted_after_change', $r['status'] === 200, youth_detail($r));
$r = youth_req('POST', "$base/auth/logout", '{}', $dedicatedJar);

$unknown = youth_req('POST', "$base/auth/forgot-password", json_encode(['email' => 'nobody-youth-auth@example.test']), $resetJar);
$known = youth_req('POST', "$base/auth/forgot-password", json_encode(['email' => $dedicatedEmail]), $resetJar);
$unknownMessage = (string) (youth_data($unknown)['message'] ?? '');
$knownMessage = (string) (youth_data($known)['message'] ?? '');
$check(
    'forgot_password_generic_success',
    $unknown['status'] === 200
        && $known['status'] === 200
        && $unknownMessage === $generic
        && $knownMessage === $generic
        && !array_key_exists('token', youth_data($unknown))
        && !array_key_exists('token', youth_data($known))
        && !str_contains($unknown['body'], 'token_hash')
        && !str_contains($known['body'], 'token_hash'),
    youth_detail($known['status'] === 200 ? $unknown : $known)
);
$tokenMeta = $pdo->prepare(
    'SELECT CHAR_LENGTH(token_hash) AS hash_len, used_at
     FROM password_reset_tokens
     WHERE user_id = :id
     ORDER BY id DESC
     LIMIT 1'
);
$tokenMeta->execute(['id' => $dedicatedUserId]);
$tokenRow = $tokenMeta->fetch();
$check(
    'forgot_password_stores_hash_only',
    is_array($tokenRow) && (int) $tokenRow['hash_len'] === 64 && $tokenRow['used_at'] === null
);

$raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$hash = hash('sha256', $raw);
$pdo->prepare(
    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
     VALUES (:user_id, :hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
)->execute(['user_id' => $dedicatedUserId, 'hash' => $hash]);

$r = youth_req('POST', "$base/auth/reset-password", json_encode(['token' => $raw, 'new_password' => $p3]), $resetJar);
$check('reset_password_updates_hash', $r['status'] === 200, youth_detail($r));
$ownStmt->execute(['id' => $dedicatedUserId]);
$afterReset = $ownStmt->fetch();
$check(
    'reset_password_hash_is_new',
    is_array($afterReset) && password_verify($p3, (string) $afterReset['password_hash']) && !password_verify($p2, (string) $afterReset['password_hash'])
);
$r = youth_req('POST', "$base/auth/reset-password", json_encode(['token' => $raw, 'new_password' => 'YouthAuthTest4!']), $resetJar);
$check('reset_token_cannot_be_reused', $r['status'] === 400 && ($r['json']['error']['code'] ?? '') === 'RESET_INVALID', youth_detail($r));

$expiredRaw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$pdo->prepare(
    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
     VALUES (:user_id, :hash, :expires)'
)->execute([
    'user_id' => $dedicatedUserId,
    'hash' => hash('sha256', $expiredRaw),
    'expires' => '2000-01-01 00:00:00',
]);
$r = youth_req('POST', "$base/auth/reset-password", json_encode(['token' => $expiredRaw, 'new_password' => 'YouthAuthTest4!']), $resetJar);
$check('expired_reset_token_rejected', $r['status'] === 400 && ($r['json']['error']['code'] ?? '') === 'RESET_INVALID', youth_detail($r));

$r = youth_req('POST', "$base/auth/reset-password", json_encode(['token' => 'not-a-real-token', 'new_password' => 'YouthAuthTest4!']), $resetJar);
$check('wrong_reset_token_rejected', $r['status'] === 400 && ($r['json']['error']['code'] ?? '') === 'RESET_INVALID', youth_detail($r));

$ownStmt->execute(['id' => $dedicatedUserId]);
$still = $ownStmt->fetch();
$check('rejected_resets_leave_new_hash', is_array($still) && password_verify($p3, (string) $still['password_hash']));

$fresh = youth_jar('youthsync_youth_after_reset');
$r = youth_req('POST', "$base/auth/login", json_encode(['email' => $dedicatedEmail, 'password' => $p3]), $fresh);
$check('new_reset_password_can_authenticate', $r['status'] === 200 && (youth_data($r)['role'] ?? '') === 'YOUTH', youth_detail($r));
$r = youth_req('POST', "$base/auth/logout", '{}', $fresh);
$old = youth_jar('youthsync_youth_old_after_reset');
$r = youth_req('POST', "$base/auth/login", json_encode(['email' => $dedicatedEmail, 'password' => $p2]), $old);
$check('old_password_cannot_authenticate', $r['status'] === 401, youth_detail($r));
$r = youth_req('GET', "$base/auth/me", null, $old);
$check('old_password_creates_no_session', $r['status'] === 401, youth_detail($r));

$pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id AND email = :email')
    ->execute([
        'hash' => password_hash($p1, PASSWORD_DEFAULT),
        'id' => $dedicatedUserId,
        'email' => $dedicatedEmail,
    ]);
echo "Restored dedicated account password so the account can be reused. No other passwords were changed.\n";

$r = youth_req('POST', "$base/auth/logout", '{}', $sk);
$check('sk_logout', $r['status'] === 200, youth_detail($r));
$r = youth_req('GET', "$base/auth/me", null, $sk);
$check('sk_me_after_logout', $r['status'] === 401, youth_detail($r));

$r = youth_req('POST', "$base/auth/logout", '{}', $admin);
$check('admin_logout', $r['status'] === 200, youth_detail($r));
$r = youth_req('GET', "$base/auth/me", null, $admin);
$check('admin_me_after_logout', $r['status'] === 401, youth_detail($r));

$stamp = date('YmdHis');
$regBody = json_encode([
    'barangay' => 'AuthYouth' . $stamp,
    'municipality' => 'AuthTown',
    'province' => 'Laguna',
    'chairperson' => 'Auth Youth Chair',
    'email' => 'authyouth' . $stamp . '@example.test',
    'contact' => '09181234567',
    'ownerName' => 'Auth Youth Owner',
    'ownerEmail' => 'skauthyouth' . $stamp . '@example.test',
    'ownerPassword' => 'YouthAuthReg1!',
    'confirmPassword' => 'YouthAuthReg1!',
    'acceptTerms' => true,
    'acceptPrivacy' => true,
]);
$r = youth_req('POST', "$base/auth/register", $regBody, $reg);
$regData = youth_data($r);
$check(
    'public_registration_creates_pending_org',
    $r['status'] === 201 && ($regData['organization']['status'] ?? '') === 'pending' && (int) ($regData['organization']['id'] ?? 0) > 0,
    youth_detail($r)
);
$r = youth_req('GET', "$base/auth/me", null, $reg);
$check('public_registration_does_not_login', $r['status'] === 401, youth_detail($r));
$ownerEmail = 'skauthyouth' . $stamp . '@example.test';
$r = youth_req('POST', "$base/auth/login", json_encode(['email' => $ownerEmail, 'password' => 'YouthAuthReg1!']), $reg);
$check('pending_registration_cannot_login', $r['status'] === 403, youth_detail($r));
$r = youth_req('GET', "$base/auth/me", null, $reg);
$check('pending_registration_has_no_session', $r['status'] === 401, youth_detail($r));

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
