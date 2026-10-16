<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Forbidden\n"); }
require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'YouthSync\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
});
$pdo = youthsync_pdo();
$pdo->prepare('DELETE FROM organizations WHERE email = :e')->execute(['e' => 'ys-expiry-probe@example.test']);
$pdo->prepare("INSERT INTO organizations (name, barangay, municipality, province, chairperson, email, contact, status, plan, sub_status, cycle, started_on, expires_at) VALUES ('Expiry Probe','Probe','Probe','Probe','Probe','ys-expiry-probe@example.test','09170000000','active','premium','trial','trial',CURDATE(),DATE_SUB(CURDATE(), INTERVAL 1 DAY))")->execute();
$id = (int) $pdo->lastInsertId();
$row = $pdo->query("SELECT id, plan, sub_status, cycle, expires_at FROM organizations WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
$row = YouthSync\Org\PlanLimits::applyExpiry($pdo, $row);
$db = $pdo->query("SELECT sub_status FROM organizations WHERE id = {$id}")->fetchColumn();
$pdo->prepare('DELETE FROM organizations WHERE id = :id AND email = :e')->execute(['id' => $id, 'e' => 'ys-expiry-probe@example.test']);
$ok = ($row['sub_status'] ?? '') === 'expired' && $db === 'expired';
echo ($ok ? "PASS expiry_on_read\nALL PASSED\n" : "FAIL expiry_on_read row={$row['sub_status']} db={$db}\n");
exit($ok ? 0 : 1);