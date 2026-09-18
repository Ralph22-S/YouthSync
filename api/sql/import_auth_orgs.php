<?php
declare(strict_types=1);

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
    fwrite(STDERR, "Could not connect to MySQL as root with empty password.\n");
    exit(1);
}

echo "Connected on port {$usedPort}\n";
echo "=== databases ===\n";
foreach ($pdo->query('SHOW DATABASES') as $row) {
    echo $row[0] . PHP_EOL;
}

$exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'youthsync'")->fetchColumn();
if ($exists === 0) {
    $pdo->exec('CREATE DATABASE youthsync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo "Created database youthsync (did not drop anything).\n";
} else {
    echo "Database youthsync already exists; leaving it in place.\n";
}

$pdo->exec('USE youthsync');
echo "=== tables before import ===\n";
$before = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!$before) {
    echo "(none)\n";
} else {
    foreach ($before as $t) {
        echo $t . PHP_EOL;
    }
}

$schema = file_get_contents(__DIR__ . '/schema.sql');
$seed = file_get_contents(__DIR__ . '/seed.sql');
if ($schema === false || $seed === false) {
    fwrite(STDERR, "Missing schema.sql or seed.sql\n");
    exit(1);
}

foreach (['DROP DATABASE', 'DROP SCHEMA', 'TRUNCATE', 'DELETE FROM'] as $forbidden) {
    if (stripos($schema, $forbidden) !== false || stripos($seed, $forbidden) !== false) {
        fwrite(STDERR, "Refusing to run SQL that contains {$forbidden}\n");
        exit(1);
    }
}

if (preg_match('/DROP\s+TABLE/i', $schema) || preg_match('/DROP\s+TABLE/i', $seed)) {
    fwrite(STDERR, "Refusing to run SQL that contains DROP TABLE\n");
    exit(1);
}

run_sql_file($pdo, $schema);
echo "schema.sql applied (CREATE TABLE IF NOT EXISTS only).\n";
run_sql_file($pdo, $seed);
echo "seed.sql applied (INSERT IGNORE only).\n";

echo "=== tables after import ===\n";
foreach ($pdo->query('SHOW TABLES') as $row) {
    echo $row[0] . PHP_EOL;
}

echo "=== counts ===\n";
foreach (['roles', 'users', 'organizations', 'organization_users'] as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    echo "{$table}: {$count}\n";
}

echo "=== roles ===\n";
foreach ($pdo->query('SELECT id, code, name FROM roles ORDER BY id') as $row) {
    echo "{$row['id']}\t{$row['code']}\t{$row['name']}\n";
}

echo "=== users (no hashes) ===\n";
foreach ($pdo->query('SELECT id, email, first_name, last_name, role_id, status FROM users ORDER BY id') as $row) {
    echo implode("\t", $row) . PHP_EOL;
}

echo "=== organizations ===\n";
foreach ($pdo->query('SELECT id, name, status, plan, sub_status, youth_count FROM organizations ORDER BY id') as $row) {
    echo implode("\t", $row) . PHP_EOL;
}

echo "=== organization_users ===\n";
foreach ($pdo->query('SELECT user_id, organization_id, is_owner, status FROM organization_users ORDER BY id') as $row) {
    echo implode("\t", $row) . PHP_EOL;
}

echo "USED_PORT={$usedPort}\n";

function run_sql_file(PDO $pdo, string $sql): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $parts = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($parts as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}
