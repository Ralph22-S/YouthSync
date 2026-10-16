<?php
declare(strict_types=1);

/**
 * Backup failure checks use a temporary folder and a stand-in program.
 * The live backup folder, api/.env, and organization rows are not changed.
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

$failed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}";
    if (!$ok && $detail !== '') {
        echo ' — ' . $detail;
    }
    echo "\n";
    if (!$ok) {
        $failed++;
    }
};

$realDir = dirname(__DIR__) . '/storage/backups';
$beforeFiles = glob($realDir . '/youthsync-*.sql') ?: [];
sort($beforeFiles);

$pdo = youthsync_pdo();
$beforeOrgs = $pdo->query('SELECT id, plan, sub_status, expires_at FROM organizations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys-backup-fail-' . bin2hex(random_bytes(4));
$backupDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
mkdir($backupDir, 0750, true);
$controller = (string) file_get_contents(dirname(__DIR__) . '/src/Controllers/AdminController.php');

$missing = $root . DIRECTORY_SEPARATOR . 'missing-mysqldump.exe';
$missingError = '';
try {
    (new YouthSync\Infra\DatabaseBackup($backupDir, $missing))->create();
    $check('missing_program_fails', false, 'create returned');
} catch (Throwable $e) {
    $missingError = $e->getMessage();
    $check('missing_program_fails', $missingError === 'mysqldump was not found.', $missingError);
}
$check('missing_program_leaves_no_file', glob($backupDir . '/youthsync-*.sql') === [] || glob($backupDir . '/youthsync-*.sql') === false);

$fake = $root . DIRECTORY_SEPARATOR . 'fake-mysqldump.bat';
file_put_contents($fake, "@echo off\r\necho broken mysqldump 1>&2\r\nfor %%A in (%*) do (\r\n  echo %%A | findstr /b /c:\"--result-file=\" >nul\r\n  if not errorlevel 1 (\r\n    set \"arg=%%A\"\r\n    call set \"out=%%arg:~14%%\"\r\n    call echo partial>\"%%out%%\"\r\n  )\r\n)\r\nexit /b 1\r\n");
$brokenError = '';
try {
    (new YouthSync\Infra\DatabaseBackup($backupDir, $fake))->create();
    $check('invalid_program_fails', false, 'create returned');
} catch (Throwable $e) {
    $brokenError = $e->getMessage();
    $check(
        'invalid_program_fails',
        in_array($brokenError, ['Database backup failed.', 'mysqldump could not be started.'], true),
        $brokenError
    );
}
$left = glob($backupDir . '/youthsync-*.sql');
$check('invalid_program_leaves_no_partial', $left === [] || $left === false, json_encode($left));
$log = $root . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'backup.err.log';
$logText = is_file($log) ? (string) file_get_contents($log) : '';
$check(
    'failure_is_recorded',
    str_contains($logText, 'broken mysqldump') || str_contains($logText, 'mysqldump exited with status'),
    $logText === '' ? $brokenError : trim($logText)
);
$check(
    'http_failure_is_500',
    str_contains($controller, "Json::error('BACKUP_FAILED', \$e->getMessage(), 500)")
        && $missingError !== ''
        && $brokenError !== ''
);

$afterFiles = glob($realDir . '/youthsync-*.sql') ?: [];
sort($afterFiles);
$check('real_backup_files_unchanged', $beforeFiles === $afterFiles);
$afterOrgs = $pdo->query('SELECT id, plan, sub_status, expires_at FROM organizations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$check('organizations_unchanged', $beforeOrgs === $afterOrgs);

$cleanup = static function (string $dir) use (&$cleanup): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $cleanup($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
};
$cleanup($root);

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
