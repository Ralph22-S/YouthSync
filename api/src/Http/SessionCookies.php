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

        // Never mark the session cookie Secure on plain HTTP. Chrome will set
        // SameSite=None; Secure from a localhost PHP response, then refuse to
        // send that cookie on the next document load of http://localhost:5173.
        $https = self::requestIsHttps();
        $secure = $https && (bool) ($config['session_secure'] ?? false);
        if (!$https && $sameSite === 'None') {
            $sameSite = 'Lax';
        }

        return [
            'lifetime' => max(0, $lifetime),
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ];
    }

    private static function requestIsHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_string($forwarded) && strtolower(trim(explode(',', $forwarded)[0])) === 'https') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
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

    public static function writeRemember(string $value, array $config, int $expires): void
    {
        $params = self::params($config, 0);
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => (bool) $params['secure'],
            'httponly' => true,
            'samesite' => (string) $params['samesite'],
        ]);
    }
}
