<?php
declare(strict_types=1);

/**
 * YouthSync scheduler (CLI only).
 *
 * Runs due rows in scheduled_jobs. Registered jobs:
 *   subscription.expire  — same expiry rule as the admin subscription cycle
 *   subscription.remind  — one notice while a trial or subscription is inside 3 days
 *   application.remind   — one later reminder for applications still waiting
 *   database.backup      — mysqldump stored under api/storage/backups
 *   scheduler.heartbeat  — records that this process ran
 *
 * Usage:
 *   C:\xampp\php\php.exe api\bin\scheduler.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$logFile = dirname(__DIR__) . '/logs/scheduler.log';

$log = static function (string $line) use ($logFile): void {
    @file_put_contents($logFile, '[' . date('c') . '] ' . $line . "\n", FILE_APPEND);
};

try {
    require dirname(__DIR__) . '/config/config.php';
    require dirname(__DIR__) . '/config/database.php';

    spl_autoload_register(static function (string $class): void {
        $prefix = 'YouthSync\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    $pdo = youthsync_pdo();
    $result = (new YouthSync\Schedule\Scheduler($pdo))->runDue();
    $parts = [];
    foreach ($result['ran'] as $job) {
        $parts[] = $job['code'] . '=' . $job['status'] . ':' . $job['message'];
    }
    $summary = $parts === [] ? 'ran=0' : implode(' ', $parts);
    $log($summary);
    echo 'Scheduler: ' . $summary . "\n";
    $failed = false;
    foreach ($result['ran'] as $job) {
        if ($job['status'] === 'failed') {
            $failed = true;
        }
    }
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    $log('ERROR ' . $e->getMessage());
    fwrite(STDERR, 'Scheduler failed: ' . $e->getMessage() . "\n");
    exit(1);
}
