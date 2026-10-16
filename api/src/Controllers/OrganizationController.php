<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;
use YouthSync\Org\OrganizationProfileService;

final class OrganizationController
{
    public function __construct(
        private SkGuard $guard,
        private OrganizationProfileService $profiles,
    ) {
    }

    public function show(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->profiles->get($ctx['organization_id']));
    }

    public function update(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->profiles->update($ctx['organization_id'], Json::readBody(), $ctx['user']));
    }
}
