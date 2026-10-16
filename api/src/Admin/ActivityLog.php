<?php
declare(strict_types=1);

namespace YouthSync\Admin;

use PDO;
use PDOException;
use Throwable;

/**
 * Writes the organization activity trail read by SK Officials (/sk/activity)
 * and the System Administrator (/admin/activity).
 *
 * Static on purpose: every SK service already holds a PDO, so recording an
 * event costs one call and no constructor change.
 *
 * Recording never throws. An audit trail that can break a youth record being
 * saved is worse than a missing line in the trail, so a failed write does not
 * stop the business action. It is not silent either: the failure is written
 * to api/logs/app.log (the same file the front controller uses) with the
 * action, category and database error class only - never the description,
 * actor name, or any submitted value. No placeholder audit row is invented.
 */
final class ActivityLog
{
    public const ACTION_LOGIN = 'login';
    public const ACTION_LOGIN_FAILED = 'login.failed';
    public const ACTION_LOGOUT = 'logout';
    public const ACTION_PASSWORD_CHANGED = 'password.changed';
    public const ACTION_PASSWORD_RESET_REQUESTED = 'password.reset_requested';
    public const ACTION_PASSWORD_RESET = 'password.reset';
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_PUBLISH = 'publish';
    public const ACTION_REPORT = 'report';
    public const ACTION_DELETE = 'delete';

    /** Category for sign-in, sign-out and password events. */
    public const CATEGORY_AUTH = 'auth';

