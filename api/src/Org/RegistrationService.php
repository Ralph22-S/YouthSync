<?php
declare(strict_types=1);

namespace YouthSync\Org;

use PDO;
use PDOException;
use YouthSync\Admin\ActivityLog;
use YouthSync\Auth\Password;
use YouthSync\Http\Json;

/**
 * Public self-registration for an SK council.
 *
 * Unauthenticated, so nothing about the resulting record is taken from the
 * request: the organization is always created pending on the free plan, and
 * only the system administrator can move it on from there. No session is
 * established - the council cannot sign in until it is approved.
 */
final class RegistrationService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function register(array $input): array
    {
        $values = OrganizationValidator::normalize($input);
        $errors = OrganizationValidator::validate($values, true);

        // Registration-only rules: confirm the password and accept both policies.
        $confirm = (string) ($input['confirmPassword'] ?? $input['confirm_password'] ?? '');
        if (!isset($errors['ownerPassword']) && $values['ownerPassword'] !== '' && $confirm !== $values['ownerPassword']) {
            $errors['confirmPassword'] = 'The passwords do not match.';
        }
        if (!self::accepted($input['acceptTerms'] ?? $input['accept_terms'] ?? false)) {
            $errors['acceptTerms'] = 'You must accept the Terms and Conditions.';
        }
        if (!self::accepted($input['acceptPrivacy'] ?? $input['accept_privacy'] ?? false)) {
            $errors['acceptPrivacy'] = 'You must accept the Privacy Policy.';
        }

        if ($errors) {
            Json::validation($errors);
        }

        $conflict = OrganizationValidator::conflict($this->pdo, $values);
        if ($conflict !== null) {
            Json::error($conflict['code'], $conflict['message'], 409);
        }

        $roleId = (int) $this->pdo->query("SELECT id FROM roles WHERE code = 'SK_OFFICIAL' LIMIT 1")->fetchColumn();
        if ($roleId < 1) {
            Json::error('SERVER_ERROR', 'SK Official role is not configured.', 500);
        }

        [$first, $last] = OrganizationValidator::splitName($values['ownerName']);
        $name = OrganizationValidator::displayName($values['barangay'], $values['municipality']);

        try {
            $this->pdo->beginTransaction();

            $this->pdo->prepare(
                "INSERT INTO organizations
                    (name, barangay, municipality, province, chairperson, email, contact,
                     status, status_note, plan, sub_status, cycle)
                 VALUES
                    (:name, :barangay, :municipality, :province, :chairperson, :email, :contact,
                     'pending', '', 'free', 'free', 'none')"
            )->execute([
                'name' => $name,
                'barangay' => $values['barangay'],
                'municipality' => $values['municipality'],
                'province' => $values['province'],
                'chairperson' => $values['chairperson'],
                'email' => $values['email'],
                'contact' => $values['contact'],
            ]);
            $organizationId = (int) $this->pdo->lastInsertId();

            // The council chose this password itself, so there is nothing to change on first sign-in.
            $this->pdo->prepare(
                'INSERT INTO users (email, password_hash, first_name, last_name, role_id, status, must_change_password)
                 VALUES (:email, :hash, :first_name, :last_name, :role_id, :status, 0)'
            )->execute([
                'email' => $values['ownerEmail'],
                'hash' => Password::hash($values['ownerPassword']),
                'first_name' => $first,
                'last_name' => $last,
                'role_id' => $roleId,
                'status' => 'active',
            ]);
            $ownerId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                "INSERT INTO organization_users (user_id, organization_id, is_owner, status)
                 VALUES (:user_id, :org, 1, 'active')"
            )->execute(['user_id' => $ownerId, 'org' => $organizationId]);

            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                Json::error('CONFLICT', 'That email already has an account. Log in instead.', 409);
            }
            throw $e;
        }

        ActivityLog::record($this->pdo, [
            'organizationId' => $organizationId,
            'userId' => $ownerId,
            'actorName' => $values['ownerName'],
            'actorRole' => 'sk_official',
            'action' => ActivityLog::ACTION_CREATE,
            'category' => 'organization',
            'description' => $name . ' submitted a registration and is awaiting verification',
            'entityType' => 'organization',
            'entityId' => $organizationId,
        ]);

        // Deliberately thin: an unauthenticated caller gets back only what it
        // already told us, plus the status it now has.
        return [
            'organization' => [
                'id' => $organizationId,
                'name' => $name,
                'status' => 'pending',
            ],
            'ownerEmail' => $values['ownerEmail'],
            'message' => 'Your registration was submitted and is awaiting verification.',
        ];
    }

    private static function accepted(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
