<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Assistance\AssistanceService;
use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;

final class AssistanceController
{
    public function __construct(
        private SkGuard $guard,
        private AssistanceService $assistance,
    ) {
    }

    public function index(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->assistance->list($ctx['organization_id'], $_GET, $ctx['membership']));
    }

    public function show(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->assistance->get($ctx['organization_id'], (int) $params['id']));
    }

    public function store(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->assistance->create($ctx['organization_id'], Json::readBody(), $ctx['membership']), 201);
    }

    public function update(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->assistance->update(
            $ctx['organization_id'],
            (int) $params['id'],
            Json::readBody(),
            $ctx['membership']
        ));
    }

    public function archive(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->assistance->archive($ctx['organization_id'], (int) $params['id'], $ctx['membership']));
    }

    public function destroy(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->assistance->delete($ctx['organization_id'], (int) $params['id']);
        Json::success(['deleted' => true]);
    }

    public function types(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success(['items' => $this->assistance->listTypes($ctx['organization_id'])]);
    }

    public function storeType(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->assistance->createType($ctx['organization_id'], Json::readBody()), 201);
    }

    public function storeBeneficiary(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->assistance->saveBeneficiary(
            $ctx['organization_id'],
            (int) $params['id'],
            Json::readBody()
        ), 201);
    }

    public function destroyBeneficiary(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->assistance->deleteBeneficiary($ctx['organization_id'], (int) $params['id']);
        Json::success(['deleted' => true]);
    }

    public function storeRequirement(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->assistance->addRequirement($ctx['organization_id'], Json::readBody()), 201);
    }

    public function updateRequirement(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->assistance->updateRequirement(
            $ctx['organization_id'],
            (int) $params['id'],
            Json::readBody()
        ));
    }

    public function destroyRequirement(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->assistance->deleteRequirement($ctx['organization_id'], (int) $params['id']);
        Json::success(['deleted' => true]);
    }
}
