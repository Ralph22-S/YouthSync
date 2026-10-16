<?php
declare(strict_types=1);

namespace YouthSync\Http;

use YouthSync\Auth\AuthService;
use YouthSync\Middleware\AuthMiddleware;
use YouthSync\Org\Tenant;

/**
 * Youth routes only. Identity is the session youth id, never a client id.
 */
final class YouthGuard
{
    public function __construct(private AuthMiddleware $authMiddleware)
    {
    }

    /**
     * @return array{user: array<string, mixed>, youth_id: int}
     */
    public function requireYouth(): array
    {
        $user = $this->authMiddleware->handle();
        if (($user['role_code'] ?? '') !== AuthService::ROLE_YOUTH) {
            Json::error('FORBIDDEN', 'This account is not allowed to use the youth service.', 403);
        }

        $sessionYouth = $_SESSION[Tenant::SESSION_YOUTH_KEY] ?? null;
        $sessionYouthId = is_int($sessionYouth) || (is_string($sessionYouth) && ctype_digit((string) $sessionYouth))
            ? (int) $sessionYouth
            : 0;
        $accountYouthId = (int) ($user['youth_id'] ?? 0);
        if ($sessionYouthId < 1 || $accountYouthId < 1 || $sessionYouthId !== $accountYouthId) {
            Json::error('FORBIDDEN', 'This youth account is not linked to a youth record.', 403);
        }
        unset($_GET['youth_id'], $_POST['youth_id'], $_GET['organization_id'], $_POST['organization_id']);

        SessionLock::releaseWriteLock();
        return [
            'user' => $user,
            'youth_id' => $accountYouthId,
        ];
    }
}
