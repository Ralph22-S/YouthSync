<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = 'http://localhost/YouthSync_UI_Refresh/api';

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

function payload(array $t): array
{
    $decoded = json_decode($t['body'], true);
    return is_array($decoded) ? $decoded : [];
}

$sk1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p2_sk1.txt';
$sk2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p2_sk2.txt';
$sk4 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p2_sk4.txt';
$empty = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_p2_empty.txt';
@unlink($sk1);
@unlink($sk2);
@unlink($sk4);
@unlink($empty);

$login = static fn (string $email): string => json_encode(['email' => $email, 'password' => 'YouthSync1!']);

$tests = [];
$tests[] = req('unauth_list', 'GET', "$base/sk/youth", null, $empty);

$tests[] = req('login_sk1', 'POST', "$base/auth/login", $login('sk1@demo.test'), $sk1);
$tests[] = req('me_sk1', 'GET', "$base/auth/me", null, $sk1);
$tests[] = req('list_emptyish', 'GET', "$base/sk/youth", null, $sk1);

$youthBody = json_encode([
    'firstName' => 'PhaseTwo',
    'lastName' => 'Tester',
    'birthDate' => '2004-06-15',
    'gender' => 'Female',
    'address' => 'Purok 2, Barangay Ibaba del Norte',
    'contact' => '09170001111',
    'email' => 'phasetwo.tester@example.com',
    'interests' => ['Sports'],
    'skills' => [],
    'employment' => 'Student',
    'educationStatus' => 'Currently Studying',
    'education' => 'College',
    'organization_id' => 999,
    'orgId' => 4,
]);
$created = req('create', 'POST', "$base/sk/youth", $youthBody, $sk1);
$tests[] = $created;
$createdData = payload($created)['data']['youth'] ?? [];
$id = (int) ($createdData['id'] ?? 0);
$orgId = 1;

$tests[] = req('get', 'GET', "$base/sk/youth/{$id}", null, $sk1);
$tests[] = req('update', 'PATCH', "$base/sk/youth/{$id}", json_encode([
    'firstName' => 'PhaseTwo',
    'lastName' => 'Updated',
    'birthDate' => '2004-06-15',
    'gender' => 'Female',
    'address' => 'Purok 3, Barangay Ibaba del Norte',
    'contact' => '09170001111',
    'interests' => ['Sports', 'Technology'],
    'educationStatus' => 'Currently Studying',
    'education' => 'College',
    'employment' => 'Student',
    'organization_id' => 4,
]), $sk1);

$tests[] = req('invalid_required', 'POST', "$base/sk/youth", json_encode(['firstName' => '']), $sk1);
$tests[] = req('invalid_date', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'Bad',
    'lastName' => 'Date',
    'birthDate' => 'not-a-date',
    'address' => 'Somewhere',
    'contact' => '09170001111',
    'interests' => ['Sports'],
]), $sk1);
$tests[] = req('future_dob', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'Future',
    'lastName' => 'Kid',
    'birthDate' => '2099-01-01',
    'address' => 'Somewhere',
    'contact' => '09170001111',
    'interests' => ['Sports'],
]), $sk1);
$tests[] = req('bad_contact', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'Bad',
    'lastName' => 'Contact',
    'birthDate' => '2004-01-01',
    'address' => 'Somewhere',
    'contact' => '123',
    'interests' => ['Sports'],
]), $sk1);

$tests[] = req('duplicate', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'PhaseTwo',
    'lastName' => 'Updated',
    'birthDate' => '2004-06-15',
    'address' => 'Purok 3',
    'contact' => '09170001112',
    'interests' => ['Sports'],
]), $sk1);

$tests[] = req('archive', 'POST', "$base/sk/youth/{$id}/archive", null, $sk1);
$tests[] = req('list_hides_archived', 'GET', "$base/sk/youth", null, $sk1);
$tests[] = req('list_archived', 'GET', "$base/sk/youth?archived=true", null, $sk1);
$tests[] = req('restore', 'POST', "$base/sk/youth/{$id}/restore", null, $sk1);

