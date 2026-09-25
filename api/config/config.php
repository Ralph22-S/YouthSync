<?php
declare(strict_types=1);

/**
 * Load api/.env into getenv()/$_ENV. Missing file is allowed (use OS env).
 */
function youthsync_load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, "\"'");
        if ($key === '') {
            continue;
        }
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function youthsync_env(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string) $value;
}

function youthsync_env_bool(string $key, bool $default = false): bool
{
    $raw = youthsync_env($key, $default ? 'true' : 'false');
    return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
}

youthsync_load_env(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

return [
    'env' => youthsync_env('APP_ENV', 'development'),
    'debug' => youthsync_env_bool('APP_DEBUG', true),
    'app_url' => youthsync_env('APP_URL', 'http://localhost/YouthSync_UI_Refresh/api'),
    'base_path' => rtrim(youthsync_env('APP_BASE_PATH', '/YouthSync_UI_Refresh/api'), '/'),
    'frontend_origin' => youthsync_env('FRONTEND_ORIGIN', 'http://localhost:5173'),
    'session_name' => youthsync_env('SESSION_NAME', 'youthsync_session'),
    'session_samesite' => youthsync_env('SESSION_SAMESITE', 'Lax'),
    'session_secure' => youthsync_env_bool('SESSION_SECURE', false),
    'session_remember_lifetime' => max(0, (int) youthsync_env('SESSION_REMEMBER_LIFETIME', '2592000')),
    // Demo password bypass is off unless DEMO_MODE=true AND APP_ENV is not production.
    'demo_mode' => youthsync_env_bool('DEMO_MODE', false)
        && strtolower(youthsync_env('APP_ENV', 'development')) !== 'production',
    'demo_login_email' => strtolower(youthsync_env('DEMO_LOGIN_EMAIL', 'sk1@demo.test')),
    'semaphore_api_key' => youthsync_env('SEMAPHORE_API_KEY', ''),
    'semaphore_sender_name' => youthsync_env('SEMAPHORE_SENDER_NAME', ''),
    'semaphore_base_url' => youthsync_env('SEMAPHORE_BASE_URL', 'https://api.semaphore.co'),
];
