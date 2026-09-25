<?php
declare(strict_types=1);

use YouthSync\Applications\ApplicationService;
use YouthSync\Assistance\AssistanceService;
use YouthSync\Attendance\AttendanceService;
use YouthSync\Auth\AuthService;
use YouthSync\Controllers\ApplicationController;
use YouthSync\Controllers\AssistanceController;
use YouthSync\Controllers\AttendanceController;
use YouthSync\Controllers\AuthController;
use YouthSync\Controllers\DashboardController;
use YouthSync\Controllers\NotificationController;
use YouthSync\Controllers\ProgramController;
use YouthSync\Controllers\SubscriptionController;
use YouthSync\Controllers\UserController;
use YouthSync\Controllers\YouthController;
use YouthSync\Infra\SchemaPatches;
use YouthSync\Notifications\NotificationService;
use YouthSync\Notifications\SmsService;
use YouthSync\Subscription\SubscriptionService;
use YouthSync\Dashboard\DashboardService;
use YouthSync\Http\Json;
use YouthSync\Http\Router;
use YouthSync\Http\SessionCookies;
use YouthSync\Http\SkGuard;
use YouthSync\Middleware\AuthMiddleware;
use YouthSync\Middleware\OrganizationMiddleware;
use YouthSync\Middleware\RoleMiddleware;
use YouthSync\Org\Tenant;
use YouthSync\Programs\ProgramService;
use YouthSync\Users\UserService;
use YouthSync\Youth\YouthService;

$config = require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'YouthSync\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$logFile = dirname(__DIR__) . '/logs/app.log';

set_exception_handler(static function (Throwable $e) use ($logFile): void {
    $line = sprintf("[%s] %s in %s:%d\n", date('c'), $e->getMessage(), $e->getFile(), $e->getLine());
    @file_put_contents($logFile, $line, FILE_APPEND);
    if (!headers_sent()) {
        Json::error('SERVER_ERROR', 'Something went wrong. Please try again later.', 500);
    }
});

