<?php
declare(strict_types=1);

namespace YouthSync\Auth;

final class Password
{
    public const RULE = 'Use at least 8 characters, with 1 capital letter and 1 lowercase letter.';

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
