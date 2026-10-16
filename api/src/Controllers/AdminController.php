<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Admin\AdminService;
use YouthSync\Http\AdminGuard;
use YouthSync\Admin\ActivityLog;
use YouthSync\Http\Json;
use YouthSync\Infra\DatabaseBackup;
use YouthSync\Infra\ErrorLog;
use YouthSync\Subscription\SubscriptionAdminService;

final class AdminController
{
    public function __construct(
        private AdminGuard $guard,
        private AdminService $admin,
        private SubscriptionAdminService $subscriptions,
    ) {
    }

    public function plans(array $params = []): void
    {
        $this->guard->requireSystemAdmin();
        Json::success($this->subscriptions->plans());
    }

    public function organizationSubscription(array $params): void
    {
        $this->guard->requireSystemAdmin();
        Json::success($this->subscriptions->show((int) $params['id']));
    }

    public function changeOrganizationPlan(array $params): void
    {
        $user = $this->guard->requireSystemAdmin();
        Json::success($this->subscriptions->changePlan((int) $params['id'], Json::readBody(), $user));
    }

    public function extendOrganizationSubscription(array $params): void
    {
        $user = $this->guard->requireSystemAdmin();
        Json::success($this->subscriptions->extend((int) $params['id'], Json::readBody(), $user));
    }

    public function cancelOrganizationSubscription(array $params): void
    {
        $user = $this->guard->requireSystemAdmin();
        Json::success($this->subscriptions->cancel((int) $params['id'], Json::readBody(), $user));
    }

    public function runSubscriptionCycle(array $params = []): void
    {
        $user = $this->guard->requireSystemAdmin();
        Json::success($this->subscriptions->runCycle($user));
    }

    public function dashboard(array $params = []): void
    {
        $this->guard->requireSystemAdmin();
        Json::success($this->admin->dashboard());
    }

    public function organizations(array $params = []): void
    {
        $this->guard->requireSystemAdmin();
        Json::success($this->admin->listOrganizations($_GET));
    }

    public function storeOrganization(array $params = []): void
    {
        $user = $this->guard->requireSystemAdmin();
        Json::success($this->admin->createOrganization(Json::readBody(), $user), 201);
    }

    public function showOrganization(array $params): void
    {
        $this->guard->requireSystemAdmin();
        Json::success($this->admin->getOrganization((int) $params['id']));
    }

    public function approveOrganization(array $params): void
    {
        $user = $this->guard->requireSystemAdmin();
        Json::success($this->admin->approveOrganization((int) $params['id'], $user));
    }

    public function setOrganizationStatus(array $params): void
    {
        $user = $this->guard->requireSystemAdmin();
        $body = Json::readBody();
        $status = (string) ($body['status'] ?? '');
        $note = (string) ($body['note'] ?? $body['statusNote'] ?? '');
        Json::success($this->admin->setOrganizationStatus((int) $params['id'], $status, $note, $user));
    }

    public function activity(array $params = []): void
    {
        $this->guard->requireSystemAdmin();
        Json::success($this->admin->listActivity(null, $_GET));
    }

    public function errors(array $params = []): void
    {
        $this->guard->requireSystemAdmin();
        Json::success([
            'source' => 'api/logs/app.log',
            'items' => (new ErrorLog())->latest(),
        ]);
    }

    public function organizationActivity(array $params): void
    {
        $this->guard->requireSystemAdmin();
        Json::success($this->admin->listActivity((int) $params['id'], $_GET));
    }

    public function users(array $params = []): void
    {
        $this->guard->requireSystemAdmin();
        Json::success($this->admin->listUsers($_GET));
    }

    public function setUserStatus(array $params): void
    {
        $user = $this->guard->requireSystemAdmin();
        Json::success($this->admin->setUserStatus((int) $params['id'], Json::readBody(), $user));
    }

    public function resetUserPassword(array $params): void
    {
        $user = $this->guard->requireSystemAdmin();
        Json::success($this->admin->resetUserPassword((int) $params['id'], $user));
    }

    public function backups(array $params = []): void
    {
        $this->guard->requireSystemAdmin();
        Json::success(['items' => DatabaseBackup::storage()->list()]);
    }

    public function createBackup(array $params = []): void
    {
        $user = $this->guard->requireSystemAdmin();
        try {
            $created = DatabaseBackup::storage()->create();
        } catch (\Throwable $e) {
            Json::error('BACKUP_FAILED', $e->getMessage(), 500);
        }
        ActivityLog::record($this->adminPdo(), [
            'userId' => (int) ($user['id'] ?? 0),
            'actorName' => trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))),
            'actorRole' => 'SYSTEM_ADMIN',
            'action' => 'database.backup',
            'category' => 'system',
            'description' => 'Stored database backup ' . $created['file'],
            'entityType' => 'backup',
        ]);
        Json::success($created, 201);
    }

    public function downloadBackup(array $params): void
    {
        $this->guard->requireSystemAdmin();
        try {
            $path = DatabaseBackup::storage()->path((string) ($_GET['file'] ?? ''));
        } catch (\Throwable) {
            Json::error('NOT_FOUND', 'That backup file was not found.', 404);
        }
        $name = basename($path);
        header('Content-Type: application/sql');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    private function adminPdo(): \PDO
    {
        return \youthsync_pdo();
    }
}
