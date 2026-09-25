<?php
declare(strict_types=1);

namespace YouthSync\Notifications;

use PDO;
use YouthSync\Http\Json;

final class NotificationService
{
    public const TYPES = [
        'scholarship',
        'assistance',
        'programs',
        'applications',
        'subscription',
        'system',
    ];

    public function __construct(
        private PDO $pdo,
        private ?SmsService $sms = null,
    ) {
    }

    /**
     * SK Official inbox: this organization, not youth-targeted,
     * and either org-wide (user_id NULL) or addressed to this SK user.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function list(int $organizationId, int $userId, array $query): array
    {
        $page = (int) ($query['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        $perPage = (int) ($query['perPage'] ?? $query['per_page'] ?? 50);
        if ($perPage < 1) {
            $perPage = 50;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        [$sqlWhere, $params] = $this->inboxWhere($organizationId, $userId);

        $unreadStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM notifications WHERE {$sqlWhere} AND is_read = 0"
        );
        $unreadStmt->execute($params);
        $unreadCount = (int) $unreadStmt->fetchColumn();

        $type = strtolower(trim((string) ($query['type'] ?? $query['category'] ?? '')));
        if ($type !== '') {
            $sqlWhere .= ' AND type = :type';
            $params['type'] = $type;
        }

        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            $sqlWhere .= ' AND (title LIKE :qTitle OR message LIKE :qMessage)';
            $params['qTitle'] = '%' . $q . '%';
            $params['qMessage'] = '%' . $q . '%';
        }

        $unreadOnly = $query['unreadOnly'] ?? $query['unread_only'] ?? null;
        if ($this->truthy($unreadOnly)) {
            $sqlWhere .= ' AND is_read = 0';
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $pages = max(1, (int) ceil(($total ?: 1) / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $listStmt = $this->pdo->prepare(
            "SELECT * FROM notifications WHERE {$sqlWhere} ORDER BY created_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $listStmt->execute($params);
        $items = [];
        foreach ($listStmt->fetchAll() as $row) {
            $items[] = $this->publicNotification($row);
        }

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'unreadCount' => $unreadCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $organizationId, int $userId, int $id): array
    {
        return $this->publicNotification($this->requireAccessible($organizationId, $userId, $id));
    }

    /**
     * @return array<string, mixed>
     */
    public function markRead(int $organizationId, int $userId, int $id): array
    {
        $this->requireAccessible($organizationId, $userId, $id);
        $this->pdo->prepare(
            'UPDATE notifications
             SET is_read = 1, read_at = NOW()
             WHERE id = :id AND organization_id = :org'
        )->execute([
            'id' => $id,
            'org' => $organizationId,
        ]);
        return $this->publicNotification($this->requireAccessible($organizationId, $userId, $id));
    }

    /**
     * @return array{updated: int}
     */
    public function markAllRead(int $organizationId, int $userId): array
    {
        [$sqlWhere, $params] = $this->inboxWhere($organizationId, $userId);
        $stmt = $this->pdo->prepare(
            "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE {$sqlWhere} AND is_read = 0"
        );
        $stmt->execute($params);
        return ['updated' => $stmt->rowCount()];
    }

    /**
     * @return array{deleted: true}
     */
    public function delete(int $organizationId, int $userId, int $id): array
    {
        $this->requireAccessible($organizationId, $userId, $id);
        $this->pdo->prepare(
            'DELETE FROM notifications WHERE id = :id AND organization_id = :org'
        )->execute([
            'id' => $id,
            'org' => $organizationId,
        ]);
        return ['deleted' => true];
    }

