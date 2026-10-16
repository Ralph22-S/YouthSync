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
        // PAYMONGO_* values already present in the real process environment win over
        // the file (standard dotenv behaviour). Apache/mod_php does not pass these, so
        // under Apache api/.env is used; the payment test suite starts its own local
        // PHP server with MOCK values this way without editing api/.env.
        if (str_starts_with($key, 'PAYMONGO_') && getenv($key, true) !== false) {
            $_ENV[$key] = (string) getenv($key, true);
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

/**
 * Production-only checks. Empty SMS and email keys are allowed.
 * The returned messages name the variable only.
 *
 * @param array{db_password_set:bool,session_secure:bool,demo_mode_requested:bool,debug:bool} $flags
 * @return list<string>
 */
function youthsync_production_problems(array $flags): array
{
    $problems = [];
    if (empty($flags['db_password_set'])) {
        $problems[] = 'DB_PASSWORD is required.';
    }
    if (empty($flags['session_secure'])) {
        $problems[] = 'SESSION_SECURE must be true.';
    }
    if (!empty($flags['demo_mode_requested'])) {
        $problems[] = 'DEMO_MODE must be false.';
    }
    if (!empty($flags['debug'])) {
        $problems[] = 'APP_DEBUG must be false.';
    }
    return $problems;
}

$config = [
    'env' => youthsync_env('APP_ENV', 'development'),
    'debug' => youthsync_env_bool('APP_DEBUG', true),
    'app_url' => youthsync_env('APP_URL', 'http://localhost/YouthSync_UI_Refresh/api'),
    'base_path' => rtrim(youthsync_env('APP_BASE_PATH', '/YouthSync_UI_Refresh/api'), '/'),
    'frontend_origin' => youthsync_env('FRONTEND_ORIGIN', 'http://localhost:5173'),
    'session_name' => youthsync_env('SESSION_NAME', 'youthsync_session'),
    'session_samesite' => youthsync_env('SESSION_SAMESITE', 'Lax'),
    'session_secure' => youthsync_env_bool('SESSION_SECURE', false),
    'session_remember_lifetime' => max(0, (int) youthsync_env('SESSION_REMEMBER_LIFETIME', '2592000')),
    // Required for POST /cron/run. Empty disables the HTTP cron route. The CLI scheduler does not use it.
    'cron_secret' => youthsync_env('CRON_SECRET', ''),
    // Demo password bypass is off unless DEMO_MODE=true AND APP_ENV is not production.
    'demo_mode' => youthsync_env_bool('DEMO_MODE', false)
        && strtolower(youthsync_env('APP_ENV', 'development')) !== 'production',
    'demo_login_email' => strtolower(youthsync_env('DEMO_LOGIN_EMAIL', 'sk1@demo.test')),
    'semaphore_api_key' => youthsync_env('SEMAPHORE_API_KEY', ''),
    'semaphore_sender_name' => youthsync_env('SEMAPHORE_SENDER_NAME', ''),
    'semaphore_base_url' => youthsync_env('SEMAPHORE_BASE_URL', 'https://api.semaphore.co'),
    'brevo_api_key' => youthsync_env('BREVO_API_KEY', ''),
    'brevo_from_email' => youthsync_env('BREVO_FROM_EMAIL', ''),
    'brevo_from_name' => youthsync_env('BREVO_FROM_NAME', 'YouthSync'),
    'brevo_base_url' => youthsync_env('BREVO_BASE_URL', 'https://api.brevo.com'),
    // PayMongo hosted checkout. Secrets come only from the environment (api/.env, git-ignored).
    // See docs/PAYMONGO_SETUP.md. An empty secret key or webhook secret disables online payment.
    'paymongo' => [
        'secret_key' => youthsync_env('PAYMONGO_SECRET_KEY', ''),
        'webhook_secret' => youthsync_env('PAYMONGO_WEBHOOK_SECRET', ''),
        'mode' => strtolower(youthsync_env('PAYMONGO_MODE', 'test')),
        'allowed_methods' => youthsync_env('PAYMONGO_ALLOWED_METHODS', ''),
        'success_url' => youthsync_env('PAYMONGO_SUCCESS_URL', rtrim(youthsync_env('FRONTEND_ORIGIN', 'http://localhost:5173'), '/') . '/sk/subscription/payment/success?tx={id}'),
        'cancel_url' => youthsync_env('PAYMONGO_CANCEL_URL', rtrim(youthsync_env('FRONTEND_ORIGIN', 'http://localhost:5173'), '/') . '/sk/subscription/payment/cancel?tx={id}'),
        'api_base' => youthsync_env('PAYMONGO_API_BASE', 'https://api.paymongo.com'),
        'webhook_tolerance' => max(60, (int) youthsync_env('PAYMONGO_WEBHOOK_TOLERANCE', '300')),
        // Test mode only: let the protected status endpoint activate from the server-side
        // checkout-session fetch alone (for hosts with no public webhook URL). Ignored in live mode.
        'confirm_by_retrieval' => youthsync_env_bool('PAYMONGO_CONFIRM_BY_RETRIEVAL', false),
        // MOCK test suites only: ignored unless PAYMONGO_MODE=test AND the API base is a
        // plain-http loopback MOCK server (see PaymentVerificationService::fault). Never set it in .env.
        'test_fault' => youthsync_env('PAYMONGO_TEST_FAULT', ''),
    ],
];

if (strtolower($config['env']) === 'production') {
    $problems = youthsync_production_problems([
        'db_password_set' => youthsync_env('DB_PASSWORD', '') !== '',
        'session_secure' => $config['session_secure'],
        'demo_mode_requested' => youthsync_env_bool('DEMO_MODE', false),
        'debug' => youthsync_env_bool('APP_DEBUG', true),
    ]);
    if ($problems !== []) {
        throw new RuntimeException('Refusing to start in production. ' . implode(' ', $problems));
    }
    $config['debug'] = false;
}

return $config;
