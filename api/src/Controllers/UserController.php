<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;
use YouthSync\Users\UserService;

final class UserController
{
    public function __construct(
        private SkGuard $guard,
        private UserService $users,
    ) {
    }

    public function index(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->users->list($ctx['organization_id'], $_GET));
    }

    public function show(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->users->get($ctx['organization_id'], (int) $params['id']));
    }

    public function store(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->users->create(
            $ctx['organization_id'],
            Json::readBody(),
            $ctx['membership']
        ), 201);
    }

    public function update(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->users->update(
            $ctx['organization_id'],
            (int) $params['id'],
            Json::readBody()
        ));
    }

    public function setStatus(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->users->setStatus(
            $ctx['organization_id'],
            (int) $ctx['user']['id'],
            (int) $params['id'],
            Json::readBody()
        ));
    }

    public function toggleActive(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->users->toggleActive(
            $ctx['organization_id'],
            (int) $ctx['user']['id'],
            (int) $params['id']
        ));
    }

    public function resetPassword(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->users->resetPassword(
            $ctx['organization_id'],
            (int) $params['id']
        ));
    }

    public function destroy(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->users->delete(
            $ctx['organization_id'],
            (int) $ctx['user']['id'],
            (int) $params['id']
        ));
    }
}
