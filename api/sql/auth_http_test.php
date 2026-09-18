<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = 'http://localhost/YouthSync_UI_Refresh/api';
$cookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'youthsync_phase1.txt';
@unlink($cookie);

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
        CURLOPT_TIMEOUT => 15,
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
        return ['name' => $name, 'status' => 0, 'body' => $err, 'sid' => null];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    curl_close($ch);
    $sid = null;
    if (preg_match('/^Set-Cookie:\s*youthsync_session=([^;]+)/mi', $header, $m)) {
        $sid = $m[1];
    }
    return ['name' => $name, 'status' => $status, 'body' => $body, 'sid' => $sid, 'header' => $header];
}

$tests = [];
$loginOk = json_encode(['email' => 'sk1@demo.test', 'password' => 'YouthSync1!']);

$r = req('valid_login', 'POST', "$base/auth/login", $loginOk, $cookie);
$sid1 = $r['sid'];
$tests[] = $r;

$r = req('me_authenticated', 'GET', "$base/auth/me", null, $cookie);
$tests[] = $r;

$r = req('wrong_password', 'POST', "$base/auth/login", json_encode(['email' => 'sk1@demo.test', 'password' => 'wrong-password']), $cookie);
$tests[] = $r;

$r = req('unknown_email', 'POST', "$base/auth/login", json_encode(['email' => 'nobody@demo.test', 'password' => 'YouthSync1!']), $cookie);
$tests[] = $r;

$r = req('inactive_user', 'POST', "$base/auth/login", json_encode(['email' => 'inactive.sk@demo.test', 'password' => 'YouthSync1!']), $cookie);
$tests[] = $r;

$r = req('non_sk_role', 'POST', "$base/auth/login", json_encode(['email' => 'youth1@demo.test', 'password' => 'YouthSync1!']), $cookie);
$tests[] = $r;

$r = req('inactive_org', 'POST', "$base/auth/login", json_encode(['email' => 'inactive.org.sk@demo.test', 'password' => 'YouthSync1!']), $cookie);
$tests[] = $r;

$r = req('missing_membership', 'POST', "$base/auth/login", json_encode(['email' => 'orphan.sk@demo.test', 'password' => 'YouthSync1!']), $cookie);
$tests[] = $r;

$r = req('pending_org', 'POST', "$base/auth/login", json_encode(['email' => 'pending.sk@demo.test', 'password' => 'YouthSync1!']), $cookie);
$tests[] = $r;

$cookie2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'youthsync_phase1_b.txt';
@unlink($cookie2);
$r = req('login_for_session', 'POST', "$base/auth/login", $loginOk, $cookie2);
$sidBefore = $r['sid'];
$tests[] = $r;
$r = req('login_again_regen', 'POST', "$base/auth/login", $loginOk, $cookie2);
$sidAfter = $r['sid'];
$tests[] = $r;

$r = req('org_from_session', 'GET', "$base/auth/me", json_encode(['organization_id' => 999]), $cookie2);
$tests[] = $r;

$r = req('change_pw_wrong', 'POST', "$base/auth/change-password", json_encode(['current_password' => 'nope', 'new_password' => 'NewPass123']), $cookie2);
$tests[] = $r;

$r = req('change_pw_ok', 'POST', "$base/auth/change-password", json_encode(['current_password' => 'YouthSync1!', 'new_password' => 'NewPass123']), $cookie2);
$tests[] = $r;

$r = req('login_old_pw', 'POST', "$base/auth/login", $loginOk, $cookie2);
$tests[] = $r;

$r = req('login_new_pw', 'POST', "$base/auth/login", json_encode(['email' => 'sk1@demo.test', 'password' => 'NewPass123']), $cookie2);
$tests[] = $r;

$r = req('restore_pw', 'POST', "$base/auth/change-password", json_encode(['current_password' => 'NewPass123', 'new_password' => 'YouthSync1!']), $cookie2);
$tests[] = $r;

$r = req('logout', 'POST', "$base/auth/logout", null, $cookie2);
$tests[] = $r;

$r = req('me_after_logout', 'GET', "$base/auth/me", null, $cookie2);
$tests[] = $r;

$r = req('me_unauthenticated', 'GET', "$base/auth/me", null, sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'youthsync_empty.txt');
$tests[] = $r;

foreach ($tests as $t) {
    $hasHash = stripos($t['body'], 'password_hash') !== false;
    $hasSecret = stripos($t['body'], 'DB_PASSWORD') !== false || str_contains($t['body'], 'root@');
    echo $t['name'] . "\t" . $t['status'] . "\thash=" . ($hasHash ? 'YES' : 'no') . "\tcreds=" . ($hasSecret ? 'YES' : 'no') . PHP_EOL;
    echo $t['body'] . PHP_EOL . PHP_EOL;
}

echo "session_before={$sidBefore}\nsession_after={$sidAfter}\nregen=" . (($sidBefore && $sidAfter && $sidBefore !== $sidAfter) ? 'yes' : 'no') . PHP_EOL;
echo "login_sid={$sid1}\n";
