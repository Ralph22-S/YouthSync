<?php
declare(strict_types=1);

namespace YouthSync\Http;

use YouthSync\Middleware\AuthMiddleware;
use YouthSync\Middleware\OrganizationMiddleware;
use YouthSync\Middleware\RoleMiddleware;

final class SkGuard
{
    public function __construct(
        private AuthMiddleware $authMiddleware,
        private RoleMiddleware $roleMiddleware,
        private OrganizationMiddleware $organizationMiddleware,
    ) {
    }

    /**
     * @return array{user: array, membership: array, organization_id: int}
     */
    public function requireSkOfficial(): array
    {
        $user = $this->authMiddleware->handle();
        $this->roleMiddleware->handle($user);
        $membership = $this->organizationMiddleware->handle((int) $user['id']);
        SessionLock::releaseWriteLock();
        return [
            'user' => $user,
            'membership' => $membership,
            'organization_id' => (int) $membership['organization_id'],
        ];
    }
}
