<?php
declare(strict_types=1);

namespace YouthSync\Http;

use YouthSync\Auth\AuthService;
use YouthSync\Middleware\AuthMiddleware;

/**
 * The admin counterpart of SkGuard.
 *
 * Deliberately does not run OrganizationMiddleware: the system administrator
 * holds no membership row, and every /admin route is deployment-wide.
 */
final class AdminGuard
{
    public function __construct(private AuthMiddleware $authMiddleware)
    {
    }

    /**
     * @return array<string, mixed> the authenticated admin user row
     */
    public function requireSystemAdmin(): array
    {
        $user = $this->authMiddleware->handle();
        if (($user['role_code'] ?? '') !== AuthService::ROLE_SYSTEM_ADMIN) {
            Json::error('FORBIDDEN', 'This account is not allowed to use the system administrator console.', 403);
        }
        SessionLock::releaseWriteLock();
        return $user;
    }
}
