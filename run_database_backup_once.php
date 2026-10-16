<?php
declare(strict_types=1);

/**
 * Temporary CLI runner. Invokes only database.backup.
 * Delete this file after the test. It is not part of the application.
 *
 * Usage:
 *   C:\xampp\php\php.exe run_database_backup_once.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require __DIR__ . '/api/config/config.php';
require __DIR__ . '/api/config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'YouthSync\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/api/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$result = (new YouthSync\Schedule\Scheduler(youthsync_pdo()))->runJob('database.backup');
echo $result['status'] . ' ' . $result['message'] . PHP_EOL;
exit(($result['status'] ?? '') === 'ok' ? 0 : 1);