    /**
     * @param array<string, mixed> $entry
     */
    public static function record(PDO $pdo, array $entry): void
    {
        $description = trim((string) ($entry['description'] ?? ''));
        $action = trim((string) ($entry['action'] ?? ''));
        $category = (string) ($entry['category'] ?? 'general');
        if ($description === '' || $action === '') {
            self::reportFailure('entry skipped: action or description is empty', $action, $category);
            return;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO activity_logs
                    (organization_id, user_id, youth_id, actor_name, actor_role,
                     action, category, description, entity_type, entity_id, ip)
                 VALUES
                    (:organization_id, :user_id, :youth_id, :actor_name, :actor_role,
                     :action, :category, :description, :entity_type, :entity_id, :ip)'
            );
            $stmt->execute([
                'organization_id' => self::id($entry['organizationId'] ?? null),
                'user_id' => self::id($entry['userId'] ?? null),
                'youth_id' => self::id($entry['youthId'] ?? null),
                'actor_name' => mb_substr(trim((string) ($entry['actorName'] ?? 'System')) ?: 'System', 0, 160),
                'actor_role' => mb_substr((string) ($entry['actorRole'] ?? 'system'), 0, 32),
                'action' => mb_substr($action, 0, 32),
                'category' => mb_substr($category, 0, 32),
                'description' => mb_substr($description, 0, 500),
                'entity_type' => mb_substr((string) ($entry['entityType'] ?? ''), 0, 64),
                'entity_id' => self::id($entry['entityId'] ?? null),
                'ip' => mb_substr(self::clientIp(), 0, 45),
            ]);
        } catch (Throwable $e) {
            self::reportFailure(self::describeError($e), $action, $category);
        }
    }

    /**
     * SK Official audit line. organizationId must be the authenticated session
     * organization already enforced by SkGuard / YouthGuard - never a
     * client-supplied id. The stored role is the actor's real role (YOUTH for
     * youth-portal actions routed through SK services, SK_OFFICIAL otherwise).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $extra
     */
    public static function recordSk(
        PDO $pdo,
        int $organizationId,
        array $user,
        string $action,
        string $category,
        string $description,
        array $extra = []
    ): void {
        if ($organizationId < 1 || $user === [] || $action === '' || $description === '') {
            return;
        }

        $role = strtoupper(trim((string) ($user['role_code'] ?? '')));
        self::record($pdo, array_merge(self::actor($user), $extra, [
            'organizationId' => $organizationId,
            'actorRole' => $role === 'YOUTH' ? 'YOUTH' : 'SK_OFFICIAL',
            'action' => $action,
            'category' => $category,
            'description' => $description,
        ]));
    }

    /**
     * Sign-in / sign-out / password event for a known account. The
     * organization is resolved from the database (SK membership, or the
     * youth record's organization; NULL for system administrators), never
     * from the request. Pass $organizationId when the caller already holds
     * the authenticated membership.
     *
     * @param array<string, mixed> $user users row with id, first_name, last_name, email, role_code, youth_id
     */
    public static function recordAuth(
        PDO $pdo,
        array $user,
        string $action,
        string $description,
        ?int $organizationId = null
    ): void {
        $userId = self::id($user['id'] ?? null);
        if ($organizationId === null && $userId !== null) {
            $organizationId = self::organizationForUser($pdo, $user);
        }
        $name = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
        self::record($pdo, [
            'organizationId' => $organizationId,
            'userId' => $userId,
            'youthId' => self::id($user['youth_id'] ?? null),
            'actorName' => $name !== '' ? $name : (string) ($user['email'] ?? 'User'),
            'actorRole' => strtoupper((string) ($user['role_code'] ?? 'system')),
            'action' => $action,
            'category' => self::CATEGORY_AUTH,
            'description' => $description,
            'entityType' => 'user',
            'entityId' => $userId,
        ]);
    }

    /**
     * Same as recordAuth() but loads the account by id first (used where the
     * caller only holds a user id, e.g. password reset tokens and logout).
     */
    public static function recordAuthForUserId(PDO $pdo, int $userId, string $action, string $description): void
    {
        if ($userId < 1) {
            return;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT u.id, u.email, u.first_name, u.last_name, u.youth_id, r.code AS role_code
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();
        } catch (Throwable $e) {
            self::reportFailure(self::describeError($e), $action, self::CATEGORY_AUTH);
            return;
        }
        if (!is_array($user)) {
            return;
        }
        self::recordAuth($pdo, $user, $action, $description);
    }

    /**
     * Failed sign-in for an email that matches no account. Neither the
     * submitted email nor the password is stored; user and organization stay
     * NULL so the row is visible to the System Administrator only.
     */
    public static function recordUnknownAccountLoginFailure(PDO $pdo): void
    {
        self::record($pdo, [
            'organizationId' => null,
            'userId' => null,
            'actorName' => 'Unknown account',
            'actorRole' => 'anonymous',
            'action' => self::ACTION_LOGIN_FAILED,
            'category' => self::CATEGORY_AUTH,
            'description' => 'Failed sign-in: no account matches the submitted email',
            'entityType' => 'user',
        ]);
    }

    /**
     * Builds the actor half of an entry from an authenticated user row, so
     * callers do not each re-derive the display name and role.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function actor(array $user): array
    {
        $name = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
        return [
            'userId' => $user['id'] ?? null,
            'actorName' => $name !== '' ? $name : (string) ($user['email'] ?? 'System'),
            'actorRole' => strtolower((string) ($user['role_code'] ?? 'system')),
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function organizationForUser(PDO $pdo, array $user): ?int
    {
        $role = strtoupper((string) ($user['role_code'] ?? ''));
        try {
            if ($role === 'SK_OFFICIAL') {
                $stmt = $pdo->prepare(
                    "SELECT organization_id FROM organization_users
                     WHERE user_id = :id AND status = 'active'
                     ORDER BY is_owner DESC, id ASC
                     LIMIT 1"
                );
                $stmt->execute(['id' => (int) $user['id']]);
                return self::id($stmt->fetchColumn());
            }
            if ($role === 'YOUTH' && self::id($user['youth_id'] ?? null) !== null) {
                $stmt = $pdo->prepare('SELECT organization_id FROM youth WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => (int) $user['youth_id']]);
                return self::id($stmt->fetchColumn());
            }
        } catch (Throwable) {
            // The audit row is still written, just without an organization.
        }
        return null;
    }

    private static function describeError(Throwable $e): string
    {
        $state = $e instanceof PDOException ? (string) ($e->errorInfo[0] ?? $e->getCode()) : (string) $e->getCode();
        // PDO messages name tables/columns/constraints, never bound values.
        $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';
        return sprintf('%s [%s] %s', $e::class, $state, mb_substr($message, 0, 240));
    }

    private static function reportFailure(string $reason, string $action, string $category): void
    {
        $line = sprintf(
            "[%s] ActivityLog write failed (action=%s, category=%s): %s\n",
            date('c'),
            preg_replace('/[^A-Za-z0-9_.\-]/', '', mb_substr($action, 0, 32)) ?: '-',
            preg_replace('/[^A-Za-z0-9_.\-]/', '', mb_substr($category, 0, 32)) ?: '-',
            $reason
        );
        $file = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
        if (@file_put_contents($file, $line, FILE_APPEND) === false) {
            error_log(rtrim($line));
        }
    }

    private static function id(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private static function clientIp(): string
    {
        $raw = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $raw !== '' ? $raw : 'cli';
    }
}
