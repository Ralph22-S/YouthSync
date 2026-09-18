<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();

$schema = file_get_contents(__DIR__ . '/schema_programs.sql');
if ($schema === false) {
    fwrite(STDERR, "Missing schema_programs.sql\n");
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

echo "programs table ensured.\n";
echo "tables: " . implode(',', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
echo "programs=" . $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn() . PHP_EOL;
echo "users=" . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . PHP_EOL;
echo "youth=" . $pdo->query('SELECT COUNT(*) FROM youth')->fetchColumn() . PHP_EOL;
