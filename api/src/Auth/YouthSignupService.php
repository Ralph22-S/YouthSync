<?php
declare(strict_types=1);

namespace YouthSync\Auth;

use PDO;
use YouthSync\Http\Json;
use YouthSync\Identity\ContactIdentity;
use YouthSync\Notifications\EmailService;
use YouthSync\Notifications\SmsService;
use YouthSync\Org\PlanLimits;
use YouthSync\Youth\YouthService;
use YouthSync\Youth\YouthValidator;

/**
 * Public youth self-registration. The one-time code is stored as a hash.
 * The chosen password is stored only as a hash. The plain code is returned
 * only when OTP exposure is enabled (local development).
 */
final class YouthSignupService
{
    private const TTL_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private PDO $pdo,
        private YouthService $youth,
        private AuthService $auth,
        private EmailService $email,
        private SmsService $sms,
        private bool $exposeCode,
    ) {
    }

    /**
     * @return list<array{id:int,name:string,barangay:string,municipality:string,province:string}>
     */
    public function organizations(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, name, barangay, municipality, province
             FROM organizations
             WHERE status = 'active'
             ORDER BY municipality ASC, barangay ASC, name ASC"
        );
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'barangay' => (string) $row['barangay'],
                'municipality' => (string) $row['municipality'],
                'province' => (string) $row['province'],
            ];
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function start(array $input): array
    {
        $profile = $this->profile($input);
        $password = (string) ($input['password'] ?? '');
        if (!Password::isValidNew($password)) {
            Json::error('VALIDATION_ERROR', Password::RULE, 422);
        }
        $organizationId = (int) ($input['organizationId'] ?? $input['orgId'] ?? 0);
        $org = $this->activeOrganization($organizationId);
        $this->assertAvailable($organizationId, $org, $profile);

        $code = (string) random_int(100000, 999999);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $payload = $profile;
        $payload['passwordHash'] = Password::hash($password);

        $this->pdo->prepare(
            'UPDATE youth_signups SET consumed_at = NOW()
             WHERE email = :email AND consumed_at IS NULL'
        )->execute(['email' => $profile['email']]);

        $this->pdo->prepare(
            'INSERT INTO youth_signups (
                token_hash, organization_id, email, contact, code_hash, payload, attempts, expires_at
             ) VALUES (
                :token_hash, :organization_id, :email, :contact, :code_hash, :payload, 0,
                DATE_ADD(NOW(), INTERVAL ' . self::TTL_MINUTES . ' MINUTE)
             )'
        )->execute([
            'token_hash' => hash('sha256', $token),
            'organization_id' => $organizationId,
            'email' => $profile['email'],
            'contact' => $profile['contact'],
            'code_hash' => hash('sha256', $code),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $sent = $this->deliver($profile['email'], $profile['contact'], $code);
        if (!$sent['email'] && !$sent['sms'] && !$this->exposeCode) {
            Json::error('VALIDATION_ERROR', 'The verification code could not be sent. Try again in a moment.', 422);
        }

        $result = [
            'signupId' => $token,
            'attempts' => 0,
            'expiresIn' => self::TTL_MINUTES * 60,
            'sent' => $sent,
        ];
        if ($this->exposeCode) {
            $result['code'] = $code;
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function resend(string $token): array
    {
        $row = $this->openSignup($token);
        $code = (string) random_int(100000, 999999);
        $this->pdo->prepare(
            'UPDATE youth_signups
             SET code_hash = :code_hash, attempts = 0,
                 expires_at = DATE_ADD(NOW(), INTERVAL ' . self::TTL_MINUTES . ' MINUTE)
             WHERE id = :id AND consumed_at IS NULL'
        )->execute([
            'code_hash' => hash('sha256', $code),
            'id' => (int) $row['id'],
        ]);
        $sent = $this->deliver((string) $row['email'], (string) $row['contact'], $code);
        if (!$sent['email'] && !$sent['sms'] && !$this->exposeCode) {
            Json::error('VALIDATION_ERROR', 'The verification code could not be sent. Try again in a moment.', 422);
        }
        $result = [
            'signupId' => $token,
            'attempts' => 0,
            'sent' => $sent,
        ];
        if ($this->exposeCode) {
            $result['code'] = $code;
        }
        return $result;
    }

    public function cancel(string $token): void
    {
        $hash = hash('sha256', trim($token));
        $this->pdo->prepare(
            'UPDATE youth_signups SET consumed_at = NOW()
             WHERE token_hash = :hash AND consumed_at IS NULL'
        )->execute(['hash' => $hash]);
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $token, string $code): array
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            Json::error('VALIDATION_ERROR', 'Enter the 6-digit code.', 422);
        }
        $row = $this->openSignup($token);
        $attempts = (int) $row['attempts'];
        if ($attempts >= self::MAX_ATTEMPTS) {
            Json::error('VALIDATION_ERROR', 'Too many incorrect attempts. Resend the code to try again.', 422, [
                'attempts' => $attempts,
            ]);
        }
        if (!hash_equals((string) $row['code_hash'], hash('sha256', $code))) {
            $attempts++;
            $this->pdo->prepare('UPDATE youth_signups SET attempts = :attempts WHERE id = :id')
                ->execute(['attempts' => $attempts, 'id' => (int) $row['id']]);
            $message = $attempts >= self::MAX_ATTEMPTS
                ? 'Too many incorrect attempts. Resend the code to try again.'
                : 'Incorrect code. ' . (self::MAX_ATTEMPTS - $attempts) . ' attempt(s) left.';
            Json::error('VALIDATION_ERROR', $message, 422, ['attempts' => $attempts]);
        }

        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload) || !isset($payload['passwordHash'])) {
            Json::error('VALIDATION_ERROR', 'Start the sign-up again.', 422);
        }
        $passwordHash = (string) $payload['passwordHash'];
        unset($payload['passwordHash']);

        $org = $this->activeOrganization((int) $row['organization_id']);
        $this->assertAvailable((int) $row['organization_id'], $org, $payload);

        $userId = $this->youth->registerSelf((int) $row['organization_id'], $payload, $passwordHash, $org);
        $this->pdo->prepare('UPDATE youth_signups SET consumed_at = NOW() WHERE id = :id')
            ->execute(['id' => (int) $row['id']]);

        return $this->auth->signInYouthAccount($userId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function profile(array $input): array
    {
        $input['email'] = strtolower(trim((string) ($input['email'] ?? '')));
        $input['contact'] = preg_replace('/\D+/', '', (string) ($input['contact'] ?? $input['mobile'] ?? '')) ?? '';
        if (!preg_match('/^[^@\s]+@gmail\.com$/', $input['email'])) {
            Json::error('VALIDATION_ERROR', 'Use your Gmail address, like name@gmail.com.', 422);
        }
        $values = YouthValidator::normalize($input);
        $values['email'] = $input['email'];
        $values['contact'] = $input['contact'];
        $errors = YouthValidator::validate($values, true);
        if ($values['email'] === '') {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($errors) {
            Json::validation($errors);
        }
        return $values;
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $org
     */
    private function assertAvailable(int $organizationId, array $org, array $profile): void
    {
        $email = (string) $profile['email'];
        $taken = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $taken->execute(['email' => $email]);
        if ($taken->fetch()) {
            Json::error('CONFLICT', 'That Gmail address already has a YouthSync account. Check the email that was sent to that inbox, then log in. Another account cannot be created.', 409);
        }
        $match = $this->youth->signupMatch($organizationId, $profile);
        if ($match !== null && (int) ($match['user_id'] ?? 0) > 0) {
            Json::error('CONFLICT', 'This youth record already has a YouthSync account. Check the Gmail message that was sent, then log in. Another account cannot be created.', 409);
        }
        if ($match !== null && (int) $match['archived'] === 1) {
            Json::error('VALIDATION_ERROR', 'This youth record is archived. Ask your SK to restore it.', 422);
        }
        $exceptYouthId = $match !== null ? (int) $match['id'] : null;
        $identity = ContactIdentity::youthErrors($this->pdo, $email, (string) $profile['contact'], $exceptYouthId);
        if ($identity) {
            Json::error('CONFLICT', (string) reset($identity), 409);
        }
        if ($match !== null) {
            return;
        }
        $plan = PlanLimits::effective($org);
        if ($plan['youth'] !== null) {
            $count = $this->pdo->prepare(
                'SELECT COUNT(*) FROM youth WHERE organization_id = :org AND archived = 0'
            );
            $count->execute(['org' => $organizationId]);
            PlanLimits::assertRoom($this->pdo, 'youth', $plan, (int) $count->fetchColumn());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function activeOrganization(int $id): array
    {
        if ($id < 1) {
            Json::error('VALIDATION_ERROR', 'Select your SK barangay.', 422);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM organizations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false || (string) $row['status'] !== 'active') {
            Json::error('VALIDATION_ERROR', 'That SK barangay is not open for youth sign-up.', 422);
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function openSignup(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            Json::error('VALIDATION_ERROR', 'Start the sign-up again.', 422);
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM youth_signups WHERE token_hash = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        if ($row === false || $row['consumed_at'] !== null) {
            Json::error('VALIDATION_ERROR', 'Start the sign-up again.', 422);
        }
        $expires = strtotime((string) $row['expires_at']);
        if ($expires === false || $expires < time()) {
            Json::error('VALIDATION_ERROR', 'That code has expired. Tap Resend code to get a new one.', 422);
        }
        return $row;
    }

    /**
     * @return array{email:bool,sms:bool}
     */
    private function deliver(string $email, string $contact, string $code): array
    {
        $text = 'Your YouthSync code is ' . $code . '. It expires in 5 minutes.';
        $emailOk = false;
        if ($this->email->isConfigured()) {
            $sent = $this->email->send(
                $email,
                'Your YouthSync verification code',
                '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>',
                $text
            );
            $emailOk = !empty($sent['ok']);
        }
        $smsOk = false;
        if ($this->sms->isConfigured()) {
            $sent = $this->sms->send($contact, $text);
            $smsOk = !empty($sent['ok']);
        }
        return ['email' => $emailOk, 'sms' => $smsOk];
    }
}
