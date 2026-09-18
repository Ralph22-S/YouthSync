<?php
declare(strict_types=1);

namespace YouthSync\Users;

final class UserValidator
{
    public const ALLOWED_ROLES = ['SK_OFFICIAL'];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeCreate(array $input): array
    {
        unset(
            $input['organization_id'],
            $input['organizationId'],
            $input['orgId'],
            $input['org_id'],
            $input['role_id'],
            $input['roleId'],
            $input['is_owner'],
            $input['isOwner'],
            $input['created_by']
        );

        $first = self::str($input, 'firstName', 80);
        $last = self::str($input, 'lastName', 80);
        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/', self::str($input, 'name', 160), 2) ?: [];
            $first = $parts[0] ?? '';
            $last = $parts[1] ?? '';
        }

        return [
            'firstName' => $first,
            'lastName' => $last,
            'email' => strtolower(self::str($input, 'email', 190)),
            'role' => self::normalizeRole($input),
            'password' => (string) ($input['password'] ?? ''),
            'status' => 'active',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeUpdate(array $input): array
    {
        unset(
            $input['organization_id'],
            $input['organizationId'],
            $input['orgId'],
            $input['org_id'],
            $input['role_id'],
            $input['roleId'],
            $input['is_owner'],
            $input['isOwner'],
            $input['created_by']
        );

        $out = [];
        if (
            array_key_exists('firstName', $input)
            || array_key_exists('first_name', $input)
            || array_key_exists('lastName', $input)
            || array_key_exists('last_name', $input)
            || array_key_exists('name', $input)
        ) {
            $first = self::str($input, 'firstName', 80);
            $last = self::str($input, 'lastName', 80);
            if ($first === '' && $last === '' && isset($input['name'])) {
                $parts = preg_split('/\s+/', self::str($input, 'name', 160), 2) ?: [];
                $first = $parts[0] ?? '';
                $last = $parts[1] ?? '';
            }
            $out['firstName'] = $first;
            $out['lastName'] = $last;
        }
        if (isset($input['email'])) {
            $out['email'] = strtolower(self::str($input, 'email', 190));
        }
        if (array_key_exists('role', $input) || array_key_exists('roleCode', $input)) {
            $out['role'] = self::normalizeRole($input);
        }
        if (isset($input['password']) && is_string($input['password']) && $input['password'] !== '') {
            $out['password'] = $input['password'];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function validateCreate(array $values): array
    {
        $errors = self::validateNameEmail($values);
        if (!in_array($values['role'], self::ALLOWED_ROLES, true)) {
            $errors['role'] = 'That role is not allowed for SK organization users.';
        }
        if ($values['password'] !== '' && strlen($values['password']) < 8) {
            $errors['password'] = 'Use a password of at least 8 characters.';
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function validateUpdate(array $values): array
    {
        $errors = [];
        if (isset($values['firstName']) || isset($values['lastName'])) {
            $errors = array_merge($errors, self::validateNameEmail([
                'firstName' => $values['firstName'] ?? 'x',
                'lastName' => $values['lastName'] ?? 'x',
                'email' => $values['email'] ?? 'ok@example.com',
            ]));
            if (!isset($values['email'])) {
                unset($errors['email']);
            }
        } elseif (isset($values['email'])) {
            if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            }
        }
        if (isset($values['role']) && !in_array($values['role'], self::ALLOWED_ROLES, true)) {
            $errors['role'] = 'That role is not allowed for SK organization users.';
        }
        if (isset($values['password']) && strlen((string) $values['password']) < 8) {
            $errors['password'] = 'Use a password of at least 8 characters.';
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private static function validateNameEmail(array $values): array
    {
        $errors = [];
        if (($values['firstName'] ?? '') === '') {
            $errors['firstName'] = 'First name is required.';
        }
        if (($values['lastName'] ?? '') === '') {
            $errors['lastName'] = 'Last name is required.';
        }
        if (($values['email'] ?? '') === '' || !filter_var((string) $values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function normalizeRole(array $input): string
    {
        $raw = $input['role'] ?? $input['roleCode'] ?? 'SK_OFFICIAL';
        $role = strtoupper(str_replace(['-', ' '], '_', trim((string) $raw)));
        if ($role === 'SKOFFICIAL') {
            $role = 'SK_OFFICIAL';
        }
        return $role;
    }

    private static function str(array $input, string $camel, int $max): string
    {
        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $camel) ?? $camel);
        $value = $input[$camel] ?? $input[$snake] ?? '';
        if (!is_string($value)) {
            $value = (string) $value;
        }
        $value = trim($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }
}
