<?php
declare(strict_types=1);

namespace YouthSync\Applications;

use PDO;
use Throwable;
use YouthSync\Notifications\NotificationService;

/**
 * Later reminders for applications that are still waiting.
 * A decision notice sent today is not repeated here. Each application is reminded once.
 */
final class ApplicationReminder
{
    public function __construct(
        private PDO $pdo,
        private NotificationService $notifications,
    ) {
    }

    public function remind(): string
    {
        return 'youth=' . $this->remindYouth() . ' sk=' . $this->remindSk();
    }

    private function remindYouth(): int
    {
        $rows = $this->pdo->query(
            "SELECT a.id, a.organization_id, a.youth_id, y.contact, y.email,
                    COALESCE(NULLIF(p.name, ''), NULLIF(s.name, ''), 'your application') AS target_name
             FROM applications a
             INNER JOIN youth y ON y.id = a.youth_id AND y.organization_id = a.organization_id AND y.archived = 0
             LEFT JOIN programs p ON p.id = a.program_id AND p.organization_id = a.organization_id
             LEFT JOIN assistance_programs s ON s.id = a.assistance_id AND s.organization_id = a.organization_id
             WHERE a.status = 'needs_resubmission'
               AND DATE(a.updated_at) < CURDATE()"
        )->fetchAll();
        $sent = 0;
        foreach ($rows as $row) {
            $applicationId = (int) $row['id'];
            $organizationId = (int) $row['organization_id'];
            $youthId = (int) $row['youth_id'];
            $title = 'Reminder: resubmit your application';
            if ($this->alreadySent($organizationId, $applicationId, $title, $youthId)) {
                continue;
            }
            $name = (string) $row['target_name'];
            $message = 'Your application for ' . $name . ' still needs requirements replaced. Open it in YouthSync to resubmit.';
            try {
                $note = $this->notifications->notifyYouth($organizationId, $youthId, [
                    'type' => 'applications',
                    'title' => $title,
                    'message' => $message,
                    'link' => '/youth/applications/' . $applicationId,
                    'related' => $name,
                    'relatedEntityType' => 'application',
                    'relatedEntityId' => $applicationId,
                ]);
                $noteId = isset($note['id']) ? (int) $note['id'] : null;
                $this->notifications->deliverSmsOnce(
                    $organizationId,
                    'application.reminder',
                    $applicationId,
                    (string) ($row['contact'] ?? ''),
                    $message,
                    $noteId
                );
                $this->notifications->deliverEmailOnce(
                    $organizationId,
                    'application.reminder',
                    $applicationId,
                    (string) ($row['email'] ?? ''),
                    $title,
                    $message,
                    $name,
                    $noteId
                );
                $sent++;
            } catch (Throwable) {
                continue;
            }
        }
        return $sent;
    }

    private function remindSk(): int
    {
        $rows = $this->pdo->query(
            "SELECT a.id, a.organization_id,
                    COALESCE(NULLIF(p.name, ''), NULLIF(s.name, ''), 'an application') AS target_name,
                    TRIM(CONCAT(y.first_name, ' ', y.last_name)) AS youth_name
             FROM applications a
             INNER JOIN youth y ON y.id = a.youth_id AND y.organization_id = a.organization_id
             LEFT JOIN programs p ON p.id = a.program_id AND p.organization_id = a.organization_id
             LEFT JOIN assistance_programs s ON s.id = a.assistance_id AND s.organization_id = a.organization_id
             WHERE a.status = 'pending'
               AND DATE(a.submitted_at) < CURDATE()"
        )->fetchAll();
        $sent = 0;
        foreach ($rows as $row) {
            $applicationId = (int) $row['id'];
            $organizationId = (int) $row['organization_id'];
            $title = 'Reminder: application still pending';
            if ($this->alreadySent($organizationId, $applicationId, $title, null)) {
                continue;
            }
            $name = (string) $row['target_name'];
            $youthName = trim((string) $row['youth_name']);
            $message = ($youthName !== '' ? $youthName : 'A youth') . ' applied for ' . $name . ' and it is still waiting for review.';
            try {
                $this->notifications->notifySk($organizationId, [
                    'type' => 'applications',
                    'title' => $title,
                    'message' => $message,
                    'link' => '/sk/applications/' . $applicationId,
                    'related' => $name,
                    'relatedEntityType' => 'application',
                    'relatedEntityId' => $applicationId,
                ]);
                $sent++;
            } catch (Throwable) {
                continue;
            }
        }
        return $sent;
    }

    private function alreadySent(int $organizationId, int $applicationId, string $title, ?int $youthId): bool
    {
        $sql = "SELECT id FROM notifications
                WHERE organization_id = :org
                  AND related_entity_type = 'application'
                  AND related_entity_id = :application
                  AND title = :title";
        $params = [
            'org' => $organizationId,
            'application' => $applicationId,
            'title' => $title,
        ];
        if ($youthId === null) {
            $sql .= ' AND youth_id IS NULL';
        } else {
            $sql .= ' AND youth_id = :youth';
            $params['youth'] = $youthId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }
}
