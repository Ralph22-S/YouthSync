<?php
declare(strict_types=1);

namespace YouthSync\Org;

use PDO;
use YouthSync\Auth\Password;

/**
 * Shared rules for the two ways an SK organization comes into existence:
 * a council registering itself, and an administrator entering one by hand.
 *
 * The two flows differ in what they produce - pending on the free plan versus
 * active on a trial - but the fields and the conflicts are identical, so they
 * are checked in one place rather than drifting apart.
 */
final class OrganizationValidator
{
    public const CONTACT_LENGTH = 11;

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function normalize(array $input): array
    {
        $text = static function (mixed $value, int $max): string {
            return mb_substr(trim((string) ($value ?? '')), 0, $max);
        };
        $pick = static function (array $row, string ...$keys): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $row)) {
                    return $row[$key];
                }
            }
            return '';
        };

        return [
            'barangay' => $text($pick($input, 'barangay'), 120),
            'municipality' => $text($pick($input, 'municipality'), 120),
            'province' => $text($pick($input, 'province'), 120) ?: 'Laguna',
            'chairperson' => $text($pick($input, 'chairperson'), 160),
            'email' => mb_strtolower($text($pick($input, 'email'), 190)),
            'contact' => preg_replace('/\D+/', '', $text($pick($input, 'contact'), 20)) ?? '',
            'ownerName' => $text($pick($input, 'ownerName', 'owner_name'), 160),
            'ownerEmail' => mb_strtolower($text($pick($input, 'ownerEmail', 'owner_email'), 190)),
            'ownerPassword' => (string) $pick($input, 'ownerPassword', 'owner_password', 'password'),
        ];
    }

    /**
     * @param array<string, string> $values
     * @param bool $requirePassword true for self-registration, where the council
     *                              chooses its own password; false for the admin
     *                              console, where a blank field issues one.
     * @return array<string, string>
     */
    public static function validate(array $values, bool $requirePassword): array
    {
        $errors = [];

        if ($values['barangay'] === '') {
            $errors['barangay'] = 'Barangay is required.';
        }
        if ($values['municipality'] === '') {
            $errors['municipality'] = 'Municipality is required.';
        }
        if ($values['province'] === '') {
            $errors['province'] = 'Province is required.';
        }
        if ($values['chairperson'] === '') {
            $errors['chairperson'] = 'The SK chairperson is required.';
        }

        if ($values['email'] === '') {
            $errors['email'] = 'An official email address is required.';
        } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        if ($values['contact'] === '') {
            $errors['contact'] = 'A contact number is required.';
        } elseif (strlen($values['contact']) !== self::CONTACT_LENGTH) {
            $errors['contact'] = 'Contact number must be exactly ' . self::CONTACT_LENGTH . ' digits.';
        }

        if ($values['ownerName'] === '') {
            $errors['ownerName'] = "The account holder's full name is required.";
        }

        if ($values['ownerEmail'] === '') {
            $errors['ownerEmail'] = 'An email address is required.';
        } elseif (!filter_var($values['ownerEmail'], FILTER_VALIDATE_EMAIL)) {
            $errors['ownerEmail'] = 'Enter a valid email address.';
        } elseif ($values['email'] !== '' && $values['ownerEmail'] === $values['email']) {
            $errors['ownerEmail'] = 'Use a personal address for the account, not the organization inbox.';
        }

        if ($requirePassword && $values['ownerPassword'] === '') {
            $errors['ownerPassword'] = 'A password is required.';
        } elseif ($values['ownerPassword'] !== '' && !Password::isValidNew($values['ownerPassword'])) {
            $errors['ownerPassword'] = 'Password must be at least 8 characters.';
        }

        return $errors;
    }

    /**
     * The conflicts neither flow may create.
     *
     * The barangay/municipality pair has no unique index behind it, so without
     * this check one barangay could quietly end up with two SK councils.
     *
     * @param array<string, string> $values
     * @return array{code:string, message:string}|null
     */
    public static function conflict(PDO $pdo, array $values): ?array
    {
        $stmt = $pdo->prepare('SELECT id FROM organizations WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $values['email']]);
        if ($stmt->fetch() !== false) {
            return ['code' => 'CONFLICT', 'message' => 'An organization with that official email already exists.'];
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM organizations WHERE LOWER(barangay) = :b AND LOWER(municipality) = :m LIMIT 1'
        );
        $stmt->execute([
            'b' => mb_strtolower($values['barangay']),
            'm' => mb_strtolower($values['municipality']),
        ]);
        if ($stmt->fetch() !== false) {
            return ['code' => 'CONFLICT', 'message' => 'That barangay and municipality already has an SK organization.'];
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $values['ownerEmail']]);
        if ($stmt->fetch() !== false) {
            return ['code' => 'CONFLICT', 'message' => 'That email already has an account. Log in instead.'];
        }

        return null;
    }

    /** "Juan Dela Cruz" splits to first "Juan", last "Dela Cruz"; one word is a last name. */
    public static function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [$fullName];
        $first = count($parts) > 1 ? (string) array_shift($parts) : '';
        return [$first, implode(' ', $parts)];
    }

    /** The display name every organization row follows. */
    public static function displayName(string $barangay, string $municipality): string
    {
        return 'SK ' . $barangay . ', ' . $municipality;
    }
}
