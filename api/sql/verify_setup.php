<?php
declare(strict_types=1);

/**
 * Read-only check that api/.env can reach MySQL and demo SK emails exist.
 * Does not print password hashes. Does not modify data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();
echo 'pdo_ok db=' . youthsync_env('DB_NAME', 'youthsync') . ' port=' . youthsync_env('DB_PORT', '?') . PHP_EOL;

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'tables=' . count($tables) . PHP_EOL;
echo implode(',', $tables) . PHP_EOL;

$expected = [
    'roles', 'organizations', 'users', 'organization_users', 'youth', 'programs',
    'attendance_qr_tokens', 'attendance', 'assistance_types', 'assistance_programs',
    'assistance_requirements', 'beneficiaries', 'applications', 'application_submissions',
    'notifications',
];
$missing = array_values(array_diff($expected, $tables));
if ($missing) {
    echo 'MISSING_TABLES=' . implode(',', $missing) . PHP_EOL;
    exit(1);
}

echo "=== counts ===\n";
foreach ($expected as $table) {
    echo $table . '=' . $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() . PHP_EOL;
}

echo "=== demo SK emails (no hashes) ===\n";
$stmt = $pdo->query(
    "SELECT u.email, u.first_name, u.last_name, u.status, o.name AS org_name, o.plan, o.sub_status
     FROM users u
     LEFT JOIN organization_users ou ON ou.user_id = u.id
     LEFT JOIN organizations o ON o.id = ou.organization_id
     WHERE u.email IN ('sk1@demo.test','sk2@demo.test','sk4@demo.test')
     ORDER BY u.email"
);
$found = [];
foreach ($stmt as $row) {
    $found[] = $row['email'];
    echo implode("\t", $row) . PHP_EOL;
}

foreach (['sk1@demo.test', 'sk2@demo.test', 'sk4@demo.test'] as $email) {
    if (!in_array($email, $found, true)) {
        fwrite(STDERR, "Missing demo account {$email}\n");
        exit(1);
    }
}

echo "verify_setup_ok\n";
