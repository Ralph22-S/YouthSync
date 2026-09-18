<?php
declare(strict_types=1);

namespace YouthSync\Applications;

final class ApplicationValidator
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'withdrawn', 'needs_resubmission'];
    public const REVIEW_STATUSES = ['pending', 'approved', 'rejected', 'withdrawn', 'needs_resubmission'];
    public const SUBMISSION_STATUSES = ['missing', 'submitted', 'verified', 'needs_resubmission'];
    public const APPLY_STATUSES = ['open', 'full'];

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
            $input['reviewed_by'],
            $input['reviewedBy']
        );
        $assistanceId = (int) (self::pick($input, 'assistanceId', 0) ?? 0);
        $youthId = (int) (self::pick($input, 'youthId', 0) ?? 0);
        $submissions = self::pick($input, 'submissions', []);
        if (!is_array($submissions)) {
            $submissions = [];
        }
        $normalizedSubs = [];
        foreach ($submissions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalizedSubs[] = self::normalizeSubmissionInput($row);
        }
        return [
            'assistanceId' => $assistanceId,
            'youthId' => $youthId,
            'remarks' => mb_substr(trim((string) (self::pick($input, 'remarks', '') ?? '')), 0, 1000),
            'submissions' => $normalizedSubs,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function validateCreate(array $values): array
    {
        $errors = [];
        if ($values['assistanceId'] < 1) {
            $errors['assistanceId'] = 'Select an assistance program.';
        }
        if ($values['youthId'] < 1) {
            $errors['youthId'] = 'Select a youth record.';
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizePatch(array $input): array
    {
        unset(
            $input['organization_id'],
            $input['organizationId'],
            $input['orgId'],
            $input['org_id'],
            $input['reviewed_by'],
            $input['reviewedBy'],
            $input['youthId'],
            $input['assistanceId']
        );
        $out = [];
        if (array_key_exists('remarks', $input)) {
            $out['remarks'] = mb_substr(trim((string) $input['remarks']), 0, 1000);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeStatus(array $input): array
    {
        unset(
            $input['organization_id'],
            $input['organizationId'],
            $input['orgId'],
            $input['org_id'],
            $input['reviewed_by'],
            $input['reviewedBy']
        );
        return [
            'status' => strtolower(trim((string) (self::pick($input, 'status', '') ?? ''))),
            'remarks' => mb_substr(trim((string) (self::pick($input, 'remarks', '') ?? '')), 0, 1000),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function validateStatus(array $values): array
    {
        $errors = [];
        if (!in_array($values['status'], self::REVIEW_STATUSES, true)) {
            $errors['status'] = 'Application status is not valid.';
        }
        if ($values['status'] === 'rejected' && $values['remarks'] === '') {
            $errors['remarks'] = 'A reason is required to reject an application.';
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeSubmissionReview(array $input): array
    {
        unset(
            $input['organization_id'],
            $input['organizationId'],
            $input['orgId'],
            $input['org_id'],
            $input['reviewed_by'],
            $input['reviewedBy'],
            $input['application_id'],
            $input['requirement_id']
        );
        $out = [];
        if (array_key_exists('status', $input)) {
            $out['status'] = strtolower(trim((string) $input['status']));
        }
        if (array_key_exists('remarks', $input)) {
            $out['remarks'] = mb_substr(trim((string) $input['remarks']), 0, 500);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function validateSubmissionReview(array $values): array
    {
        $errors = [];
        if (isset($values['status']) && !in_array($values['status'], self::SUBMISSION_STATUSES, true)) {
            $errors['status'] = 'Submission status is not valid.';
        }
        if ($values === []) {
            $errors['status'] = 'Provide a status or remarks to review.';
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalizeSubmissionInput(array $row): array
    {
        $name = (string) (self::pick($row, 'fileName', '') ?? '');
        $name = str_replace(['\\', '/', '..'], '', $name);
        $ref = (string) (self::pick($row, 'fileRef', '') ?? '');
        $ref = preg_replace('/[^a-zA-Z0-9._-]/', '', $ref) ?? '';
        return [
            'requirementId' => (int) (self::pick($row, 'requirementId', 0) ?? 0),
            'fileName' => mb_substr(trim($name), 0, 190),
            'fileType' => mb_substr(trim((string) (self::pick($row, 'fileType', '') ?? '')), 0, 80),
            'fileSize' => max(0, (int) (self::pick($row, 'fileSize', 0) ?? 0)),
            'fileRef' => mb_substr($ref, 0, 64),
        ];
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
}