set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($logFile): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    $entry = sprintf("[%s] PHP error: %s in %s:%d\n", date('c'), $message, $file, $line);
    @file_put_contents($logFile, $entry, FILE_APPEND);
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$origin = $config['frontend_origin'];
$allowOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [$origin];
if (($config['env'] ?? '') === 'development') {
    foreach (['localhost', '127.0.0.1'] as $host) {
        for ($port = 5173; $port <= 5180; $port++) {
            $allowedOrigins[] = 'http://' . $host . ':' . $port;
        }
    }
}
$allowedOrigins = array_values(array_unique($allowedOrigins));
if (in_array($allowOrigin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $allowOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Accept, Content-Type');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$sessionParams = SessionCookies::params($config, 0);

session_name((string) $config['session_name']);
session_set_cookie_params($sessionParams);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $pdo = youthsync_pdo();
} catch (PDOException $e) {
    @file_put_contents($logFile, sprintf("[%s] DB connection failed\n", date('c')), FILE_APPEND);
    Json::error('SERVER_ERROR', 'The service is temporarily unavailable.', 500);
}

SchemaPatches::apply($pdo);

$tenant = new Tenant($pdo);
$auth = new AuthService(
    $pdo,
    $tenant,
    (bool) $config['demo_mode'],
    (string) $config['demo_login_email'],
    $config,
);
$auth->resumeRememberedSession();
$authMiddleware = new AuthMiddleware($auth);
$roleMiddleware = new RoleMiddleware();
$organizationMiddleware = new OrganizationMiddleware($tenant);
$guard = new SkGuard($authMiddleware, $roleMiddleware, $organizationMiddleware);

$controller = new AuthController(
    $auth,
    $authMiddleware,
    $roleMiddleware,
    $organizationMiddleware,
);
$youthController = new YouthController($guard, new YouthService($pdo));
$programController = new ProgramController($guard, new ProgramService($pdo));
$attendanceController = new AttendanceController($guard, new AttendanceService($pdo));
$assistanceService = new AssistanceService($pdo);
$assistanceController = new AssistanceController($guard, $assistanceService);
$notificationService = new NotificationService($pdo, new SmsService(
    (string) ($config['semaphore_api_key'] ?? ''),
    (string) ($config['semaphore_sender_name'] ?? ''),
    (string) ($config['semaphore_base_url'] ?? 'https://api.semaphore.co')
));
$notificationController = new NotificationController($guard, $notificationService);
$dashboardController = new DashboardController($guard, new DashboardService($pdo));
$subscriptionController = new SubscriptionController($guard, new SubscriptionService($pdo));
$userController = new UserController($guard, new UserService($pdo));
$applicationController = new ApplicationController(
    $guard,
    new ApplicationService($pdo, $assistanceService, $notificationService)
);

$router = new Router();
$router->add('POST', '/auth/login', [$controller, 'login']);
$router->add('POST', '/auth/logout', [$controller, 'logout']);
$router->add('GET', '/auth/me', [$controller, 'me']);
$router->add('POST', '/auth/change-password', [$controller, 'changePassword']);

$router->add('GET', '/sk/youth', [$youthController, 'index']);
$router->add('POST', '/sk/youth', [$youthController, 'store']);
$router->add('POST', '/sk/youth/import', [$youthController, 'import']);
$router->add('GET', '/sk/youth/{id}', [$youthController, 'show']);
$router->add('PUT', '/sk/youth/{id}', [$youthController, 'update']);
$router->add('PATCH', '/sk/youth/{id}', [$youthController, 'update']);
$router->add('DELETE', '/sk/youth/{id}', [$youthController, 'destroy']);
$router->add('POST', '/sk/youth/{id}/archive', [$youthController, 'archive']);
$router->add('POST', '/sk/youth/{id}/restore', [$youthController, 'restore']);

$router->add('GET', '/sk/programs', [$programController, 'index']);
$router->add('POST', '/sk/programs', [$programController, 'store']);
$router->add('GET', '/sk/programs/{id}', [$programController, 'show']);
$router->add('PUT', '/sk/programs/{id}', [$programController, 'update']);
$router->add('PATCH', '/sk/programs/{id}', [$programController, 'update']);
$router->add('DELETE', '/sk/programs/{id}', [$programController, 'destroy']);
$router->add('POST', '/sk/programs/{id}/status', [$programController, 'setStatus']);
$router->add('POST', '/sk/programs/{id}/archive', [$programController, 'archive']);
$router->add('GET', '/sk/programs/{id}/events', [$programController, 'eventsForProgram']);
$router->add('POST', '/sk/programs/{id}/events', [$programController, 'storeEventForProgram']);

$router->add('POST', '/sk/programs/{id}/attendance/session', [$attendanceController, 'createSession']);
$router->add('GET', '/sk/programs/{id}/attendance/session', [$attendanceController, 'getSession']);
$router->add('GET', '/sk/programs/{id}/attendance', [$attendanceController, 'listForProgram']);
$router->add('POST', '/sk/attendance/scan', [$attendanceController, 'scan']);
$router->add('POST', '/sk/attendance/confirm', [$attendanceController, 'confirm']);
$router->add('POST', '/sk/attendance/manual', [$attendanceController, 'manual']);
$router->add('GET', '/sk/attendance/{id}', [$attendanceController, 'show']);
$router->add('PATCH', '/sk/attendance/{id}', [$attendanceController, 'updateStatus']);

$router->add('GET', '/sk/assistance-types', [$assistanceController, 'types']);
$router->add('POST', '/sk/assistance-types', [$assistanceController, 'storeType']);
$router->add('GET', '/sk/assistance', [$assistanceController, 'index']);
$router->add('POST', '/sk/assistance', [$assistanceController, 'store']);
$router->add('GET', '/sk/assistance/{id}', [$assistanceController, 'show']);
$router->add('PUT', '/sk/assistance/{id}', [$assistanceController, 'update']);
$router->add('PATCH', '/sk/assistance/{id}', [$assistanceController, 'update']);
$router->add('DELETE', '/sk/assistance/{id}', [$assistanceController, 'destroy']);
$router->add('POST', '/sk/assistance/{id}/archive', [$assistanceController, 'archive']);
$router->add('POST', '/sk/assistance/{id}/beneficiaries', [$assistanceController, 'storeBeneficiary']);
$router->add('DELETE', '/sk/beneficiaries/{id}', [$assistanceController, 'destroyBeneficiary']);
$router->add('POST', '/sk/requirements', [$assistanceController, 'storeRequirement']);
$router->add('PATCH', '/sk/requirements/{id}', [$assistanceController, 'updateRequirement']);
$router->add('DELETE', '/sk/requirements/{id}', [$assistanceController, 'destroyRequirement']);

$router->add('GET', '/sk/applications', [$applicationController, 'index']);
$router->add('POST', '/sk/applications', [$applicationController, 'store']);
$router->add('GET', '/sk/applications/{id}', [$applicationController, 'show']);
$router->add('PATCH', '/sk/applications/{id}', [$applicationController, 'update']);
$router->add('POST', '/sk/applications/{id}/status', [$applicationController, 'setStatus']);
$router->add('POST', '/sk/applications/{id}/approve', [$applicationController, 'approve']);
$router->add('POST', '/sk/applications/{id}/reject', [$applicationController, 'reject']);
$router->add('PATCH', '/sk/application-requirements/{id}', [$applicationController, 'reviewRequirement']);
$router->add('POST', '/sk/submissions/{id}/review', [$applicationController, 'reviewRequirement']);

$router->add('GET', '/sk/notifications', [$notificationController, 'index']);
$router->add('POST', '/sk/notifications/read-all', [$notificationController, 'markAllRead']);
$router->add('GET', '/sk/notifications/{id}', [$notificationController, 'show']);
$router->add('PATCH', '/sk/notifications/{id}/read', [$notificationController, 'markRead']);
$router->add('POST', '/sk/notifications/{id}/read', [$notificationController, 'markRead']);
$router->add('DELETE', '/sk/notifications/{id}', [$notificationController, 'destroy']);

$router->add('GET', '/sk/dashboard', [$dashboardController, 'dashboard']);
$router->add('GET', '/sk/reports', [$dashboardController, 'reports']);
$router->add('GET', '/sk/reports/youth', [$dashboardController, 'reportYouth']);
$router->add('GET', '/sk/reports/programs', [$dashboardController, 'reportPrograms']);
$router->add('GET', '/sk/reports/assistance', [$dashboardController, 'reportAssistance']);
$router->add('GET', '/sk/reports/applications', [$dashboardController, 'reportApplications']);
$router->add('GET', '/sk/reports/attendance', [$dashboardController, 'reportAttendance']);

$router->add('GET', '/sk/subscription/usage', [$subscriptionController, 'usage']);
$router->add('GET', '/sk/subscription', [$subscriptionController, 'show']);

$router->add('GET', '/sk/users', [$userController, 'index']);
$router->add('POST', '/sk/users', [$userController, 'store']);
$router->add('GET', '/sk/users/{id}', [$userController, 'show']);
$router->add('PUT', '/sk/users/{id}', [$userController, 'update']);
$router->add('PATCH', '/sk/users/{id}', [$userController, 'update']);
$router->add('POST', '/sk/users/{id}/status', [$userController, 'setStatus']);
$router->add('POST', '/sk/users/{id}/toggle-active', [$userController, 'toggleActive']);
$router->add('POST', '/sk/users/{id}/reset-password', [$userController, 'resetPassword']);
$router->add('DELETE', '/sk/users/{id}', [$userController, 'destroy']);

$router->add('GET', '/sk/events', [$programController, 'eventIndex']);
$router->add('POST', '/sk/events', [$programController, 'eventStore']);
$router->add('GET', '/sk/events/{id}', [$programController, 'eventShow']);
$router->add('PUT', '/sk/events/{id}', [$programController, 'eventUpdate']);
$router->add('PATCH', '/sk/events/{id}', [$programController, 'eventUpdate']);
$router->add('DELETE', '/sk/events/{id}', [$programController, 'eventDestroy']);

$path = Router::requestPath((string) $config['base_path']);
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
