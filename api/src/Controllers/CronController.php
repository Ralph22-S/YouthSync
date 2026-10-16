<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Http\Json;
use YouthSync\Schedule\Scheduler;

/**
 * HTTP entry for the scheduler. A session is not accepted.
 * The caller must send the configured X-Cron-Secret header.
 */
final class CronController
{
    public function __construct(
        private Scheduler $scheduler,
        private string $secret,
    ) {
    }

    public function run(array $params = []): void
    {
        unset($params);
        if ($this->secret === '' || strlen($this->secret) < 16) {
            Json::error('CRON_DISABLED', 'The scheduler HTTP route is not configured.', 503);
        }
        $provided = $this->headerSecret();
        if ($provided === '' || !hash_equals($this->secret, $provided)) {
            Json::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        // Body fields cannot choose an organization or a job.
        Json::success($this->scheduler->runDue());
    }

    private function headerSecret(): string
    {
        $key = 'HTTP_X_CRON_SECRET';
        $value = $_SERVER[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }
}
