<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Applications\ApplicationService;
use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;

final class ApplicationController
{
    public function __construct(
        private SkGuard $guard,
        private ApplicationService $applications,
    ) {
    }

    public function index(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->applications->list($ctx['organization_id'], $_GET));
    }

    public function show(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->applications->get($ctx['organization_id'], (int) $params['id']));
    }

    public function store(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->applications->create($ctx['organization_id'], Json::readBody()), 201);
    }

    public function update(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->applications->update(
            $ctx['organization_id'],
            (int) $params['id'],
            Json::readBody()
        ));
    }

    public function setStatus(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->applications->setStatus(
            $ctx['organization_id'],
            (int) $params['id'],
            (int) $ctx['user']['id'],
            Json::readBody()
        ));
    }

    public function approve(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $body = Json::readBody();
        $body['status'] = 'approved';
        Json::success($this->applications->setStatus(
            $ctx['organization_id'],
            (int) $params['id'],
            (int) $ctx['user']['id'],
            $body
        ));
    }

    public function reject(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $body = Json::readBody();
        $body['status'] = 'rejected';
        if (!isset($body['remarks']) && isset($body['reason'])) {
            $body['remarks'] = $body['reason'];
        }
        Json::success($this->applications->setStatus(
            $ctx['organization_id'],
            (int) $params['id'],
            (int) $ctx['user']['id'],
            $body
        ));
    }

    public function reviewRequirement(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->applications->reviewSubmission(
            $ctx['organization_id'],
            (int) $params['id'],
            (int) $ctx['user']['id'],
            Json::readBody()
        ));
    }
}
