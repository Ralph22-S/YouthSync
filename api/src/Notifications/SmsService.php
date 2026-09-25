<?php
declare(strict_types=1);

namespace YouthSync\Notifications;

/**
 * Sends one SMS through Semaphore. Does not write notification rows.
 */
final class SmsService
{
    public function __construct(
        private string $apiKey,
        private string $senderName,
        private string $baseUrl = 'https://api.semaphore.co',
        private ?SmsTransport $transport = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->senderName !== '';
    }

    /**
     * @return array{ok:bool,status:string,error:?string,providerReference:?string}
     */
    public function send(string $phone, string $message): array
    {
        $normalized = self::normalizePhone($phone);
        if ($normalized === null) {
            return $this->failed('Recipient phone is missing or invalid.');
        }
        if (!$this->isConfigured()) {
            return $this->failed('SMS provider is not configured.');
        }
        $message = trim($message);
        if ($message === '') {
            return $this->failed('SMS message is empty.');
        }

        $url = rtrim($this->baseUrl, '/') . '/api/v4/messages';
        $fields = [
            'apikey' => $this->apiKey,
            'number' => $normalized,
            'message' => $message,
            'sendername' => $this->senderName,
        ];

        try {
            $transport = $this->transport ?? new SemaphoreTransport();
            $response = $transport->post($url, $fields);
        } catch (\Throwable) {
            return $this->failed('SMS provider is unavailable.');
        }

        $status = (int) ($response['httpStatus'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        if ($status < 200 || $status >= 300) {
            return $this->failed('SMS provider rejected the message.');
        }
        $decoded = json_decode($body, true);
        $reference = null;
        if (is_array($decoded)) {
            $first = isset($decoded[0]) && is_array($decoded[0]) ? $decoded[0] : $decoded;
            if (isset($first['message_id'])) {
                $reference = (string) $first['message_id'];
            }
        }
        return [
            'ok' => true,
            'status' => 'sent',
            'error' => null,
            'providerReference' => $reference,
        ];
    }

    public static function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        }
        if (strlen($digits) !== 11 || !str_starts_with($digits, '09')) {
            return null;
        }
        return $digits;
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

interface SmsTransport
{
    /**
     * @param array<string, string> $fields
     * @return array{httpStatus:int,body:string}
     */
    public function post(string $url, array $fields): array;
}

final class SemaphoreTransport implements SmsTransport
{
    public function post(string $url, array $fields): array
    {
        if (!function_exists('curl_init')) {
            return ['httpStatus' => 0, 'body' => ''];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['httpStatus' => 0, 'body' => ''];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
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
