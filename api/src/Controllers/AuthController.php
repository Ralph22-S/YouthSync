<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Auth\AuthService;
use YouthSync\Auth\PasswordResetService;
use YouthSync\Auth\YouthSignupService;
use YouthSync\Http\Json;
use YouthSync\Http\SessionLock;
use YouthSync\Middleware\AuthMiddleware;
use YouthSync\Middleware\OrganizationMiddleware;
use YouthSync\Middleware\RoleMiddleware;
use YouthSync\Org\RegistrationService;

final class AuthController
{
    public function __construct(
        private AuthService $auth,
        private AuthMiddleware $authMiddleware,
        private RoleMiddleware $roleMiddleware,
        private OrganizationMiddleware $organizationMiddleware,
        private RegistrationService $registration,
        private PasswordResetService $passwordResets,
        private YouthSignupService $youthSignup,
    ) {
    }

    public function youthOrganizations(array $params = []): void
    {
        Json::success($this->youthSignup->organizations());
    }

    public function youthSignupStart(array $params = []): void
    {
        Json::success($this->youthSignup->start(Json::readBody()), 201);
    }

    public function youthSignupResend(array $params = []): void
    {
        $body = Json::readBody();
        $token = isset($body['signupId']) && is_string($body['signupId']) ? $body['signupId'] : '';
        Json::success($this->youthSignup->resend($token));
    }

    public function youthSignupCancel(array $params = []): void
    {
        $body = Json::readBody();
        $token = isset($body['signupId']) && is_string($body['signupId']) ? $body['signupId'] : '';
        $this->youthSignup->cancel($token);
        Json::success(['cancelled' => true]);
    }

    public function youthSignupVerify(array $params = []): void
    {
        $body = Json::readBody();
        $token = isset($body['signupId']) && is_string($body['signupId']) ? $body['signupId'] : '';
        $code = isset($body['code']) && is_string($body['code']) ? $body['code'] : '';
        Json::success($this->youthSignup->verify($token, $code));
    }

    public function register(array $params = []): void
    {
        $data = $this->registration->register(Json::readBody());
        Json::success($data, 201);
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
        $user = $this->authMiddleware->handle();
        if (($user['role_code'] ?? '') === AuthService::ROLE_SYSTEM_ADMIN) {
            SessionLock::releaseWriteLock();
            Json::success($this->auth->currentAdminSnapshot());
        }
        if (($user['role_code'] ?? '') === AuthService::ROLE_YOUTH) {
            SessionLock::releaseWriteLock();
            Json::success($this->auth->currentYouthSnapshot());
        }

        $this->roleMiddleware->handle($user);
        $this->organizationMiddleware->handle((int) $user['id']);
        SessionLock::releaseWriteLock();
        Json::success($this->auth->currentSnapshot());
    }

    public function changePassword(array $params = []): void
    {
        $user = $this->authMiddleware->handle();
        $role = (string) ($user['role_code'] ?? '');
        if ($role !== AuthService::ROLE_SYSTEM_ADMIN && $role !== AuthService::ROLE_YOUTH) {
            $this->roleMiddleware->handle($user);
            $this->organizationMiddleware->handle((int) $user['id']);
        }
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

    public function forgotPassword(array $params = []): void
    {
        $body = Json::readBody();
        $email = isset($body['email']) && is_string($body['email']) ? $body['email'] : '';
        $this->passwordResets->request($email);
        Json::success(['message' => PasswordResetService::genericMessage()]);
    }

    public function resetPassword(array $params = []): void
    {
        $body = Json::readBody();
        $token = isset($body['token']) && is_string($body['token']) ? $body['token'] : '';
        $password = '';
        if (isset($body['new_password']) && is_string($body['new_password'])) {
            $password = $body['new_password'];
        } elseif (isset($body['newPassword']) && is_string($body['newPassword'])) {
            $password = $body['newPassword'];
        }
        $this->passwordResets->reset($token, $password);
        Json::success(['password_reset' => true]);
    }
}
