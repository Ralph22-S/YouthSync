<?php
declare(strict_types=1);

namespace YouthSync\Payments;

/**
 * Minimal PayMongo REST client (Checkout Sessions API).
 *
 * - HTTP Basic auth with the SECRET key as the user name (PayMongo convention);
 *   the key never leaves this class and is never logged or returned.
 * - Short connect/total timeouts so a slow provider cannot hang a request.
 * - api_base defaults to https://api.paymongo.com. It may point at a plain-http
 *   host only when that host is 127.0.0.1 / localhost (the test-only MOCK server);
 *   any other http:// base is refused so the key cannot be sent unencrypted.
 *
 * Docs: https://developers.paymongo.com/reference/create-a-checkout
 *       https://developers.paymongo.com/reference/retrieve-a-checkout
 *       https://docs.paymongo.com/reference/expire-a-checkout-session
 */
final class PayMongoClient
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 20;

    public function __construct(
        private string $secretKey,
        private string $apiBase = 'https://api.paymongo.com',
    ) {
    }

    public static function baseAllowed(string $base): bool
    {
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if ($scheme === 'https') {
            return true;
        }
        return $scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost'], true);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed> decoded "data" object
     */
    public function createCheckoutSession(array $attributes): array
    {
        return $this->request('POST', '/v1/checkout_sessions', ['data' => ['attributes' => $attributes]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveCheckoutSession(string $id): array
    {
        return $this->request('GET', '/v1/checkout_sessions/' . rawurlencode($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function expireCheckoutSession(string $id): array
    {
        return $this->request('POST', '/v1/checkout_sessions/' . rawurlencode($id) . '/expire');
    }

    /**
     * GET /v1/payments/{id} (docs: Retrieve a Payment). Used to re-check a paid payment
     * (amount, currency, intent, mode) and its refunds[] before trusting it.
     *
     * @return array<string, mixed> the payment resource (id, type, attributes)
     */
    public function retrievePayment(string $id): array
    {
        return $this->request('GET', '/v1/payments/' . rawurlencode($id));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->secretKey === '') {
            throw new PayMongoException('NOT_CONFIGURED', 'PayMongo secret key is not set.', 0);
        }
        if (!self::baseAllowed($this->apiBase)) {
            throw new PayMongoException('NOT_CONFIGURED', 'PayMongo API base URL is not allowed.', 0);
        }
        if (!function_exists('curl_init')) {
            throw new PayMongoException('PROVIDER_UNAVAILABLE', 'The PHP curl extension is not available.', 0);
        }

        $ch = curl_init(rtrim($this->apiBase, '/') . $path);
        if ($ch === false) {
            throw new PayMongoException('PROVIDER_UNAVAILABLE', 'Could not start the payment provider request.', 0);
        }
        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        } elseif ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = '{}';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($raw === false || $errno !== 0) {
            // curl_error() text can include the host but never the key; keep only the errno.
            throw new PayMongoException('PROVIDER_UNAVAILABLE', 'The payment provider could not be reached (curl ' . $errno . ').', 0);
        }
        $decoded = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            $code = 'PROVIDER_ERROR';
            $detail = '';
            if (is_array($decoded) && isset($decoded['errors'][0]) && is_array($decoded['errors'][0])) {
                $code = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($decoded['errors'][0]['code'] ?? 'PROVIDER_ERROR')) ?: 'PROVIDER_ERROR', 0, 64);
                $detail = (string) ($decoded['errors'][0]['detail'] ?? '');
            }
            $message = 'The payment provider returned HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '.');
            throw new PayMongoException($code, self::safeText($message), $status);
        }
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new PayMongoException('PROVIDER_ERROR', 'The payment provider returned an unexpected response.', $status);
        }
        return $decoded['data'];
    }

    /** Provider text stored for admins: single line, capped, any sk_/pk_/whsk_ token masked. */
    public static function safeText(string $text): string
    {
        $text = preg_replace('/\b(sk|pk|whsk)_(test|live)_[A-Za-z0-9]+/', '$1_$2_***', $text) ?? '';
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        return mb_substr($text, 0, 255);
    }
}
