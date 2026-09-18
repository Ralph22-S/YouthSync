<?php
declare(strict_types=1);

namespace YouthSync\Assistance;

final class AssistanceValidator
{
    public const CATEGORIES = ['scholarship', 'financial', 'other'];
    public const STATUSES = ['draft', 'open', 'full', 'closed', 'archived'];
    public const ACTIVE_STATUSES = ['draft', 'open', 'full'];
    public const BENEFICIARY_STATUSES = ['applied', 'approved', 'released', 'rejected'];
    public const ACCEPTS = ['image/*,application/pdf', 'image/*', 'application/pdf'];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeProgram(array $input): array
    {
        unset($input['organization_id'], $input['organizationId'], $input['orgId'], $input['org_id']);

        $slotsRaw = self::pick($input, 'slots', null);
        $slots = null;
        if ($slotsRaw !== null && $slotsRaw !== '') {
            $slots = (int) $slotsRaw;
        }

        $amountRaw = self::pick($input, 'amount', null);
        $amount = null;
        if ($amountRaw !== null && $amountRaw !== '') {
            $amount = (float) $amountRaw;
        }

        $typeRaw = self::pick($input, 'typeId', null);
        $typeId = null;
        if ($typeRaw !== null && $typeRaw !== '') {
            $typeId = (int) $typeRaw;
            if ($typeId < 1) {
                $typeId = null;
            }
        }

        return [
            'name' => self::str($input, 'name', 160),
            'category' => self::str($input, 'category', 32) ?: 'scholarship',
            'typeId' => $typeId,
            'status' => self::str($input, 'status', 32) ?: 'draft',
            'slots' => $slots,
            'amount' => $amount,
            'description' => mb_substr(trim((string) (self::pick($input, 'description', '') ?? '')), 0, 5000),
            'requirements' => mb_substr(trim((string) (self::pick($input, 'requirements', '') ?? '')), 0, 5000),
            'deadline' => self::str($input, 'deadline', 10),
            'requiresStudying' => self::bool(self::pick($input, 'requiresStudying', false)),
            'tagInterests' => self::stringList(self::pick($input, 'tagInterests', [])),
            'tagSkills' => self::stringList(self::pick($input, 'tagSkills', [])),
            'tagActivities' => self::stringList(self::pick($input, 'tagActivities', [])),
            'minAge' => self::optionalAge(self::pick($input, 'minAge', null)),
            'maxAge' => self::optionalAge(self::pick($input, 'maxAge', null)),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function validateProgram(array $values): array
    {
        $errors = [];
        if ($values['name'] === '') {
            $errors['name'] = 'Program name is required.';
        }
        if (!in_array($values['category'], self::CATEGORIES, true)) {
            $errors['category'] = 'Category must be scholarship, financial, or other.';
        }
        if (!in_array($values['status'], self::STATUSES, true)) {
            $errors['status'] = 'Status is not valid.';
        }
        if ($values['deadline'] !== '' && !self::isDate($values['deadline'])) {
            $errors['deadline'] = 'Deadline must be YYYY-MM-DD.';
        }
        if ($values['slots'] !== null && $values['slots'] < 1) {
            $errors['slots'] = 'Available slots must be at least 1.';
        }
        if ($values['amount'] !== null && $values['amount'] < 0) {
            $errors['amount'] = 'Amount cannot be negative.';
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
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeType(array $input): array
    {
        unset($input['organization_id'], $input['organizationId'], $input['orgId'], $input['org_id'], $input['system'], $input['is_system']);
        $reqs = self::pick($input, 'requirements', []);
        $normalizedReqs = [];
        if (is_array($reqs)) {
            foreach ($reqs as $row) {
                $item = self::normalizeRequirementRow($row);
                if ($item['name'] !== '') {
                    $normalizedReqs[] = $item;
                }
            }
        }
        return [
            'name' => self::str($input, 'name', 160),
            'category' => self::str($input, 'category', 32) ?: 'other',
            'requirements' => $normalizedReqs,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function validateType(array $values): array
    {
        $errors = [];
        if ($values['name'] === '') {
            $errors['name'] = 'Type name is required.';
        }
        if (!in_array($values['category'], self::CATEGORIES, true)) {
            $errors['category'] = 'Category must be scholarship, financial, or other.';
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeRequirement(array $input): array
    {
        unset($input['organization_id'], $input['organizationId'], $input['orgId'], $input['org_id']);
        return self::normalizeRequirementRow($input);
    }

    /**
     * @param mixed $row
     * @return array{name:string,description:string,required:bool,accepts:string}
     */
    public static function normalizeRequirementRow(mixed $row): array
    {
        if (is_array($row) && array_is_list($row)) {
            return [
                'name' => mb_substr(trim((string) ($row[0] ?? '')), 0, 160),
                'description' => mb_substr(trim((string) ($row[1] ?? '')), 0, 500),
                'required' => self::bool($row[2] ?? true),
                'accepts' => self::normalizeAccepts($row[3] ?? 'image/*,application/pdf'),
            ];
        }
        if (!is_array($row)) {
            $row = [];
        }
        return [
            'name' => mb_substr(trim((string) (self::pick($row, 'name', '') ?? '')), 0, 160),
            'description' => mb_substr(trim((string) (self::pick($row, 'description', '') ?? '')), 0, 500),
            'required' => self::bool(self::pick($row, 'required', true)),
            'accepts' => self::normalizeAccepts(self::pick($row, 'accepts', 'image/*,application/pdf')),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function validateRequirement(array $values): array
    {
        $errors = [];
        if ($values['name'] === '') {
            $errors['name'] = 'Requirement name is required.';
        }
        if (!in_array($values['accepts'], self::ACCEPTS, true)) {
            $errors['accepts'] = 'Accepted file type is not valid.';
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeBeneficiary(array $input): array
    {
        unset($input['organization_id'], $input['organizationId'], $input['orgId'], $input['org_id']);
        $youthId = (int) (self::pick($input, 'youthId', 0) ?? 0);
        $status = self::str($input, 'status', 32) ?: 'applied';
        return [
            'youthId' => $youthId,
            'status' => $status,
            'remarks' => mb_substr(trim((string) (self::pick($input, 'remarks', '') ?? '')), 0, 500),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function validateBeneficiary(array $values): array
    {
        $errors = [];
        if ($values['youthId'] < 1) {
            $errors['youthId'] = 'Select a youth record.';
        }
        if (!in_array($values['status'], self::BENEFICIARY_STATUSES, true)) {
            $errors['status'] = 'Beneficiary status is not valid.';
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

    private static function str(array $input, string $key, int $max): string
    {
        $value = self::pick($input, $key, '');
        if ($value === null) {
            return '';
        }
        return mb_substr(trim((string) $value), 0, $max);
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

    private static function normalizeAccepts(mixed $value): string
    {
        $accepts = trim((string) $value);
        if (in_array($accepts, self::ACCEPTS, true)) {
            return $accepts;
        }
        return 'image/*,application/pdf';
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
