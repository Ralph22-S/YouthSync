<?php
declare(strict_types=1);

/**
 * Secure payment verification HTTP check - runs against a MOCK PayMongo.
 *
 * MOCK: no request leaves this machine. Like payments_http_test.php this suite starts local PHP
 * built-in servers: api/sql/mock/paymongo_mock_server.php (a MOCK of the PayMongo Checkout
 * Session + Payment API) and api/public/index.php with MOCK-only PAYMONGO_* process environment
 * (api/.env is NOT read for those values and NOT edited). A second API server is started with
 * PAYMONGO_TEST_FAULT=after_activation_update to prove the activation rolls back; that fault hook
 * is honoured only in test mode against a plain-http loopback (MOCK) API base.
 *
 * Covers: the verification service (webhook / admin reconcile / poll), refunds and out-of-order
 * events, that the removed manual / offline ledger routes answer 404 for every role and can no
 * longer write payments, and that verified_at is only set after provider verification.
 * Passing proves the server logic only; it does NOT prove real PayMongo payments work.
 *
 * Laptop-safe: every checkout / payment row belongs to ONE temporary organization created here
 * (pv-test-*@example.test, exact id). Real organizations are snapshotted and compared; cleanup
 * deletes only that organization's rows, its temp owner and the exact webhook event ids sent.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$config = require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

echo "=== payment_verification_http_test: MOCK PayMongo (no real provider calls) ===\n";

$demoPassword = 'YouthSync1!';
$youthEmail = 'youth-auth-test@example.test';
$youthPassword = 'YouthAuthTest1!';
$ownerPassword = 'PayVerify1!x';
$stamp = date('YmdHis') . substr(bin2hex(random_bytes(2)), 0, 3);
$orgEmail = "pv-test-org-{$stamp}@example.test";
$ownerEmail = "pv-test-owner-{$stamp}@example.test";
$barangay = "PayVerify{$stamp}";
$mockSecret = 'sk_test_MOCKONLY' . bin2hex(random_bytes(6));
$mockWebhookSecret = 'whsk_MOCKONLY' . bin2hex(random_bytes(6));

$pdo = youthsync_pdo();

$leftover = (int) $pdo->query("SELECT COUNT(*) FROM organizations WHERE email LIKE 'pv-test-%@example.test'")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'pv-test-%@example.test'")->fetchColumn();
if ($leftover > 0) {
    fwrite(STDERR, "STOP: rows from an earlier run (pv-test-*@example.test) exist. No cleanup was performed.\n");
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

function pv_req(string $method, string $url, mixed $body, ?string $cookie, array $extraHeaders = []): array
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

function pv_data(array $r): array
{
    $d = $r['json']['data'] ?? null;
    return is_array($d) ? $d : [];
}

function pv_code(array $r): string
{
    return (string) ($r['json']['error']['code'] ?? '');
}

function pv_detail(array $r): string
{
    $e = $r['json']['error'] ?? [];
    return 'status=' . $r['status'] . (isset($e['code']) ? ' code=' . $e['code'] : '') . ' ' . substr((string) ($e['message'] ?? $r['body']), 0, 300);
}

function pv_jar(): string
{
    $path = tempnam(sys_get_temp_dir(), 'yspv');
    if ($path === false) {
        throw new RuntimeException('Could not create a temp file.');
    }
    return $path;
}

function pv_free_port(): int
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
function pv_start(array $cmd, array $env, string $cwd, string $log)
{
    $spec = [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];
    $proc = proc_open($cmd, $spec, $pipes, $cwd, $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start ' . basename((string) end($cmd)));
    }
    fclose($pipes[0]);
    return $proc;
}

function pv_wait(string $url): bool
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

function pv_sign(string $raw, string $secret, ?int $t = null, string $key = 'te', bool $valid = true): string
{
    $t ??= time();
    $sig = hash_hmac('sha256', $t . '.' . $raw, $valid ? $secret : $secret . 'x');
    return 't=' . $t . ',' . ($key === 'te' ? 'te=' . $sig . ',li=' : 'te=,li=' . $sig);
}

function pv_event(string $eventId, string $type, array $resource, bool $livemode = false): string
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

function pv_payment(string $id, int $amount, string $pi, string $currency = 'PHP', string $status = 'paid', string $source = 'gcash'): array
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
function pv_refund(string $refId, string $payId, int $amount, string $status = 'succeeded'): array
{
    return ['id' => $refId, 'type' => 'refund', 'attributes' => [
        'amount' => $amount, 'currency' => 'PHP', 'status' => $status, 'payment_id' => $payId,
        'livemode' => false, 'reason' => 'requested_by_customer', 'created_at' => time(),
    ]];
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
        'sms_deliveries', 'email_deliveries', 'notifications', 'activity_logs'] as $t) {
        $out[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    }
    return $out;
};

$jars = [];
$anon = $jars[] = pv_jar();
$admin = $jars[] = pv_jar();
$sk1 = $jars[] = pv_jar();
$youth = $jars[] = pv_jar();
$owner = $jars[] = pv_jar();
$stateFile = $jars[] = pv_jar();
$mockLog = $jars[] = pv_jar();
$appLog = $jars[] = pv_jar();
file_put_contents($stateFile, '{}');

$procs = [];
$orgId = 0;
$sentEventIds = [];
$realSnapshot = [];
$beforeCounts = null;
$appLogFile = dirname(__DIR__) . '/logs/app.log';
$appLogStart = is_file($appLogFile) ? (int) filesize($appLogFile) : 0;

try {
    // ---- 1. MOCK PayMongo + local API (normal) + local API with a MOCK fault hook ----
    $mockPort = pv_free_port();
    $appPort = pv_free_port();
    $faultPort = pv_free_port();
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $procs[] = pv_start([PHP_BINARY, '-S', "127.0.0.1:{$mockPort}", __DIR__ . '/mock/paymongo_mock_server.php'],
        array_merge($env, ['MOCK_PAYMONGO_STATE' => $stateFile]), __DIR__ . '/mock', $mockLog);
    $mock = "http://127.0.0.1:{$mockPort}";
    $appEnv = array_merge($env, [
        'PAYMONGO_SECRET_KEY' => $mockSecret,
        'PAYMONGO_WEBHOOK_SECRET' => $mockWebhookSecret,
        'PAYMONGO_MODE' => 'test',
        'PAYMONGO_API_BASE' => $mock,
        'PAYMONGO_ALLOWED_METHODS' => 'card,gcash,paymaya',
        'PAYMONGO_SUCCESS_URL' => 'http://localhost:5173/sk/subscription/payment/success?tx={id}',
        'PAYMONGO_CANCEL_URL' => 'http://localhost:5173/sk/subscription/payment/cancel?tx={id}',
        'PAYMONGO_CONFIRM_BY_RETRIEVAL' => 'false',
        'PAYMONGO_WEBHOOK_TOLERANCE' => '300',
        'PAYMONGO_TEST_FAULT' => '',
    ]);
    $publicDir = dirname(__DIR__) . '/public';
    $procs[] = pv_start([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", $publicDir . '/index.php'], $appEnv, $publicDir, $appLog);
    $procs[] = pv_start([PHP_BINARY, '-S', "127.0.0.1:{$faultPort}", $publicDir . '/index.php'],
        array_merge($appEnv, ['PAYMONGO_TEST_FAULT' => 'after_activation_update']), $publicDir, $appLog);
    $base = "http://127.0.0.1:{$appPort}" . (string) $config['base_path'];
    $faultBase = "http://127.0.0.1:{$faultPort}" . (string) $config['base_path'];
    $check('mock_server_up (MOCK)', pv_wait("$mock/__mock/state"));
    $check('local_api_up', pv_wait("$base/auth/me"));
    $check('local_fault_api_up (MOCK fault hook)', pv_wait("$faultBase/auth/me"));
    $mockState = static fn (): array => (array) (pv_req('GET', "$mock/__mock/state", null, null)['json'] ?? []);
    $setSession = static fn (string $cs, array $change): array => pv_req('POST', "$mock/__mock/session/$cs", $change, null);
    $setFlags = static fn (array $flags): array => pv_req('POST', "$mock/__mock/flags", $flags, null);
    $sendTo = static function (string $b, string $raw, ?string $header): array {
        return pv_req('POST', "$b/payments/paymongo/webhook", $raw, null, $header === null ? [] : ['Paymongo-Signature: ' . $header]);
    };
    $send = static fn (string $raw, ?string $header): array => $sendTo($base, $raw, $header);
    $eventN = 0;
    $newEvent = static function () use (&$eventN, &$sentEventIds, $stamp): string {
        $eventN++;
        $id = 'evt_pvtest' . $stamp . 'n' . $eventN;
        $sentEventIds[] = $id;
        return $id;
    };
    $signed = static fn (string $raw): string => pv_sign($raw, $mockWebhookSecret);

    // ---- 2. Access control on the new admin verification routes ----
    $newRoutes = [
        ['POST', '/admin/payment-transactions/1/reconcile', []],
        ['POST', '/admin/payment-transactions/reconcile', ['reference' => 'pay_FAKE000000']],
    ];
    // The manual / offline ledger was removed: these routes no longer exist for anyone.
    $removedManualRoutes = [
        ['GET', '/admin/payments', null],
        ['POST', '/admin/payments', ['organizationId' => 1, 'amount' => 999, 'status' => 'paid', 'reference' => 'OR-REMOVED-1']],
        ['GET', '/admin/organizations/1/payments', null],
        ['POST', '/admin/organizations/1/payments', ['amount' => 999, 'status' => 'paid', 'reference' => 'OR-REMOVED-2']],
        ['POST', '/admin/payments/1/manual-verify', ['reason' => 'Bank slip checked by me']],
    ];
    // Admin routes that remain must still refuse anonymous and SK callers.
    $keptAdminReads = ['/admin/payment-transactions?perPage=5', '/admin/dashboard'];
    foreach ($newRoutes as [$m, $p, $b]) {
        $r = pv_req($m, $base . $p, $b, $anon);
        $check("anon_401 {$m} {$p}", $r['status'] === 401, pv_detail($r));
    }
    foreach ($keptAdminReads as $p) {
        $r = pv_req('GET', $base . $p, null, $anon);
        $check("anon_401 GET {$p}", $r['status'] === 401, pv_detail($r));
    }
    foreach ($removedManualRoutes as [$m, $p, $b]) {
        $r = pv_req($m, $base . $p, $b, $anon);
        $check("removed_route_anon_404 {$m} {$p}", $r['status'] === 404, pv_detail($r));
    }
    $r = pv_req('POST', "$base/auth/login", ['email' => 'admin@skmms.test', 'password' => $demoPassword], $admin);
    $check('admin_login', $r['status'] === 200, pv_detail($r));
    $r = pv_req('POST', "$base/auth/login", ['email' => 'sk1@demo.test', 'password' => $demoPassword], $sk1);
    $check('sk1_login', $r['status'] === 200, pv_detail($r));
    $r = pv_req('POST', "$base/auth/login", ['email' => $youthEmail, 'password' => $youthPassword], $youth);
    $check('youth_login', $r['status'] === 200, pv_detail($r));
    foreach ($newRoutes as [$m, $p, $b]) {
        $r = pv_req($m, $base . $p, $b, $sk1);
        $check("sk_forbidden {$m} {$p}", $r['status'] === 403, pv_detail($r));
        $r = pv_req($m, $base . $p, $b, $youth);
        $check("youth_forbidden {$m} {$p}", in_array($r['status'], [401, 403], true), pv_detail($r));
    }
    foreach ($keptAdminReads as $p) {
        $r = pv_req('GET', $base . $p, null, $sk1);
        $check("sk_forbidden GET {$p}", $r['status'] === 403, pv_detail($r));
        $r = pv_req('GET', $base . $p, null, $youth);
        $check("youth_forbidden GET {$p}", in_array($r['status'], [401, 403], true), pv_detail($r));
        $r = pv_req('GET', $base . $p, null, $admin);
        $check("admin_ok GET {$p}", $r['status'] === 200, pv_detail($r));
    }
    foreach ($removedManualRoutes as [$m, $p, $b]) {
        foreach (['sk' => $sk1, 'youth' => $youth, 'admin' => $admin] as $who => $jar) {
            $r = pv_req($m, $base . $p, $b, $jar);
            $check("removed_route_{$who}_404 {$m} {$p}", $r['status'] === 404, pv_detail($r));
        }
    }

    pv_req('GET', "$base/admin/organizations?perPage=1", null, $admin); // normal expiry sweep before the snapshot
    foreach ($pdo->query('SELECT id, plan, sub_status, cycle, started_on, expires_at, qr_uses, status FROM organizations ORDER BY id')->fetchAll() as $row) {
        $realSnapshot[(int) $row['id']] = $row;
    }
    $beforeCounts = $tableCounts();

    // ---- 3. Temporary organization ----
    $r = pv_req('POST', "$base/admin/organizations", [
        'barangay' => $barangay, 'municipality' => 'Testville', 'province' => 'Laguna',
        'chairperson' => 'Payment Verify Chair', 'email' => $orgEmail, 'contact' => '09170004001',
        'ownerName' => 'PayVerify Owner', 'ownerEmail' => $ownerEmail, 'ownerPassword' => $ownerPassword,
    ], $admin);
    array_pop($bodies); // the create response carries the temporary password by design
    $orgId = (int) (pv_data($r)['id'] ?? 0);
    $check('temp_org_created', $r['status'] === 201 && $orgId > 0, pv_detail($r));
    if ($orgId < 1) {
        throw new RuntimeException('Temp organization was not created; stopping.');
    }
    echo "temp_org_id={$orgId}\n";
    $r = pv_req('POST', "$base/auth/login", ['email' => $ownerEmail, 'password' => $ownerPassword], $owner);
    $check('temp_owner_login', $r['status'] === 200, pv_detail($r));
    $orgStart = $orgRow($orgId);
    $txCount = static fn (): int => (int) $sqlValue('SELECT COUNT(*) FROM payment_transactions WHERE organization_id = :o', ['o' => $orgId]);
    $subLogs = static fn (): int => (int) $sqlValue(
        "SELECT COUNT(*) FROM activity_logs WHERE organization_id = :o AND category = 'subscription' AND actor_name = 'PayMongo'",
        ['o' => $orgId]
    );
    $checkout = static function (string $plan, string $cycle) use ($base, $owner, $txRow): array {
        $r = pv_req('POST', "$base/sk/subscription/checkout", ['plan' => $plan, 'cycle' => $cycle], $owner);
        $id = (int) (pv_data($r)['transaction']['id'] ?? 0);
        return [$id, $id > 0 ? $txRow($id) : [], $r];
    };
    $reconcile = static fn (int $id, string $b = ''): array => pv_req('POST', ($b !== '' ? $b : $base) . "/admin/payment-transactions/{$id}/reconcile", [], $admin);

    // ---- 4. Manual / offline ledger removed: nothing can record or verify an offline payment ----
    $paymentsBefore = (int) $sqlValue('SELECT COUNT(*) FROM payments');
    foreach ([
        ['org_paid', 'POST', "/admin/organizations/{$orgId}/payments", ['reference' => 'OR-PV-' . $stamp, 'amount' => 999, 'status' => 'paid', 'planCode' => 'basic', 'method' => 'cash']],
        ['org_paymongo_ref', 'POST', "/admin/organizations/{$orgId}/payments", ['reference' => 'pay_FAKEabc123456', 'amount' => 999, 'status' => 'paid', 'planCode' => 'basic']],
        ['global_paid', 'POST', '/admin/payments', ['organizationId' => $orgId, 'amount' => 500, 'status' => 'paid', 'method' => 'bank']],
        ['manual_verify', 'POST', '/admin/payments/1/manual-verify', ['reason' => 'MOCK: BPI deposit slip 0042 checked']],
        ['org_list', 'GET', "/admin/organizations/{$orgId}/payments", null],
        ['global_list', 'GET', '/admin/payments?perPage=5', null],
    ] as [$name, $m, $p, $b]) {
        $r = pv_req($m, $base . $p, $b, $admin);
        $check("manual_ledger_removed_{$name}_404", $r['status'] === 404 && ($r['json']['error']['code'] ?? '') === 'NOT_FOUND', pv_detail($r));
    }
    $check('manual_ledger_removed_wrote_nothing', (int) $sqlValue('SELECT COUNT(*) FROM payments') === $paymentsBefore
        && $txCount() === 0 && $orgRow($orgId) == $orgStart
        && (int) $sqlValue('SELECT COUNT(*) FROM payment_transactions WHERE verified_at IS NOT NULL AND organization_id = :o', ['o' => $orgId]) === 0);
    $r = pv_req('GET', "$base/admin/dashboard", null, $admin);
    $dp = pv_data($r)['payments'] ?? [];
    $verifiedSql = $pdo->query(
        "SELECT COUNT(*) AS n, COALESCE(SUM(amount_centavos - COALESCE(refunded_centavos, 0)), 0) AS c FROM payment_transactions
         WHERE verified_at IS NOT NULL AND status IN ('paid', 'partially_refunded')"
    )->fetch();
    $check('overview_revenue_paymongo_verified_only', $r['status'] === 200 && ($dp['source'] ?? '') === 'paymongo_verified'
        && array_key_exists('onlineVerified', $dp) && abs((float) $dp['onlineVerified'] - ((int) $verifiedSql['c']) / 100) < 0.005
        && ($dp['onlineVerifiedCount'] ?? null) === (int) $verifiedSql['n']
        && !array_key_exists('collected', $dp) && !array_key_exists('recorded', $dp)
        && !array_key_exists('manualVerified', $dp) && !array_key_exists('manualUnverified', $dp), json_encode($dp));

    // ---- 5. Fake / unknown provider references can never verify anything ----
    foreach (['pay_FAKEnotours1234', 'cs_FAKEnotours1234', 'pi_FAKEnotours1234', 'YS-20261009-0123456789', 'not a ref'] as $ref) {
        $r = pv_req('POST', "$base/admin/payment-transactions/reconcile", ['reference' => $ref], $admin);
        $check("reconcile_fake_ref_404 {$ref}", $r['status'] === 404, pv_detail($r));
    }
    $r = pv_req('POST', "$base/admin/payment-transactions/reconcile", ['reference' => ''], $admin);
    $check('reconcile_empty_ref_422', $r['status'] === 422, pv_detail($r));
    $r = $reconcile(99999999);
    $check('reconcile_unknown_id_404', $r['status'] === 404, pv_detail($r));
    $check('fake_refs_no_change', $txCount() === 0 && $orgRow($orgId) == $orgStart);

    // ---- 6. Unpaid checkout: reconcile does nothing; provider "paid" alone does not set verified_at ----
    [$txA, $tA, $r] = $checkout('basic', 'monthly');
    $check('txA_created', $r['status'] === 201 && $txA > 0, pv_detail($r));
    $r = $reconcile($txA);
    $tA = $txRow($txA);
    $check('reconcile_unpaid_nothing', $r['status'] === 200 && (pv_data($r)['result'] ?? '') === 'unpaid' && $tA['status'] === 'pending'
        && $tA['verified_at'] === null && (pv_data($r)['transaction']['verification'] ?? '') === 'unverified' && $orgRow($orgId) == $orgStart, pv_detail($r));
    $check('reconcile_audited_admin', (int) $sqlValue(
        "SELECT COUNT(*) FROM activity_logs WHERE organization_id = :o AND entity_type = 'payment_transaction' AND entity_id = :id
         AND description LIKE 'Re-checked online payment%' AND actor_name <> 'PayMongo'", ['o' => $orgId, 'id' => $txA]) === 1);
    $payA = 'pay_mock' . bin2hex(random_bytes(6));
    $setSession((string) $tA['provider_reference'], ['payments' => [pv_payment($payA, 99900, (string) $tA['provider_payment_intent_id'], 'PHP', 'paid', 'card')]]);
    $pdo->prepare('UPDATE payment_transactions SET provider_checked_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = :id AND organization_id = :o')
        ->execute(['id' => $txA, 'o' => $orgId]);
    $r = pv_req('GET', "$base/sk/subscription/checkout/{$txA}", null, $owner);
    $v = pv_data($r)['transaction'] ?? [];
    $check('poll_paid_not_verified', $r['status'] === 200 && ($v['status'] ?? '') === 'pending' && ($v['verification'] ?? '') === 'awaiting_verification'
        && ($v['providerVerified'] ?? null) === false && array_key_exists('verifiedAt', $v) && $v['verifiedAt'] === null && $txRow($txA)['verified_at'] === null
        && $orgRow($orgId) == $orgStart, pv_detail($r));

    // ---- 7. DB failure mid-activation rolls back completely (MOCK fault hook) ----
    $evtA = $newEvent();
    $rawA = pv_event($evtA, 'checkout_session.payment.paid', $mockState()['sessions'][$tA['provider_reference']]);
    $r = $sendTo($faultBase, $rawA, $signed($rawA));
    $tA = $txRow($txA);
    $check('fault_webhook_500 (MOCK fault)', $r['status'] === 500, pv_detail($r));
    $check('fault_rolled_back', $tA['status'] === 'pending' && $tA['verified_at'] === null && $tA['applied_at'] === null
        && $tA['provider_payment_id'] === null && $orgRow($orgId) == $orgStart && $subLogs() === 0, json_encode($tA));
    $r = $reconcile($txA, $faultBase);
    $check('fault_reconcile_500_rolled_back (MOCK fault)', $r['status'] === 500 && $txRow($txA)['verified_at'] === null
        && $orgRow($orgId) == $orgStart && $subLogs() === 0, pv_detail($r));

    // ---- 8. Valid verified payment applies exactly once (retry of the same event) ----
    $r = $send($rawA, $signed($rawA));
    $tA = $txRow($txA);
    $oA = $orgRow($orgId);
    $check('webhook_valid_applied', $r['status'] === 200 && (pv_data($r)['result'] ?? '') === 'processed' && $tA['status'] === 'paid'
        && $tA['verified_at'] !== null && $tA['verified_via'] === 'webhook+fetch' && $tA['provider_payment_id'] === $payA
        && $tA['applied_at'] !== null && (int) $tA['review_required'] === 0, json_encode($tA) . ' ' . pv_detail($r));
    $check('activation_new_term', $oA['plan'] === 'basic' && $oA['sub_status'] === 'active' && $oA['started_on'] === $today
        && substr((string) $oA['expires_at'], 0, 10) === $dateAdd($today, '1 MONTH') && $subLogs() === 1, json_encode($oA));
    $r = pv_req('GET', "$base/sk/subscription/checkout/{$txA}", null, $owner);
    $v = pv_data($r)['transaction'] ?? [];
    $check('view_provider_verified', ($v['verification'] ?? '') === 'provider_verified' && ($v['verificationLabel'] ?? '') === 'Verified by PayMongo'
        && ($v['providerVerified'] ?? null) === true && ($v['verifiedAt'] ?? null) !== null && ($v['source'] ?? '') === 'paymongo', pv_detail($r));

    // duplicates / replays / other sources never apply twice
    $r = $send($rawA, $signed($rawA));
    $check('duplicate_event_id', $r['status'] === 200 && (pv_data($r)['duplicate'] ?? false) === true, pv_detail($r));
    $raw = pv_event($newEvent(), 'payment.paid', pv_payment($payA, 99900, (string) $tA['provider_payment_intent_id'], 'PHP', 'paid', 'card'));
    $r = $send($raw, $signed($raw));
    $check('second_paid_event_already_applied', (pv_data($r)['result'] ?? '') === 'already_applied', pv_detail($r));
    $r = $reconcile($txA);
    $check('reconcile_after_paid_already_applied', $r['status'] === 200 && (pv_data($r)['result'] ?? '') === 'already_applied', pv_detail($r));
    $r = pv_req('POST', "$base/admin/payment-transactions/reconcile", ['reference' => (string) $tA['reference']], $admin);
    $check('reconcile_by_stored_reference', $r['status'] === 200 && (pv_data($r)['transaction']['id'] ?? 0) === $txA
        && (pv_data($r)['result'] ?? '') === 'already_applied', pv_detail($r));
    $r = pv_req('POST', "$base/admin/payment-transactions/reconcile", ['reference' => $payA], $admin);
    $check('reconcile_by_stored_payment_id', $r['status'] === 200 && (pv_data($r)['transaction']['id'] ?? 0) === $txA, pv_detail($r));
    $check('applied_exactly_once', $orgRow($orgId) == $oA && $subLogs() === 1 && $txRow($txA)['verified_at'] === $tA['verified_at']);

    // out of order: payment.failed after paid cannot downgrade
    $raw = pv_event($newEvent(), 'payment.failed', pv_payment('pay_mockfail' . bin2hex(random_bytes(4)), 99900, (string) $tA['provider_payment_intent_id'], 'PHP', 'failed'));
    $r = $send($raw, $signed($raw));
    $t = $txRow($txA);
    $check('failed_after_paid_no_downgrade', $r['status'] === 200 && $t['status'] === 'paid' && $t['verified_at'] !== null
        && $t['provider_status'] === 'paid' && $orgRow($orgId) == $oA, json_encode($t) . ' ' . pv_detail($r));

    // ---- 9. Refund after activation: recorded + needs admin review, NO auto-downgrade ----
    $refA = 'ref_mock' . bin2hex(random_bytes(6));
    $refundedPayment = pv_payment($payA, 99900, (string) $tA['provider_payment_intent_id'], 'PHP', 'refunded', 'card');
    $refundedPayment['attributes']['refunds'] = [pv_refund($refA, $payA, 99900)];
    $setSession((string) $tA['provider_reference'], ['payments' => [$refundedPayment]]);
    $raw = pv_event($newEvent(), 'payment.refunded', $refundedPayment);
    $r = $send($raw, $signed($raw));
    $t = $txRow($txA);
    $check('refund_after_paid_recorded', $r['status'] === 200 && (pv_data($r)['result'] ?? '') === 'refunded' && $t['status'] === 'refunded'
        && (int) $t['refunded_centavos'] === 99900 && $t['refunded_at'] !== null && (int) $t['review_required'] === 1
        && $t['verified_at'] !== null, json_encode($t) . ' ' . pv_detail($r));
    $check('refund_no_auto_downgrade', $orgRow($orgId) == $oA);
    $check('refund_audited', (int) $sqlValue("SELECT COUNT(*) FROM activity_logs WHERE organization_id = :o AND entity_type = 'payment_transaction'
        AND entity_id = :id AND description LIKE '%refunded at PayMongo%'", ['o' => $orgId, 'id' => $txA]) === 1);
    $refundedAt = $t['refunded_at'];
    $raw = pv_event($newEvent(), 'payment.refund.updated', pv_refund($refA, $payA, 99900));
    $r = $send($raw, $signed($raw));
    $check('refund_event_replay_once', $r['status'] === 200 && (pv_data($r)['result'] ?? '') === 'already_refunded'
        && $txRow($txA)['refunded_at'] === $refundedAt, pv_detail($r));
    $raw = pv_event($newEvent(), 'checkout_session.payment.paid', $mockState()['sessions'][$tA['provider_reference']]);
    $r = $send($raw, $signed($raw));
    $check('paid_after_refund_no_regrant', $r['status'] === 200 && $txRow($txA)['status'] === 'refunded' && $orgRow($orgId) == $oA
        && $subLogs() === 1, pv_detail($r));
    $r = pv_req('GET', "$base/admin/payment-transactions?verification=needs_review&q=" . rawurlencode($barangay), null, $admin);
    $check('admin_filter_needs_review_has_refund', $r['status'] === 200 && in_array($txA, array_column(pv_data($r)['items'] ?? [], 'id'), true), pv_detail($r));

    // ---- 10. Mismatches -> review, verified_at stays NULL, never activates ----
    $mismatch = static function (string $name, array $sessionChange, callable $payment, string $via) use (
        $checkout, $setSession, $mockState, $newEvent, $send, $signed, $reconcile, $txRow, $orgRow, $orgId, $check
    ): int {
        [$id, $t] = $checkout('premium', 'monthly');
        $before = $orgRow($orgId);
        $sessionChange['payments'] = [$payment($t)];
        $setSession((string) $t['provider_reference'], $sessionChange);
        if ($via === 'webhook') {
            $raw = pv_event($newEvent(), 'checkout_session.payment.paid', $mockState()['sessions'][$t['provider_reference']]);
            $r = $send($raw, $signed($raw));
        } else {
            $r = $reconcile($id);
        }
        $t = $txRow($id);
        $check("{$name}_review ({$via})", $r['status'] === 200 && (pv_data($r)['result'] ?? '') === 'review' && $t['status'] === 'review'
            && $t['verified_at'] === null && $t['applied_at'] === null && (int) $t['review_required'] === 1 && $orgRow($orgId) == $before,
            json_encode($t) . ' ' . pv_detail($r));
        return $id;
    };
    $pp = static fn (int $amount, string $currency = 'PHP'): callable =>
        static fn (array $t): array => pv_payment('pay_mock' . bin2hex(random_bytes(6)), $amount, (string) $t['provider_payment_intent_id'], $currency);
    $mismatch('amount_mismatch', [], $pp(100), 'reconcile');
    $mismatch('currency_mismatch', [], $pp(199900, 'USD'), 'webhook');
    $org1Before = $orgRow(1);
    $txWrongOrg = $mismatch('wrong_org', ['attributes' => ['metadata' => ['organization_id' => '1']]], $pp(199900), 'reconcile');
    $check('wrong_org_real_org_untouched', $orgRow(1) == $org1Before);
    $mismatch('wrong_tx_metadata', ['attributes' => ['metadata' => ['transaction_id' => (string) $txA]]], $pp(199900), 'webhook');
    $mismatch('intent_mismatch', [], static fn (array $t): array => pv_payment('pay_mock' . bin2hex(random_bytes(6)), 199900, 'pi_mockOTHERintent00'), 'reconcile');
    $r = $reconcile($txWrongOrg);
    $check('review_not_auto_activated_by_reconcile', (pv_data($r)['result'] ?? '') === 'review' && $txRow($txWrongOrg)['status'] === 'review'
        && $txRow($txWrongOrg)['verified_at'] === null, pv_detail($r));

    // ---- 11. pending / failed / cancelled / expired / refunded never activate ----
    $o = $orgRow($orgId);
    [$txP, $tP] = $checkout('premium', 'monthly');
    $r = $reconcile($txP);
    $check('pending_reconcile_not_activated', (pv_data($r)['result'] ?? '') === 'unpaid' && $txRow($txP)['verified_at'] === null && $orgRow($orgId) == $o, pv_detail($r));
    $raw = pv_event($newEvent(), 'payment.failed', pv_payment('pay_mockfail' . bin2hex(random_bytes(4)), 199900, (string) $tP['provider_payment_intent_id'], 'PHP', 'failed'));
    $r = $send($raw, $signed($raw));
    $check('failed_not_activated', (pv_data($r)['result'] ?? '') === 'unpaid' && $txRow($txP)['status'] === 'pending'
        && $txRow($txP)['provider_status'] === 'payment_failed' && $txRow($txP)['verified_at'] === null && $orgRow($orgId) == $o, pv_detail($r));
    $setSession((string) $tP['provider_reference'], ['status' => 'expired']);
    $r = $reconcile($txP);
    $check('expired_not_activated', (pv_data($r)['result'] ?? '') === 'expired' && $txRow($txP)['status'] === 'expired'
        && $txRow($txP)['verified_at'] === null && $orgRow($orgId) == $o, pv_detail($r));

    [$txC, $tC] = $checkout('premium', 'monthly');
    $r = pv_req('POST', "$base/sk/subscription/checkout/{$txC}/cancel", [], $owner);
    $check('cancel_ok', $r['status'] === 200 && $txRow($txC)['status'] === 'cancelled', pv_detail($r));
    $setSession((string) $tC['provider_reference'], ['payments' => [pv_payment('pay_mock' . bin2hex(random_bytes(6)), 199900, (string) $tC['provider_payment_intent_id'])]]);
    $r = $reconcile($txC);
    $check('cancelled_then_paid_review_not_activated', in_array(pv_data($r)['result'] ?? '', ['review', 'not_pending'], true)
        && $txRow($txC)['applied_at'] === null && $txRow($txC)['verified_at'] === null && $orgRow($orgId) == $o, pv_detail($r));

    [$txR, $tR] = $checkout('premium', 'monthly');
    $payR = 'pay_mock' . bin2hex(random_bytes(6));
    $pr = pv_payment($payR, 199900, (string) $tR['provider_payment_intent_id'], 'PHP', 'refunded');
    $pr['attributes']['refunds'] = [pv_refund('ref_mock' . bin2hex(random_bytes(6)), $payR, 199900)];
    $setSession((string) $tR['provider_reference'], ['payments' => [$pr]]);
    $raw = pv_event($newEvent(), 'checkout_session.payment.paid', $mockState()['sessions'][$tR['provider_reference']]);
    $r = $send($raw, $signed($raw));
    $t = $txRow($txR);
    $check('refunded_before_activation_never_activates', (pv_data($r)['result'] ?? '') === 'refunded' && $t['status'] === 'refunded'
        && $t['applied_at'] === null && $t['verified_at'] === null && $orgRow($orgId) == $o && $subLogs() === 1, json_encode($t) . ' ' . pv_detail($r));

    // pending refund (status "pending" at PayMongo) is not treated as refunded
    [$txQ, $tQ] = $checkout('premium', 'monthly');
    $payQ = 'pay_mock' . bin2hex(random_bytes(6));
    $pq = pv_payment($payQ, 199900, (string) $tQ['provider_payment_intent_id']);
    $setSession((string) $tQ['provider_reference'], ['payments' => [$pq]]);

    // ---- 12. Provider unreachable during reconcile -> 503, nothing changes; then admin reconcile verifies ----
    $setFlags(['fail_payment_retrieve' => true]);
    $r = $reconcile($txQ);
    $check('reconcile_unavailable_503 (MOCK)', $r['status'] === 503 && pv_code($r) === 'VERIFICATION_UNAVAILABLE'
        && $txRow($txQ)['status'] === 'pending' && $txRow($txQ)['verified_at'] === null && $orgRow($orgId) == $o, pv_detail($r));
    $setFlags(['fail_payment_retrieve' => false]);
    $r = $reconcile($txQ);
    $t = $txRow($txQ);
    $oQ = $orgRow($orgId);
    $check('admin_reconcile_verifies_and_applies', $r['status'] === 200 && (pv_data($r)['result'] ?? '') === 'applied' && $t['status'] === 'paid'
        && $t['verified_at'] !== null && $t['verified_via'] === 'admin reconcile+fetch' && $oQ['plan'] === 'premium'
        && $oQ['started_on'] === $today && substr((string) $oQ['expires_at'], 0, 10) === $dateAdd($today, '1 MONTH') && $subLogs() === 2,
        json_encode($t) . ' ' . json_encode($oQ) . ' ' . pv_detail($r));
    $r = $reconcile($txQ);
    $check('admin_reconcile_twice_no_double', (pv_data($r)['result'] ?? '') === 'already_applied' && $orgRow($orgId) == $oQ && $subLogs() === 2, pv_detail($r));
    $pq['attributes']['refunds'] = [pv_refund('ref_mock' . bin2hex(random_bytes(6)), $payQ, 50000, 'pending')];
    $setSession((string) $tQ['provider_reference'], ['payments' => [$pq]]);
    $r = $reconcile($txQ);
    $check('pending_refund_not_counted', (pv_data($r)['result'] ?? '') === 'already_applied' && $txRow($txQ)['refunded_centavos'] === null, pv_detail($r));
    $pq['attributes']['refunds'] = [pv_refund('ref_mock' . bin2hex(random_bytes(6)), $payQ, 50000)];
    $setSession((string) $tQ['provider_reference'], ['payments' => [$pq]]);
    $r = $reconcile($txQ);
    $t = $txRow($txQ);
    $check('partial_refund_review_no_downgrade', (pv_data($r)['result'] ?? '') === 'refunded' && $t['status'] === 'partially_refunded'
        && (int) $t['refunded_centavos'] === 50000 && (int) $t['review_required'] === 1 && $orgRow($orgId) == $oQ, json_encode($t));

    // ---- 13. Webhook signature / payload checks (nothing changes) ----
    [$txS, $tS] = $checkout('basic', 'monthly');
    $setSession((string) $tS['provider_reference'], ['payments' => [pv_payment('pay_mock' . bin2hex(random_bytes(6)), 99900, (string) $tS['provider_payment_intent_id'])]]);
    $raw = pv_event($newEvent(), 'checkout_session.payment.paid', $mockState()['sessions'][$tS['provider_reference']]);
    $before = $orgRow($orgId);
    $r = $send($raw, pv_sign($raw, $mockWebhookSecret, null, 'te', false));
    $check('invalid_signature_401', $r['status'] === 401 && pv_code($r) === 'INVALID_SIGNATURE', pv_detail($r));
    $r = $send($raw, pv_sign($raw, $mockWebhookSecret, null, 'li'));
    $check('live_signature_in_test_mode_401', $r['status'] === 401, pv_detail($r));
    $r = $send($raw, pv_sign($raw, $mockWebhookSecret, time() - 3600));
    $check('stale_timestamp_401', $r['status'] === 401, pv_detail($r));
    $r = $send($raw, null);
    $check('missing_signature_400', $r['status'] === 400, pv_detail($r));
    foreach (['not json', '{"data":{"id":"evt_x","attributes":{"type":"checkout_session.payment.paid"}}}', '{"data":[]}', '[]'] as $i => $bad) {
        $r = $send($bad, $signed($bad));
        $check("malformed_payload_400 #{$i}", $r['status'] === 400, pv_detail($r));
    }
    $check('rejected_webhooks_no_change', $txRow($txS)['status'] === 'pending' && $txRow($txS)['verified_at'] === null && $orgRow($orgId) == $before);
    $raw2 = pv_event($newEvent(), 'payment.refund.updated', ['id' => 'ref_mockunknown01', 'type' => 'refund', 'attributes' => ['payment_id' => 'pay_mockunknown0001']]);
    $r = $send($raw2, $signed($raw2));
    $check('refund_event_unknown_payment_unmatched', $r['status'] === 200 && in_array(pv_data($r)['result'] ?? '', ['unmatched'], true), pv_detail($r));
    $raw3 = pv_event($newEvent(), 'link.payment.paid', ['id' => 'link_mock1', 'type' => 'link', 'attributes' => []]);
    $r = $send($raw3, $signed($raw3));
    $check('unsupported_event_ignored', $r['status'] === 200 && (pv_data($r)['result'] ?? '') === 'ignored', pv_detail($r));

    // ---- 14. Admin Payment History: verified vs unverified is explicit ----
    $r = pv_req('GET', "$base/admin/payment-transactions?perPage=50&q=" . rawurlencode($barangay), null, $admin);
    $h = pv_data($r);
    $byId = [];
    foreach ($h['items'] ?? [] as $it) {
        $byId[(int) $it['id']] = $it;
    }
    $iA = $byId[$txA] ?? [];
    $check('history_fields', $r['status'] === 200 && ($iA['source'] ?? '') === 'paymongo' && ($iA['verification'] ?? '') === 'provider_verified'
        && ($iA['verifiedAt'] ?? null) !== null && ($iA['verifiedVia'] ?? '') === 'webhook+fetch' && ($iA['providerPaymentId'] ?? '') === $payA
        && array_key_exists('providerReference', $iA) && array_key_exists('providerPaymentIntentId', $iA) && ($iA['currency'] ?? '') === 'PHP'
        && ($iA['refundedAmount'] ?? 0) == 999 && ($iA['reviewRequired'] ?? false) === true && ($iA['canReconcile'] ?? false) === true, json_encode($iA));
    $iS = $byId[$txS] ?? [];
    $check('history_unverified_row', ($iS['verification'] ?? '') === 'unverified' && array_key_exists('verifiedAt', $iS) && $iS['verifiedAt'] === null
        && ($iS['providerVerified'] ?? null) === false, json_encode($iS));
    $r = pv_req('GET', "$base/admin/payment-transactions?verification=provider_verified&q=" . rawurlencode($barangay), null, $admin);
    $ids = array_column(pv_data($r)['items'] ?? [], 'id');
    sort($ids);
    $check('history_filter_provider_verified', $r['status'] === 200 && $ids === [$txA, $txQ], json_encode($ids));
    $r = pv_req('GET', "$base/admin/payment-transactions?verification=bogus", null, $admin);
    $check('history_bad_verification_filter_422', $r['status'] === 422, pv_detail($r));
    $r = pv_req('GET', "$base/admin/payment-transactions?status=refunded&q=" . rawurlencode($barangay), null, $admin);
    $check('history_status_refunded_filter', $r['status'] === 200 && (pv_data($r)['total'] ?? 0) === 2, pv_detail($r));
    foreach ([['POST', "/admin/payment-transactions/{$txS}"], ['PATCH', "/admin/payment-transactions/{$txS}"],
        ['POST', "/admin/payment-transactions/{$txS}/mark-paid"], ['POST', "/admin/payment-transactions/{$txS}/verify"], ['POST', '/admin/payment-transactions']] as [$m, $p]) {
        $r = pv_req($m, $base . $p, ['status' => 'paid', 'verified' => true], $admin);
        $check("no_admin_mark_verified {$m} {$p}", in_array($r['status'], [404, 405], true), pv_detail($r));
    }
    $check('txS_still_unverified', $txRow($txS)['verified_at'] === null && $txRow($txS)['status'] === 'pending');
    $r = pv_req('POST', "$base/admin/payment-transactions/{$txS}/reconcile", ['status' => 'paid', 'amount' => 1], $admin);
    $check('reconcile_ignores_body_status', $r['status'] === 200 && (pv_data($r)['result'] ?? '') === 'applied'
        && $txRow($txS)['amount_centavos'] == 99900, pv_detail($r));

    // ---- 15. verified_at is set only on verified rows; no other row has it ----
    $check('verified_at_only_on_verified', (int) $sqlValue(
        "SELECT COUNT(*) FROM payment_transactions WHERE organization_id = :o AND verified_at IS NOT NULL", ['o' => $orgId]) === 3
        && (int) $sqlValue("SELECT COUNT(*) FROM payment_transactions WHERE organization_id = :o AND verified_at IS NOT NULL AND applied_at IS NULL", ['o' => $orgId]) === 0);
    $check('no_legacy_payments_rows_written', (int) $sqlValue('SELECT COUNT(*) FROM payments WHERE organization_id = :o', ['o' => $orgId]) === 0);
    $check('no_sms_or_email_rows', (int) $sqlValue('SELECT COUNT(*) FROM sms_deliveries WHERE organization_id = :o', ['o' => $orgId]) === 0
        && (int) $sqlValue('SELECT COUNT(*) FROM email_deliveries WHERE organization_id = :o', ['o' => $orgId]) === 0);
} catch (Throwable $e) {
    $check('suite_exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    foreach ($procs as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

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
            + (int) $sqlValue("SELECT COUNT(*) FROM users WHERE email LIKE 'pv-test-%@example.test'")
            + (int) $sqlValue('SELECT COUNT(*) FROM payment_transactions WHERE organization_id = :id', ['id' => $orgId])
            + (int) $sqlValue('SELECT COUNT(*) FROM payments WHERE organization_id = :id', ['id' => $orgId])
            + (int) $sqlValue('SELECT COUNT(*) FROM payment_webhook_events WHERE event_id LIKE :p', ['p' => 'evt_pvtest' . $stamp . '%']);
        $check('cleanup_removed_temp_rows', $cleanupOk && $left === 0, "left={$left}");
    }
    if ($beforeCounts !== null) {
        $after = $tableCounts();
        $diff = array_diff_assoc($after, $beforeCounts);
        $check('table_counts_restored', $diff === [], json_encode($diff));
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
