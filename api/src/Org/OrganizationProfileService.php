<?php
declare(strict_types=1);

namespace YouthSync\Org;

use PDO;
use YouthSync\Admin\ActivityLog;
use YouthSync\Http\Json;

/**
 * The signed-in SK official's own organization profile.
 *
 * organization_id always comes from the session membership. Plan, subscription,
 * and account status are not writable here.
 */
final class OrganizationProfileService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $organizationId): array
    {
        return $this->present($this->requireRow($organizationId));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function update(int $organizationId, array $input, array $actor = []): array
    {
        unset($input['id'], $input['organization_id'], $input['organizationId'], $input['orgId']);

        $this->requireRow($organizationId);
        $values = $this->profileValues($input);
        $errors = $this->validate($values);
        if ($errors) {
            Json::validation($errors);
        }

        $conflict = $this->conflict($values, $organizationId);
        if ($conflict !== null) {
            Json::error($conflict['code'], $conflict['message'], 409);
        }

        $name = OrganizationValidator::displayName($values['barangay'], $values['municipality']);
        $this->pdo->prepare(
            'UPDATE organizations
             SET name = :name, barangay = :barangay, municipality = :municipality, province = :province,
                 chairperson = :chairperson, email = :email, contact = :contact
             WHERE id = :id'
        )->execute([
            'name' => $name,
            'barangay' => $values['barangay'],
            'municipality' => $values['municipality'],
            'province' => $values['province'],
            'chairperson' => $values['chairperson'],
            'email' => $values['email'],
            'contact' => $values['contact'],
            'id' => $organizationId,
        ]);

        ActivityLog::record($this->pdo, array_merge(ActivityLog::actor($actor), [
            'organizationId' => $organizationId,
            'action' => ActivityLog::ACTION_UPDATE,
            'category' => 'organization',
            'description' => 'Updated the organization profile for ' . $name,
            'entityType' => 'organization',
            'entityId' => $organizationId,
        ]));

        return $this->present($this->requireRow($organizationId));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function profileValues(array $input): array
    {
        $text = static function (mixed $value, int $max): string {
            return mb_substr(trim((string) ($value ?? '')), 0, $max);
        };

        return [
            'barangay' => $text($input['barangay'] ?? '', 120),
            'municipality' => $text($input['municipality'] ?? '', 120),
            'province' => $text($input['province'] ?? '', 120),
            'chairperson' => $text($input['chairperson'] ?? '', 160),
            'email' => mb_strtolower($text($input['email'] ?? '', 190)),
            'contact' => preg_replace('/\D+/', '', $text($input['contact'] ?? '', 20)) ?? '',
        ];
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function validate(array $values): array
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
        } elseif (strlen($values['contact']) !== OrganizationValidator::CONTACT_LENGTH) {
            $errors['contact'] = 'Contact number must be exactly ' . OrganizationValidator::CONTACT_LENGTH . ' digits.';
        }
        return $errors;
    }

    /**
     * @param array<string, string> $values
     * @return array{code:string, message:string}|null
     */
    private function conflict(array $values, int $organizationId): ?array
    {
        $email = $this->pdo->prepare(
            'SELECT id FROM organizations WHERE email = :email AND id <> :id LIMIT 1'
        );
        $email->execute(['email' => $values['email'], 'id' => $organizationId]);
        if ($email->fetch() !== false) {
            return ['code' => 'CONFLICT', 'message' => 'An organization with that official email already exists.'];
        }

        $place = $this->pdo->prepare(
            'SELECT id FROM organizations
             WHERE LOWER(barangay) = :b AND LOWER(municipality) = :m AND id <> :id
             LIMIT 1'
        );
        $place->execute([
            'b' => mb_strtolower($values['barangay']),
            'm' => mb_strtolower($values['municipality']),
            'id' => $organizationId,
        ]);
        if ($place->fetch() !== false) {
            return ['code' => 'CONFLICT', 'message' => 'That barangay and municipality already has an SK organization.'];
        }

        if (\YouthSync\Identity\ContactIdentity::organizationContactTaken($this->pdo, $values['contact'], $organizationId)) {
            return ['code' => 'CONFLICT', 'message' => \YouthSync\Identity\ContactIdentity::CONTACT_MESSAGE];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRow(int $organizationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, barangay, municipality, province, chairperson, email, contact,
                    status, status_note, plan, sub_status, cycle, started_on, expires_at
             FROM organizations WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $organizationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            Json::error('NOT_FOUND', 'That organization is not on this deployment.', 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'barangay' => $row['barangay'],
            'municipality' => $row['municipality'],
            'province' => $row['province'],
            'chairperson' => $row['chairperson'],
            'email' => $row['email'],
            'contact' => $row['contact'],
            'status' => $row['status'],
            'statusNote' => $row['status_note'] ?? '',
            'plan' => $row['plan'],
            'subStatus' => $row['sub_status'],
            'cycle' => $row['cycle'],
            'startedOn' => $row['started_on'] ?? null,
            'expiresAt' => $row['expires_at'] ?? null,
            'subscription' => [
                'plan' => $row['plan'],
                'status' => $row['sub_status'],
                'cycle' => $row['cycle'],
                'expires_at' => $row['expires_at'] ?? null,
            ],
        ];
    }
}
