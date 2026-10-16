<?php
declare(strict_types=1);

namespace YouthSync\Identity;

use PDO;

/**
 * Youth profile emails are unique when filled in.
 * Contact numbers are unique across youth records and SK organizations.
 * Blank emails and blank numbers are not compared.
 */
final class ContactIdentity
{
    public const EMAIL_MESSAGE = 'That email is already used by another youth record.';
    public const CONTACT_MESSAGE = 'That contact number is already used by a youth record or an SK organization.';

    /**
     * @return array<string, string>
     */
    public static function youthErrors(PDO $pdo, string $email, string $contact, ?int $exceptYouthId = null): array
    {
        $errors = [];
        $email = strtolower(trim($email));
        $contact = preg_replace('/\D+/', '', $contact) ?? '';
        $exceptYouthId = $exceptYouthId ?? 0;

        if ($email !== '') {
            $stmt = $pdo->prepare(
                'SELECT id FROM youth
                 WHERE email IS NOT NULL AND email <> \'\' AND LOWER(email) = :email AND id <> :id
                 LIMIT 1'
            );
            $stmt->execute(['email' => $email, 'id' => $exceptYouthId]);
            if ($stmt->fetch() !== false) {
                $errors['email'] = self::EMAIL_MESSAGE;
            }
        }

        if ($contact !== '' && self::contactTaken($pdo, $contact, $exceptYouthId, 0)) {
            $errors['contact'] = self::CONTACT_MESSAGE;
        }

        return $errors;
    }

    public static function organizationContactTaken(PDO $pdo, string $contact, ?int $exceptOrganizationId = null): bool
    {
        $contact = preg_replace('/\D+/', '', $contact) ?? '';
        if ($contact === '') {
            return false;
        }
        return self::contactTaken($pdo, $contact, 0, $exceptOrganizationId ?? 0);
    }

    private static function contactTaken(PDO $pdo, string $contact, int $exceptYouthId, int $exceptOrganizationId): bool
    {
        $youth = $pdo->prepare(
            'SELECT id FROM youth WHERE contact <> \'\' AND contact = :contact AND id <> :id LIMIT 1'
        );
        $youth->execute(['contact' => $contact, 'id' => $exceptYouthId]);
        if ($youth->fetch() !== false) {
            return true;
        }

        $org = $pdo->prepare(
            'SELECT id FROM organizations WHERE contact <> \'\' AND contact = :contact AND id <> :id LIMIT 1'
        );
        $org->execute(['contact' => $contact, 'id' => $exceptOrganizationId]);
        return $org->fetch() !== false;
    }
}
