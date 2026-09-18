<?php
declare(strict_types=1);

namespace YouthSync\Programs;

final class ProgramValidator
{
    public const KINDS = ['program', 'event'];
    public const STATUSES = ['draft', 'published', 'ongoing', 'completed', 'archived'];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, ?string $forceKind = null): array
    {
        unset($input['organization_id'], $input['organizationId'], $input['orgId'], $input['org_id']);

        $str = static function (string $key, int $max) use ($input): string {
            $value = self::pick($input, $key, '');
            if ($value === null) {
                $value = '';
            } elseif (!is_string($value)) {
                $value = (string) $value;
            }
            return mb_substr(trim($value), 0, $max);
        };

        $kind = $forceKind ?? $str('kind', 16);
        if ($kind === '') {
            $kind = 'program';
        }

        $starts = self::normalizeTime($str('startsAt', 8), '08:00');
        $ends = self::normalizeTime($str('endsAt', 8), '15:00');

        $max = self::pick($input, 'maxParticipants', null);
        $maxParticipants = null;
        if ($max !== null && $max !== '') {
            $maxParticipants = (int) $max;
        }

        $minAge = self::optionalAge(self::pick($input, 'minAge', null));
        $maxAge = self::optionalAge(self::pick($input, 'maxAge', null));

        return [
            'kind' => $kind,
            'name' => $str('name', 160),
            'category' => $str('category', 80),
            'status' => $str('status', 32) ?: 'draft',
            'scheduledOn' => $str('scheduledOn', 10),
            'startsAt' => $starts,
            'endsAt' => $ends,
            'location' => $str('location', 190),
            'description' => mb_substr(trim((string) (self::pick($input, 'description', '') ?? '')), 0, 5000),
            'maxParticipants' => $maxParticipants,
            'registrationDeadline' => $str('registrationDeadline', 10),
            'tagInterests' => self::stringList(self::pick($input, 'tagInterests', [])),
            'tagSkills' => self::stringList(self::pick($input, 'tagSkills', [])),
            'tagActivities' => self::stringList(self::pick($input, 'tagActivities', [])),
            'requiresStudying' => self::bool(self::pick($input, 'requiresStudying', false)),
            'minAge' => $minAge,
            'maxAge' => $maxAge,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function validate(array $values): array
    {
        $errors = [];
        $isEvent = ($values['kind'] ?? '') === 'event';

        if (!in_array($values['kind'], self::KINDS, true)) {
            $errors['kind'] = 'Type must be program or event.';
        }
        if ($values['name'] === '') {
            $errors['name'] = $isEvent ? 'Event name is required.' : 'Program name is required.';
        }
        if ($values['scheduledOn'] === '') {
            $errors['scheduledOn'] = $isEvent ? 'Event date is required.' : 'Program date is required.';
        } elseif (!self::isDate($values['scheduledOn'])) {
            $errors['scheduledOn'] = 'Date must be YYYY-MM-DD.';
        }
        if (!in_array($values['status'], self::STATUSES, true)) {
            $errors['status'] = 'Status is not valid.';
        }
        if (!self::isTime($values['startsAt'])) {
            $errors['startsAt'] = 'Start time must be HH:MM.';
        }
        if (!self::isTime($values['endsAt'])) {
            $errors['endsAt'] = 'End time must be HH:MM.';
        } elseif (self::isTime($values['startsAt']) && $values['endsAt'] <= $values['startsAt']) {
            $errors['endsAt'] = 'End time must be after the start time.';
        }
        if ($values['registrationDeadline'] !== '') {
            if (!self::isDate($values['registrationDeadline'])) {
                $errors['registrationDeadline'] = 'The deadline must be YYYY-MM-DD.';
            } elseif (self::isDate($values['scheduledOn']) && $values['registrationDeadline'] > $values['scheduledOn']) {
                $errors['registrationDeadline'] = 'The deadline must be on or before the activity date.';
            }
        }
        if ($values['maxParticipants'] !== null && $values['maxParticipants'] < 1) {
            $errors['maxParticipants'] = 'Maximum participants must be at least 1.';
        }
        if ($values['minAge'] !== null && ($values['minAge'] < 10 || $values['minAge'] > 35)) {
            $errors['minAge'] = 'Minimum age must be between 10 and 35.';
        }
        if ($values['maxAge'] !== null && ($values['maxAge'] < 10 || $values['maxAge'] > 35)) {
            $errors['maxAge'] = 'Maximum age must be between 10 and 35.';
        }
        if ($values['minAge'] !== null && $values['maxAge'] !== null && $values['minAge'] > $values['maxAge']) {
            $errors['maxAge'] = 'Maximum age must be greater than or equal to minimum age.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function pick(array $row, string $camel, mixed $default): mixed
    {
        if (array_key_exists($camel, $row)) {
            return $row[$camel];
        }
        $snake = strtolower(preg_replace('/[A-Z]/', '_$0', $camel) ?? $camel);
        if (array_key_exists($snake, $row)) {
            return $row[$snake];
        }
        return $default;
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = mb_substr($item, 0, 80);
            }
        }
        return array_values(array_unique($out));
    }

    private static function optionalAge(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private static function normalizeTime(string $value, string $default): string
    {
        if ($value === '') {
            return $default;
        }
        if (preg_match('/^(\d{2}:\d{2})(:\d{2})?$/', $value, $m)) {
            return $m[1];
        }
        return $value;
    }

    private static function isDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }

    private static function isTime(string $value): bool
    {
        return (bool) preg_match('/^\d{2}:\d{2}$/', $value);
    }
}
