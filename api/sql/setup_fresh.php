<?php
declare(strict_types=1);

/**
 * Fresh-database installer for a new XAMPP copy of YouthSync.
 *
 * Does NOT run against a database that already has tables.
 * Does NOT DROP, TRUNCATE, or DELETE data.
 *
 * Seed data (demo SK accounts) is INSERT IGNORE and is intended for a
 * first-time empty database. Re-running this script on an existing
 * youthsync database is refused.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$ports = [3307, 3306];
$pdo = null;
$usedPort = null;

foreach ($ports as $port) {
    try {
        $pdo = new PDO(
            "mysql:host=127.0.0.1;port={$port};charset=utf8mb4",
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $usedPort = $port;
        break;
    } catch (Throwable $e) {
        fwrite(STDERR, "port {$port}: " . $e->getMessage() . PHP_EOL);
    }
}

if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Could not connect to MySQL as root with an empty password.\n");
    fwrite(STDERR, "Start MySQL in XAMPP, then set DB_PORT in api/.env if needed.\n");
    exit(1);
}

echo "Connected on port {$usedPort}\n";

$exists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'youthsync'"
)->fetchColumn();

if ($exists === 0) {
    $pdo->exec('CREATE DATABASE youthsync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo "Created database youthsync.\n";
} else {
    echo "Database youthsync already exists; it was not dropped.\n";
}

$pdo->exec('USE youthsync');
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

if ($tables) {
    echo "Refusing to import: youthsync already has " . count($tables) . " table(s).\n";
    echo "This script is for a fresh empty database only.\n";
    echo "Existing project data was left unchanged.\n";
    echo "To check the connection: C:\\xampp\\php\\php.exe " . __DIR__ . "\\verify_setup.php\n";
    exit(0);
}

echo "Database is empty. Applying Phase 1 schema and demo seed, then Phases 2–7.\n";

$php = PHP_BINARY;
$dir = __DIR__;
$scripts = [
    'import_auth_orgs.php',
    'import_youth.php',
    'import_programs.php',
    'import_attendance.php',
    'import_assistance.php',
    'import_applications.php',
    'import_notifications.php',
];

foreach ($scripts as $name) {
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}\n");
        exit(1);
    }
    echo "--- {$name} ---\n";
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($path), $code);
    if ($code !== 0) {
        fwrite(STDERR, "{$name} exited with code {$code}\n");
        exit($code);
    }
}

echo "Fresh setup finished. Demo SK emails: sk1@demo.test, sk2@demo.test, sk4@demo.test\n";
echo "Local demo password is documented in the project README (not printed here).\n";
echo "USED_PORT={$usedPort}\n";
