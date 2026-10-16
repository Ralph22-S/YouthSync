<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = 'http://localhost/YouthSync/api';
$cookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'youthsync_admin_http.txt';
$skCookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'youthsync_admin_sk_http.txt';
@unlink($cookie);
@unlink($skCookie);

function admin_req(string $name, string $method, string $url, ?string $json, string $cookie): array
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

function admin_ok(array $row, int $expect, ?string $code = null): bool
{
    if ($row['status'] !== $expect) {
        return false;
    }
    if ($code === null) {
        return true;
    }
    $decoded = json_decode($row['body'], true);
    return is_array($decoded) && (($decoded['error']['code'] ?? '') === $code || ($decoded['success'] ?? false) === true);
}

$failed = 0;
$check = static function (string $name, bool $ok) use (&$failed): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}\n";
    if (!$ok) {
        $failed++;
    }
};

$adminLogin = json_encode(['email' => 'admin@skmms.test', 'password' => 'YouthSync1!']);
$skLogin = json_encode(['email' => 'sk1@demo.test', 'password' => 'YouthSync1!']);

$r = admin_req('admin_login', 'POST', "$base/auth/login", $adminLogin, $cookie);
$check('admin_login', $r['status'] === 200 && str_contains($r['body'], 'SYSTEM_ADMIN'));

$r = admin_req('admin_me', 'GET', "$base/auth/me", null, $cookie);
$decoded = json_decode($r['body'], true);
$check(
    'admin_me',
    $r['status'] === 200
        && ($decoded['data']['role'] ?? '') === 'SYSTEM_ADMIN'
        && array_key_exists('organization', $decoded['data'] ?? [])
        && $decoded['data']['organization'] === null
);

$r = admin_req('admin_orgs', 'GET', "$base/admin/organizations", null, $cookie);
$orgs = json_decode($r['body'], true);
$check('admin_organizations', $r['status'] === 200 && isset($orgs['data']['items']));
$firstId = (int) ($orgs['data']['items'][0]['id'] ?? 0);

$r = admin_req('admin_users', 'GET', "$base/admin/users", null, $cookie);
$check('admin_users', $r['status'] === 200);

$r = admin_req('admin_activity', 'GET', "$base/admin/activity", null, $cookie);
$check('admin_activity', $r['status'] === 200);

$r = admin_req('admin_payment_transactions', 'GET', "$base/admin/payment-transactions", null, $cookie);
$check('admin_payment_transactions', $r['status'] === 200);

// The manual / offline payment ledger was removed: its routes are gone (router 404).
$r = admin_req('admin_manual_ledger_removed', 'GET', "$base/admin/payments", null, $cookie);
$check('admin_manual_ledger_route_404', $r['status'] === 404);

if ($firstId > 0) {
    $r = admin_req('admin_org_detail', 'GET', "$base/admin/organizations/{$firstId}", null, $cookie);
    $check('admin_organization_detail', $r['status'] === 200);
}

$r = admin_req('sk_blocked_from_admin', 'GET', "$base/admin/organizations", null, $skCookie);
$check('admin_requires_auth', $r['status'] === 401);

$r = admin_req('sk_login', 'POST', "$base/auth/login", $skLogin, $skCookie);
$check('sk_login_still_works', $r['status'] === 200 && str_contains($r['body'], 'SK_OFFICIAL'));

$r = admin_req('sk_me', 'GET', "$base/auth/me", null, $skCookie);
$skMe = json_decode($r['body'], true);
$check('sk_me_has_organization', $r['status'] === 200 && isset($skMe['data']['organization']['id']));

$r = admin_req('sk_cannot_admin', 'GET', "$base/admin/organizations", null, $skCookie);
$check('sk_cannot_use_admin', $r['status'] === 403);

$r = admin_req('youth_not_admin', 'POST', "$base/auth/login", json_encode(['email' => 'youth1@demo.test', 'password' => 'YouthSync1!']), $cookie);
$check('youth_rejected', $r['status'] === 401 || $r['status'] === 403);

$r = admin_req('admin_logout', 'POST', "$base/auth/logout", '{}', $cookie);
$check('admin_logout', $r['status'] === 200);

$r = admin_req('admin_me_after_logout', 'GET', "$base/auth/me", null, $cookie);
$check('admin_me_after_logout', $r['status'] === 401);

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
