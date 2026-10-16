<?php
declare(strict_types=1);

/**
 * Creates one real mysqldump and checks it is stored.
 * Does not restore the database and does not change organization rows.
 */

if (PHP_SAPI !== 'cli') {
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'YouthSync\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$pdo = youthsync_pdo();
$before = $pdo->query('SELECT id, plan, sub_status, expires_at FROM organizations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$backup = YouthSync\Infra\DatabaseBackup::storage();
$created = $backup->create();
$after = $pdo->query('SELECT id, plan, sub_status, expires_at FROM organizations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$path = $backup->path($created['file']);
$head = (string) file_get_contents($path, false, null, 0, 400);
$body = (string) file_get_contents($path);
$listed = false;
foreach ($backup->list() as $row) {
    if ($row['file'] === $created['file']) {
        $listed = true;
    }
}
$rejected = false;
try {
    $backup->path('../config/config.php');
} catch (Throwable) {
    $rejected = true;
}
(new YouthSync\Schedule\Scheduler($pdo))->ensureSeeded();
$job = $pdo->query("SELECT code, enabled, next_run_at FROM scheduled_jobs WHERE code = 'database.backup'")->fetch(PDO::FETCH_ASSOC);

$checks = [
    'dump_header' => str_contains($head, 'MariaDB dump') && str_contains($head, 'Database: youthsync'),
    'create_table_in_body' => str_contains($body, 'CREATE TABLE'),
    'drop_table_in_body' => str_contains($body, 'DROP TABLE IF EXISTS'),
    'create_table_not_required_in_header' => !str_contains($head, 'CREATE TABLE'),
    'organizations_unchanged' => $before === $after,
    'file_listed' => $listed,
    'path_outside_folder_rejected' => $rejected,
    'job_registered' => ($job['code'] ?? '') === 'database.backup' && (int) ($job['enabled'] ?? 0) === 1,
];
$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}\n";
    if (!$ok) {
        $failed++;
    }
}
echo 'file=' . $created['file'] . ' bytes=' . ($created['bytes'] ?? 0) . "\n";
echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
