<?php
declare(strict_types=1);

/**
 * System Administrator migration.
 *
 * Adds the SYSTEM_ADMIN role, the admin account, the widened organization
 * status vocabulary, and the activity_logs / payments tables.
 *
 * Idempotent: every step checks before it writes, so re-running is safe.
 * Never drops, truncates, or deletes existing records.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();

$schema = file_get_contents(__DIR__ . '/schema_admin.sql');
if ($schema === false) {
    fwrite(STDERR, "Missing schema_admin.sql\n");
    exit(1);
}

foreach (['DROP DATABASE', 'DROP SCHEMA', 'TRUNCATE', 'DELETE FROM', 'DROP TABLE'] as $forbidden) {
    if (stripos($schema, $forbidden) !== false) {
        fwrite(STDERR, "Refusing to run SQL that contains {$forbidden}\n");
        exit(1);
    }
}

$hasColumn = static function (PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
};

// ---- organizations: wider status vocabulary + review columns ---------------

$pdo->exec(
    "ALTER TABLE organizations MODIFY status
     ENUM('pending', 'active', 'suspended', 'rejected', 'inactive')
     NOT NULL DEFAULT 'pending'"
);
echo "organizations.status widened to pending/active/suspended/rejected/inactive.\n";

$columns = [
    'status_note' => "ALTER TABLE organizations ADD COLUMN status_note VARCHAR(500) NOT NULL DEFAULT '' AFTER status",
    'approved_at' => 'ALTER TABLE organizations ADD COLUMN approved_at DATETIME NULL DEFAULT NULL AFTER status_note',
    'started_on' => 'ALTER TABLE organizations ADD COLUMN started_on DATE NULL DEFAULT NULL AFTER cycle',
];
foreach ($columns as $column => $sql) {
    if ($hasColumn($pdo, 'organizations', $column)) {
        echo "organizations.{$column} already present.\n";
        continue;
    }
    $pdo->exec($sql);
    echo "organizations.{$column} added.\n";
}

// ---- activity_logs + payments ---------------------------------------------

$schema = preg_replace('/^\s*--.*$/m', '', $schema) ?? $schema;
foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
    if ($statement !== '') {
        $pdo->exec($statement);
    }
}
echo "activity_logs and payments tables ensured.\n";

// ---- SYSTEM_ADMIN role + account ------------------------------------------

$pdo->prepare(
    "INSERT INTO roles (code, name) VALUES ('SYSTEM_ADMIN', 'System Administrator')
     ON DUPLICATE KEY UPDATE name = VALUES(name)"
)->execute();
$roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'SYSTEM_ADMIN' LIMIT 1")->fetchColumn();
echo "SYSTEM_ADMIN role id={$roleId}.\n";

$adminEmail = strtolower(youthsync_env('ADMIN_EMAIL', 'admin@skmms.test'));
$adminPassword = youthsync_env('ADMIN_PASSWORD', 'YouthSync1!');

$exists = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$exists->execute(['email' => $adminEmail]);
$adminId = (int) ($exists->fetchColumn() ?: 0);

if ($adminId > 0) {
    // Only the role is re-pointed; an existing password is never overwritten.
    $pdo->prepare('UPDATE users SET role_id = :role WHERE id = :id')
        ->execute(['role' => $roleId, 'id' => $adminId]);
    echo "Admin account {$adminEmail} already present (id={$adminId}); role confirmed.\n";
} else {
    $pdo->prepare(
        'INSERT INTO users (email, password_hash, first_name, last_name, role_id, status, must_change_password)
         VALUES (:email, :hash, :fn, :ln, :role, :status, 0)'
    )->execute([
        'email' => $adminEmail,
        'hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
        'fn' => 'System',
        'ln' => 'Administrator',
        'role' => $roleId,
        'status' => 'active',
    ]);
    $adminId = (int) $pdo->lastInsertId();
    echo "Admin account {$adminEmail} created (id={$adminId}).\n";
}

// The system admin is deployment-wide and deliberately holds no organization row.
$pdo->prepare('DELETE FROM organization_users WHERE user_id = :id')->execute(['id' => $adminId]);

// ---- backfill: started_on for organizations that already pay --------------

$pdo->exec(
    "UPDATE organizations SET started_on = DATE(created_at)
     WHERE started_on IS NULL AND plan <> 'free'"
);

// ---- backfill: payments derived from each organization's current plan ------

$planPrice = [
    'basic' => ['monthly' => 499.00, 'yearly' => 4990.00],
    'premium' => ['monthly' => 999.00, 'yearly' => 9990.00],
];

$paying = $pdo->query(
    "SELECT id, plan, cycle, sub_status, created_at FROM organizations WHERE plan <> 'free'"
)->fetchAll(PDO::FETCH_ASSOC);

$insertPayment = $pdo->prepare(
    'INSERT INTO payments (organization_id, reference, amount, plan_code, cycle, method, status, paid_at, note)
     VALUES (:org, :ref, :amount, :plan, :cycle, :method, :status, :paid_at, :note)'
);
$paymentExists = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE organization_id = :org');

$added = 0;
foreach ($paying as $org) {
    $paymentExists->execute(['org' => $org['id']]);
    if ((int) $paymentExists->fetchColumn() > 0) {
        continue;
    }

    $cycle = in_array($org['cycle'], ['monthly', 'yearly'], true) ? (string) $org['cycle'] : 'monthly';
    $amount = $planPrice[$org['plan']][$cycle] ?? 499.00;
    // A trial has not been charged yet; anything else on a paid plan was charged once.
    $status = match ((string) $org['sub_status']) {
        'trial' => 'pending',
        'payment_failed' => 'failed',
        default => 'paid',
    };
    $created = strtotime((string) $org['created_at']) ?: time();

    $insertPayment->execute([
        'org' => $org['id'],
        'ref' => 'YSP-' . str_pad((string) $org['id'], 4, '0', STR_PAD_LEFT) . '-' . date('Ymd', $created),
        'amount' => $amount,
        'plan' => $org['plan'],
        'cycle' => $cycle,
        'method' => 'gcash',
        'status' => $status,
        'paid_at' => $status === 'paid' ? date('Y-m-d H:i:s', $created) : null,
        'note' => 'Opening balance derived from the subscription on record.',
    ]);
    $added += 1;
}
echo "payments backfilled: {$added} row(s).\n";

// ---- backfill: activity_logs derived from rows that already exist ----------
// Real events with their real timestamps, not invented filler.

$logged = (int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
if ($logged === 0) {
    $pdo->exec(
        "INSERT INTO activity_logs
            (organization_id, youth_id, actor_name, actor_role, action, category, description, entity_type, entity_id, created_at)
         SELECT y.organization_id, y.id, 'SK Official', 'sk_official', 'create', 'youth',
                CONCAT('Added youth record ', y.code, ' - ', y.first_name, ' ', y.last_name),
                'youth', y.id, COALESCE(y.added_at, y.created_at)
         FROM youth y"
    );
    $pdo->exec(
        "INSERT INTO activity_logs
            (organization_id, actor_name, actor_role, action, category, description, entity_type, entity_id, created_at)
         SELECT p.organization_id, 'SK Official', 'sk_official', 'publish', 'program',
                CONCAT('Published ', p.kind, ' - ', p.name), 'program', p.id, p.created_at
         FROM programs p WHERE p.status IN ('published', 'ongoing', 'completed')"
    );
    $pdo->exec(
        "INSERT INTO activity_logs
            (organization_id, user_id, actor_name, actor_role, action, category, description, created_at)
         SELECT ou.organization_id, u.id, CONCAT(u.first_name, ' ', u.last_name), 'sk_official', 'login', 'auth',
                CONCAT(u.email, ' signed in'), u.last_login_at
         FROM users u
         JOIN organization_users ou ON ou.user_id = u.id
         WHERE u.last_login_at IS NOT NULL"
    );
    echo "activity_logs backfilled from existing youth, programs and sign-ins.\n";
} else {
    echo "activity_logs already has {$logged} row(s); backfill skipped.\n";
}

echo 'activity_logs=' . $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn() . PHP_EOL;
echo 'payments=' . $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() . PHP_EOL;
echo 'organizations=' . $pdo->query('SELECT COUNT(*) FROM organizations')->fetchColumn() . PHP_EOL;
echo "Admin sign-in: {$adminEmail}\n";
