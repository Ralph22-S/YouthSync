<?php
declare(strict_types=1);

/**
 * One-time local development helper: set the SYSTEM_ADMIN password hash
 * using password_hash(). Does not print the password or the hash.
 *
 * Usage (from this folder):
 *   php set_local_system_admin_password.php
 * Password is read from env YOUTHSYNC_LOCAL_ADMIN_PASSWORD (not echoed).
 */

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/src/Auth/Password.php';

$plain = (string) (getenv('YOUTHSYNC_LOCAL_ADMIN_PASSWORD') ?: '');
if ($plain === '' || !YouthSync\Auth\Password::isValidNew($plain)) {
    fwrite(STDERR, "Set YOUTHSYNC_LOCAL_ADMIN_PASSWORD to a password of at least 8 characters.\n");
    exit(1);
}

$pdo = youthsync_pdo();
$stmt = $pdo->prepare(
    "SELECT u.id, u.password_hash, r.code AS role_code, u.status
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.email = :email
     LIMIT 1"
);
$stmt->execute(['email' => 'admin@skmms.test']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row === false) {
    fwrite(STDERR, "SYSTEM_ADMIN user was not found.\n");
    exit(1);
}
if (($row['role_code'] ?? '') !== 'SYSTEM_ADMIN' || ($row['status'] ?? '') !== 'active') {
    fwrite(STDERR, "admin@skmms.test is not an active SYSTEM_ADMIN.\n");
    exit(1);
}

$already = YouthSync\Auth\Password::verify($plain, (string) $row['password_hash']);
if ($already) {
    echo "SYSTEM_ADMIN password hash already matches.\n";
    exit(0);
}

$hash = YouthSync\Auth\Password::hash($plain);
$upd = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
$upd->execute(['hash' => $hash, 'id' => (int) $row['id']]);
echo "SYSTEM_ADMIN password hash updated.\n";
exit(0);