$acct = req('create_account', 'POST', "$base/sk/youth", json_encode([
    'firstName' => 'With',
    'lastName' => 'Account',
    'birthDate' => '2003-02-02',
    'address' => 'Purok 1',
    'contact' => '09170002222',
    'interests' => ['Arts'],
    'createAccount' => true,
    'accountEmail' => 'with.account.phase2@example.com',
]), $sk1);
$tests[] = $acct;
$acctYouthId = (int) (payload($acct)['data']['youth']['id'] ?? 0);

$tests[] = req('login_sk2', 'POST', "$base/auth/login", $login('sk2@demo.test'), $sk2);
$tests[] = req('cross_get', 'GET', "$base/sk/youth/{$id}", null, $sk2);
$tests[] = req('cross_update', 'PATCH', "$base/sk/youth/{$id}", json_encode([
    'firstName' => 'Hacker',
    'lastName' => 'Nope',
    'birthDate' => '2004-06-15',
    'address' => 'Other org',
    'contact' => '09170003333',
    'interests' => ['Sports'],
]), $sk2);
$tests[] = req('cross_delete', 'DELETE', "$base/sk/youth/{$id}", null, $sk2);

$tests[] = req('login_sk4', 'POST', "$base/auth/login", $login('sk4@demo.test'), $sk4);
$tests[] = req('csv_free_denied', 'POST', "$base/sk/youth/import", json_encode([
    ['firstName' => 'Csv', 'lastName' => 'Free', 'birthDate' => '2001-01-01', 'address' => 'x', 'contact' => '09170004444', 'interests' => ['Community service']],
]), $sk4);

$tests[] = req('csv_ok', 'POST', "$base/sk/youth/import", json_encode([
    [
        'firstName' => 'Import',
        'lastName' => 'One',
        'birthDate' => '2002-03-03',
        'address' => 'Imported address',
        'contact' => '09170005555',
        'gender' => 'Male',
    ],
]), $sk1);
$importId = (int) (payload($tests[array_key_last($tests)])['data']['items'][0]['id'] ?? 0);

$tests[] = req('delete', 'DELETE', "$base/sk/youth/{$id}", null, $sk1);
$tests[] = req('get_deleted', 'GET', "$base/sk/youth/{$id}", null, $sk1);
if ($acctYouthId) {
    $tests[] = req('delete_acct', 'DELETE', "$base/sk/youth/{$acctYouthId}", null, $sk1);
}
if ($importId) {
    $tests[] = req('delete_import', 'DELETE', "$base/sk/youth/{$importId}", null, $sk1);
}

$tests[] = req('logout', 'POST', "$base/auth/logout", null, $sk1);
$tests[] = req('me_after_logout', 'GET', "$base/auth/me", null, $sk1);
$tests[] = req('relogin', 'POST', "$base/auth/login", $login('sk1@demo.test'), $sk1);

foreach ($tests as $t) {
    $hasHash = stripos($t['body'], 'password_hash') !== false;
    echo $t['name'] . "\t" . $t['status'] . "\thash=" . ($hasHash ? 'YES' : 'no') . PHP_EOL;
    echo $t['body'] . PHP_EOL . PHP_EOL;
}

$createJson = payload($created);
$createOrgOk = (($createJson['data']['youth']['id'] ?? 0) > 0);
$updateJson = null;
foreach ($tests as $t) {
    if ($t['name'] === 'update') {
        $updateJson = payload($t);
    }
}
echo "created_id={$id}\n";
echo "create_ignored_org_id=" . (($createJson['success'] ?? false) ? 'yes' : 'no') . "\n";
echo "update_lastName=" . ($updateJson['data']['lastName'] ?? '') . "\n";
echo "temp_password_present=" . (isset(payload($acct)['data']['temporaryPassword']) ? 'yes' : 'no') . "\n";
echo "temp_is_not_hash=" . ((isset(payload($acct)['data']['temporaryPassword']) && !str_starts_with((string) payload($acct)['data']['temporaryPassword'], '$2y$')) ? 'yes' : 'no') . "\n";
