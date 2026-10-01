<?php
declare(strict_types=1);

namespace YouthSync\Admin;

use PDO;
use Throwable;

/**
 * Writes the organization activity trail the System Administrator reads.
 *
 * Static on purpose: every SK service already holds a PDO, so recording an
 * event costs one call and no constructor change.
 *
 * Recording never throws. An audit trail that can break a youth record being
 * saved is worse than a missing line in the trail, so a failed write is
 * swallowed — including on a deployment where import_admin.php has not run yet.
 */
final class ActivityLog
{
    public const ACTION_LOGIN = 'login';
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_PUBLISH = 'publish';
    public const ACTION_REPORT = 'report';
    public const ACTION_DELETE = 'delete';

    /**
     * @param array<string, mixed> $entry
     */
    public static function record(PDO $pdo, array $entry): void
    {
        $description = trim((string) ($entry['description'] ?? ''));
        $action = trim((string) ($entry['action'] ?? ''));
        if ($description === '' || $action === '') {
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
                'category' => mb_substr((string) ($entry['category'] ?? 'general'), 0, 32),
                'description' => mb_substr($description, 0, 500),
                'entity_type' => mb_substr((string) ($entry['entityType'] ?? ''), 0, 64),
                'entity_id' => self::id($entry['entityId'] ?? null),
                'ip' => mb_substr(self::clientIp(), 0, 45),
            ]);
        } catch (Throwable) {
            // Intentionally ignored — see the class comment.
        }
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

    private static function id(mixed $value): ?int
    {
        if ($value === null || $value === '') {
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
