<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;
use YouthSync\Youth\YouthService;

final class YouthController
{
    public function __construct(
        private SkGuard $guard,
        private YouthService $youth,
    ) {
    }

    public function index(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->youth->list($ctx['organization_id'], $_GET, $ctx['membership']));
    }

    public function show(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->youth->get($ctx['organization_id'], (int) $params['id']));
    }

    public function store(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->youth->create($ctx['organization_id'], Json::readBody(), $ctx['membership']), 201);
    }

    public function update(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->youth->update($ctx['organization_id'], (int) $params['id'], Json::readBody()));
    }

    public function archive(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->youth->archive($ctx['organization_id'], (int) $params['id'], $ctx['membership'], true));
    }

    public function restore(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->youth->archive($ctx['organization_id'], (int) $params['id'], $ctx['membership'], false));
    }

    public function destroy(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->youth->delete($ctx['organization_id'], (int) $params['id']);
        Json::success(['deleted' => true]);
    }

    public function import(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $body = Json::readBody();
        $rows = [];
        if (array_is_list($body)) {
            $rows = $body;
        } elseif (isset($body['rows']) && is_array($body['rows'])) {
            $rows = $body['rows'];
        }
        Json::success($this->youth->import($ctx['organization_id'], $rows, $ctx['membership']), 201);
    }
}
