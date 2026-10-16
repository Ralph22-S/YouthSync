<?php
declare(strict_types=1);

/**
 * Scheduled application reminders.
 * Fixture rows and the notices they create are rolled back. Organizations are not changed.
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

$youth = $pdo->query(
    "SELECT y.id, y.organization_id
     FROM youth y
     INNER JOIN users u ON u.youth_id = y.id
     WHERE u.email = 'youth-auth-test@example.test'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if ($youth === false) {
    fwrite(STDERR, "STOP: youth-auth-test account was not found. No reminder was sent.\n");
    exit(2);
}
$youthId = (int) $youth['id'];
$orgId = (int) $youth['organization_id'];

(new YouthSync\Schedule\Scheduler($pdo))->ensureSeeded();

$noticeCount = static function (int $applicationId, string $title) use ($pdo): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications
         WHERE related_entity_type = 'application'
           AND related_entity_id = :id
           AND title = :title"
    );
    $stmt->execute(['id' => $applicationId, 'title' => $title]);
    return (int) $stmt->fetchColumn();
};

try {
    $pdo->beginTransaction();
    $program = $pdo->prepare(
        "INSERT INTO programs
            (organization_id, kind, name, status, scheduled_on, starts_at, ends_at, location)
         VALUES
            (:org, 'program', :name, 'draft', CURDATE(), '08:00', '15:00', '')"
    );
    $programIds = [];
    foreach (['waiting', 'pending', 'today'] as $label) {
        $program->execute([
            'org' => $orgId,
            'name' => 'Reminder fixture ' . $label . ' ' . bin2hex(random_bytes(4)),
        ]);
        $programIds[] = (int) $pdo->lastInsertId();
    }
    $insert = $pdo->prepare(
        "INSERT INTO applications
            (organization_id, program_id, youth_id, status, remarks, submitted_at, updated_at)
         VALUES
            (:org, :program, :youth, :status, '', :submitted, :updated)"
    );
    $insert->execute([
        'org' => $orgId,
        'program' => $programIds[0],
        'youth' => $youthId,
        'status' => 'needs_resubmission',
        'submitted' => date('Y-m-d H:i:s', strtotime('-3 days')),
        'updated' => date('Y-m-d H:i:s', strtotime('-2 days')),
    ]);
    $waitingId = (int) $pdo->lastInsertId();
    $insert->execute([
        'org' => $orgId,
        'program' => $programIds[1],
        'youth' => $youthId,
        'status' => 'pending',
        'submitted' => date('Y-m-d H:i:s', strtotime('-2 days')),
        'updated' => date('Y-m-d H:i:s', strtotime('-2 days')),
    ]);
    $pendingId = (int) $pdo->lastInsertId();
    $insert->execute([
        'org' => $orgId,
        'program' => $programIds[2],
        'youth' => $youthId,
        'status' => 'needs_resubmission',
        'submitted' => date('Y-m-d H:i:s'),
        'updated' => date('Y-m-d H:i:s'),
    ]);
    $todayId = (int) $pdo->lastInsertId();

    $quiet = new YouthSync\Schedule\Scheduler($pdo, new YouthSync\Notifications\NotificationService($pdo));
    $first = $quiet->runJob('application.remind');
    $check('job_ran', ($first['status'] ?? '') === 'ok' && str_contains((string) ($first['message'] ?? ''), 'youth='), (string) ($first['message'] ?? ''));
    $check('later_resubmit_reminded', $noticeCount($waitingId, 'Reminder: resubmit your application') === 1);
    $check('later_pending_reminded', $noticeCount($pendingId, 'Reminder: application still pending') === 1);
    $check('same_day_decision_not_reminded', $noticeCount($todayId, 'Reminder: resubmit your application') === 0);

    $scoped = $pdo->prepare(
        "SELECT organization_id, youth_id FROM notifications
         WHERE related_entity_id = :id AND title = 'Reminder: resubmit your application'
         LIMIT 1"
    );
    $scoped->execute(['id' => $waitingId]);
    $row = $scoped->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(
        'reminder_stays_in_org',
        (int) ($row['organization_id'] ?? 0) === $orgId && (int) ($row['youth_id'] ?? 0) === $youthId,
        json_encode($row)
    );
    $sk = $pdo->prepare(
        "SELECT youth_id, link FROM notifications
         WHERE related_entity_id = :id AND title = 'Reminder: application still pending'
         LIMIT 1"
    );
    $sk->execute(['id' => $pendingId]);
    $skRow = $sk->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(
        'sk_reminder_is_org_inbox',
        $skRow !== [] && $skRow['youth_id'] === null && ($skRow['link'] ?? '') === '/sk/applications/' . $pendingId,
        json_encode($skRow)
    );

    $quiet->runJob('application.remind');
    $check('second_run_does_not_duplicate', $noticeCount($waitingId, 'Reminder: resubmit your application') === 1
        && $noticeCount($pendingId, 'Reminder: application still pending') === 1);
} catch (Throwable $e) {
    $check('exception', false, $e->getMessage());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$left = (int) $pdo->query(
    "SELECT COUNT(*) FROM programs WHERE organization_id = {$orgId} AND name LIKE 'Reminder fixture %'"
)->fetchColumn();
$check('fixtures_rolled_back', $left === 0, (string) $left);

$job = $pdo->query("SELECT code, enabled FROM scheduled_jobs WHERE code = 'application.remind'")->fetch(PDO::FETCH_ASSOC);
$check('job_installed', ($job['code'] ?? '') === 'application.remind' && (int) ($job['enabled'] ?? 0) === 1, json_encode($job));

$afterOrgs = $pdo->query('SELECT id, plan, sub_status, expires_at FROM organizations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$check('organizations_unchanged', $beforeOrgs === $afterOrgs);

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
