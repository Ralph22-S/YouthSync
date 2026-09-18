<?php
declare(strict_types=1);

namespace YouthSync\Auth;

final class Password
{
    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verify(string $plain, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        return password_verify($plain, $hash);
    }

    public static function isValidNew(string $plain): bool
    {
        return strlen($plain) >= 8;
    }
}
