<?php
declare(strict_types=1);

/**
 * Daily subscription expiry job (CLI only).
 * Moves every organization whose expires_at has passed to sub_status=expired
 * using PlanLimits::expireDue, the same rule as admin "run cycle" and the
 * lazy applyExpiry on SK/admin reads. Run by Windows Task Scheduler via
 * expire_subscriptions.bat.
 *
 * Usage:
 *   C:\xampp\php\php.exe api\bin\expire_subscriptions.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$logFile = dirname(__DIR__) . '/logs/subscription_expiry.log';

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
    $result = (new YouthSync\Schedule\Scheduler($pdo))->runJob('subscription.expire');

    // No activity_logs row here: those need an acting admin user.
    $summary = $result['status'] . ' ' . $result['message'];
    $log($summary);
    echo 'Subscription expiry: ' . $summary . "\n";
    exit($result['status'] === 'ok' ? 0 : 1);
} catch (Throwable $e) {
    $msg = sprintf('ERROR %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine());
    $log($msg);
    fwrite(STDERR, 'Subscription expiry failed: ' . $e->getMessage() . "\n");
    exit(1);
}