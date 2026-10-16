<?php
declare(strict_types=1);

/**
 * Application submit and resubmit notices.
 * Uses an in-app-only notification service, then rolls the fixture back.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'YouthSync\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$pdo = youthsync_pdo();
$failed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}";
    if (!$ok && $detail !== '') {
        echo ' — ' . $detail;
    }
    echo "\n";
    if (!$ok) {
        $failed++;
    }
};

$beforeOrgs = $pdo->query('SELECT id, plan, sub_status, expires_at FROM organizations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$beforeSms = (int) $pdo->query('SELECT COUNT(*) FROM sms_deliveries')->fetchColumn();
$beforeEmail = (int) $pdo->query('SELECT COUNT(*) FROM email_deliveries')->fetchColumn();

$youth = $pdo->query(
    "SELECT y.id, y.organization_id
     FROM youth y
     INNER JOIN users u ON u.youth_id = y.id
     WHERE u.email = 'youth-auth-test@example.test'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if ($youth === false) {
    fwrite(STDERR, "STOP: youth-auth-test account was not found.\n");
    exit(2);
}
$youthId = (int) $youth['id'];
$orgId = (int) $youth['organization_id'];

$service = new YouthSync\Applications\ApplicationService(
    $pdo,
    new YouthSync\Assistance\AssistanceService($pdo),
    new YouthSync\Notifications\NotificationService($pdo)
);

$notice = static function (int $applicationId, string $title, bool $youth) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT youth_id, link FROM notifications
         WHERE related_entity_type = \'application\'
           AND related_entity_id = :id
           AND title = :title
           AND ' . ($youth ? 'youth_id IS NOT NULL' : 'youth_id IS NULL') . '
         LIMIT 1'
    );
    $stmt->execute(['id' => $applicationId, 'title' => $title]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
};

try {
    $pdo->beginTransaction();
    $program = $pdo->prepare(
        "INSERT INTO programs
            (organization_id, kind, name, status, scheduled_on, starts_at, ends_at, location)
         VALUES
            (:org, 'program', :name, 'published', CURDATE(), '08:00', '15:00', '')"
    );
    $program->execute(['org' => $orgId, 'name' => 'Notice fixture ' . bin2hex(random_bytes(4))]);
    $programId = (int) $pdo->lastInsertId();

    $created = $service->create($orgId, [
        'programId' => $programId,
        'youthId' => $youthId,
        'remarks' => '',
    ], ['id' => 0, 'email' => 'notice-test@example.test']);
    $applicationId = (int) ($created['id'] ?? 0);
    $youthSubmit = $notice($applicationId, 'Application submitted', true);
    $skSubmit = $notice($applicationId, 'New application received', false);
    $check(
        'youth_submit_notice',
        $applicationId > 0
            && (int) ($youthSubmit['youth_id'] ?? 0) === $youthId
            && ($youthSubmit['link'] ?? '') === '/youth/applications/' . $applicationId,
        json_encode($youthSubmit)
    );
    $check(
        'sk_submit_notice',
        ($skSubmit['youth_id'] ?? null) === null
            && ($skSubmit['link'] ?? '') === '/sk/applications/' . $applicationId,
        json_encode($skSubmit)
    );

    $pdo->prepare(
        "UPDATE applications SET status = 'needs_resubmission' WHERE id = :id AND organization_id = :org"
    )->execute(['id' => $applicationId, 'org' => $orgId]);
    $service->resubmit($orgId, $youthId, $applicationId, ['id' => 0, 'email' => 'notice-test@example.test']);
    $youthAgain = $notice($applicationId, 'Application resubmitted', true);
    $skAgain = $notice($applicationId, 'Application resubmitted', false);
    $check(
        'youth_resubmit_notice',
        (int) ($youthAgain['youth_id'] ?? 0) === $youthId
            && ($youthAgain['link'] ?? '') === '/youth/applications/' . $applicationId,
        json_encode($youthAgain)
    );
    $check(
        'sk_resubmit_notice',
        ($skAgain['youth_id'] ?? null) === null
            && ($skAgain['link'] ?? '') === '/sk/applications/' . $applicationId,
        json_encode($skAgain)
    );
} catch (Throwable $e) {
    $check('exception', false, $e->getMessage());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$left = (int) $pdo->query(
    "SELECT COUNT(*) FROM programs WHERE organization_id = {$orgId} AND name LIKE 'Notice fixture %'"
)->fetchColumn();
$check('fixtures_rolled_back', $left === 0, (string) $left);
$check('sms_rows_unchanged', (int) $pdo->query('SELECT COUNT(*) FROM sms_deliveries')->fetchColumn() === $beforeSms);
$check('email_rows_unchanged', (int) $pdo->query('SELECT COUNT(*) FROM email_deliveries')->fetchColumn() === $beforeEmail);
$afterOrgs = $pdo->query('SELECT id, plan, sub_status, expires_at FROM organizations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$check('organizations_unchanged', $beforeOrgs === $afterOrgs);

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
