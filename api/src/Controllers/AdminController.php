<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Admin\AdminService;
use YouthSync\Http\AdminGuard;
use YouthSync\Http\Json;

final class AdminController
{
    public function __construct(
        private AdminGuard $guard,
        private AdminService $admin,
    ) {
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

    public function organizationActivity(array $params): void
    {
        $this->guard->requireSystemAdmin();
        Json::success($this->admin->listActivity((int) $params['id'], $_GET));
    }

    public function payments(array $params = []): void
    {
        $this->guard->requireSystemAdmin();
        Json::success($this->admin->listPayments(null, $_GET));
    }

    public function organizationPayments(array $params): void
    {
        $this->guard->requireSystemAdmin();
        Json::success($this->admin->listPayments((int) $params['id'], $_GET));
    }

    public function storePayment(array $params = []): void
    {
        $user = $this->guard->requireSystemAdmin();
        Json::success($this->admin->recordPayment(Json::readBody(), $user), 201);
    }

    public function storeOrganizationPayment(array $params): void
    {
        $user = $this->guard->requireSystemAdmin();
        $body = Json::readBody();
        $body['organizationId'] = (int) $params['id'];
        Json::success($this->admin->recordPayment($body, $user), 201);
    }

    public function users(array $params = []): void
    {
        $this->guard->requireSystemAdmin();
        Json::success($this->admin->listUsers($_GET));
    }
}
