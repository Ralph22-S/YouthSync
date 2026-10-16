<?php
declare(strict_types=1);

/**
 * MOCK PayMongo API - TEST ONLY. Never deploy; it is not PayMongo.
 *
 * Started by api/sql/payments_http_test.php with the PHP built-in server bound to
 * 127.0.0.1 (php -S 127.0.0.1:<port> paymongo_mock_server.php). Implements just the
 * Checkout Session calls YouthSync makes, in the documented response shape:
 *   POST /v1/checkout_sessions            create (needs Basic auth user sk_test_*)
 *   GET  /v1/checkout_sessions/{id}       retrieve
 *   POST /v1/checkout_sessions/{id}/expire
 *   GET  /v1/payments/{id}                retrieve a payment (MOCK: looked up in the sessions'
 *                                         payments[], newest session first, incl. refunds[])
 * plus test controls (MOCK only):
 *   POST /__mock/flags                    {"fail_next_create":bool,"fail_retrieve":bool,"fail_payment_retrieve":bool}
 *   POST /__mock/session/{id}             merge {"status":..,"payments":[..],"attributes":{..}}
 *   GET  /__mock/state                    sessions + recorded create requests (no keys)
 * State lives in the JSON file named by MOCK_PAYMONGO_STATE.
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit("MOCK server: run with php -S\n");
}

$stateFile = (string) getenv('MOCK_PAYMONGO_STATE');
if ($stateFile === '') {
    http_response_code(500);
    exit('{"errors":[{"code":"mock_misconfigured","detail":"MOCK_PAYMONGO_STATE not set"}]}');
}

function mock_out(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

$fh = fopen($stateFile, 'c+');
flock($fh, LOCK_EX);
$raw = stream_get_contents($fh);
$state = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true) ?: [];
$state += ['sessions' => [], 'requests' => [], 'flags' => []];
$state['flags'] += ['fail_next_create' => false, 'fail_retrieve' => false, 'fail_payment_retrieve' => false];

$save = static function () use (&$state, $fh): void {
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state, JSON_UNESCAPED_SLASHES));
    fflush($fh);
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true);

// ---- test controls ----
if ($path === '/__mock/state' && $method === 'GET') {
    mock_out(200, $state);
}
if ($path === '/__mock/flags' && $method === 'POST') {
    foreach (['fail_next_create', 'fail_retrieve', 'fail_payment_retrieve'] as $flag) {
        if (is_array($body) && array_key_exists($flag, $body)) {
            $state['flags'][$flag] = (bool) $body[$flag];
        }
    }
    $save();
    mock_out(200, ['ok' => true, 'flags' => $state['flags']]);
}
if (preg_match('#^/__mock/session/(cs_[A-Za-z0-9]+)$#', $path, $m) && $method === 'POST') {
    if (!isset($state['sessions'][$m[1]])) {
        mock_out(404, ['errors' => [['code' => 'resource_not_found', 'detail' => 'MOCK: no such session']]]);
    }
    $attrs = &$state['sessions'][$m[1]]['attributes'];
    if (isset($body['status'])) {
        $attrs['status'] = (string) $body['status'];
    }
    if (isset($body['payments']) && is_array($body['payments'])) {
        $attrs['payments'] = $body['payments'];
    }
    if (isset($body['attributes']) && is_array($body['attributes'])) {
        $attrs = array_replace_recursive($attrs, $body['attributes']);
    }
    unset($attrs);
    $save();
    mock_out(200, ['data' => $state['sessions'][$m[1]]]);
}

// ---- PayMongo-shaped API ----
$user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
if ($user === '' && preg_match('/^Basic\s+(.+)$/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $am)) {
    $user = explode(':', (string) base64_decode($am[1]), 2)[0];
}
if (!str_starts_with($user, 'sk_test_')) {
    mock_out(401, ['errors' => [['code' => 'api_key_invalid', 'detail' => 'MOCK: secret key missing or not a test key']]]);
}

if ($path === '/v1/checkout_sessions' && $method === 'POST') {
    if ($state['flags']['fail_next_create']) {
        $state['flags']['fail_next_create'] = false;
        $save();
        mock_out(500, ['errors' => [['code' => 'mock_provider_failure', 'detail' => 'MOCK provider failure']]]);
    }
    $a = is_array($body) ? ($body['data']['attributes'] ?? null) : null;
    $ok = is_array($a) && isset($a['line_items'][0]) && is_array($a['payment_method_types'] ?? null) && $a['payment_method_types'] !== [];
    foreach ((array) ($a['line_items'] ?? []) as $li) {
        $ok = $ok && is_int($li['amount'] ?? null) && ($li['currency'] ?? '') === 'PHP';
    }
    foreach ((array) ($a['metadata'] ?? []) as $v) {
        $ok = $ok && is_string($v);
    }
    if (!$ok) {
        mock_out(400, ['errors' => [['code' => 'parameter_invalid', 'detail' => 'MOCK: invalid checkout attributes']]]);
    }
    $state['requests'][] = ['attributes' => $a, 'auth_user_prefix' => substr($user, 0, 8)];
    $id = 'cs_mock' . bin2hex(random_bytes(8));
    $pi = 'pi_mock' . bin2hex(random_bytes(8));
    $now = time();
    $session = [
        'id' => $id,
        'type' => 'checkout_session',
        'attributes' => array_merge($a, [
            'checkout_url' => 'https://checkout.paymongo.mock/' . $id . '#MOCK',
            'client_key' => $id . '_client_mock',
            'livemode' => false,
            'status' => 'active',
            'payments' => [],
            'payment_intent' => ['id' => $pi, 'type' => 'payment_intent', 'attributes' => ['amount' => $a['line_items'][0]['amount'], 'currency' => 'PHP', 'status' => 'awaiting_payment_method']],
            'payment_method_used' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]),
    ];
    $state['sessions'][$id] = $session;
    $save();
    mock_out(200, ['data' => $session]);
}

if (preg_match('#^/v1/checkout_sessions/(cs_[A-Za-z0-9]+)(/expire)?$#', $path, $m)) {
    if ($state['flags']['fail_retrieve'] && empty($m[2])) {
        mock_out(503, ['errors' => [['code' => 'mock_unavailable', 'detail' => 'MOCK retrieval unavailable']]]);
    }
    if (!isset($state['sessions'][$m[1]])) {
        mock_out(404, ['errors' => [['code' => 'resource_not_found', 'detail' => 'MOCK: no such session']]]);
    }
    if (!empty($m[2]) && $method === 'POST') {
        $state['sessions'][$m[1]]['attributes']['status'] = 'expired';
        $save();
    }
    mock_out(200, ['data' => $state['sessions'][$m[1]]]);
}

if (preg_match('#^/v1/payments/(pay_[A-Za-z0-9]+)$#', $path, $m) && $method === 'GET') {
    if ($state['flags']['fail_payment_retrieve']) {
        mock_out(503, ['errors' => [['code' => 'mock_unavailable', 'detail' => 'MOCK payment retrieval unavailable']]]);
    }
    foreach (array_reverse($state['sessions']) as $session) {
        foreach ((array) ($session['attributes']['payments'] ?? []) as $payment) {
            if (is_array($payment) && ($payment['id'] ?? null) === $m[1]) {
                mock_out(200, ['data' => $payment]);
            }
        }
    }
    mock_out(404, ['errors' => [['code' => 'resource_not_found', 'detail' => 'MOCK: no such payment']]]);
}

mock_out(404, ['errors' => [['code' => 'route_not_found', 'detail' => 'MOCK: unknown route']]]);
