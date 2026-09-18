<?php
declare(strict_types=1);

namespace YouthSync\Auth;

use PDO;
use YouthSync\Http\Json;
use YouthSync\Http\SessionCookies;
use YouthSync\Org\Tenant;

final class AuthService
{
    public const ROLE_SK_OFFICIAL = 'SK_OFFICIAL';

    private const CREDENTIALS_MESSAGE = 'Those credentials do not match our records.';
    private const INACTIVE_USER_MESSAGE = 'This account has been deactivated.';
    private const PENDING_ORG_MESSAGE = 'Your organization is awaiting verification. You will be notified once it is approved.';
    private const INACTIVE_ORG_MESSAGE = 'Your organization account is not active. Contact the system administrator.';
    private const NO_ORG_MESSAGE = 'Your organization account is not active. Contact the system administrator.';
    private const ROLE_MESSAGE = 'This account is not allowed to use the SK Official service.';

    /**
     * @param array<string, mixed> $sessionConfig
     */
    public function __construct(
        private PDO $pdo,
        private Tenant $tenant,
        private bool $demoMode = false,
        private string $demoLoginEmail = 'sk1@demo.test',
        private array $sessionConfig = [],
        private ?RememberTokenStore $rememberTokens = null,
    ) {
        $this->demoLoginEmail = strtolower(trim($this->demoLoginEmail));
        if ($this->rememberTokens === null) {
            $this->rememberTokens = new RememberTokenStore(
                dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'remember_tokens.json'
            );
        }
    }

    public function login(string $email, string $password, bool $remember = false): array
    {
        $email = $this->normalizeEmail($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Json::error('VALIDATION_ERROR', 'Enter a valid email address.', 422);
        }
        if ($password === '') {
            Json::error('VALIDATION_ERROR', 'Password is required.', 422);
        }

        $user = $this->findUserByEmail($email);
        $demoBypass = $this->allowsDemoPasswordBypass($email);
        $passwordOk = $user !== null && (
            ($demoBypass && $password !== '')
            || Password::verify($password, (string) $user['password_hash'])
        );
        if ($user === null || !$passwordOk) {
            Json::error('INVALID_CREDENTIALS', self::CREDENTIALS_MESSAGE, 401);
        }

        if ($user['status'] !== 'active') {
            Json::error('ACCOUNT_INACTIVE', self::INACTIVE_USER_MESSAGE, 403);
        }

        if ($user['role_code'] !== self::ROLE_SK_OFFICIAL) {
            Json::error('FORBIDDEN', self::ROLE_MESSAGE, 403);
        }

        $membership = $this->tenant->membershipForUser((int) $user['id']);
        if ($membership === null) {
            Json::error('ORGANIZATION_REQUIRED', self::NO_ORG_MESSAGE, 403);
        }

        if ($membership['status'] === 'pending') {
            Json::error('ORGANIZATION_PENDING', self::PENDING_ORG_MESSAGE, 403);
        }

        if ($membership['status'] !== 'active') {
            Json::error('ORGANIZATION_INACTIVE', self::INACTIVE_ORG_MESSAGE, 403);
        }

        $this->establishSession((int) $user['id'], (int) $membership['organization_id'], $user['role_code']);
        $this->replaceRememberToken((int) $user['id'], (int) $membership['organization_id'], $user['role_code'], $remember);
        $this->touchLastLogin((int) $user['id']);
        $user['last_login_at'] = date('Y-m-d H:i:s');

        return [
            'user' => $this->publicUser($user),
            'organization' => $this->tenant->publicOrganization($membership),
            'role' => self::ROLE_SK_OFFICIAL,
        ];
    }

