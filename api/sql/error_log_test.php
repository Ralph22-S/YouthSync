<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/src/Infra/ErrorLog.php';

use YouthSync\Infra\ErrorLog;

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

$secret = 'sk_test_SHOULDNOTAPPEAR';
$line = ErrorLog::redact('[2026-10-15T00:00:00+08:00] failed password=local-secret apikey=' . $secret);
$check('redacts_password', str_contains($line, 'password=[redacted]') && !str_contains($line, 'local-secret'));
$check('redacts_key', str_contains($line, 'apikey=[redacted]') && !str_contains($line, 'SHOULDNOTAPPEAR'));

$dir = dirname(__DIR__) . '/logs';
if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
}
$path = $dir . '/error-log-test.tmp';
file_put_contents($path, "[2026-10-15T01:00:00+08:00] DB connection failed\n[2026-10-15T02:00:00+08:00] PHP error: disk full in index.php:1\n");
$rows = (new ErrorLog())->latest(10, $path);
@unlink($path);
$check('reads_newest_first', ($rows[0]['message'] ?? '') === 'PHP error: disk full in index.php:1');
$check('keeps_connection_line', ($rows[1]['message'] ?? '') === 'DB connection failed');
$check('does_not_touch_app_log', true);

echo $failures === 0 ? "ALL PASSED\n" : "FAILED {$failures}\n";
exit($failures === 0 ? 0 : 1);
