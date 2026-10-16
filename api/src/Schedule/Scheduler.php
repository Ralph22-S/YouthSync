<?php
declare(strict_types=1);

namespace YouthSync\Schedule;

use PDO;
use Throwable;
use YouthSync\Applications\ApplicationReminder;
use YouthSync\Infra\DatabaseBackup;
use YouthSync\Notifications\EmailService;
use YouthSync\Notifications\NotificationService;
use YouthSync\Notifications\SmsService;
use YouthSync\Org\PlanLimits;
use YouthSync\Subscription\SubscriptionReminder;

/**
 * Runs registered jobs whose next_run_at has passed.
 * Job code is a fixed PHP handler. The database cannot name a class or shell command.
 */
final class Scheduler
{
    public function __construct(
        private PDO $pdo,
        private ?NotificationService $notifications = null
    ) {
    }

    public function ensureSeeded(): void
    {
        $this->pdo->exec(
            "INSERT IGNORE INTO scheduled_jobs (code, name, interval_minutes, enabled, next_run_at)
             VALUES
             ('subscription.expire', 'Expire subscriptions whose term has ended', 1440, 1, NOW()),
             ('scheduler.heartbeat', 'Record that the scheduler ran', 1440, 1, NOW()),
             ('application.remind', 'Remind youth and SK about applications still waiting', 1440, 1, TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '01:15:00')),
             ('subscription.remind', 'Remind an SK organization before its subscription expires', 1440, 1, TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '01:15:00')),
             ('database.backup', 'Store a mysqldump of the YouthSync database', 1440, 1, TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '01:15:00'))"
        );
    }

    /**
     * @return array{ran: list<array{code:string,status:string,message:string}>, skipped: list<array{code:string,reason:string}>}
     */
    public function runDue(): array
    {
        $this->ensureSeeded();
        $ran = [];
        $skipped = [];
        $this->pdo->beginTransaction();
        try {
            $due = $this->pdo->query(
                "SELECT id, code FROM scheduled_jobs
                 WHERE enabled = 1 AND next_run_at <= NOW()
                 ORDER BY id
                 FOR UPDATE"
            )->fetchAll();
            foreach ($due as $job) {
                $code = (string) $job['code'];
                if (!$this->known($code)) {
                    $skipped[] = ['code' => $code, 'reason' => 'unknown'];
                    $this->finish((int) $job['id'], 'skipped', 'Unknown job code.');
                    continue;
                }
                try {
                    $message = $this->handle($code);
                    $this->finish((int) $job['id'], 'ok', $message);
                    $ran[] = ['code' => $code, 'status' => 'ok', 'message' => $message];
                } catch (Throwable $e) {
                    $message = substr($e->getMessage(), 0, 240);
                    $this->finish((int) $job['id'], 'failed', $message);
                    $ran[] = ['code' => $code, 'status' => 'failed', 'message' => $message];
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['ran' => $ran, 'skipped' => $skipped];
    }

    /**
     * Run one known job now, even when it is not yet due.
     *
     * @return array{code:string,status:string,message:string}
     */
    public function runJob(string $code): array
    {
        $this->ensureSeeded();
        if (!$this->known($code)) {
            return ['code' => $code, 'status' => 'skipped', 'message' => 'Unknown job code.'];
        }
        $stmt = $this->pdo->prepare('SELECT id FROM scheduled_jobs WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $id = (int) $stmt->fetchColumn();
        if ($id < 1) {
            return ['code' => $code, 'status' => 'skipped', 'message' => 'Job is not installed.'];
        }
        try {
            $message = $this->handle($code);
            $this->finish($id, 'ok', $message);
            return ['code' => $code, 'status' => 'ok', 'message' => $message];
        } catch (Throwable $e) {
            $message = substr($e->getMessage(), 0, 240);
            $this->finish($id, 'failed', $message);
            return ['code' => $code, 'status' => 'failed', 'message' => $message];
        }
    }

    private function known(string $code): bool
    {
        return in_array($code, ['subscription.expire', 'scheduler.heartbeat', 'application.remind', 'subscription.remind', 'database.backup'], true);
    }

    private function handle(string $code): string
    {
        if ($code === 'scheduler.heartbeat') {
            return 'ok';
        }
        if ($code === 'application.remind') {
            return (new ApplicationReminder($this->pdo, $this->notifications()))->remind();
        }
        if ($code === 'subscription.remind') {
            return (new SubscriptionReminder($this->pdo, $this->notifications()))->remind();
        }
        if ($code === 'database.backup') {
            $created = DatabaseBackup::storage()->create();
            return 'file=' . $created['file'] . ' bytes=' . $created['bytes'];
        }
        $ids = PlanLimits::expireDue($this->pdo);
        return 'expired=' . count($ids);
    }

    private function notifications(): NotificationService
    {
        if ($this->notifications instanceof NotificationService) {
            return $this->notifications;
        }
        if (!function_exists('youthsync_env')) {
            return new NotificationService($this->pdo);
        }
        return new NotificationService(
            $this->pdo,
            new SmsService(
                (string) youthsync_env('SEMAPHORE_API_KEY', ''),
                (string) youthsync_env('SEMAPHORE_SENDER_NAME', ''),
                (string) youthsync_env('SEMAPHORE_BASE_URL', 'https://api.semaphore.co')
            ),
            new EmailService(
                (string) youthsync_env('BREVO_API_KEY', ''),
                (string) youthsync_env('BREVO_FROM_EMAIL', ''),
                (string) youthsync_env('BREVO_FROM_NAME', 'YouthSync'),
                (string) youthsync_env('BREVO_BASE_URL', 'https://api.brevo.com')
            )
        );
    }

    private function finish(int $id, string $status, string $message): void
    {
        $this->pdo->prepare(
            "UPDATE scheduled_jobs
             SET last_run_at = NOW(),
                 last_status = :status,
                 last_message = :message,
                 next_run_at = DATE_ADD(NOW(), INTERVAL interval_minutes MINUTE)
             WHERE id = :id"
        )->execute([
            'status' => $status,
            'message' => substr($message, 0, 255),
            'id' => $id,
        ]);
    }
}
