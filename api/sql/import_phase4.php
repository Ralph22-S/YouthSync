<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();

$qrCol = $pdo->query("SHOW COLUMNS FROM organizations LIKE 'qr_uses'")->fetch();
if ($qrCol === false) {
    $pdo->exec(
        'ALTER TABLE organizations ADD COLUMN qr_uses INT UNSIGNED NOT NULL DEFAULT 0 AFTER youth_count'
    );
    echo "organizations.qr_uses added.\n";
} else {
    echo "organizations.qr_uses already present.\n";
}

$schema = file_get_contents(__DIR__ . '/schema_phase4.sql');
if ($schema === false) {
    fwrite(STDERR, "Missing schema_phase4.sql\n");
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

echo "attendance tables ensured.\n";
echo "tables: " . implode(',', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)) . PHP_EOL;
echo "attendance=" . $pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn() . PHP_EOL;
echo "attendance_qr_tokens=" . $pdo->query('SELECT COUNT(*) FROM attendance_qr_tokens')->fetchColumn() . PHP_EOL;
echo "programs=" . $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn() . PHP_EOL;
echo "youth=" . $pdo->query('SELECT COUNT(*) FROM youth')->fetchColumn() . PHP_EOL;
echo "users=" . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . PHP_EOL;
