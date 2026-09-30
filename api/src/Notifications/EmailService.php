<?php
declare(strict_types=1);

namespace YouthSync\Notifications;

/**
 * Sends one email through Resend. Does not write notification rows.
 */
final class EmailService
{
    public function __construct(
        private string $apiKey,
        private string $fromEmail,
        private string $fromName = 'YouthSync',
        private string $baseUrl = 'https://api.resend.com',
        private ?EmailTransport $transport = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && self::normalizeEmail($this->fromEmail) !== null;
    }

    /**
     * @return array{ok:bool,status:string,error:?string,providerReference:?string}
     */
    public function send(string $to, string $subject, string $html, string $text): array
    {
        $recipient = self::normalizeEmail($to);
        if ($recipient === null) {
            return $this->failed('Recipient email is missing or invalid.');
        }
        if (!$this->isConfigured()) {
            return $this->failed('Email provider is not configured.');
        }
        $subject = trim($subject);
        if ($subject === '') {
            return $this->failed('Email subject is empty.');
        }
        $html = trim($html);
        $text = trim($text);
        if ($html === '' && $text === '') {
            return $this->failed('Email message is empty.');
        }
        if ($text === '') {
            $text = strip_tags($html);
        }
        if ($html === '') {
            $html = '<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        $fromEmail = self::normalizeEmail($this->fromEmail) ?? '';
        $fromName = trim($this->fromName);
        $from = $fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail;

        $url = rtrim($this->baseUrl, '/') . '/emails';
        $payload = [
            'from' => $from,
            'to' => [$recipient],
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];

        try {
            $transport = $this->transport ?? new ResendTransport();
            $response = $transport->post($url, [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        } catch (\Throwable) {
            return $this->failed('Email provider is unavailable.');
        }

        $status = (int) ($response['httpStatus'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        if ($status < 200 || $status >= 300) {
            return $this->failed('Email provider rejected the message.');
        }
        $decoded = json_decode($body, true);
        $reference = null;
        if (is_array($decoded) && isset($decoded['id']) && is_scalar($decoded['id'])) {
            $reference = (string) $decoded['id'];
        }
        return [
            'ok' => true,
            'status' => 'sent',
            'error' => null,
            'providerReference' => $reference,
        ];
    }

    /**
     * @return array{subject:string,html:string,text:string}
     */
    public static function compose(string $title, string $message, string $related = ''): array
    {
        $title = trim($title);
        if ($title === '') {
            $title = 'Notification';
        }
        $message = trim($message);
        $related = trim($related);
        $subject = 'YouthSync: ' . $title;

        $text = "YouthSync\n\n" . $title . "\n\n" . $message;
        if ($related !== '') {
            $text .= "\n\nProgram: " . $related;
        }
        $text .= "\n\nThis is an automated message from YouthSync. Please do not reply with passwords or account credentials.";

        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $relatedHtml = '';
        if ($related !== '') {
            $relatedHtml = '<p style="color:#475569;font-size:14px;margin:16px 0 0;">Program: '
                . htmlspecialchars($related, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p>';
        }
        $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f8fafc;margin:0;padding:24px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">'
            . '<tr><td><p style="margin:0 0 8px;color:#0f766e;font-weight:700;letter-spacing:.04em;">YouthSync</p>'
            . '<h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">' . $safeTitle . '</h1>'
            . '<p style="margin:0;color:#334155;font-size:15px;line-height:1.5;">' . $safeMessage . '</p>'
            . $relatedHtml
            . '<p style="margin:24px 0 0;color:#94a3b8;font-size:12px;">Automated notification. Do not include passwords or access tokens in replies.</p>'
            . '</td></tr></table></body></html>';

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
    }

    public static function normalizeEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $email;
    }

    /**
     * @return array{ok:bool,status:string,error:?string,providerReference:?string}
     */
    private function failed(string $error): array
    {
        return [
            'ok' => false,
            'status' => 'failed',
            'error' => $error,
            'providerReference' => null,
        ];
    }
}

interface EmailTransport
{
    /**
     * @param array<string, string> $headers
     * @return array{httpStatus:int,body:string}
     */
    public function post(string $url, array $headers, string $jsonBody): array;
}

final class ResendTransport implements EmailTransport
{
    public function post(string $url, array $headers, string $jsonBody): array
    {
        if (!function_exists('curl_init')) {
            return ['httpStatus' => 0, 'body' => ''];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['httpStatus' => 0, 'body' => ''];
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [
            'httpStatus' => $status,
            'body' => is_string($body) ? $body : '',
        ];
    }
}
