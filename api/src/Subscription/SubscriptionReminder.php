<?php
declare(strict_types=1);

namespace YouthSync\Subscription;

use PDO;
use Throwable;
use YouthSync\Notifications\NotificationService;

/**
 * One notice when a trial or active subscription is inside the same 3-day
 * window the SK banner already uses. Expired, cancelled, and later dates
 * are left to the expiry job and the banner. A new expiry date can be
 * reminded again. This class does not change plan, status, or expires_at.
 */
final class SubscriptionReminder
{
    public const WINDOW_DAYS = 3;

    public function __construct(
        private PDO $pdo,
        private NotificationService $notifications,
    ) {
    }

    public function remind(): string
    {
        $rows = $this->pdo->query(
            "SELECT id, name, email, contact, sub_status, DATE(expires_at) AS expires_on,
                    DATEDIFF(DATE(expires_at), CURDATE()) AS days_left
             FROM organizations
             WHERE status = 'active'
               AND sub_status IN ('trial', 'active')
               AND expires_at IS NOT NULL
               AND DATE(expires_at) >= CURDATE()
               AND DATE(expires_at) <= DATE_ADD(CURDATE(), INTERVAL " . self::WINDOW_DAYS . " DAY)"
        )->fetchAll();
        $sent = 0;
        foreach ($rows as $row) {
            if ($this->remindOne($row)) {
                $sent++;
            }
        }
        return 'orgs=' . $sent;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function remindOne(array $row): bool
    {
        $organizationId = (int) $row['id'];
        $expiresOn = (string) $row['expires_on'];
        $title = 'Subscription expires soon';
        if ($organizationId < 1 || $expiresOn === '' || $this->alreadySent($organizationId, $title, $expiresOn)) {
            return false;
        }
        $days = (int) $row['days_left'];
        $kind = strtolower((string) $row['sub_status']) === 'trial' ? 'trial' : 'subscription';
        $when = $days <= 0 ? 'ends today' : 'expires in ' . $days . ' day' . ($days === 1 ? '' : 's');
        $name = trim((string) $row['name']);
        $message = 'Your ' . $kind . ($name !== '' ? ' for ' . $name : '') . ' ' . $when
            . ' (' . $expiresOn . '). Open Subscription in YouthSync to renew before Free plan limits apply.';
        $eventId = (int) str_replace('-', '', $expiresOn);
        try {
            $note = $this->notifications->notifySk($organizationId, [
                'type' => 'subscription',
                'title' => $title,
                'message' => $message,
                'link' => '/sk/subscription',
                'related' => $expiresOn,
                'relatedEntityType' => 'organization',
                'relatedEntityId' => $organizationId,
            ]);
            $noteId = isset($note['id']) ? (int) $note['id'] : null;
            $this->notifications->deliverSmsOnce(
                $organizationId,
                'subscription.expiring',
                $eventId,
                (string) ($row['contact'] ?? ''),
                $message,
                $noteId
            );
            $this->notifications->deliverEmailOnce(
                $organizationId,
                'subscription.expiring',
                $eventId,
                (string) ($row['email'] ?? ''),
                $title,
                $message,
                $name,
                $noteId
            );
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function alreadySent(int $organizationId, string $title, string $expiresOn): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM notifications
             WHERE organization_id = :org
               AND youth_id IS NULL
               AND type = 'subscription'
               AND title = :title
               AND related = :expires
             LIMIT 1"
        );
        $stmt->execute([
            'org' => $organizationId,
            'title' => $title,
            'expires' => $expiresOn,
        ]);
        return $stmt->fetchColumn() !== false;
    }
}
