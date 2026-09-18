<?php
declare(strict_types=1);

namespace YouthSync\Http;

final class SessionCookies
{
    public const REMEMBER_COOKIE = 'youthsync_remember';

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function params(array $config, int $lifetime): array
    {
        $sameSite = (string) ($config['session_samesite'] ?? 'Lax');
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }

        return [
            'lifetime' => max(0, $lifetime),
            'path' => '/',
            'domain' => '',
            'secure' => (bool) ($config['session_secure'] ?? false),
            'httponly' => true,
            'samesite' => $sameSite,
        ];
    }

    public static function token(): string
    {
        $value = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function write(string $name, string $value, array $params, int $expires): void
    {
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => (string) ($params['path'] ?? '/'),
            'domain' => (string) ($params['domain'] ?? ''),
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => (string) ($params['samesite'] ?? 'Lax'),
        ]);
    }

    /**
     * Remember-me is set from a cross-origin fetch (Vite :5173/:5175 → Apache :80).
     * Chrome will not persist SameSite=Lax cookies from that response. Localhost is a
     * secure context, so SameSite=None; Secure is valid on http://localhost.
     */
    public static function writeRemember(string $value, array $config, int $expires): void
    {
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None',
        ]);
    }
}
