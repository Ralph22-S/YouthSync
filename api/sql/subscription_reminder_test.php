<?php
declare(strict_types=1);

/**
 * Scheduled subscription reminders.
 * Fixture organizations and the notices they create are rolled back.
 * Plan, status, and expires_at of existing organizations are not changed.
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
$beforeJobs = $pdo->query(
    "SELECT id, code, enabled, next_run_at, last_run_at, last_status, last_message
     FROM scheduled_jobs
     WHERE code IN ('subscription.expire', 'scheduler.heartbeat', 'application.remind')
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
$beforeNotices = (int) $pdo->query(
    "SELECT COUNT(*) FROM notifications WHERE title = 'Subscription expires soon'"
)->fetchColumn();

(new YouthSync\Schedule\Scheduler($pdo))->ensureSeeded();

$stamp = bin2hex(random_bytes(4));
$soonEmail = "ys-sub-remind-soon-{$stamp}@example.test";
$laterEmail = "ys-sub-remind-later-{$stamp}@example.test";
$pastEmail = "ys-sub-remind-past-{$stamp}@example.test";

try {
    $pdo->beginTransaction();
    $insert = $pdo->prepare(
        "INSERT INTO organizations
            (name, barangay, municipality, province, chairperson, email, contact, status, plan, sub_status, cycle, expires_at)
         VALUES
            (:name, 'Remind', 'Remind', 'Laguna', 'Remind Test', :email, '09170000000', 'active', 'premium', :sub, 'monthly', :expires)"
    );
    $insert->execute([
        'name' => 'Subscription reminder soon',
        'email' => $soonEmail,
        'sub' => 'trial',
        'expires' => date('Y-m-d', strtotime('+2 days')),
    ]);
    $soonId = (int) $pdo->lastInsertId();
    $insert->execute([
        'name' => 'Subscription reminder later',
        'email' => $laterEmail,
        'sub' => 'active',
        'expires' => date('Y-m-d', strtotime('+10 days')),
    ]);
    $laterId = (int) $pdo->lastInsertId();
    $insert->execute([
        'name' => 'Subscription reminder past',
        'email' => $pastEmail,
        'sub' => 'trial',
        'expires' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $pastId = (int) $pdo->lastInsertId();

    $quiet = new YouthSync\Schedule\Scheduler($pdo, new YouthSync\Notifications\NotificationService($pdo));
    $first = $quiet->runJob('subscription.remind');
    $check('job_ran', ($first['status'] ?? '') === 'ok' && str_contains((string) ($first['message'] ?? ''), 'orgs='), (string) ($first['message'] ?? ''));

    $notice = $pdo->prepare(
        "SELECT organization_id, youth_id, type, link, related
         FROM notifications
         WHERE organization_id = :org AND title = 'Subscription expires soon'
         LIMIT 1"
    );
    $notice->execute(['org' => $soonId]);
    $soonNote = $notice->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(
        'soon_org_reminded',
        (int) ($soonNote['organization_id'] ?? 0) === $soonId
            && $soonNote['youth_id'] === null
            && ($soonNote['type'] ?? '') === 'subscription'
            && ($soonNote['link'] ?? '') === '/sk/subscription'
            && ($soonNote['related'] ?? '') === date('Y-m-d', strtotime('+2 days')),
        json_encode($soonNote)
    );

    $countFor = static function (int $organizationId) use ($pdo): int {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE organization_id = :org AND title = 'Subscription expires soon'"
        );
        $stmt->execute(['org' => $organizationId]);
        return (int) $stmt->fetchColumn();
    };
    $check('later_org_not_reminded', $countFor($laterId) === 0);
    $check('past_org_not_reminded', $countFor($pastId) === 0);

    $pastStatus = $pdo->prepare('SELECT plan, sub_status, expires_at FROM organizations WHERE id = :id');
    $pastStatus->execute(['id' => $pastId]);
    $pastRow = $pastStatus->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(
        'past_org_not_expired',
        ($pastRow['plan'] ?? '') === 'premium'
            && ($pastRow['sub_status'] ?? '') === 'trial'
            && substr((string) ($pastRow['expires_at'] ?? ''), 0, 10) === date('Y-m-d', strtotime('-1 day')),
        json_encode($pastRow)
    );

    $quiet->runJob('subscription.remind');
    $check('second_run_does_not_duplicate', $countFor($soonId) === 1);
} catch (Throwable $e) {
    $check('exception', false, $e->getMessage());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$left = (int) $pdo->query(
    "SELECT COUNT(*) FROM organizations WHERE email IN ("
    . $pdo->quote($soonEmail) . ',' . $pdo->quote($laterEmail) . ',' . $pdo->quote($pastEmail) . ')'
)->fetchColumn();
$check('fixtures_rolled_back', $left === 0, (string) $left);

$afterNotices = (int) $pdo->query(
    "SELECT COUNT(*) FROM notifications WHERE title = 'Subscription expires soon'"
)->fetchColumn();
$check('saved_notices_unchanged', $afterNotices === $beforeNotices, "before={$beforeNotices} after={$afterNotices}");

$job = $pdo->query(
    "SELECT code, enabled, next_run_at, last_run_at FROM scheduled_jobs WHERE code = 'subscription.remind'"
)->fetch(PDO::FETCH_ASSOC);
$check(
    'job_installed_for_later',
    ($job['code'] ?? '') === 'subscription.remind'
        && (int) ($job['enabled'] ?? 0) === 1
        && ($job['last_run_at'] ?? null) === null
        && strtotime((string) ($job['next_run_at'] ?? '')) > time(),
    json_encode($job)
);

$afterOrgs = $pdo->query('SELECT id, plan, sub_status, expires_at FROM organizations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$check('organizations_unchanged', $beforeOrgs === $afterOrgs);

$afterJobs = $pdo->query(
    "SELECT id, code, enabled, next_run_at, last_run_at, last_status, last_message
     FROM scheduled_jobs
     WHERE code IN ('subscription.expire', 'scheduler.heartbeat', 'application.remind')
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
$check('other_jobs_unchanged', $beforeJobs === $afterJobs);

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
