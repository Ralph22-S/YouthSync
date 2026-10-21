<?php
declare(strict_types=1);

namespace YouthSync\Auth;

use PDO;
use YouthSync\Admin\ActivityLog;
use YouthSync\Http\Json;
use YouthSync\Http\SessionCookies;
use YouthSync\Org\Tenant;

final class AuthService
{
    public const ROLE_SK_OFFICIAL = 'SK_OFFICIAL';
    public const ROLE_SYSTEM_ADMIN = 'SYSTEM_ADMIN';
    public const ROLE_YOUTH = 'YOUTH';

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
            if ($user === null) {
                ActivityLog::recordUnknownAccountLoginFailure($this->pdo);
            } else {
                $this->recordLoginFailure($user, 'incorrect password');
            }
            $this->abandonUnauthenticatedSession();
            Json::error('INVALID_CREDENTIALS', self::CREDENTIALS_MESSAGE, 401);
        }

        if ($user['status'] !== 'active') {
            $this->recordLoginFailure($user, 'account is deactivated');
            Json::error('ACCOUNT_INACTIVE', self::INACTIVE_USER_MESSAGE, 403);
        }

        if (($user['role_code'] ?? '') === self::ROLE_SYSTEM_ADMIN) {
            return $this->completeSystemAdminLogin($user, $remember);
        }

        if (($user['role_code'] ?? '') === self::ROLE_YOUTH) {
            return $this->completeYouthLogin($user, $remember);
        }

        if ($user['role_code'] !== self::ROLE_SK_OFFICIAL) {
            $this->recordLoginFailure($user, 'role is not allowed to sign in');
            Json::error('FORBIDDEN', self::ROLE_MESSAGE, 403);
        }

        $membership = $this->tenant->membershipForUser((int) $user['id']);
        if ($membership === null) {
            $this->recordLoginFailure($user, 'no active organization membership');
            Json::error('ORGANIZATION_REQUIRED', self::NO_ORG_MESSAGE, 403);
        }

        if ($membership['status'] === 'pending') {
            $this->recordLoginFailure($user, 'organization is awaiting verification', (int) $membership['organization_id']);
            Json::error('ORGANIZATION_PENDING', self::PENDING_ORG_MESSAGE, 403);
        }

        if ($membership['status'] !== 'active') {
            $this->recordLoginFailure($user, 'organization is not active', (int) $membership['organization_id']);
            Json::error('ORGANIZATION_INACTIVE', self::INACTIVE_ORG_MESSAGE, 403);
        }

        $this->establishSession((int) $user['id'], (int) $membership['organization_id'], $user['role_code']);
        $this->replaceRememberToken((int) $user['id'], (int) $membership['organization_id'], $user['role_code'], $remember);
        $this->touchLastLogin((int) $user['id']);
        $user['last_login_at'] = date('Y-m-d H:i:s');
        $this->recordLoginActivity($user, (int) $membership['organization_id']);

        return [
            'user' => $this->publicUser($user),
            'organization' => $this->tenant->publicOrganization($membership),
            'role' => self::ROLE_SK_OFFICIAL,
        ];
    }

    /**
     * Signs in a youth account that was just created by self-registration.
     * The signup password is already stored as a hash, so this does not check it again.
     *
     * @return array<string, mixed>
     */
    public function signInYouthAccount(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.*, r.code AS role_code, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if ($user === false || ($user['role_code'] ?? '') !== self::ROLE_YOUTH || $user['status'] !== 'active') {
            Json::error('FORBIDDEN', 'This youth account could not be signed in.', 403);
        }
        return $this->completeYouthLogin($user, false);
    }

    public function logout(): void
    {
        $userId = 0;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $raw = $_SESSION[Tenant::SESSION_USER_KEY] ?? 0;
            $userId = (int) $raw;
        }
        if ($userId > 0) {
            // Only a real signed-in session produces a sign-out row.
            $role = (string) ($_SESSION[Tenant::SESSION_ROLE_KEY] ?? '');
            ActivityLog::recordAuthForUserId(
                $this->pdo,
                $userId,
                ActivityLog::ACTION_LOGOUT,
                'Signed out' . ($role !== '' ? ' (' . $role . ')' : '')
            );
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
        if ($user === null || $user['status'] !== 'active') {
            $this->rememberTokens?->forgetPlain($plain);
            $this->clearRememberCookie();
            return;
        }

        if (($user['role_code'] ?? '') === self::ROLE_SYSTEM_ADMIN) {
            if (($record['role_code'] ?? '') !== self::ROLE_SYSTEM_ADMIN) {
                $this->rememberTokens?->forgetPlain($plain);
                $this->clearRememberCookie();
                return;
            }
            $this->establishSystemAdminSession((int) $user['id']);
            return;
        }

        if (($user['role_code'] ?? '') === self::ROLE_YOUTH) {
            $youthId = (int) ($user['youth_id'] ?? 0);
            if (($record['role_code'] ?? '') !== self::ROLE_YOUTH || $youthId < 1 || $youthId !== (int) ($record['youth_id'] ?? 0)) {
                $this->rememberTokens?->forgetPlain($plain);
                $this->clearRememberCookie();
                return;
            }
            if (!$this->youthIsActive($youthId)) {
                $this->rememberTokens?->forgetPlain($plain);
                $this->clearRememberCookie();
                return;
            }
            $this->establishYouthSession((int) $user['id'], $youthId);
            return;
        }

        if (($user['role_code'] ?? '') !== self::ROLE_SK_OFFICIAL) {
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

    /**
     * SYSTEM_ADMIN snapshot. No organization is attached to this session.
     *
     * @return array<string, mixed>
     */
    public function currentAdminSnapshot(): array
    {
        $userId = $this->tenant->userId();
        $user = $this->findUserById($userId);
        if ($user === null || $user['status'] !== 'active') {
            Json::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        if (($user['role_code'] ?? '') !== self::ROLE_SYSTEM_ADMIN) {
            Json::error('FORBIDDEN', 'This account is not allowed to use the system administrator console.', 403);
        }

        return [
            'user' => $this->publicUser($user),
            'organization' => null,
            'role' => self::ROLE_SYSTEM_ADMIN,
        ];
    }

    /**
     * Youth snapshot. Identity comes from the session user and session youth id.
     *
     * @return array<string, mixed>
     */
    public function currentYouthSnapshot(): array
    {
        $userId = $this->tenant->userId();
        $user = $this->findUserById($userId);
        if ($user === null || $user['status'] !== 'active') {
            Json::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        if (($user['role_code'] ?? '') !== self::ROLE_YOUTH) {
            Json::error('FORBIDDEN', 'This account is not allowed to use the youth service.', 403);
        }

        $sessionYouth = $_SESSION[Tenant::SESSION_YOUTH_KEY] ?? null;
        $sessionYouthId = is_int($sessionYouth) || (is_string($sessionYouth) && ctype_digit($sessionYouth))
            ? (int) $sessionYouth
            : 0;
        $accountYouthId = (int) ($user['youth_id'] ?? 0);
        if ($sessionYouthId < 1 || $accountYouthId < 1 || $sessionYouthId !== $accountYouthId) {
            Json::error('FORBIDDEN', 'This youth account is not linked to a youth record.', 403);
        }

        $profile = $this->youthProfile($accountYouthId);
        if ($profile === null) {
            Json::error('FORBIDDEN', 'This youth account is not linked to a youth record.', 403);
        }

        return [
            'user' => $this->publicUser($user),
            'organization' => $profile['organization'],
            'youth' => $profile['youth'],
            'role' => self::ROLE_YOUTH,
        ];
    }

    public function currentSnapshot(): array
    {
        $userId = $this->tenant->userId();
        $user = $this->findUserById($userId);
        if ($user === null || $user['status'] !== 'active') {
            Json::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        if (($user['role_code'] ?? '') === self::ROLE_SYSTEM_ADMIN) {
            Json::error('FORBIDDEN', self::ROLE_MESSAGE, 403);
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
            Json::error('VALIDATION_ERROR', Password::RULE, 422);
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
        ActivityLog::recordAuth(
            $this->pdo,
            $user,
            ActivityLog::ACTION_PASSWORD_CHANGED,
            'Changed own password'
        );
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
            'role' => $user['role_code'] ?? null,
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

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function completeYouthLogin(array $user, bool $remember): array
    {
        $youthId = (int) ($user['youth_id'] ?? 0);
        if ($youthId < 1 || !$this->youthIsActive($youthId)) {
            $this->recordLoginFailure($user, 'youth account is not linked to an active youth record');
            Json::error('FORBIDDEN', 'This youth account is not linked to a youth record.', 403);
        }

        $this->establishYouthSession((int) $user['id'], $youthId);
        $this->replaceRememberToken((int) $user['id'], 0, self::ROLE_YOUTH, $remember, $youthId);
        $this->touchLastLogin((int) $user['id']);
        $user['last_login_at'] = date('Y-m-d H:i:s');
        $this->recordLoginActivity($user, null);

        return $this->currentYouthSnapshot();
    }

    private function completeSystemAdminLogin(array $user, bool $remember): array
    {
        $this->establishSystemAdminSession((int) $user['id']);
        $this->replaceRememberToken((int) $user['id'], 0, self::ROLE_SYSTEM_ADMIN, $remember);
        $this->touchLastLogin((int) $user['id']);
        $user['last_login_at'] = date('Y-m-d H:i:s');
        $this->recordLoginActivity($user, null);

        return [
            'user' => $this->publicUser($user),
            'organization' => null,
            'role' => self::ROLE_SYSTEM_ADMIN,
        ];
    }

    private function establishSystemAdminSession(int $userId): void
    {
        $this->startFreshSession();
        $_SESSION[Tenant::SESSION_USER_KEY] = $userId;
        unset($_SESSION[Tenant::SESSION_ORG_KEY], $_SESSION[Tenant::SESSION_YOUTH_KEY]);
        $_SESSION[Tenant::SESSION_ROLE_KEY] = self::ROLE_SYSTEM_ADMIN;
    }

    private function establishSession(int $userId, int $organizationId, string $roleCode): void
    {
        $this->startFreshSession();
        $_SESSION[Tenant::SESSION_USER_KEY] = $userId;
        $_SESSION[Tenant::SESSION_ORG_KEY] = $organizationId;
        unset($_SESSION[Tenant::SESSION_YOUTH_KEY]);
        $_SESSION[Tenant::SESSION_ROLE_KEY] = $roleCode;
    }

    private function establishYouthSession(int $userId, int $youthId): void
    {
        $this->startFreshSession();
        $_SESSION[Tenant::SESSION_USER_KEY] = $userId;
        unset($_SESSION[Tenant::SESSION_ORG_KEY]);
        $_SESSION[Tenant::SESSION_YOUTH_KEY] = $youthId;
        $_SESSION[Tenant::SESSION_ROLE_KEY] = self::ROLE_YOUTH;
    }

    private function startFreshSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_regenerate_id(true);
        $name = session_name();
        $current = session_id();
        $keep = [];
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) {
                continue;
            }
            if (!preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) {
                $keep[] = $header;
                continue;
            }
            if (strcasecmp($match[1], $name) === 0 && !hash_equals($current, $match[2])) {
                continue;
            }
            $keep[] = $header;
        }
        header_remove('Set-Cookie');
        foreach ($keep as $header) {
            header($header, false);
        }
    }

    private function replaceRememberToken(int $userId, int $organizationId, string $roleCode, bool $remember, int $youthId = 0): void
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
        $this->rememberTokens?->put($plain, $userId, $organizationId, $roleCode, $expires, $youthId);
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

    private function abandonUnauthenticatedSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!empty($_SESSION[Tenant::SESSION_USER_KEY])) {
            return;
        }
        $_SESSION = [];
        $params = SessionCookies::params($this->sessionConfig, 0);
        SessionCookies::write(session_name(), '', $params, time() - 3600);
        session_destroy();
    }

    private function youthIsActive(int $youthId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM youth WHERE id = :id AND archived = 0 LIMIT 1');
        $stmt->execute(['id' => $youthId]);
        return $stmt->fetch() !== false;
    }

    /**
     * @return array{youth: array<string, mixed>, organization: array<string, mixed>}|null
     */
    private function youthProfile(int $youthId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT y.*, o.name AS organization_name, o.barangay, o.municipality, o.province, o.status AS organization_status
             FROM youth y
             INNER JOIN organizations o ON o.id = y.organization_id
             WHERE y.id = :id AND y.archived = 0
             LIMIT 1'
        );
        $stmt->execute(['id' => $youthId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $decode = static function (mixed $value): array {
            if (is_array($value)) {
                return $value;
            }
            if (!is_string($value) || $value === '') {
                return [];
            }
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        };

        return [
            'youth' => [
                'id' => (int) $row['id'],
                'organizationId' => (int) $row['organization_id'],
                'code' => $row['code'],
                'firstName' => $row['first_name'],
                'middleName' => $row['middle_name'],
                'lastName' => $row['last_name'],
                'birthDate' => $row['birth_date'],
                'gender' => $row['gender'],
                'address' => $row['address'],
                'contact' => $row['contact'],
                'email' => $row['email'],
                'civilStatus' => $row['civil_status'],
                'educationStatus' => $row['education_status'],
                'education' => $row['education'],
                'school' => $row['school'],
                'course' => $row['course'],
                'yearLevel' => $row['year_level'],
                'strand' => $row['strand'],
                'studying' => (bool) $row['studying'],
                'employment' => $row['employment'],
                'occupation' => $row['occupation'],
                'guardianName' => $row['guardian_name'],
                'guardianEmployment' => $row['guardian_employment'],
                'guardianOccupation' => $row['guardian_occupation'],
                'familyIncome' => (float) $row['family_income'],
                'familyMembers' => (int) $row['family_members'],
                'skills' => $decode($row['skills']),
                'interests' => $decode($row['interests']),
                'preferredActivities' => $decode($row['preferred_activities']),
                'archived' => false,
            ],
            'organization' => [
                'id' => (int) $row['organization_id'],
                'name' => $row['organization_name'],
                'barangay' => $row['barangay'],
                'municipality' => $row['municipality'],
                'province' => $row['province'],
                'status' => $row['organization_status'],
            ],
        ];
    }
    /**
     * @param array<string, mixed> $user
     */
    private function recordLoginActivity(array $user, ?int $organizationId = null): void
    {
        // Organization: the authenticated SK membership when given, otherwise
        // resolved from the account (youth record organization; NULL for admins).
        ActivityLog::recordAuth(
            $this->pdo,
            $user,
            ActivityLog::ACTION_LOGIN,
            'Signed in (' . (string) ($user['role_code'] ?? 'user') . ')',
            $organizationId
        );
    }

    /**
     * Failed sign-in for an existing account. Only the reason is stored -
     * never the submitted password or email.
     *
     * @param array<string, mixed> $user
     */
    private function recordLoginFailure(array $user, string $reason, ?int $organizationId = null): void
    {
        ActivityLog::recordAuth(
            $this->pdo,
            $user,
            ActivityLog::ACTION_LOGIN_FAILED,
            'Failed sign-in: ' . $reason,
            $organizationId
        );
    }

}
