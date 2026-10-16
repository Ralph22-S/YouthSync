<?php
declare(strict_types=1);

/**
 * Online payments (PayMongo hosted checkout) HTTP check - runs against a MOCK PayMongo.
 *
 * MOCK: no request leaves this machine. The suite starts two local PHP built-in servers:
 *   1. api/sql/mock/paymongo_mock_server.php  - a MOCK of the PayMongo Checkout Session API
 *   2. api/public/index.php                   - this API, with MOCK-only PAYMONGO_* values passed
 *      as process environment (api/.env is NOT edited): PAYMONGO_API_BASE points at the mock,
 *      the secret key / webhook secret are fixed MOCK strings, PAYMONGO_MODE=test.
 * Webhooks are signed here with the MOCK webhook secret exactly as PayMongo documents
 * (HMAC-SHA256 over "{t}.{raw body}", header Paymongo-Signature: t=..,te=..,li=..).
 * Passing this suite proves the server logic only. It does NOT prove that real PayMongo
 * test or live payments work; that needs real test keys and a public webhook URL.
 *
 * Laptop-safe:
 * - All checkouts, payments and plan activations happen on ONE temporary organization
 *   created here through POST /admin/organizations (exact id, test e-mail).
 * - Real organizations are snapshotted and compared at the end (plan, status, expiry, QR).
 * - Cleanup deletes only that organization's rows, its temp owner, the payment rows of that
 *   organization and the webhook events with the exact event ids sent here.
 * - No SMS or e-mail is sent (checked).
 *
 * Optional: YOUTHSYNC_API_BASE overrides http://localhost/YouthSync/api (used only for the
 * "gateway not configured" check against the normal web server).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$config = require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

echo "=== payments_http_test: MOCK PayMongo (no real provider calls) ===\n";

$apacheBase = rtrim((string) (getenv('YOUTHSYNC_API_BASE') ?: 'http://localhost/YouthSync/api'), '/');
$demoPassword = 'YouthSync1!';
$youthEmail = 'youth-auth-test@example.test';
$youthPassword = 'YouthAuthTest1!';
$ownerPassword = 'PayTest1!x';
$stamp = date('YmdHis') . substr(bin2hex(random_bytes(2)), 0, 3);
$prefix = 'pm-test-';
$orgEmail = "{$prefix}org-{$stamp}@example.test";
$ownerEmail = "{$prefix}owner-{$stamp}@example.test";
$barangay = "PayTest{$stamp}";
$mockSecret = 'sk_test_MOCKONLY' . bin2hex(random_bytes(6));
$mockWebhookSecret = 'whsk_MOCKONLY' . bin2hex(random_bytes(6));

$pdo = youthsync_pdo();

$leftover = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE email LIKE 'pm-test-%@example.test'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'pm-test-%@example.test'")->fetchColumn();
if ($leftover > 0) {
    fwrite(STDERR, "STOP: rows from an earlier payments run (pm-test-*@example.test) exist. No cleanup was performed.\n");
    exit(2);
}

$failed = 0;
$passed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed, &$passed): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}";
    if (!$ok && $detail !== '') {
        echo ' - ' . $detail;
    }
    echo "\n";
    $ok ? $passed++ : $failed++;
};

/** @var list<string> $bodies response bodies scanned for secrets at the end */
$bodies = [];

function pm_req(string $method, string $url, mixed $body, ?string $cookie, array $extraHeaders = []): array
{
    global $bodies;
    $ch = curl_init($url);
    $headers = array_merge(['Accept: application/json'], $extraHeaders);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60,
    ];
    if ($cookie !== null) {
        $opts[CURLOPT_COOKIEJAR] = $cookie;
        $opts[CURLOPT_COOKIEFILE] = $cookie;
    }
    curl_setopt_array($ch, $opts);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        unset($ch);
        return ['status' => 0, 'body' => $err, 'json' => null, 'headers' => ''];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    unset($ch);
    $text = substr($raw, $headerSize);
    $bodies[] = $text;
    $decoded = json_decode($text, true);
    return ['status' => $status, 'body' => $text, 'json' => is_array($decoded) ? $decoded : null, 'headers' => substr($raw, 0, $headerSize)];
}

function pm_data(array $r): array
{
    $d = $r['json']['data'] ?? null;
    return is_array($d) ? $d : [];
}

function pm_code(array $r): string
{
    return (string) ($r['json']['error']['code'] ?? '');
}

function pm_detail(array $r): string
{
    $e = $r['json']['error'] ?? [];
    return 'status=' . $r['status'] . (isset($e['code']) ? ' code=' . $e['code'] : '') . ' ' . substr((string) ($e['message'] ?? $r['body']), 0, 300);
}

function pm_jar(): string
{
    $path = tempnam(sys_get_temp_dir(), 'yspm');
    if ($path === false) {
        throw new RuntimeException('Could not create a temp file.');
    }
    return $path;
}

function pm_free_port(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($s === false) {
        throw new RuntimeException('No free local port.');
    }
    $name = (string) stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr((string) strrchr($name, ':'), 1);
}

/** @return resource */
function pm_start(array $cmd, array $env, string $cwd, string $log)
{
    $spec = [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];
    $proc = proc_open($cmd, $spec, $pipes, $cwd, $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start ' . basename((string) end($cmd)));
    }
    fclose($pipes[0]);
    return $proc;
}

function pm_wait(string $url): bool
{
    for ($i = 0; $i < 50; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        $ok = curl_exec($ch) !== false;
        unset($ch);
        if ($ok) {
            return true;
        }
        usleep(200000);
    }
    return false;
}

function pm_sign(string $raw, string $secret, ?int $t = null, string $key = 'te', bool $valid = true): string
{
    $t ??= time();
    $sig = hash_hmac('sha256', $t . '.' . $raw, $valid ? $secret : $secret . 'x');
    return 't=' . $t . ',' . ($key === 'te' ? 'te=' . $sig . ',li=' : 'te=,li=' . $sig);
}

function pm_event(string $eventId, string $type, array $resource, bool $livemode = false): string
{
    return json_encode(['data' => [
        'id' => $eventId,
        'type' => 'event',
        'attributes' => [
            'type' => $type,
            'livemode' => $livemode,
            'data' => $resource,
            'previous_data' => new stdClass(),
            'created_at' => time(),
            'updated_at' => time(),
        ],
    ]], JSON_UNESCAPED_SLASHES);
}

