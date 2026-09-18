<?php
declare(strict_types=1);

namespace YouthSync\Middleware;

use YouthSync\Auth\AuthService;
use YouthSync\Http\Json;
use YouthSync\Org\Tenant;

final class AuthMiddleware
{
    public function __construct(private AuthService $auth)
    {
    }

    public function handle(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION[Tenant::SESSION_USER_KEY])) {
            Json::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        $userId = (int) $_SESSION[Tenant::SESSION_USER_KEY];
        $user = $this->auth->findUserById($userId);
        if ($user === null || $user['status'] !== 'active') {
            Json::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        return $user;
    }
}
