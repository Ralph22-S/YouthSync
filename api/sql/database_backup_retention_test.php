<?php
declare(strict_types=1);

/**
 * Retention checks use disposable files in a temporary directory.
 * api/storage/backups is only listed, never written.
 */

if (PHP_SAPI !== 'cli') {
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';

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

$names = static function (string $dir): array {
    $rows = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
    $base = array_map('basename', $rows);
    sort($base);
    return $base;
};

$realDir = dirname(__DIR__) . '/storage/backups';
$beforeReal = glob($realDir . '/youthsync-*.sql') ?: [];
sort($beforeReal);

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys-backup-keep-' . bin2hex(random_bytes(4));
mkdir($root, 0750, true);
$backup = new YouthSync\Infra\DatabaseBackup($root);
$prune = new ReflectionMethod($backup, 'prune');
$prune->setAccessible(true);

$write = static function (string $dir, string $name, int $mtime): void {
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, 'disposable');
    touch($path, $mtime);
};

$base = time() - 86400;
for ($i = 1; $i <= 3; $i++) {
    $write($root, sprintf('youthsync-20260101-%06d.sql', $i), $base + ($i * 60));
}
$write($root, 'notes.txt', $base);
$write($root, 'other.sql', $base);
$write($root, 'youthsync-not-a-backup.sql', $base);
$prune->invoke($backup);
$few = $names($root);
$check(
    'fewer_than_14_are_kept',
    $few === [
        'notes.txt',
        'other.sql',
        'youthsync-20260101-000001.sql',
        'youthsync-20260101-000002.sql',
        'youthsync-20260101-000003.sql',
        'youthsync-not-a-backup.sql',
    ],
    implode(',', $few)
);

for ($i = 4; $i <= 16; $i++) {
    $write($root, sprintf('youthsync-20260101-%06d.sql', $i), $base + ($i * 60));
}
$prune->invoke($backup);
$many = $names($root);
$expected = [
    'notes.txt',
    'other.sql',
    'youthsync-not-a-backup.sql',
];
for ($i = 3; $i <= 16; $i++) {
    $expected[] = sprintf('youthsync-20260101-%06d.sql', $i);
}
sort($expected);
$check('newest_14_remain', $many === $expected, implode(',', $many));
$check('oldest_two_removed', !in_array('youthsync-20260101-000001.sql', $many, true) && !in_array('youthsync-20260101-000002.sql', $many, true));
$check('unrelated_files_remain', in_array('notes.txt', $many, true) && in_array('other.sql', $many, true) && in_array('youthsync-not-a-backup.sql', $many, true));

foreach ($names($root) as $name) {
    @unlink($root . DIRECTORY_SEPARATOR . $name);
}
@rmdir($root);
$check('temporary_directory_removed', !is_dir($root));

$afterReal = glob($realDir . '/youthsync-*.sql') ?: [];
sort($afterReal);
$check('real_backup_files_unchanged', $beforeReal === $afterReal);

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
