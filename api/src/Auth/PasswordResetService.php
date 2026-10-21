<?php
declare(strict_types=1);

namespace YouthSync\Auth;

use PDO;
use YouthSync\Admin\ActivityLog;
use YouthSync\Http\Json;
use YouthSync\Notifications\EmailService;

/**
 * Single-use password reset tokens. The raw token is never stored or logged.
 */
final class PasswordResetService
{
    private const TTL_MINUTES = 30;
    private const GENERIC = 'If an account exists for that email, password reset instructions have been sent.';

    public function __construct(
        private PDO $pdo,
        private EmailService $email,
        private string $logFile,
        private ?RememberTokenStore $rememberTokens = null,
        private string $frontendOrigin = '',
    ) {
        if ($this->rememberTokens === null) {
            $this->rememberTokens = new RememberTokenStore(
                dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'remember_tokens.json'
            );
        }
    }

    public function request(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Json::error('VALIDATION_ERROR', 'Enter a valid email address.', 422);
        }

        $user = $this->findActiveUser($email);
        if ($user === null) {
            return;
        }

        $this->invalidateActive((int) $user['id']);
        $raw = (string) random_int(100000, 999999);
        $hash = hash('sha256', $raw);
        $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL ' . self::TTL_MINUTES . ' MINUTE))'
        )->execute([
            'user_id' => (int) $user['id'],
            'token_hash' => $hash,
        ]);

        // Audited only for an existing account; the token is never recorded.
        ActivityLog::recordAuthForUserId(
            $this->pdo,
            (int) $user['id'],
            ActivityLog::ACTION_PASSWORD_RESET_REQUESTED,
            'Requested a password reset code'
        );
        $this->deliver($email, $raw);
    }

    public function reset(string $token, string $newPassword): void
    {
        $token = trim($token);
        if ($token === '' || $newPassword === '') {
            Json::error('VALIDATION_ERROR', 'The code and a new password are required.', 422);
        }
        if (!Password::isValidNew($newPassword)) {
            Json::error('VALIDATION_ERROR', Password::RULE, 422);
        }

        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, expires_at, used_at
             FROM password_reset_tokens
             WHERE token_hash = :hash
             LIMIT 1'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();
        if ($row === false || $row['used_at'] !== null) {
            Json::error('RESET_INVALID', 'This code is invalid or has expired.', 400);
        }

        $expires = strtotime((string) $row['expires_at']);
        if ($expires === false || $expires < time()) {
            Json::error('RESET_INVALID', 'This code is invalid or has expired.', 400);
        }

        $userId = (int) $row['user_id'];
        $user = $this->pdo->prepare('SELECT id, password_hash, status FROM users WHERE id = :id LIMIT 1');
        $user->execute(['id' => $userId]);
        $account = $user->fetch();
        if ($account === false || $account['status'] !== 'active') {
            Json::error('RESET_INVALID', 'This code is invalid or has expired.', 400);
        }
        if (Password::verify($newPassword, (string) $account['password_hash'])) {
            Json::error('VALIDATION_ERROR', 'Choose a new password that is different from the current password.', 422);
        }

        $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id'
        )->execute([
            'hash' => Password::hash($newPassword),
            'id' => $userId,
        ]);
        $this->pdo->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL'
        )->execute(['id' => (int) $row['id']]);
        $this->invalidateActive($userId);
        $this->rememberTokens?->forgetUser($userId);
        ActivityLog::recordAuthForUserId(
            $this->pdo,
            $userId,
            ActivityLog::ACTION_PASSWORD_RESET,
            'Reset password with a password reset link'
        );
    }

    public static function genericMessage(): string
    {
        return self::GENERIC;
    }

    private function deliver(string $email, string $rawToken): void
    {
        $subject = 'Your YouthSync password code';
        $safeCode = htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8');
        $text = 'Your YouthSync password code is ' . $rawToken . '. It expires in 30 minutes and can be used once.';
        $html = '<p>Your YouthSync password code is <strong>' . $safeCode . '</strong>.</p>'
            . '<p>Enter this code on the forgot-password page. It expires in 30 minutes and can be used once.</p>';
        if (!$this->email->isConfigured()) {
            $this->log('password reset email was not sent because the email provider is not configured');
            return;
        }
        $result = $this->email->send($email, $subject, $html, $text);
        if (!($result['ok'] ?? false)) {
            $reason = trim((string) ($result['error'] ?? 'password reset email delivery failed'));
            $this->log($reason);
        }
    }

    private function invalidateActive(int $userId): void
    {
        $this->pdo->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :id AND used_at IS NULL'
        )->execute(['id' => $userId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActiveUser(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM users WHERE email = :email AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function log(string $message): void
    {
        @file_put_contents($this->logFile, sprintf("[%s] %s\n", date('c'), $message), FILE_APPEND);
    }
}
