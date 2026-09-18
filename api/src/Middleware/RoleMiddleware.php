<?php
declare(strict_types=1);

namespace YouthSync\Middleware;

use YouthSync\Auth\AuthService;
use YouthSync\Http\Json;

final class RoleMiddleware
{
    public function handle(array $user): void
    {
        $code = (string) ($user['role_code'] ?? '');
        if ($code !== AuthService::ROLE_SK_OFFICIAL) {
            Json::error('FORBIDDEN', 'This account is not allowed to use the SK Official service.', 403);
        }
    }
}