    /**
     * Organization-wide SK inbox notice (youth_id NULL, user_id NULL).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function notifySk(int $organizationId, array $input): array
    {
        return $this->create($organizationId, $input, null, null);
    }

    /**
     * Notice for a specific SK Official in this organization.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function notifySkUser(int $organizationId, int $userId, array $input): array
    {
        return $this->create($organizationId, $input, $userId, null);
    }

    /**
     * Youth inbox row (youth_id set). Hidden from SK Official list.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function notifyYouth(int $organizationId, int $youthId, array $input): array
    {
        return $this->create($organizationId, $input, null, $youthId);
    }

    /**
     * Internal create. organization_id / user_id / created_by in $input are ignored.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $organizationId, array $input, ?int $userId = null, ?int $youthId = null): array
    {
        $type = strtolower(trim((string) ($input['type'] ?? $input['category'] ?? 'system')));
        if (!in_array($type, self::TYPES, true)) {
            $type = 'system';
        }

        $title = $this->clip((string) ($input['title'] ?? ''), 190);
        $message = $this->clip((string) ($input['message'] ?? $input['body'] ?? ''), 2000);
        if ($title === '') {
            $title = 'Notification';
        }

        $recipientUserId = $userId;
        $recipientYouthId = $youthId;

        if ($recipientUserId !== null && $recipientUserId > 0) {
            if (!$this->userInOrganization($organizationId, $recipientUserId)) {
                Json::error('NOT_FOUND', 'This notification recipient does not exist in this organization.', 404);
            }
        } else {
            $recipientUserId = null;
        }

        if ($recipientYouthId !== null && $recipientYouthId > 0) {
            if (!$this->youthInOrganization($organizationId, $recipientYouthId)) {
                Json::error('NOT_FOUND', 'This youth record does not exist in this organization.', 404);
            }
        } else {
            $recipientYouthId = null;
        }

        $relatedEntityId = $input['relatedEntityId'] ?? $input['related_entity_id'] ?? null;
        $relatedEntityId = $relatedEntityId === null || $relatedEntityId === ''
            ? null
            : (int) $relatedEntityId;
        if ($relatedEntityId !== null && $relatedEntityId < 1) {
            $relatedEntityId = null;
        }

        $this->pdo->prepare(
            'INSERT INTO notifications (
                organization_id, user_id, youth_id, type, title, message,
                link, related, related_entity_type, related_entity_id, is_read
            ) VALUES (
                :organization_id, :user_id, :youth_id, :type, :title, :message,
                :link, :related, :related_entity_type, :related_entity_id, 0
            )'
        )->execute([
            'organization_id' => $organizationId,
            'user_id' => $recipientUserId,
            'youth_id' => $recipientYouthId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $this->clip((string) ($input['link'] ?? ''), 255),
            'related' => $this->clip((string) ($input['related'] ?? ''), 190),
            'related_entity_type' => $this->clip(
                (string) ($input['relatedEntityType'] ?? $input['related_entity_type'] ?? ''),
                64
            ),
            'related_entity_id' => $relatedEntityId,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('SELECT * FROM notifications WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $this->publicNotification(is_array($row) ? $row : []);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function inboxWhere(int $organizationId, int $userId): array
    {
        return [
            'organization_id = :org AND youth_id IS NULL AND (user_id IS NULL OR user_id = :userId)',
            ['org' => $organizationId, 'userId' => $userId],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireAccessible(int $organizationId, int $userId, int $id): array
    {
        if ($id < 1) {
            Json::error('NOT_FOUND', 'This notification does not exist in this organization.', 404);
        }
        [$sqlWhere, $params] = $this->inboxWhere($organizationId, $userId);
        $stmt = $this->pdo->prepare(
            "SELECT * FROM notifications WHERE id = :id AND {$sqlWhere} LIMIT 1"
        );
        $stmt->execute($params + ['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'This notification does not exist in this organization.', 404);
        }
        return $row;
    }

    private function userInOrganization(int $organizationId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM organization_users
             WHERE organization_id = :org AND user_id = :userId
             LIMIT 1'
        );
        $stmt->execute(['org' => $organizationId, 'userId' => $userId]);
        return $stmt->fetch() !== false;
    }

    private function youthInOrganization(int $organizationId, int $youthId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM youth WHERE id = :id AND organization_id = :org LIMIT 1'
        );
        $stmt->execute(['id' => $youthId, 'org' => $organizationId]);
        return $stmt->fetch() !== false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicNotification(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'type' => $row['type'] ?? 'system',
            'category' => $row['type'] ?? 'system',
            'title' => $row['title'] ?? '',
            'message' => $row['message'] ?? '',
            'body' => $row['message'] ?? '',
            'link' => $row['link'] ?? '',
            'related' => $row['related'] ?? '',
            'relatedEntityType' => $row['related_entity_type'] ?? '',
            'relatedEntityId' => isset($row['related_entity_id']) && $row['related_entity_id'] !== null
                ? (int) $row['related_entity_id']
                : null,
            'isRead' => (bool) ($row['is_read'] ?? 0),
            'readAt' => $row['read_at'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'userId' => isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'youthId' => isset($row['youth_id']) && $row['youth_id'] !== null ? (int) $row['youth_id'] : null,
        ];
    }

    private function clip(string $value, int $max): string
    {
        $value = trim($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * One SMS per organization + event + recipient. A second call does not send again.
     *
     * @return array<string, mixed>
     */
    public function deliverSmsOnce(
        int $organizationId,
        string $eventCode,
        int $eventId,
        string $phone,
        string $message,
        ?int $notificationId = null
    ): array {
        $normalized = SmsService::normalizePhone($phone);
        if ($normalized === null || $eventId < 1 || trim($eventCode) === '') {
            return [
                'ok' => false,
                'status' => 'skipped',
                'error' => 'Recipient phone is missing or invalid.',
                'duplicate' => false,
            ];
        }
        if ($this->sms === null) {
            return [
                'ok' => false,
                'status' => 'skipped',
                'error' => 'SMS delivery is not enabled.',
                'duplicate' => false,
            ];
        }

        $existing = $this->findSmsDelivery($organizationId, $eventCode, $eventId, $normalized);
        if ($existing !== null) {
            return [
                'ok' => ($existing['status'] ?? '') === 'sent',
                'status' => (string) ($existing['status'] ?? 'failed'),
                'error' => $existing['error_message'] ?? null,
                'duplicate' => true,
                'id' => (int) $existing['id'],
            ];
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO sms_deliveries (
                    organization_id, notification_id, event_code, event_id, recipient, provider, status
                 ) VALUES (
                    :organization_id, :notification_id, :event_code, :event_id, :recipient, :provider, :status
                 )'
            )->execute([
                'organization_id' => $organizationId,
                'notification_id' => $notificationId,
                'event_code' => $eventCode,
                'event_id' => $eventId,
                'recipient' => $normalized,
                'provider' => 'semaphore',
                'status' => 'pending',
            ]);
        } catch (\PDOException $e) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($driverCode === 1062) {
                $row = $this->findSmsDelivery($organizationId, $eventCode, $eventId, $normalized);
                return [
                    'ok' => ($row['status'] ?? '') === 'sent',
                    'status' => (string) ($row['status'] ?? 'failed'),
                    'duplicate' => true,
                    'id' => (int) ($row['id'] ?? 0),
                ];
            }
            return [
                'ok' => false,
                'status' => 'failed',
                'error' => 'SMS delivery could not be recorded.',
                'duplicate' => false,
            ];
        }

        $id = (int) $this->pdo->lastInsertId();
        $result = $this->sms->send($normalized, $message);
        $sent = ($result['ok'] ?? false) === true;
        $this->pdo->prepare(
            'UPDATE sms_deliveries
             SET status = :status, provider_reference = :ref, error_message = :error, sent_at = :sent_at
             WHERE id = :id'
        )->execute([
            'status' => $sent ? 'sent' : 'failed',
            'ref' => $result['providerReference'] ?? null,
            'error' => $sent ? null : (string) ($result['error'] ?? 'SMS provider is unavailable.'),
            'sent_at' => $sent ? date('Y-m-d H:i:s') : null,
            'id' => $id,
        ]);
        return [
            'ok' => $sent,
            'status' => $sent ? 'sent' : 'failed',
            'error' => $sent ? null : (string) ($result['error'] ?? 'SMS provider is unavailable.'),
            'duplicate' => false,
            'id' => $id,
            'providerReference' => $result['providerReference'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSmsDelivery(int $organizationId, string $eventCode, int $eventId, string $recipient): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sms_deliveries
             WHERE organization_id = :org AND event_code = :code AND event_id = :eventId AND recipient = :recipient
             LIMIT 1'
        );
        $stmt->execute([
            'org' => $organizationId,
            'code' => $eventCode,
            'eventId' => $eventId,
            'recipient' => $recipient,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }
}
