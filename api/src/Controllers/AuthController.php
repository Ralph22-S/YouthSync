<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Auth\AuthService;
use YouthSync\Http\Json;
use YouthSync\Http\SessionLock;
use YouthSync\Middleware\AuthMiddleware;
use YouthSync\Middleware\OrganizationMiddleware;
use YouthSync\Middleware\RoleMiddleware;

final class AuthController
{
    public function __construct(
        private AuthService $auth,
        private AuthMiddleware $authMiddleware,
        private RoleMiddleware $roleMiddleware,
        private OrganizationMiddleware $organizationMiddleware,
    ) {
    }

    public function login(array $params = []): void
    {
        $body = Json::readBody();
        $email = isset($body['email']) && is_string($body['email']) ? $body['email'] : '';
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        $remember = false;
        if (array_key_exists('remember', $body)) {
            $remember = filter_var($body['remember'], FILTER_VALIDATE_BOOLEAN);
        } elseif (array_key_exists('rememberMe', $body)) {
            $remember = filter_var($body['rememberMe'], FILTER_VALIDATE_BOOLEAN);
        }

        $data = $this->auth->login($email, $password, $remember);
        Json::success($data, 200);
    }

    public function logout(array $params = []): void
    {
        $this->auth->logout();
        Json::success(['logged_out' => true]);
    }

    public function me(array $params = []): void
    {
        $this->guardSkOfficial();
        SessionLock::releaseWriteLock();
        Json::success($this->auth->currentSnapshot());
    }

    public function changePassword(array $params = []): void
    {
        $this->guardSkOfficial();
        SessionLock::releaseWriteLock();
        $body = Json::readBody();
        $current = '';
        if (isset($body['current_password']) && is_string($body['current_password'])) {
            $current = $body['current_password'];
        } elseif (isset($body['currentPassword']) && is_string($body['currentPassword'])) {
            $current = $body['currentPassword'];
        }
        $new = '';
        if (isset($body['new_password']) && is_string($body['new_password'])) {
            $new = $body['new_password'];
        } elseif (isset($body['newPassword']) && is_string($body['newPassword'])) {
            $new = $body['newPassword'];
        }

        $this->auth->changePassword($current, $new);
        Json::success(['password_changed' => true]);
    }

    private function guardSkOfficial(): array
    {
        $user = $this->authMiddleware->handle();
        $this->roleMiddleware->handle($user);
        $this->organizationMiddleware->handle((int) $user['id']);
        return $user;
    }
}
