<?php
declare(strict_types=1);

/**
 * Production environment guard. Does not read or print secret values.
 * Usage: php api/sql/env_production_guard_test.php
 */

$config = require dirname(__DIR__) . '/config/config.php';

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

$check('local_env_still_loads', ($config['env'] ?? '') === 'development');
$check('local_port', youthsync_env('DB_PORT', '') === '3307');
$check('local_db', youthsync_env('DB_NAME', '') === 'youthsync');

$unsafe = youthsync_production_problems([
    'db_password_set' => false,
    'session_secure' => false,
    'demo_mode_requested' => true,
    'debug' => true,
]);
$check('unsafe_names_db', in_array('DB_PASSWORD is required.', $unsafe, true));
$check('unsafe_names_session', in_array('SESSION_SECURE must be true.', $unsafe, true));
$check('unsafe_names_demo', in_array('DEMO_MODE must be false.', $unsafe, true));
$check('unsafe_names_debug', in_array('APP_DEBUG must be false.', $unsafe, true));
$joined = implode(' ', $unsafe);
$check('unsafe_has_no_secret_shape', !preg_match('/sk_|whsk_|re_|apikey/i', $joined));

$safe = youthsync_production_problems([
    'db_password_set' => true,
    'session_secure' => true,
    'demo_mode_requested' => false,
    'debug' => false,
]);
$check('safe_production_flags', $safe === []);

echo $failures === 0 ? "ALL PASSED\n" : "FAILED {$failures}\n";
exit($failures === 0 ? 0 : 1);
