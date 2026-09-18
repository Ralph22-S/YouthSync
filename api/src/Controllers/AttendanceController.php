<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Attendance\AttendanceService;
use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;

final class AttendanceController
{
    public function __construct(
        private SkGuard $guard,
        private AttendanceService $attendance,
    ) {
    }

    public function createSession(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->attendance->createSession(
            $ctx['organization_id'],
            (int) $params['id'],
            (int) $ctx['user']['id'],
            $ctx['membership'],
            Json::readBody()
        ), 201);
    }

    public function getSession(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->attendance->getSession($ctx['organization_id'], (int) $params['id']));
    }

    public function listForProgram(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->attendance->listForProgram($ctx['organization_id'], (int) $params['id']));
    }

    public function scan(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $result = $this->attendance->scan($ctx['organization_id'], $ctx['membership'], Json::readBody());
        Json::success($result, !empty($result['created']) ? 201 : 200);
    }

    public function confirm(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->attendance->confirm($ctx['organization_id'], Json::readBody()));
    }

    public function manual(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->attendance->manual($ctx['organization_id'], Json::readBody()), 201);
    }

    public function show(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->attendance->get($ctx['organization_id'], (int) $params['id']));
    }

    public function updateStatus(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->attendance->updateStatus(
            $ctx['organization_id'],
            (int) $params['id'],
            Json::readBody()
        ));
    }
}
