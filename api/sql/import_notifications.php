<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();

$schema = file_get_contents(__DIR__ . '/schema_notifications.sql');
if ($schema === false) {
    fwrite(STDERR, "Missing schema_notifications.sql\n");
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

echo "notification table ensured.\n";
echo "tables: " . implode(',', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
echo "notifications=" . $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() . PHP_EOL;
echo "users=" . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . PHP_EOL;
echo "organizations=" . $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn() . PHP_EOL;
echo "applications=" . $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn() . PHP_EOL;
