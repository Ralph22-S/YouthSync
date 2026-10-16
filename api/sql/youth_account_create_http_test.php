<?php
declare(strict_types=1);

/**
 * Safe check for one-time Youth temporary passwords.
 *
 * Creates one YmgtAcct youth and user, then deletes only those rows.
 * Does not change existing account passwords.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = 'http://localhost/YouthSync/api';
$adminEmail = 'admin@skmms.test';
$skEmail = 'sk1@demo.test';
$sk2Email = 'sk2@demo.test';
$demoPassword = 'YouthSync1!';
$youthEmail = 'youth-auth-test@example.test';
$youthPassword = 'YouthAuthTest1!';
$stamp = date('YmdHis');
$accountEmail = 'ymgt-acct-' . $stamp . '@example.test';

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';
$pdo = youthsync_pdo();

$failed = 0;
$createdYouthId = 0;
$createdUserId = 0;
$temporary = '';
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

function yac_req(string $method, string $url, ?string $json, string $cookie): array
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

function yac_detail(array $row): string
{
    $code = (string) ($row['json']['error']['code'] ?? '');
    $message = (string) ($row['json']['error']['message'] ?? '');
    $snippet = $message !== '' ? $message : substr(preg_replace('/\s+/', ' ', (string) $row['body']) ?? '', 0, 160);
    return 'status=' . $row['status'] . ($code !== '' ? " code={$code}" : '') . " {$snippet}";
}

function yac_data(array $row): array
{
    $data = $row['json']['data'] ?? null;
    return is_array($data) ? $data : [];
}

function yac_jar(string $name): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . '-' . getmypid() . '.txt';
    @unlink($path);
    return $path;
}

function yac_youth(string $last, string $email): array
{
    return [
        'firstName' => 'YmgtAcct',
        'lastName' => $last,
        'birthDate' => '2006-06-06',
        'gender' => 'Male',
        'address' => 'Ymgt account test address',
        'contact' => '09181230000',
        'civilStatus' => 'Single',
        'educationStatus' => 'Currently Studying',
        'education' => 'Senior High School',
        'employment' => 'Student',
        'interests' => ['Community service'],
        'createAccount' => true,
        'accountEmail' => $email,
    ];
}

$sk = yac_jar('yac-sk');
$sk2 = yac_jar('yac-sk2');
$admin = yac_jar('yac-admin');
$youth = yac_jar('yac-youth');
$created = yac_jar('yac-created');
$wrong = yac_jar('yac-wrong');
$anon = yac_jar('yac-anon');

try {
    $body = json_encode(yac_youth('Anon' . $stamp, $accountEmail));
    $r = yac_req('POST', "$base/sk/youth", $body, $anon);
    $check('anonymous_create_rejected', $r['status'] === 401, yac_detail($r));

    $r = yac_req('POST', "$base/auth/login", json_encode(['email' => $adminEmail, 'password' => $demoPassword]), $admin);
    $check('admin_login', $r['status'] === 200 && (yac_data($r)['role'] ?? '') === 'SYSTEM_ADMIN', yac_detail($r));
    $r = yac_req('GET', "$base/auth/me", null, $admin);
    $check('admin_me', $r['status'] === 200 && (yac_data($r)['role'] ?? '') === 'SYSTEM_ADMIN', yac_detail($r));
    $r = yac_req('POST', "$base/sk/youth", $body, $admin);
    $check('admin_cannot_create_youth', $r['status'] === 403, yac_detail($r));

    $r = yac_req('POST', "$base/auth/login", json_encode(['email' => $youthEmail, 'password' => $youthPassword]), $youth);
    $check('existing_youth_login', $r['status'] === 200 && (yac_data($r)['role'] ?? '') === 'YOUTH', yac_detail($r));
    $r = yac_req('GET', "$base/auth/me", null, $youth);
    $check('existing_youth_me', $r['status'] === 200 && (yac_data($r)['role'] ?? '') === 'YOUTH', yac_detail($r));
    $r = yac_req('POST', "$base/sk/youth", $body, $youth);
    $check('youth_cannot_create_youth', $r['status'] === 403, yac_detail($r));
    $r = yac_req('GET', "$base/sk/youth", null, $youth);
    $check('youth_sk_routes_forbidden', $r['status'] === 403, yac_detail($r));
    $r = yac_req('GET', "$base/youth/profile", null, $admin);
    $check('admin_youth_route_forbidden', $r['status'] === 403, yac_detail($r));
    $r = yac_req('GET', "$base/youth/profile", null, $sk);
    $check('sk_youth_route_forbidden_before_login', $r['status'] === 401, yac_detail($r));

    $r = yac_req('POST', "$base/auth/login", json_encode(['email' => $skEmail, 'password' => $demoPassword]), $sk);
    $skMe = yac_req('GET', "$base/auth/me", null, $sk);
    $skOrg = (int) (yac_data($skMe)['organization']['id'] ?? 0);
    $check('sk_login', $r['status'] === 200 && (yac_data($skMe)['role'] ?? '') === 'SK_OFFICIAL' && $skOrg > 0, yac_detail($r));
    $r = yac_req('GET', "$base/youth/profile", null, $sk);
    $check('sk_youth_route_forbidden', $r['status'] === 403, yac_detail($r));

    $r = yac_req('POST', "$base/sk/youth", json_encode(yac_youth('Rec' . $stamp, $accountEmail)), $sk);
    $data = yac_data($r);
    $temporary = (string) ($data['temporary_password'] ?? $data['temporaryPassword'] ?? '');
    $user = is_array($data['user'] ?? null) ? $data['user'] : [];
    $createdYouthId = (int) ($data['youth']['id'] ?? 0);
    $createdUserId = (int) ($user['id'] ?? 0);
    $raw = (string) $r['body'];
    $check(
        'sk_create_returns_temporary_password',
        $r['status'] === 201 && $temporary !== '' && $createdYouthId > 0,
        $temporary === '' ? 'temporary_password missing' : yac_detail($r)
    );
    $check(
        'response_user_is_youth',
        (int) ($user['youth_id'] ?? 0) === $createdYouthId
            && strtoupper((string) ($user['role'] ?? '')) === 'YOUTH'
            && strtolower((string) ($user['email'] ?? '')) === $accountEmail,
        yac_detail($r)
    );
    $check('response_omits_password_hash', !str_contains($raw, 'password_hash'), 'response included password_hash');
    $check(
        'youth_record_scoped_to_sk_org',
        $createdYouthId > 0 && (int) $pdo->query('SELECT organization_id FROM youth WHERE id = ' . $createdYouthId)->fetchColumn() === $skOrg
    );

    $hash = '';
    $mustChange = null;
    if ($createdUserId > 0) {
        $rowStmt = $pdo->prepare('SELECT password_hash, must_change_password, youth_id FROM users WHERE id = :id AND email = :email');
        $rowStmt->execute(['id' => $createdUserId, 'email' => $accountEmail]);
        $row = $rowStmt->fetch() ?: [];
        $hash = (string) ($row['password_hash'] ?? '');
        $mustChange = $row['must_change_password'] ?? null;
    }
    $check('hash_is_not_plaintext', $hash !== '' && $hash !== $temporary && !str_contains($hash, $temporary));
    $check('password_verify_matches_hash', $temporary !== '' && password_verify($temporary, $hash));
    $check('must_change_password_set', (int) $mustChange === 1, 'must_change_password=' . var_export($mustChange, true));

    $shown = yac_req('GET', "$base/sk/youth/{$createdYouthId}", null, $sk);
    $check(
        'later_read_omits_temporary_password',
        $shown['status'] === 200 && $temporary !== '' && !str_contains((string) $shown['body'], $temporary),
        yac_detail($shown)
    );

    $login = yac_req('POST', "$base/auth/login", json_encode(['email' => $accountEmail, 'password' => $temporary]), $created);
    $loginData = yac_data($login);
    $check(
        'temporary_password_authenticates',
        $login['status'] === 200 && ($loginData['role'] ?? '') === 'YOUTH',
        yac_detail($login)
    );
    $me = yac_req('GET', "$base/auth/me", null, $created);
    $check(
        'new_youth_must_change_password',
        $me['status'] === 200
            && (yac_data($me)['role'] ?? '') === 'YOUTH'
            && (yac_data($me)['user']['must_change_password'] ?? false) === true,
        yac_detail($me)
    );
    $bad = yac_req('POST', "$base/auth/login", json_encode(['email' => $accountEmail, 'password' => $temporary . 'x']), $wrong);
    $check('wrong_password_rejected', $bad['status'] === 401, yac_detail($bad));

    $r = yac_req('POST', "$base/auth/login", json_encode(['email' => $sk2Email, 'password' => $demoPassword]), $sk2);
    $sk2Org = (int) (yac_data(yac_req('GET', "$base/auth/me", null, $sk2))['organization']['id'] ?? 0);
    $check('sk2_other_org', $r['status'] === 200 && $sk2Org > 0 && $sk2Org !== $skOrg, yac_detail($r));
    $r = yac_req('GET', "$base/sk/youth/{$createdYouthId}", null, $sk2);
    $check('other_org_cannot_read_youth', $r['status'] === 404, yac_detail($r));
    $r = yac_req('PATCH', "$base/sk/youth/{$createdYouthId}", json_encode(['firstName' => 'Hijack']), $sk2);
    $check('other_org_cannot_update_youth', $r['status'] === 404, yac_detail($r));

    $logged = false;
    if ($temporary !== '') {
        $logStmt = $pdo->prepare(
            'SELECT description, action, category, entity_type FROM activity_logs
             WHERE youth_id = :youth OR (entity_type = :type AND entity_id = :entity)'
        );
        $logStmt->execute(['youth' => $createdYouthId, 'type' => 'youth', 'entity' => $createdYouthId]);
        foreach ($logStmt->fetchAll() as $entry) {
            $blob = json_encode($entry);
            if (is_string($blob) && str_contains($blob, $temporary)) {
                $logged = true;
            }
        }
        $appLog = dirname(__DIR__) . '/logs/app.log';
        $logText = is_file($appLog) ? (string) file_get_contents($appLog) : '';
        $check('temporary_password_not_in_app_log', !str_contains($logText, $temporary), 'plaintext found in app.log');
    }
    $check('temporary_password_not_in_activity_logs', $temporary !== '' && !$logged, 'plaintext found in activity_logs');

    $r = yac_req('POST', "$base/auth/register", json_encode([
        'barangay' => 'ShouldNotRegister' . $stamp,
        'municipality' => 'Town',
        'province' => 'Laguna',
        'chairperson' => 'Nobody',
        'email' => 'skip' . $stamp . '@example.test',
        'contact' => '09180000000',
        'ownerName' => 'Nobody',
        'ownerEmail' => 'skipowner' . $stamp . '@example.test',
        'ownerPassword' => 'NotUsed1!',
        'confirmPassword' => 'NotUsed1!',
        'acceptTerms' => false,
        'acceptPrivacy' => false,
    ]), $anon);
    $check('public_registration_unchanged_validation', $r['status'] === 422, yac_detail($r));
} finally {
    if ($createdYouthId > 0) {
        $deleted = yac_req('DELETE', "$base/sk/youth/{$createdYouthId}", null, $sk);
        $check('cleanup_created_youth', $deleted['status'] === 200, yac_detail($deleted));
    }
    if ($createdYouthId > 0) {
        $leftYouth = $pdo->prepare('SELECT COUNT(*) FROM youth WHERE id = :id');
        $leftYouth->execute(['id' => $createdYouthId]);
        $check('cleanup_youth_row_gone', (int) $leftYouth->fetchColumn() === 0);
    }
    if ($createdUserId > 0) {
        $leftUser = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id AND email = :email');
        $leftUser->execute(['id' => $createdUserId, 'email' => $accountEmail]);
        $remaining = (int) $leftUser->fetchColumn();
        if ($remaining > 0) {
            $pdo->prepare('DELETE FROM organization_users WHERE user_id = :id')->execute(['id' => $createdUserId]);
            $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :email')->execute([
                'id' => $createdUserId,
                'email' => $accountEmail,
            ]);
            $leftUser->execute(['id' => $createdUserId, 'email' => $accountEmail]);
            $remaining = (int) $leftUser->fetchColumn();
        }
        $check('cleanup_user_row_gone', $remaining === 0);
    }
    foreach ([$sk, $sk2, $admin, $youth, $created, $wrong, $anon] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
