<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;
use YouthSync\Notifications\NotificationService;

final class NotificationController
{
    public function __construct(
        private SkGuard $guard,
        private NotificationService $notifications,
    ) {
    }

    public function index(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->notifications->list(
            $ctx['organization_id'],
            (int) $ctx['user']['id'],
            $_GET
        ));
    }

    public function show(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->notifications->get(
            $ctx['organization_id'],
            (int) $ctx['user']['id'],
            (int) $params['id']
        ));
    }

    public function markRead(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::readBody();
        Json::success($this->notifications->markRead(
            $ctx['organization_id'],
            (int) $ctx['user']['id'],
            (int) $params['id']
        ));
    }

    public function markAllRead(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::readBody();
        Json::success($this->notifications->markAllRead(
            $ctx['organization_id'],
            (int) $ctx['user']['id']
        ));
    }

    public function destroy(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->notifications->delete(
            $ctx['organization_id'],
            (int) $ctx['user']['id'],
            (int) $params['id']
        ));
    }
}
