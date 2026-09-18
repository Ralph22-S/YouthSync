<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;
use YouthSync\Subscription\SubscriptionService;

final class SubscriptionController
{
    public function __construct(
        private SkGuard $guard,
        private SubscriptionService $subscription,
    ) {
    }

    public function show(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->subscription->get(
            $ctx['organization_id'],
            $ctx['membership'],
            $_GET
        ));
    }

    public function usage(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->subscription->usage(
            $ctx['organization_id'],
            $ctx['membership'],
            $_GET
        ));
    }
}
