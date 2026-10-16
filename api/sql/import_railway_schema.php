<?php
declare(strict_types=1);

/**
 * One-off importer for the Railway MySQL trial.
 * Password comes from RAILWAY_DB_PASSWORD and is never written here.
 *
 *   $env:RAILWAY_DB_PASSWORD = Read-Host "password"
 *   C:\xampp\php\php.exe api\sql\import_railway_schema.php
 */

$host = getenv('RAILWAY_DB_HOST') ?: 'shuttle.proxy.rlwy.net';
$port = (int) (getenv('RAILWAY_DB_PORT') ?: 48040);
$user = getenv('RAILWAY_DB_USER') ?: 'root';
$database = getenv('RAILWAY_DB_NAME') ?: 'railway';
$password = getenv('RAILWAY_DB_PASSWORD') ?: '';

if ($password === '') {
    fwrite(STDERR, "Set RAILWAY_DB_PASSWORD first.\n");
    exit(1);
}

$mysqli = mysqli_init();
if ($mysqli === false || !$mysqli->real_connect($host, $user, $password, $database, $port)) {
    fwrite(STDERR, 'Connect failed: ' . mysqli_connect_error() . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

$files = [
    'schema.sql',
    'schema_youth.sql',
    'schema_programs.sql',
    'schema_applications.sql',
    'schema_assistance.sql',
    'schema_attendance.sql',
    'schema_notifications.sql',
    'schema_admin.sql',
];

$dir = __DIR__;
foreach ($files as $name) {
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "Missing {$name}\n");
        exit(1);
    }
    if (!$mysqli->multi_query($sql)) {
        fwrite(STDERR, "{$name}: {$mysqli->error}\n");
        exit(1);
    }
    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
        if ($mysqli->errno) {
            fwrite(STDERR, "{$name}: {$mysqli->error}\n");
            exit(1);
        }
    } while ($mysqli->more_results() && $mysqli->next_result());
    echo "ok {$name}\n";
}

echo "done\n";
