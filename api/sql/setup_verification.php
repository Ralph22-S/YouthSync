<?php
declare(strict_types=1);

/**
 * Phase 12 read-only HTTP verification.
 * Login, GET SK endpoints, logout. Does not INSERT/UPDATE/DELETE application data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = 'http://localhost/YouthSync_UI_Refresh/api';
$cookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'youthsync_setup_verify_' . bin2hex(random_bytes(4)) . '.txt';
$failed = [];

function req(string $name, string $method, string $url, ?string $json, string $cookie): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Origin: http://localhost:5173'];
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

function expect(array $result, array $ok, array &$failed): void
{
    $pass = in_array($result['status'], $ok, true);
    $line = sprintf(
        '%s HTTP %d %s',
        $result['name'],
        $result['status'],
        $pass ? 'OK' : 'FAIL expected ' . implode('|', $ok)
    );
    echo $line . PHP_EOL;
    if (!$pass) {
        $failed[] = $line;
        echo substr($result['body'], 0, 240) . PHP_EOL;
    }
    if (str_contains($result['body'], 'password_hash') || str_contains($result['body'], '$2y$')) {
        $failed[] = $result['name'] . ' leaked a password hash';
        echo "HASH_LEAK\n";
    }
}

$anon = req('auth_me_anonymous', 'GET', $base . '/auth/me', null, $cookie);
expect($anon, [401], $failed);

$login = req(
    'login_sk1',
    'POST',
    $base . '/auth/login',
    json_encode(['email' => 'sk1@demo.test', 'password' => 'YouthSync1!']),
    $cookie
);
expect($login, [200], $failed);

$me = req('auth_me', 'GET', $base . '/auth/me', null, $cookie);
expect($me, [200], $failed);

$gets = [
    ['youth', '/sk/youth?page=1&perPage=5'],
    ['users', '/sk/users?page=1&perPage=5'],
    ['programs', '/sk/programs?page=1&perPage=20'],
    ['events', '/sk/events?page=1&perPage=20'],
    ['assistance', '/sk/assistance?page=1&perPage=20'],
    ['assistance_types', '/sk/assistance-types'],
    ['applications', '/sk/applications?page=1&perPage=20'],
    ['notifications', '/sk/notifications?page=1&perPage=20'],
    ['dashboard', '/sk/dashboard'],
    ['reports_youth', '/sk/reports?type=youth'],
    ['subscription', '/sk/subscription'],
];

foreach ($gets as [$name, $path]) {
    expect(req($name, 'GET', $base . $path, null, $cookie), [200], $failed);
}

$programs = json_decode(req('programs_for_attendance', 'GET', $base . '/sk/programs?page=1&perPage=20', null, $cookie)['body'], true);
$programId = 0;
foreach (($programs['data']['items'] ?? []) as $row) {
    if (!empty($row['id'])) {
        $programId = (int) $row['id'];
        break;
    }
}
if ($programId > 0) {
    expect(
        req('attendance_list', 'GET', $base . '/sk/programs/' . $programId . '/attendance', null, $cookie),
        [200],
        $failed
    );
} else {
    echo "attendance_list SKIP no program id\n";
}

$logout = req('logout', 'POST', $base . '/auth/logout', '{}', $cookie);
expect($logout, [200], $failed);
$after = req('auth_me_after_logout', 'GET', $base . '/auth/me', null, $cookie);
expect($after, [401], $failed);

@unlink($cookie);

if ($failed) {
    echo "setup_verification FAILED " . count($failed) . "\n";
    exit(1);
}

echo "setup_verification OK\n";
