<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;
use YouthSync\Programs\ProgramService;

final class ProgramController
{
    public function __construct(
        private SkGuard $guard,
        private ProgramService $programs,
    ) {
    }

    public function index(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->programs->list($ctx['organization_id'], $_GET, $ctx['membership']));
    }

    public function show(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->programs->get($ctx['organization_id'], (int) $params['id']));
    }

    public function store(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->programs->create($ctx['organization_id'], Json::readBody(), $ctx['membership']), 201);
    }

    public function update(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->programs->update(
            $ctx['organization_id'],
            (int) $params['id'],
            Json::readBody(),
            $ctx['membership']
        ));
    }

    public function destroy(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->programs->delete($ctx['organization_id'], (int) $params['id']);
        Json::success(['deleted' => true]);
    }

    public function setStatus(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $body = Json::readBody();
        $status = isset($body['status']) && is_string($body['status']) ? $body['status'] : '';
        Json::success($this->programs->setStatus(
            $ctx['organization_id'],
            (int) $params['id'],
            $status,
            $ctx['membership']
        ));
    }

    public function archive(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->programs->setStatus(
            $ctx['organization_id'],
            (int) $params['id'],
            'archived',
            $ctx['membership']
        ));
    }

    public function eventIndex(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->programs->list($ctx['organization_id'], $_GET, $ctx['membership'], 'event'));
    }

    public function eventStore(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->programs->create($ctx['organization_id'], Json::readBody(), $ctx['membership'], 'event'), 201);
    }

    public function eventShow(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->programs->get($ctx['organization_id'], (int) $params['id'], 'event'));
    }

    public function eventUpdate(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->programs->update(
            $ctx['organization_id'],
            (int) $params['id'],
            Json::readBody(),
            $ctx['membership'],
            'event'
        ));
    }

    public function eventDestroy(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->programs->delete($ctx['organization_id'], (int) $params['id'], 'event');
        Json::success(['deleted' => true]);
    }

    public function eventsForProgram(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->programs->get($ctx['organization_id'], (int) $params['id']);
        Json::success($this->programs->list($ctx['organization_id'], $_GET, $ctx['membership'], 'event'));
    }

    public function storeEventForProgram(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->programs->get($ctx['organization_id'], (int) $params['id']);
        Json::success($this->programs->create($ctx['organization_id'], Json::readBody(), $ctx['membership'], 'event'), 201);
    }
}