function pm_payment(string $id, int $amount, string $pi, string $currency = 'PHP', string $status = 'paid', string $source = 'gcash'): array
{
    return [
        'id' => $id,
        'type' => 'payment',
        'attributes' => [
            'amount' => $amount, 'currency' => $currency, 'status' => $status, 'livemode' => false,
            'paid_at' => time(), 'payment_intent_id' => $pi, 'source' => ['type' => $source],
            'failed_code' => $status === 'failed' ? 'card_declined' : null,
            'failed_message' => $status === 'failed' ? 'MOCK: the card was declined.' : null,
        ],
    ];
}

$sqlValue = static function (string $sql, array $params = []) use ($pdo): mixed {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};
$orgRow = static function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT id, plan, sub_status, cycle, started_on, expires_at, qr_uses, status FROM organizations WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: [];
};
$txRow = static function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM payment_transactions WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: [];
};
$dateAdd = static function (string $base, string $interval) use ($sqlValue): string {
    return (string) $sqlValue("SELECT DATE_ADD(:b, INTERVAL {$interval})", ['b' => $base]);
};
$today = (string) $sqlValue('SELECT CURDATE()');

$tableCounts = static function () use ($pdo): array {
    $out = [];
    foreach (['organizations', 'users', 'organization_users', 'youth', 'payments', 'payment_transactions', 'payment_webhook_events',
        'sms_deliveries', 'email_deliveries', 'notifications'] as $t) {
        $out[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    }
    return $out;
};

$jars = [];
$anon = $jars[] = pm_jar();
$admin = $jars[] = pm_jar();
$sk1 = $jars[] = pm_jar();
$youth = $jars[] = pm_jar();
$owner = $jars[] = pm_jar();
$apacheJar = $jars[] = pm_jar();
$stateFile = $jars[] = pm_jar();
$mockLog = $jars[] = pm_jar();
$appLog = $jars[] = pm_jar();
file_put_contents($stateFile, '{}');

$procs = [];
$orgId = 0;
$sentEventIds = [];
$realSnapshot = [];
$beforeCounts = null;
$appLogFile = dirname(__DIR__) . '/logs/app.log';
$appLogStart = is_file($appLogFile) ? (int) filesize($appLogFile) : 0;

try {
    // ---- 0. Normal web server without keys: checkout must say "not configured" ----
    $r = pm_req('POST', "$apacheBase/auth/login", ['email' => 'sk1@demo.test', 'password' => $demoPassword], $apacheJar);
    $check('apache_sk1_login', $r['status'] === 200, pm_detail($r));
    $r = pm_req('GET', "$apacheBase/sk/subscription/payment-options", null, $apacheJar);
    $opts = pm_data($r);
    if ($r['status'] === 200 && ($opts['enabled'] ?? null) === false) {
        $txBefore = (int) $sqlValue('SELECT COUNT(*) FROM payment_transactions');
        $r = pm_req('POST', "$apacheBase/sk/subscription/checkout", ['plan' => 'basic', 'cycle' => 'monthly'], $apacheJar);
        $check('not_configured_503', $r['status'] === 503 && pm_code($r) === 'CHECKOUT_NOT_CONFIGURED'
            && is_string($r['json']['error']['message'] ?? null), pm_detail($r));
        $check('not_configured_no_row', (int) $sqlValue('SELECT COUNT(*) FROM payment_transactions') === $txBefore);
        $r = pm_req('POST', "$apacheBase/payments/paymongo/webhook", '{}', null);
        $check('not_configured_webhook_503', $r['status'] === 503 && pm_code($r) === 'WEBHOOK_NOT_CONFIGURED', pm_detail($r));
    } else {
        echo "SKIP not_configured checks: the normal web server reports online payment enabled (real keys present); not creating a real checkout.\n";
    }

    // ---- 1. Start the MOCK PayMongo server and a local API server pointed at it ----
    $mockPort = pm_free_port();
    $appPort = pm_free_port();
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $procs[] = pm_start([PHP_BINARY, '-S', "127.0.0.1:{$mockPort}", __DIR__ . '/mock/paymongo_mock_server.php'],
        array_merge($env, ['MOCK_PAYMONGO_STATE' => $stateFile]), __DIR__ . '/mock', $mockLog);
    $mock = "http://127.0.0.1:{$mockPort}";
    $appEnv = array_merge($env, [
        'PAYMONGO_SECRET_KEY' => $mockSecret,
        'PAYMONGO_WEBHOOK_SECRET' => $mockWebhookSecret,
        'PAYMONGO_MODE' => 'test',
        'PAYMONGO_API_BASE' => $mock,
        'PAYMONGO_ALLOWED_METHODS' => 'card, gcash ,paymaya,not_a_method',
        'PAYMONGO_SUCCESS_URL' => 'http://localhost:5173/sk/subscription/payment/success?tx={id}',
        'PAYMONGO_CANCEL_URL' => 'http://localhost:5173/sk/subscription/payment/cancel?tx={id}',
        'PAYMONGO_CONFIRM_BY_RETRIEVAL' => 'false',
        'PAYMONGO_WEBHOOK_TOLERANCE' => '300',
    ]);
    $publicDir = dirname(__DIR__) . '/public';
    $procs[] = pm_start([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", $publicDir . '/index.php'], $appEnv, $publicDir, $appLog);
    $base = "http://127.0.0.1:{$appPort}" . (string) $config['base_path'];
    $check('mock_server_up (MOCK)', pm_wait("$mock/__mock/state"));
    $check('local_api_up', pm_wait("$base/auth/me"));
    echo "MOCK PayMongo at {$mock}; local API at {$base}\n";
    $hook = "$base/payments/paymongo/webhook";
    $mockState = static fn (): array => (array) (pm_req('GET', "$mock/__mock/state", null, null)['json'] ?? []);
    $setSession = static fn (string $cs, array $change): array => pm_req('POST', "$mock/__mock/session/$cs", $change, null);
    $setFlags = static fn (array $flags): array => pm_req('POST', "$mock/__mock/flags", $flags, null);
    $send = static function (string $raw, ?string $header) use ($hook): array {
        return pm_req('POST', $hook, $raw, null, $header === null ? [] : ['Paymongo-Signature: ' . $header]);
    };
    $eventN = 0;
    $newEvent = static function () use (&$eventN, &$sentEventIds, $stamp): string {
        $eventN++;
        $id = 'evt_pmtest' . $stamp . 'n' . $eventN;
        $sentEventIds[] = $id;
        return $id;
    };

    // ---- 2. Auth ----
    foreach ([['GET', '/sk/subscription/payment-options', null], ['POST', '/sk/subscription/checkout', ['plan' => 'basic', 'cycle' => 'monthly']],
        ['GET', '/sk/subscription/checkout/1', null], ['POST', '/sk/subscription/checkout/1/cancel', []],
        ['GET', '/sk/subscription/checkouts', null], ['GET', '/admin/payment-transactions', null]] as [$m, $p, $b]) {
        $r = pm_req($m, $base . $p, $b, $anon);
        $check("anon_401 {$m} {$p}", $r['status'] === 401, pm_detail($r));
    }
    $r = pm_req('POST', "$base/auth/login", ['email' => 'admin@skmms.test', 'password' => $demoPassword], $admin);
    $check('admin_login', $r['status'] === 200, pm_detail($r));
    $r = pm_req('POST', "$base/auth/login", ['email' => 'sk1@demo.test', 'password' => $demoPassword], $sk1);
    $check('sk1_login', $r['status'] === 200, pm_detail($r));
    $r = pm_req('POST', "$base/auth/login", ['email' => $youthEmail, 'password' => $youthPassword], $youth);
    $check('youth_login', $r['status'] === 200, pm_detail($r));
    $r = pm_req('POST', "$base/sk/subscription/checkout", ['plan' => 'basic', 'cycle' => 'monthly'], $youth);
    $check('youth_checkout_forbidden', in_array($r['status'], [401, 403], true), pm_detail($r));
    $r = pm_req('GET', "$base/admin/payment-transactions", null, $youth);
    $check('youth_admin_history_forbidden', in_array($r['status'], [401, 403], true), pm_detail($r));
    $r = pm_req('GET', "$base/admin/payment-transactions", null, $sk1);
    $check('sk_admin_history_forbidden', $r['status'] === 403, pm_detail($r));

    pm_req('GET', "$base/admin/organizations?perPage=1", null, $admin); // normal expiry sweep before the snapshot
    foreach ($pdo->query('SELECT id, plan, sub_status, cycle, started_on, expires_at, qr_uses, status FROM organizations ORDER BY id')->fetchAll() as $row) {
        $realSnapshot[(int) $row['id']] = $row;
    }
    $beforeCounts = $tableCounts();

    // ---- 3. Temporary organization ----
    $r = pm_req('POST', "$base/admin/organizations", [
        'barangay' => $barangay, 'municipality' => 'Testville', 'province' => 'Laguna',
        'chairperson' => 'Payment Test Chair', 'email' => $orgEmail, 'contact' => '09170003001',
        'ownerName' => 'PayTest Owner', 'ownerEmail' => $ownerEmail, 'ownerPassword' => $ownerPassword,
    ], $admin);
    array_pop($bodies); // the create response carries the temporary password by design
    $orgId = (int) (pm_data($r)['id'] ?? 0);
    $check('temp_org_created', $r['status'] === 201 && $orgId > 0, pm_detail($r));
    if ($orgId < 1) {
        throw new RuntimeException('Temp organization was not created; stopping.');
    }
    echo "temp_org_id={$orgId}\n";
    $r = pm_req('POST', "$base/auth/login", ['email' => $ownerEmail, 'password' => $ownerPassword], $owner);
    $check('temp_owner_login', $r['status'] === 200, pm_detail($r));
    $ownerId = (int) $sqlValue('SELECT id FROM users WHERE email = :e', ['e' => $ownerEmail]);
    $orgStart = $orgRow($orgId);

    // ---- 4. Payment options (MOCK-configured server) ----
    $r = pm_req('GET', "$base/sk/subscription/payment-options", null, $owner);
    $o = pm_data($r);
    $check('options_enabled_test_mode', $r['status'] === 200 && ($o['enabled'] ?? false) === true && ($o['mode'] ?? '') === 'test'
        && ($o['currency'] ?? '') === 'PHP', pm_detail($r));
    $check('options_methods_allowlist', array_column($o['methods'] ?? [], 'code') === ['card', 'gcash', 'paymaya'], json_encode($o['methods'] ?? null));

    // ---- 5. Validation (nothing created) ----
    $txCount = static fn (): int => (int) $sqlValue('SELECT COUNT(*) FROM payment_transactions WHERE organization_id = :o', ['o' => $orgId]);
    foreach ([
        ['free_plan', ['plan' => 'free', 'cycle' => 'monthly'], 'plan'],
        ['unknown_plan', ['plan' => 'gold', 'cycle' => 'monthly'], 'plan'],
        ['bad_cycle', ['plan' => 'basic', 'cycle' => 'weekly'], 'cycle'],
        ['unknown_method', ['plan' => 'basic', 'cycle' => 'monthly', 'method' => 'bitcoin'], 'method'],
        ['method_not_allowed', ['plan' => 'basic', 'cycle' => 'monthly', 'method' => 'grab_pay'], 'method'],
    ] as [$name, $b, $field]) {
        $r = pm_req('POST', "$base/sk/subscription/checkout", $b, $owner);
        $check("invalid_{$name}_422", $r['status'] === 422 && isset($r['json']['error']['fields'][$field]), pm_detail($r));
    }
    $check('invalid_requests_created_nothing', $txCount() === 0);

    // ---- 6. Provider init failure (MOCK returns 500) ----
    $setFlags(['fail_next_create' => true]);
    $r = pm_req('POST', "$base/sk/subscription/checkout", ['plan' => 'basic', 'cycle' => 'monthly'], $owner);
    $failTx = (int) ($r['json']['error']['transactionId'] ?? 0);
    $check('provider_failure_502', $r['status'] === 502 && pm_code($r) === 'PAYMENT_PROVIDER_ERROR' && $failTx > 0, pm_detail($r));
    $ft = $txRow($failTx);
    $check('provider_failure_marked_failed', ($ft['status'] ?? '') === 'failed' && ($ft['failure_code'] ?? '') === 'mock_provider_failure'
        && $ft['provider_reference'] === null && $ft['applied_at'] === null, json_encode($ft));
    $check('provider_failure_org_unchanged', $orgRow($orgId) == $orgStart);

    // ---- 7. Valid checkout; client amount / org / status / currency are ignored ----
    $r = pm_req('POST', "$base/sk/subscription/checkout", [
        'plan' => 'basic', 'cycle' => 'monthly', 'amount' => 1, 'amount_centavos' => 100, 'currency' => 'USD',
        'organization_id' => 1, 'organizationId' => 1, 'status' => 'paid', 'transaction_id' => 999999, 'user_id' => 1,
    ], $owner);
    $d = pm_data($r);
    $tx1 = (int) ($d['transaction']['id'] ?? 0);
    $check('checkout_created_201', $r['status'] === 201 && $tx1 > 0 && str_starts_with((string) ($d['checkoutUrl'] ?? ''), 'https://')
        && ($d['transaction']['status'] ?? '') === 'pending', pm_detail($r));
    $t1 = $txRow($tx1);
    $check('checkout_server_values', (int) $t1['organization_id'] === $orgId && (int) $t1['user_id'] === $ownerId
        && (int) $t1['amount_centavos'] === 99900 && $t1['amount'] === '999.00' && $t1['currency'] === 'PHP'
        && $t1['plan'] === 'basic' && $t1['cycle'] === 'monthly' && $t1['provider'] === 'paymongo'
        && str_starts_with((string) $t1['provider_reference'], 'cs_mock') && str_starts_with((string) $t1['provider_payment_intent_id'], 'pi_mock')
        && (int) $t1['livemode'] === 0 && $t1['verified_at'] === null, json_encode($t1));
    $state = $mockState();
    $req = end($state['requests'])['attributes'] ?? [];
    $check('provider_request_amount_centavos (MOCK)', ($req['line_items'][0]['amount'] ?? null) === 99900
        && ($req['line_items'][0]['currency'] ?? '') === 'PHP' && ($req['line_items'][0]['quantity'] ?? 0) === 1, json_encode($req['line_items'] ?? null));
    $check('provider_request_methods (MOCK)', ($req['payment_method_types'] ?? null) === ['card', 'gcash', 'paymaya'], json_encode($req['payment_method_types'] ?? null));
    $check('provider_request_refs (MOCK)', ($req['reference_number'] ?? '') === $t1['reference']
        && ($req['metadata']['transaction_id'] ?? '') === (string) $tx1 && ($req['metadata']['organization_id'] ?? '') === (string) $orgId
        && ($req['success_url'] ?? '') === "http://localhost:5173/sk/subscription/payment/success?tx={$tx1}"
        && ($req['send_email_receipt'] ?? true) === false, json_encode($req));
    $check('provider_auth_test_key (MOCK)', (end($state['requests'])['auth_user_prefix'] ?? '') === 'sk_test_');
    $check('checkout_org_unchanged', $orgRow($orgId) == $orgStart);

    $r = pm_req('POST', "$base/sk/subscription/checkout", ['plan' => 'basic', 'cycle' => 'monthly'], $owner);
    $check('double_submit_409', $r['status'] === 409 && pm_code($r) === 'CHECKOUT_IN_PROGRESS'
        && ($r['json']['error']['transactionId'] ?? 0) === $tx1, pm_detail($r));

    // ---- 8. Own organization only ----
    $r = pm_req('GET', "$base/sk/subscription/checkout/{$tx1}", null, $sk1);
    $check('other_org_status_404', $r['status'] === 404, pm_detail($r));
    $r = pm_req('POST', "$base/sk/subscription/checkout/{$tx1}/cancel", [], $sk1);
    $check('other_org_cancel_404', $r['status'] === 404, pm_detail($r));
    $r = pm_req('GET', "$base/sk/subscription/checkout/{$tx1}", null, $owner);
    $check('own_status_pending', $r['status'] === 200 && (pm_data($r)['transaction']['status'] ?? '') === 'pending'
        && array_key_exists('subscription', pm_data($r)) && pm_data($r)['subscription'] === null, pm_detail($r));

    // ---- 9. Provider says paid, but no webhook yet: polling must NOT activate ----
    $cs1 = (string) $t1['provider_reference'];
    $pi1 = (string) $t1['provider_payment_intent_id'];
    $pay1 = 'pay_mock' . bin2hex(random_bytes(6));
    $setSession($cs1, ['payments' => [pm_payment($pay1, 99900, $pi1)]]);
    $pdo->prepare('UPDATE payment_transactions SET provider_checked_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id AND organization_id = :o')
        ->execute(['id' => $tx1, 'o' => $orgId]);
    $r = pm_req('GET', "$base/sk/subscription/checkout/{$tx1}", null, $owner);
    $check('poll_does_not_activate', $r['status'] === 200 && (pm_data($r)['transaction']['status'] ?? '') === 'pending'
        && (pm_data($r)['transaction']['providerStatus'] ?? '') === 'paid' && $orgRow($orgId) == $orgStart, pm_detail($r));
    $check('poll_paid_is_not_verified', (pm_data($r)['transaction']['verification'] ?? '') === 'awaiting_verification'
        && (pm_data($r)['transaction']['providerVerified'] ?? null) === false && $txRow($tx1)['verified_at'] === null, pm_detail($r));

    // ---- 10. Invalid / malformed webhooks (all rejected, nothing changes) ----
    $session1 = $mockState()['sessions'][$cs1];
    $raw = pm_event($newEvent(), 'checkout_session.payment.paid', $session1);
    $r = $send($raw, null);
    $check('webhook_no_signature_400', $r['status'] === 400 && pm_code($r) === 'INVALID_SIGNATURE', pm_detail($r));
    $r = $send($raw, 't=abc,te=00');
    $check('webhook_malformed_header_400', $r['status'] === 400, pm_detail($r));
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret, null, 'te', false));
    $check('webhook_bad_signature_401', $r['status'] === 401 && pm_code($r) === 'INVALID_SIGNATURE', pm_detail($r));
    $r = $send($raw . ' ', pm_sign($raw, $mockWebhookSecret));
    $check('webhook_body_tampered_401', $r['status'] === 401, pm_detail($r));
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret, time() - 1000));
    $check('webhook_stale_timestamp_401', $r['status'] === 401, pm_detail($r));
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret, null, 'li'));
    $check('webhook_live_sig_in_test_mode_401', $r['status'] === 401, pm_detail($r));
    $r = $send($raw, pm_sign($raw, $mockSecret));
    $check('webhook_signed_with_api_key_401', $r['status'] === 401, pm_detail($r));
    $bad = 'not json';
    $r = $send($bad, pm_sign($bad, $mockWebhookSecret));
    $check('webhook_not_json_400', $r['status'] === 400 && pm_code($r) === 'INVALID_EVENT', pm_detail($r));
    $bad = '{"data":{"id":"evt_x1","attributes":{"type":"checkout_session.payment.paid"}}}';
    $r = $send($bad, pm_sign($bad, $mockWebhookSecret));
    $check('webhook_missing_resource_400', $r['status'] === 400, pm_detail($r));
    $check('rejected_webhooks_no_change', $orgRow($orgId) == $orgStart && $txRow($tx1)['status'] === 'pending'
        && (int) $sqlValue('SELECT COUNT(*) FROM payment_webhook_events WHERE event_id = :e', ['e' => end($sentEventIds)]) === 0);

    // irrelevant, live-mode and unknown-session events are acknowledged and ignored
    $raw = pm_event($newEvent(), 'source.chargeable', ['id' => 'src_mock1', 'type' => 'source', 'attributes' => []]);
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
    $check('webhook_irrelevant_ignored', $r['status'] === 200 && (pm_data($r)['result'] ?? '') === 'ignored', pm_detail($r));
    $raw = pm_event($newEvent(), 'checkout_session.payment.paid', $session1, true);
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
    $check('webhook_livemode_event_ignored_in_test', $r['status'] === 200 && (pm_data($r)['result'] ?? '') === 'ignored_mode', pm_detail($r));
    $raw = pm_event($newEvent(), 'checkout_session.payment.paid', ['id' => 'cs_mockunknown000', 'type' => 'checkout_session', 'attributes' => []]);
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
    $check('webhook_unknown_session_unmatched', $r['status'] === 200 && (pm_data($r)['result'] ?? '') === 'unmatched', pm_detail($r));
    $check('ignored_webhooks_no_change', $orgRow($orgId) == $orgStart);

    // ---- 11. Verified success activates exactly once (new term from today) ----
    $subLogs = static fn (): int => (int) $sqlValue(
        "SELECT COUNT(*) FROM activity_logs WHERE organization_id = :o AND category = 'subscription' AND actor_name = 'PayMongo' AND actor_role = 'system'",
        ['o' => $orgId]
    );
    $evtPaid1 = $newEvent();
    $raw = pm_event($evtPaid1, 'checkout_session.payment.paid', $session1);
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
    $check('webhook_paid_processed', $r['status'] === 200 && (pm_data($r)['result'] ?? '') === 'processed', pm_detail($r));
    $t1 = $txRow($tx1);
    $o1 = $orgRow($orgId);
    $check('tx1_paid', $t1['status'] === 'paid' && $t1['provider_status'] === 'paid' && $t1['provider_payment_id'] === $pay1
        && $t1['method'] === 'gcash' && $t1['applied_at'] !== null && $t1['verified_at'] !== null && $t1['paid_at'] !== null, json_encode($t1));
    $check('activation_new_term', $o1['plan'] === 'basic' && $o1['sub_status'] === 'active' && $o1['cycle'] === 'monthly'
        && $o1['started_on'] === $today && substr((string) $o1['expires_at'], 0, 10) === $dateAdd($today, '1 MONTH')
        && (int) $o1['qr_uses'] === (int) $orgStart['qr_uses'], json_encode($o1));
    $check('activation_audit_once', $subLogs() === 1, 'count=' . $subLogs());
    $r = pm_req('GET', "$base/sk/subscription/checkout/{$tx1}", null, $owner);
    $check('status_paid_with_subscription', ($r['status'] === 200) && (pm_data($r)['transaction']['status'] ?? '') === 'paid'
        && (pm_data($r)['subscription']['plan'] ?? '') === 'basic', pm_detail($r));

    // duplicate event id and a second event for the same payment: no double grant
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
    $check('webhook_duplicate_event', $r['status'] === 200 && (pm_data($r)['duplicate'] ?? false) === true, pm_detail($r));
    $raw2 = pm_event($newEvent(), 'payment.paid', pm_payment($pay1, 99900, $pi1));
    $r = $send($raw2, pm_sign($raw2, $mockWebhookSecret));
    $check('webhook_second_event_already_applied', $r['status'] === 200 && (pm_data($r)['result'] ?? '') === 'already_applied', pm_detail($r));
    $check('no_double_grant', $orgRow($orgId) == $o1 && $subLogs() === 1, json_encode($orgRow($orgId)));
    $check('event_attempts_counted', (int) $sqlValue('SELECT attempts FROM payment_webhook_events WHERE event_id = :e', ['e' => $evtPaid1]) === 2);

    // ---- 12. Same plan still active -> extend from current expiry ----
    $r = pm_req('POST', "$base/sk/subscription/checkout", ['plan' => 'basic', 'cycle' => 'yearly'], $owner);
    $tx2 = (int) (pm_data($r)['transaction']['id'] ?? 0);
    $t2 = $txRow($tx2);
    $check('tx2_created_yearly_price', $r['status'] === 201 && (int) ($t2['amount_centavos'] ?? 0) === 999000, pm_detail($r));
    $setSession((string) $t2['provider_reference'], ['payments' => [pm_payment('pay_mock' . bin2hex(random_bytes(6)), 999000, (string) $t2['provider_payment_intent_id'], 'PHP', 'paid', 'card')]]);
    $raw = pm_event($newEvent(), 'checkout_session.payment.paid', $mockState()['sessions'][$t2['provider_reference']]);
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
    $o2 = $orgRow($orgId);
    $check('extend_from_current_expiry', (pm_data($r)['result'] ?? '') === 'processed' && $o2['plan'] === 'basic' && $o2['cycle'] === 'yearly'
        && $o2['started_on'] === $o1['started_on']
        && substr((string) $o2['expires_at'], 0, 10) === $dateAdd(substr((string) $o1['expires_at'], 0, 10), '12 MONTH'), json_encode($o2) . ' ' . pm_detail($r));
    $check('tx2_method_card', $txRow($tx2)['method'] === 'card' && $subLogs() === 2);

    // ---- 13. failed / pending / expired / cancelled never activate ----
    $r = pm_req('POST', "$base/sk/subscription/checkout", ['plan' => 'premium', 'cycle' => 'monthly', 'method' => 'gcash'], $owner);
    $tx3 = (int) (pm_data($r)['transaction']['id'] ?? 0);
    $t3 = $txRow($tx3);
    $state = $mockState();
    $check('tx3_single_method (MOCK)', $r['status'] === 201 && (end($state['requests'])['attributes']['payment_method_types'] ?? null) === ['gcash']
        && $t3['requested_method'] === 'gcash', pm_detail($r));
    $raw = pm_event($newEvent(), 'payment.failed', pm_payment('pay_mockfail' . bin2hex(random_bytes(4)), 199900, (string) $t3['provider_payment_intent_id'], 'PHP', 'failed'));
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
    $t3 = $txRow($tx3);
    $check('payment_failed_not_activated', $r['status'] === 200 && (pm_data($r)['result'] ?? '') === 'unpaid' && $t3['status'] === 'pending'
        && $t3['provider_status'] === 'payment_failed' && $t3['failure_code'] === 'card_declined' && $orgRow($orgId) == $o2, json_encode($t3));
    $raw = pm_event($newEvent(), 'checkout_session.payment.paid', $mockState()['sessions'][$t3['provider_reference']]);
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
    $check('paid_event_without_paid_payment_not_activated', (pm_data($r)['result'] ?? '') === 'unpaid' && $txRow($tx3)['status'] === 'pending'
        && $orgRow($orgId) == $o2, pm_detail($r));
    $setSession((string) $t3['provider_reference'], ['status' => 'expired']);
    $raw = pm_event($newEvent(), 'checkout_session.payment.paid', $mockState()['sessions'][$t3['provider_reference']]);
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
    $check('expired_not_activated', (pm_data($r)['result'] ?? '') === 'expired' && $txRow($tx3)['status'] === 'expired' && $orgRow($orgId) == $o2, pm_detail($r));
    $r = pm_req('GET', "$base/sk/subscription/checkout/{$tx3}", null, $owner);
    $check('expired_status_view', (pm_data($r)['transaction']['status'] ?? '') === 'expired' && array_key_exists('subscription', pm_data($r)) && pm_data($r)['subscription'] === null, pm_detail($r));

    $r = pm_req('POST', "$base/sk/subscription/checkout", ['plan' => 'premium', 'cycle' => 'monthly'], $owner);
    $tx4 = (int) (pm_data($r)['transaction']['id'] ?? 0);
    $t4 = $txRow($tx4);
    $r = pm_req('POST', "$base/sk/subscription/checkout/{$tx4}/cancel", [], $owner);
    $check('cancel_ok', $r['status'] === 200 && (pm_data($r)['transaction']['status'] ?? '') === 'cancelled'
        && ($mockState()['sessions'][$t4['provider_reference']]['attributes']['status'] ?? '') === 'expired', pm_detail($r));
    $raw = pm_event($newEvent(), 'checkout_session.payment.paid', $mockState()['sessions'][$t4['provider_reference']]);
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
    $check('cancelled_not_activated', $r['status'] === 200 && $txRow($tx4)['status'] === 'cancelled' && $orgRow($orgId) == $o2, pm_detail($r));
    $r = pm_req('POST', "$base/sk/subscription/checkout/{$tx4}/cancel", [], $owner);
    $check('cancel_twice_409', $r['status'] === 409, pm_detail($r));
    $check('no_activation_logs_added', $subLogs() === 2);

    // ---- 14. Amount / currency / wrong-org mismatches -> review, never activate ----
    $reviewLogs = static fn (): int => (int) $sqlValue(
        "SELECT COUNT(*) FROM activity_logs WHERE organization_id = :o AND category = 'payment' AND actor_name = 'PayMongo' AND description LIKE '%needs review%'",
        ['o' => $orgId]
    );
    $mismatchCase = static function (string $name, array $sessionChange, ?callable $payment) use ($base, $owner, $txRow, $setSession, $mockState, $newEvent, $send, $mockWebhookSecret, $orgRow, $orgId, $check): int {
        $r = pm_req('POST', "$base/sk/subscription/checkout", ['plan' => 'premium', 'cycle' => 'monthly'], $owner);
        $id = (int) (pm_data($r)['transaction']['id'] ?? 0);
        $t = $txRow($id);
        $before = $orgRow($orgId);
        if ($payment !== null) {
            $sessionChange['payments'] = [$payment($t)];
        }
        $setSession((string) $t['provider_reference'], $sessionChange);
        $raw = pm_event($newEvent(), 'checkout_session.payment.paid', $mockState()['sessions'][$t['provider_reference']]);
        $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
        $t = $txRow($id);
        $check("{$name}_review_not_activated", $r['status'] === 200 && (pm_data($r)['result'] ?? '') === 'review' && $t['status'] === 'review'
            && $t['failure_code'] === 'VERIFICATION_MISMATCH' && $t['applied_at'] === null && $orgRow($orgId) == $before, json_encode($t) . ' ' . pm_detail($r));
        return $id;
    };
    $mismatchCase('amount_mismatch', [], static fn (array $t): array => pm_payment('pay_mock' . bin2hex(random_bytes(6)), 100, (string) $t['provider_payment_intent_id']));
    $mismatchCase('currency_mismatch', [], static fn (array $t): array => pm_payment('pay_mock' . bin2hex(random_bytes(6)), 199900, (string) $t['provider_payment_intent_id'], 'USD'));
    $org1Before = $orgRow(1);
    $mismatchCase('wrong_org_metadata', ['attributes' => ['metadata' => ['transaction_id' => (string) $tx1, 'organization_id' => '1']]],
        static fn (array $t): array => pm_payment('pay_mock' . bin2hex(random_bytes(6)), 199900, (string) $t['provider_payment_intent_id']));
    $check('wrong_org_real_org1_unchanged', $orgRow(1) == $org1Before);
    $mismatchCase('reference_mismatch', ['attributes' => ['reference_number' => (string) $t1['reference']]],
        static fn (array $t): array => pm_payment('pay_mock' . bin2hex(random_bytes(6)), 199900, (string) $t['provider_payment_intent_id']));
    $check('review_audited', $reviewLogs() === 4, 'count=' . $reviewLogs());

    // ---- 15. DB rollback: provider payment already linked to another transaction ----
    $before = $orgRow($orgId);
    $logsBefore = $subLogs();
    $tx8 = $mismatchCase('reused_payment_rollback', [], static fn (array $t): array => pm_payment($pay1, 199900, (string) $t['provider_payment_intent_id']));
    $check('rollback_org_unchanged', $orgRow($orgId) == $before && $subLogs() === $logsBefore, json_encode($orgRow($orgId)));
    $check('rollback_reason', str_contains((string) $txRow($tx8)['failure_message'], 'already linked'));

    // ---- 16. Provider verification unavailable -> 503, then a retry of the SAME event succeeds ----
    $r = pm_req('POST', "$base/sk/subscription/checkout", ['plan' => 'premium', 'cycle' => 'monthly'], $owner);
    $tx9 = (int) (pm_data($r)['transaction']['id'] ?? 0);
    $t9 = $txRow($tx9);
    $setSession((string) $t9['provider_reference'], ['payments' => [pm_payment('pay_mock' . bin2hex(random_bytes(6)), 199900, (string) $t9['provider_payment_intent_id'], 'PHP', 'paid', 'paymaya')]]);
    $evt9 = $newEvent();
    $raw = pm_event($evt9, 'checkout_session.payment.paid', $mockState()['sessions'][$t9['provider_reference']]);
    $setFlags(['fail_retrieve' => true]);
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
    $check('verify_unavailable_503', $r['status'] === 503 && pm_code($r) === 'VERIFICATION_UNAVAILABLE' && $txRow($tx9)['status'] === 'pending'
        && $orgRow($orgId) == $before, pm_detail($r));
    $setFlags(['fail_retrieve' => false]);
    $r = $send($raw, pm_sign($raw, $mockWebhookSecret));
    $o9 = $orgRow($orgId);
    $check('retry_same_event_activates', $r['status'] === 200 && (pm_data($r)['result'] ?? '') === 'processed' && $txRow($tx9)['status'] === 'paid'
        && $o9['plan'] === 'premium' && $o9['cycle'] === 'monthly' && $o9['started_on'] === $today
        && substr((string) $o9['expires_at'], 0, 10) === $dateAdd($today, '1 MONTH') && $subLogs() === 3, json_encode($o9) . ' ' . pm_detail($r));

    // ---- 16b. Optional test-mode fallback: PAYMONGO_CONFIRM_BY_RETRIEVAL=true (second local server) ----
    $appPort2 = pm_free_port();
    $procs[] = pm_start([PHP_BINARY, '-S', "127.0.0.1:{$appPort2}", $publicDir . '/index.php'],
        array_merge($appEnv, ['PAYMONGO_CONFIRM_BY_RETRIEVAL' => 'true']), $publicDir, $appLog);
    $base2 = "http://127.0.0.1:{$appPort2}" . (string) $config['base_path'];
    $check('local_api2_up', pm_wait("$base2/auth/me"));
    $r = pm_req('POST', "$base2/sk/subscription/checkout", ['plan' => 'basic', 'cycle' => 'monthly'], $owner);
    $tx10 = (int) (pm_data($r)['transaction']['id'] ?? 0);
    $t10 = $txRow($tx10);
    $before10 = $orgRow($orgId);
    $r = pm_req('GET', "$base2/sk/subscription/checkout/{$tx10}", null, $owner);
    $check('fallback_unpaid_poll_no_change', $r['status'] === 200 && (pm_data($r)['transaction']['status'] ?? '') === 'pending' && $orgRow($orgId) == $before10, pm_detail($r));
    $setSession((string) $t10['provider_reference'], ['payments' => [pm_payment('pay_mock' . bin2hex(random_bytes(6)), 99900, (string) $t10['provider_payment_intent_id'])]]);
    $pdo->prepare('UPDATE payment_transactions SET provider_checked_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id AND organization_id = :o')
        ->execute(['id' => $tx10, 'o' => $orgId]);
    $r = pm_req('GET', "$base2/sk/subscription/checkout/{$tx10}", null, $owner);
    $o10 = $orgRow($orgId);
    $check('fallback_retrieval_activates_in_test_mode', (pm_data($r)['transaction']['status'] ?? '') === 'paid' && $o10['plan'] === 'basic'
        && $o10['started_on'] === $today && $subLogs() === 4
        && str_contains((string) $sqlValue("SELECT description FROM activity_logs WHERE organization_id = :o AND actor_name = 'PayMongo' AND category = 'subscription' ORDER BY id DESC LIMIT 1", ['o' => $orgId]), 'fetch (test mode)'),
        json_encode($o10) . ' ' . pm_detail($r));
    $r = pm_req('GET', "$base2/sk/subscription/checkout/{$tx10}", null, $owner);
    $check('fallback_repeat_poll_no_double_grant', $orgRow($orgId) == $o10 && $subLogs() === 4);

    // ---- 17. Payment history ----
    $r = pm_req('GET', "$base/admin/payment-transactions?perPage=50&q=" . rawurlencode($barangay), null, $admin);
    $h = pm_data($r);
    $check('admin_history_lists_org', $r['status'] === 200 && ($h['total'] ?? 0) === 12 && count($h['items'] ?? []) === 12, pm_detail($r) . ' total=' . ($h['total'] ?? '?'));
    $item = null;
    foreach ($h['items'] ?? [] as $it) {
        if (($it['id'] ?? 0) === $tx1) {
            $item = $it;
        }
    }
    $check('admin_history_fields', is_array($item) && $item['reference'] === $t1['reference'] && $item['organizationName'] !== null
        && $item['plan'] === 'basic' && $item['cycle'] === 'monthly' && $item['amount'] === 999 && $item['amountCentavos'] === 99900
        && $item['method'] === 'gcash' && $item['providerStatus'] === 'paid' && $item['status'] === 'paid' && $item['paidAt'] !== null
        && $item['createdAt'] !== null && $item['mode'] === 'test', json_encode($item));
    $check('admin_history_verification_fields', is_array($item) && $item['source'] === 'paymongo' && $item['verification'] === 'provider_verified'
        && $item['providerVerified'] === true && $item['verifiedAt'] !== null && $item['verifiedVia'] === 'webhook+fetch'
        && str_starts_with((string) $item['providerPaymentIntentId'], 'pi_mock'), json_encode($item));
    $r = pm_req('GET', "$base/admin/payment-transactions?perPage=3&page=2&q=" . rawurlencode($barangay), null, $admin);
    $h = pm_data($r);
    $check('admin_history_paging', ($h['page'] ?? 0) === 2 && ($h['perPage'] ?? 0) === 3 && count($h['items'] ?? []) === 3
        && ($h['totalPages'] ?? 0) === 4 && ($h['total'] ?? 0) === 12, pm_detail($r));
    $r = pm_req('GET', "$base/admin/payment-transactions?status=review&q=" . rawurlencode($barangay), null, $admin);
    $check('admin_history_status_filter', ($pm = pm_data($r)) && ($pm['total'] ?? 0) === 5, pm_detail($r));
    $r = pm_req('GET', "$base/admin/payment-transactions?status=bogus", null, $admin);
    $check('admin_history_bad_status_422', $r['status'] === 422, pm_detail($r));
    foreach ([['POST', "/admin/payment-transactions/{$tx8}"], ['PATCH', "/admin/payment-transactions/{$tx8}"],
        ['POST', "/admin/payment-transactions/{$tx8}/mark-paid"], ['POST', "/admin/payment-transactions"]] as [$m, $p]) {
        $r = pm_req($m, $base . $p, ['status' => 'paid'], $admin);
        $check("no_admin_mark_paid {$m} {$p}", in_array($r['status'], [404, 405], true), pm_detail($r));
    }
    $check('review_tx_still_unpaid', $txRow($tx8)['status'] === 'review');
    $r = pm_req('GET', "$base/sk/subscription/checkouts?perPage=50", null, $owner);
    $check('sk_own_history', $r['status'] === 200 && (pm_data($r)['total'] ?? 0) === 12, pm_detail($r));
    $r = pm_req('GET', "$base/sk/subscription/checkouts?perPage=50", null, $sk1);
    $other = array_filter(pm_data($r)['items'] ?? [], static fn ($it) => ($it['organizationId'] ?? 0) === $orgId);
    $check('sk_other_org_history_excludes_temp', $r['status'] === 200 && $other === [], pm_detail($r));

    // ---- 18. Subscription management still works ----
    $r = pm_req('POST', "$base/admin/organizations/{$orgId}/subscription/plan", ['plan' => 'free'], $admin);
    $check('admin_change_plan_still_works', $r['status'] === 200 && ($orgRow($orgId)['plan'] ?? '') === 'free', pm_detail($r));
    $r = pm_req('GET', "$base/sk/subscription", null, $owner);
    $check('sk_subscription_view_ok', $r['status'] === 200 && ($r['json']['success'] ?? false) === true, pm_detail($r));

    // ---- 19. Side effects ----
    $check('no_sms_or_email_rows', (int) $sqlValue('SELECT COUNT(*) FROM sms_deliveries WHERE organization_id = :o', ['o' => $orgId]) === 0
        && (int) $sqlValue('SELECT COUNT(*) FROM email_deliveries WHERE organization_id = :o', ['o' => $orgId]) === 0);
    $check('manual_payments_ledger_untouched', (int) $sqlValue('SELECT COUNT(*) FROM payments') === $beforeCounts['payments']);
} catch (Throwable $e) {
    $check('suite_exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    foreach ($procs as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    // ---- Secrets never echoed ----
    $leak = false;
    foreach ($bodies as $text) {
        if (str_contains($text, $mockSecret) || str_contains($text, $mockWebhookSecret) || str_contains($text, 'sk_test_MOCKONLY')) {
            $leak = true;
        }
    }
    $check('no_secret_in_responses', !$leak);
    $logTail = '';
    if (is_file($appLogFile) && is_readable($appLogFile)) {
        $logTail = (string) file_get_contents($appLogFile, false, null, $appLogStart);
    }
    $logTail .= (string) @file_get_contents($appLog);
    $check('no_secret_in_logs', !str_contains($logTail, $mockSecret) && !str_contains($logTail, $mockWebhookSecret));

    // ---- Real organizations unchanged ----
    if ($realSnapshot !== []) {
        $changed = [];
        foreach ($realSnapshot as $id => $row) {
            if ($orgRow($id) != $row) {
                $changed[] = $id;
            }
        }
        $check('real_orgs_unchanged', $changed === [], 'changed=' . implode(',', $changed));
    }

    // ---- Cleanup: exact ids only ----
    $cleanupOk = true;
    try {
        foreach ($sentEventIds as $eid) {
            $pdo->prepare('DELETE FROM payment_webhook_events WHERE provider = :p AND event_id = :e')->execute(['p' => 'paymongo', 'e' => $eid]);
        }
    } catch (Throwable $e) {
        $cleanupOk = false;
        echo 'cleanup_error=' . $e->getMessage() . "\n";
    }
    if ($orgId > 0 && (string) $sqlValue('SELECT email FROM organizations WHERE id = :id', ['id' => $orgId]) === $orgEmail) {
        $ownerUid = (int) $sqlValue('SELECT id FROM users WHERE email = :e', ['e' => $ownerEmail]);
        try {
            foreach ([
                'DELETE FROM payment_transactions WHERE organization_id = :o',
                'DELETE FROM notifications WHERE organization_id = :o',
                'DELETE FROM youth WHERE organization_id = :o',
                'DELETE FROM payments WHERE organization_id = :o',
                'DELETE FROM activity_logs WHERE organization_id = :o',
                'DELETE FROM organization_users WHERE organization_id = :o',
            ] as $sql) {
                $pdo->prepare($sql)->execute(['o' => $orgId]);
            }
            if ($ownerUid > 0) {
                foreach (['notifications', 'password_reset_tokens', 'activity_logs', 'organization_users'] as $t) {
                    $pdo->prepare("DELETE FROM {$t} WHERE user_id = :id")->execute(['id' => $ownerUid]);
                }
                $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :e')->execute(['id' => $ownerUid, 'e' => $ownerEmail]);
            }
            $pdo->prepare('DELETE FROM organizations WHERE id = :id AND email = :e')->execute(['id' => $orgId, 'e' => $orgEmail]);
        } catch (Throwable $e) {
            $cleanupOk = false;
            echo 'cleanup_error=' . $e->getMessage() . "\n";
        }
        $left = (int) $sqlValue('SELECT COUNT(*) FROM organizations WHERE id = :id', ['id' => $orgId])
            + (int) $sqlValue("SELECT COUNT(*) FROM users WHERE email LIKE 'pm-test-%@example.test'")
            + (int) $sqlValue('SELECT COUNT(*) FROM payment_transactions WHERE organization_id = :id', ['id' => $orgId])
            + (int) $sqlValue("SELECT COUNT(*) FROM payment_webhook_events WHERE event_id LIKE :p", ['p' => 'evt_pmtest' . $stamp . '%']);
        $check('cleanup_removed_temp_rows', $cleanupOk && $left === 0, "left={$left}");
    }
    if ($beforeCounts !== null) {
        $after = $tableCounts();
        $check('table_counts_restored', $after === $beforeCounts, json_encode(array_diff_assoc($after, $beforeCounts)));
    }
    foreach ($jars as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

echo "\n(MOCK) passed={$passed} failed={$failed}\n";
echo $failed === 0 ? "RESULT=PASS (MOCK PayMongo)\n" : "RESULT=FAIL (MOCK PayMongo)\n";
exit($failed === 0 ? 0 : 1);