    public function logout(): void
    {
        $userId = 0;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $raw = $_SESSION[Tenant::SESSION_USER_KEY] ?? 0;
            $userId = (int) $raw;
        }
        $this->rememberTokens?->forgetPlain(SessionCookies::token());
        if ($userId > 0) {
            $this->rememberTokens?->forgetUser($userId);
        }
        $this->clearRememberCookie();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        $params = SessionCookies::params($this->sessionConfig, 0);
        SessionCookies::write(session_name(), '', $params, time() - 3600);
        session_destroy();
    }

    public function resumeRememberedSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[Tenant::SESSION_USER_KEY])) {
            return;
        }

        $plain = SessionCookies::token();
        if ($plain === '') {
            return;
        }

        $record = $this->rememberTokens?->findValid($plain);
        if ($record === null) {
            $this->clearRememberCookie();
            return;
        }

        $user = $this->findUserById($record['user_id']);
        if ($user === null || $user['status'] !== 'active' || ($user['role_code'] ?? '') !== self::ROLE_SK_OFFICIAL) {
            $this->rememberTokens?->forgetPlain($plain);
            $this->clearRememberCookie();
            return;
        }

        $membership = $this->tenant->membershipForUser((int) $user['id']);
        if ($membership === null || $membership['status'] !== 'active') {
            $this->rememberTokens?->forgetPlain($plain);
            $this->clearRememberCookie();
            return;
        }
        if ((int) $membership['organization_id'] !== $record['organization_id']) {
            $this->rememberTokens?->forgetPlain($plain);
            $this->clearRememberCookie();
            return;
        }

        $this->establishSession(
            (int) $user['id'],
            (int) $membership['organization_id'],
            (string) $user['role_code']
        );
    }

    public function currentSnapshot(): array
    {
        $userId = $this->tenant->userId();
        $user = $this->findUserById($userId);
        if ($user === null || $user['status'] !== 'active') {
            Json::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        $membership = $this->tenant->membershipForUser($userId);
        if ($membership === null) {
            Json::error('ORGANIZATION_REQUIRED', self::NO_ORG_MESSAGE, 403);
        }

        $sessionOrg = $this->tenant->organizationId();
        if ((int) $membership['organization_id'] !== $sessionOrg) {
            Json::error('FORBIDDEN', 'The organization for this session is no longer valid.', 403);
        }

        if ($membership['status'] !== 'active') {
            Json::error('ORGANIZATION_INACTIVE', self::INACTIVE_ORG_MESSAGE, 403);
        }

        return [
            'user' => $this->publicUser($user),
            'organization' => $this->tenant->publicOrganization($membership),
            'role' => $user['role_code'],
        ];
    }

    public function changePassword(string $currentPassword, string $newPassword): void
    {
        $userId = $this->tenant->userId();
        $user = $this->findUserById($userId);
        if ($user === null) {
            Json::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        if ($currentPassword === '' || $newPassword === '') {
            Json::error('VALIDATION_ERROR', 'Current password and new password are required.', 422);
        }

        if (!Password::verify($currentPassword, (string) $user['password_hash'])) {
            Json::error('INVALID_CREDENTIALS', 'The current password is incorrect.', 401);
        }

        if (!Password::isValidNew($newPassword)) {
            Json::error('VALIDATION_ERROR', 'Use a password of at least 8 characters.', 422);
        }

        if (Password::verify($newPassword, (string) $user['password_hash'])) {
            Json::error('VALIDATION_ERROR', 'Choose a new password that is different from the current password.', 422);
        }

        $hash = Password::hash($newPassword);
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id'
        );
        $stmt->execute([
            'hash' => $hash,
            'id' => $userId,
        ]);
    }

    public function findUserById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function publicUser(array $user): array
    {
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        $mustChange = (bool) $user['must_change_password'];
        if ($this->allowsDemoPasswordBypass($email)) {
            $mustChange = false;
        }

        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'status' => $user['status'],
            'must_change_password' => $mustChange,
            'last_login_at' => $user['last_login_at'],
        ];
    }

    private function allowsDemoPasswordBypass(string $normalizedEmail): bool
    {
        return $this->demoMode
            && $this->demoLoginEmail !== ''
            && $normalizedEmail === $this->demoLoginEmail;
    }

    private function findUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function establishSession(int $userId, int $organizationId, string $roleCode): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_regenerate_id(true);
        $_SESSION[Tenant::SESSION_USER_KEY] = $userId;
        $_SESSION[Tenant::SESSION_ORG_KEY] = $organizationId;
        $_SESSION[Tenant::SESSION_ROLE_KEY] = $roleCode;
    }

    private function replaceRememberToken(int $userId, int $organizationId, string $roleCode, bool $remember): void
    {
        $this->rememberTokens?->forgetPlain(SessionCookies::token());
        $this->rememberTokens?->forgetUser($userId);

        if (!$remember) {
            $this->clearRememberCookie();
            return;
        }

        $lifetime = max(0, (int) ($this->sessionConfig['session_remember_lifetime'] ?? 2592000));
        if ($lifetime < 1) {
            $this->clearRememberCookie();
            return;
        }

        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = time() + $lifetime;
        $this->rememberTokens?->put($plain, $userId, $organizationId, $roleCode, $expires);
        SessionCookies::writeRemember($plain, $this->sessionConfig, $expires);
    }

    private function clearRememberCookie(): void
    {
        SessionCookies::writeRemember('', $this->sessionConfig, time() - 3600);
    }

    private function touchLastLogin(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
