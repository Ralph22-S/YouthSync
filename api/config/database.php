<?php
declare(strict_types=1);

/**
 * Shared PDO connection. Controllers must not open their own connections.
 */
function youthsync_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = youthsync_env('DB_HOST', '127.0.0.1');
    $port = youthsync_env('DB_PORT', '3306');
    $name = youthsync_env('DB_NAME', 'youthsync');
    $user = youthsync_env('DB_USER', 'root');
    $pass = youthsync_env('DB_PASSWORD', '');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    return $pdo;
}
