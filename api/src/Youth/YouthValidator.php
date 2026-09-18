<?php
declare(strict_types=1);

namespace YouthSync\Youth;

final class YouthValidator
{
    public const GENDERS = ['Male', 'Female', 'Prefer not to say'];
    public const CIVIL = ['Single', 'Married', 'Widowed', 'Separated'];
    public const EDUCATION_STATUSES = ['Currently Studying', 'Not Currently Studying'];
    public const EDUCATION_LEVELS = [
        'Elementary', 'Junior High School', 'Senior High School',
        'College', 'Vocational/Technical', 'Graduate/Finished School',
    ];
    public const EMPLOYMENT = ['Student', 'Employed', 'Unemployed', 'Self-employed'];
    public const GUARDIAN_EMPLOYMENT = ['Employed', 'Unemployed', 'Self-employed'];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
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
        $educationStatus = $str('educationStatus', 64);
        $studying = self::pick($input, 'studying', null);
        if ($studying === null || $studying === '') {
            $studying = $educationStatus === 'Currently Studying';
        } else {
            $studying = self::bool($studying);
        }

        return [
            'firstName' => $str('firstName', 80),
            'middleName' => $str('middleName', 80),
            'lastName' => $str('lastName', 80),
            'birthDate' => $str('birthDate', 10),
            'gender' => $str('gender', 32),
            'address' => $str('address', 255),
            'contact' => preg_replace('/\D+/', '', $str('contact', 20)) ?? '',
            'email' => mb_strtolower($str('email', 190)),
            'civilStatus' => $str('civilStatus', 32) ?: 'Single',
            'educationStatus' => $educationStatus,
            'education' => $str('education', 64),
            'school' => $str('school', 160),
            'course' => $str('course', 160),
            'yearLevel' => $str('yearLevel', 64),
            'strand' => $str('strand', 64),
            'studying' => $studying,
            'employment' => $str('employment', 32),
            'occupation' => $str('occupation', 120),
            'guardianName' => $str('guardianName', 160),
            'guardianEmployment' => $str('guardianEmployment', 32),
            'guardianOccupation' => $str('guardianOccupation', 120),
            'familyIncome' => self::number(self::pick($input, 'familyIncome', 0)),
            'familyMembers' => (int) self::number(self::pick($input, 'familyMembers', 0)),
            'skills' => self::stringList(self::pick($input, 'skills', [])),
            'interests' => self::stringList(self::pick($input, 'interests', [])),
            'preferredActivities' => self::stringList(self::pick($input, 'preferredActivities', [])),
            'previousScholarship' => self::bool(self::pick($input, 'previousScholarship', false)),
            'previousAssistance' => self::bool(self::pick($input, 'previousAssistance', false)),
            'previousParticipation' => self::bool(self::pick($input, 'previousParticipation', false)),
            'createAccount' => self::bool(self::pick($input, 'createAccount', false)),
            'accountEmail' => mb_strtolower(trim((string) (self::pick($input, 'accountEmail', self::pick($input, 'account_email', '')) ?? ''))),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function validate(array $values, bool $requireContact = true): array
    {
        $errors = [];

        if ($values['firstName'] === '') {
            $errors['firstName'] = 'First name is required.';
        }
        if ($values['lastName'] === '') {
            $errors['lastName'] = 'Last name is required.';
        }
        if ($values['birthDate'] === '') {
            $errors['birthDate'] = 'Date of birth is required.';
        } elseif (!self::isDate($values['birthDate'])) {
            $errors['birthDate'] = 'Birth date is not a valid date (use YYYY-MM-DD).';
        } elseif ($values['birthDate'] > date('Y-m-d')) {
            $errors['birthDate'] = 'Date of birth cannot be in the future.';
        }
        if ($values['address'] === '') {
            $errors['address'] = 'Address is required.';
        }

        if ($requireContact || $values['contact'] !== '') {
            if ($values['contact'] === '') {
                $errors['contact'] = 'Contact number is required.';
            } elseif (!ctype_digit($values['contact'])) {
                $errors['contact'] = 'Contact number must contain numbers only.';
            } elseif (strlen($values['contact']) !== 11) {
                $errors['contact'] = 'Contact number must be exactly 11 digits.';
            }
        }

        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        if ($values['interests'] === []) {
            $errors['interests'] = 'At least one interest is required.';
        }

        if ($values['gender'] !== '' && !in_array($values['gender'], self::GENDERS, true)) {
            $errors['gender'] = 'Gender is not valid.';
        }
        if ($values['civilStatus'] !== '' && !in_array($values['civilStatus'], self::CIVIL, true)) {
            $errors['civilStatus'] = 'Civil status is not valid.';
        }
        if ($values['educationStatus'] !== '' && !in_array($values['educationStatus'], self::EDUCATION_STATUSES, true)) {
            $errors['educationStatus'] = 'Education status is not valid.';
        }
        if ($values['education'] !== '' && !in_array($values['education'], self::EDUCATION_LEVELS, true)) {
            $errors['education'] = 'Education level is not valid.';
        }
        if ($values['employment'] !== '' && !in_array($values['employment'], self::EMPLOYMENT, true)) {
            $errors['employment'] = 'Employment status is not valid.';
        }
        if ($values['guardianEmployment'] !== '' && !in_array($values['guardianEmployment'], self::GUARDIAN_EMPLOYMENT, true)) {
            $errors['guardianEmployment'] = 'Guardian employment is not valid.';
        }
        if ($values['familyIncome'] < 0) {
            $errors['familyIncome'] = 'Family income cannot be negative.';
        }
        if ($values['familyMembers'] < 0) {
            $errors['familyMembers'] = 'Family members cannot be negative.';
        }

        if ($values['createAccount']) {
            if ($values['accountEmail'] === '') {
                $errors['accountEmail'] = 'Email address is required.';
            } elseif (!filter_var($values['accountEmail'], FILTER_VALIDATE_EMAIL)) {
                $errors['accountEmail'] = 'Enter a valid email address.';
            }
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
        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function number(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return 0.0;
        }
        return (float) $raw;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            if (is_string($value) && trim($value) !== '') {
                $value = array_map('trim', explode(',', $value));
            } else {
                return [];
            }
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

    private static function isDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }
}
