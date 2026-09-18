<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();
$pdo->exec('USE `' . str_replace('`', '', youthsync_env('DB_NAME', 'youthsync')) . '`');

$schema = file_get_contents(__DIR__ . '/schema_phase2.sql');
if ($schema === false) {
    fwrite(STDERR, "Missing schema_phase2.sql\n");
    exit(1);
}

foreach (['DROP DATABASE', 'DROP SCHEMA', 'TRUNCATE', 'DELETE FROM', 'DROP TABLE'] as $forbidden) {
    if (stripos($schema, $forbidden) !== false) {
        fwrite(STDERR, "Refusing to run SQL that contains {$forbidden}\n");
        exit(1);
    }
}

$schema = preg_replace('/^\s*--.*$/m', '', $schema) ?? $schema;
foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}
echo "youth table ensured.\n";

$cols = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'youth_id'"
)->fetchColumn();

if ((int) $cols === 0) {
    $pdo->exec('ALTER TABLE users ADD COLUMN youth_id INT UNSIGNED NULL DEFAULT NULL AFTER role_id');
    $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_youth_id (youth_id)');
    $pdo->exec(
        'ALTER TABLE users ADD CONSTRAINT fk_users_youth
         FOREIGN KEY (youth_id) REFERENCES youth (id) ON UPDATE CASCADE ON DELETE SET NULL'
    );
    echo "users.youth_id column added.\n";
} else {
    echo "users.youth_id already present.\n";
}

echo "tables: " . implode(',', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
echo "youth_count=" . $pdo->query('SELECT COUNT(*) FROM youth')->fetchColumn() . PHP_EOL;
echo "phase1_users=" . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . PHP_EOL;
