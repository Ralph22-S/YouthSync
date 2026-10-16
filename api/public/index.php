<?php
declare(strict_types=1);

use YouthSync\Applications\ApplicationService;
use YouthSync\Assistance\AssistanceService;
use YouthSync\Attendance\AttendanceService;
use YouthSync\Auth\AuthService;
use YouthSync\Auth\PasswordResetService;
use YouthSync\Auth\YouthSignupService;
use YouthSync\Controllers\ApplicationController;
use YouthSync\Controllers\AssistanceController;
use YouthSync\Controllers\AttendanceController;
use YouthSync\Controllers\AdminController;
use YouthSync\Controllers\AuthController;
use YouthSync\Controllers\CronController;
use YouthSync\Controllers\DashboardController;
use YouthSync\Admin\AdminService;
use YouthSync\Controllers\NotificationController;
use YouthSync\Controllers\OrganizationController;
use YouthSync\Controllers\PaymentController;
use YouthSync\Controllers\ProgramController;
use YouthSync\Controllers\SubscriptionController;
use YouthSync\Controllers\UserController;
use YouthSync\Controllers\YouthController;
use YouthSync\Controllers\YouthPortalController;
use YouthSync\Infra\SchemaPatches;
use YouthSync\Notifications\EmailService;
use YouthSync\Notifications\NotificationService;
use YouthSync\Notifications\SmsService;
use YouthSync\Subscription\SubscriptionAdminService;
use YouthSync\Subscription\SubscriptionService;
use YouthSync\Dashboard\DashboardService;
use YouthSync\Http\AdminGuard;
use YouthSync\Http\Json;
use YouthSync\Http\Router;
use YouthSync\Schedule\Scheduler;
use YouthSync\Http\SessionCookies;
use YouthSync\Http\SkGuard;
use YouthSync\Http\YouthGuard;
use YouthSync\Middleware\AuthMiddleware;
use YouthSync\Middleware\OrganizationMiddleware;
use YouthSync\Middleware\RoleMiddleware;
use YouthSync\Org\OrganizationProfileService;
use YouthSync\Org\RegistrationService;
use YouthSync\Payments\PaymentService;
use YouthSync\Org\Tenant;
use YouthSync\Programs\ProgramService;
use YouthSync\Users\UserService;
use YouthSync\Youth\YouthPortalService;
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
ini_set('session.cookie_samesite', (string) $sessionParams['samesite']);
ini_set('session.cookie_secure', !empty($sessionParams['secure']) ? '1' : '0');

$sessionName = (string) $config['session_name'];
$hasSessionCookie = isset($_COOKIE[$sessionName]) && is_string($_COOKIE[$sessionName]) && $_COOKIE[$sessionName] !== '';
$hasRememberCookie = SessionCookies::token() !== '';
$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$isAnonymousMe = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && (str_ends_with($requestPath, '/auth/me') || str_ends_with($requestPath, '/auth/me/'))
    && !$hasSessionCookie
    && !$hasRememberCookie;
$publicAuthPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (
    str_ends_with($requestPath, '/auth/register')
    || str_ends_with($requestPath, '/auth/register/')
    || str_ends_with($requestPath, '/org/register')
    || str_ends_with($requestPath, '/org/register/')
    || str_ends_with($requestPath, '/auth/forgot-password')
    || str_ends_with($requestPath, '/auth/forgot-password/')
    || str_ends_with($requestPath, '/auth/reset-password')
    || str_ends_with($requestPath, '/auth/reset-password/')
    || str_ends_with($requestPath, '/auth/youth-signup')
    || str_ends_with($requestPath, '/auth/youth-signup/')
    || str_ends_with($requestPath, '/auth/youth-signup/resend')
    || str_ends_with($requestPath, '/auth/youth-signup/cancel')
);

// The PayMongo webhook is server-to-server: no session, no remember-me cookie.
// It is authenticated only by its HMAC signature (PaymentService::handleWebhook).
$paymentWebhook = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (
    str_ends_with($requestPath, '/payments/paymongo/webhook')
    || str_ends_with($requestPath, '/payments/paymongo/webhook/')
);
if ($paymentWebhook) {
    $publicAuthPost = true;
}

// GET /auth/me on the login page must not mint a new PHP session cookie.
// React StrictMode aborts the first bootstrap fetch but the request still
// completes; that late Set-Cookie would overwrite the cookie from POST /auth/login.
// Public registration must not mint a session or sign the applicant in.
if (!$isAnonymousMe && !$publicAuthPost && session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $pdo = youthsync_pdo();
} catch (PDOException $e) {
    @file_put_contents($logFile, sprintf("[%s] DB connection failed: %s\n", date('c'), $e->getMessage()), FILE_APPEND);
    error_log('DB connection failed: ' . $e->getMessage());
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
if (!$publicAuthPost) {
    $auth->resumeRememberedSession();
}
$authMiddleware = new AuthMiddleware($auth);
$roleMiddleware = new RoleMiddleware();
$organizationMiddleware = new OrganizationMiddleware($tenant);
$guard = new SkGuard($authMiddleware, $roleMiddleware, $organizationMiddleware);
$youthService = new YouthService(
    $pdo,
    new EmailService(
        (string) ($config['brevo_api_key'] ?? ''),
        (string) ($config['brevo_from_email'] ?? ''),
        (string) ($config['brevo_from_name'] ?? 'YouthSync'),
        (string) ($config['brevo_base_url'] ?? 'https://api.brevo.com')
    )
);

$controller = new AuthController(
    $auth,
    $authMiddleware,
    $roleMiddleware,
    $organizationMiddleware,
    new RegistrationService($pdo),
    new PasswordResetService(
        $pdo,
        new EmailService(
            (string) ($config['brevo_api_key'] ?? ''),
            (string) ($config['brevo_from_email'] ?? ''),
            (string) ($config['brevo_from_name'] ?? 'YouthSync'),
            (string) ($config['brevo_base_url'] ?? 'https://api.brevo.com')
        ),
        $logFile
    ),
    new YouthSignupService(
        $pdo,
        $youthService,
        $auth,
        new EmailService(
            (string) ($config['brevo_api_key'] ?? ''),
            (string) ($config['brevo_from_email'] ?? ''),
            (string) ($config['brevo_from_name'] ?? 'YouthSync'),
            (string) ($config['brevo_base_url'] ?? 'https://api.brevo.com')
        ),
        new SmsService(
            (string) ($config['semaphore_api_key'] ?? ''),
            (string) ($config['semaphore_sender_name'] ?? ''),
            (string) ($config['semaphore_base_url'] ?? 'https://api.semaphore.co')
        ),
        strtolower((string) ($config['env'] ?? 'development')) !== 'production'
    ),
);
$youthController = new YouthController($guard, $youthService);
$attendanceService = new AttendanceService($pdo);
$attendanceController = new AttendanceController($guard, $attendanceService);
$emailService = new EmailService(
    (string) ($config['brevo_api_key'] ?? ''),
    (string) ($config['brevo_from_email'] ?? ''),
    (string) ($config['brevo_from_name'] ?? 'YouthSync'),
    (string) ($config['brevo_base_url'] ?? 'https://api.brevo.com')
);
$notificationService = new NotificationService(
    $pdo,
    new SmsService(
        (string) ($config['semaphore_api_key'] ?? ''),
        (string) ($config['semaphore_sender_name'] ?? ''),
        (string) ($config['semaphore_base_url'] ?? 'https://api.semaphore.co')
    ),
    $emailService
);
$notificationController = new NotificationController($guard, $notificationService);
$assistanceService = new AssistanceService($pdo, $notificationService, $emailService);
$assistanceController = new AssistanceController($guard, $assistanceService);
$dashboardController = new DashboardController($guard, new DashboardService($pdo));
$subscriptionController = new SubscriptionController($guard, new SubscriptionService($pdo));
$paymentController = new PaymentController(
    $guard,
    new AdminGuard($authMiddleware),
    new PaymentService($pdo, (array) ($config['paymongo'] ?? []), new SubscriptionService($pdo), $logFile),
);
$userController = new UserController($guard, new UserService($pdo));
$applicationService = new ApplicationService($pdo, $assistanceService, $notificationService);
$applicationController = new ApplicationController($guard, $applicationService);
$programService = new ProgramService($pdo, $notificationService, $emailService);
$programController = new ProgramController($guard, $programService);
$youthPortal = new YouthPortalController(
    new YouthGuard($authMiddleware),
    $auth,
    new YouthPortalService($pdo, $programService, $assistanceService, $applicationService, $notificationService, $youthService, $attendanceService)
);
$adminController = new AdminController(
    new AdminGuard($authMiddleware),
    new AdminService($pdo),
    new SubscriptionAdminService($pdo, new SubscriptionService($pdo))
);
$organizationController = new OrganizationController($guard, new OrganizationProfileService($pdo));

$router = new Router();
$cronController = new CronController(new Scheduler($pdo), (string) ($config['cron_secret'] ?? ''));
$router->add('POST', '/cron/run', [$cronController, 'run']);
$router->add('POST', '/auth/login', [$controller, 'login']);
$router->add('GET', '/auth/youth-organizations', [$controller, 'youthOrganizations']);
$router->add('POST', '/auth/youth-signup', [$controller, 'youthSignupStart']);
$router->add('POST', '/auth/youth-signup/resend', [$controller, 'youthSignupResend']);
$router->add('POST', '/auth/youth-signup/cancel', [$controller, 'youthSignupCancel']);
$router->add('POST', '/auth/youth-signup/verify', [$controller, 'youthSignupVerify']);
$router->add('POST', '/auth/register', [$controller, 'register']);
$router->add('POST', '/org/register', [$controller, 'register']);
$router->add('POST', '/auth/logout', [$controller, 'logout']);
$router->add('GET', '/auth/me', [$controller, 'me']);
$router->add('POST', '/auth/change-password', [$controller, 'changePassword']);
$router->add('POST', '/auth/forgot-password', [$controller, 'forgotPassword']);
$router->add('POST', '/auth/reset-password', [$controller, 'resetPassword']);
$router->add('GET', '/youth/profile', [$youthPortal, 'profile']);
$router->add('PATCH', '/youth/profile', [$youthPortal, 'updateProfile']);
$router->add('POST', '/youth/profile/photo', [$youthPortal, 'uploadPhoto']);
$router->add('GET', '/youth/profile/photo', [$youthPortal, 'photo']);
$router->add('DELETE', '/youth/profile/photo', [$youthPortal, 'deletePhoto']);
$router->add('GET', '/youth/programs', [$youthPortal, 'programs']);
$router->add('POST', '/youth/programs/register', [$youthPortal, 'registerByCode']);
$router->add('GET', '/youth/programs/{id}', [$youthPortal, 'program']);
$router->add('POST', '/youth/programs/{id}/invitation', [$youthPortal, 'respondProgramInvitation']);
$router->add('GET', '/youth/assistance', [$youthPortal, 'assistance']);
$router->add('GET', '/youth/assistance/{id}', [$youthPortal, 'assistanceItem']);
$router->add('POST', '/youth/assistance/{id}/invitation', [$youthPortal, 'respondInvitation']);
$router->add('GET', '/youth/attendance', [$youthPortal, 'attendance']);
$router->add('POST', '/youth/attendance/check-in', [$youthPortal, 'checkIn']);
$router->add('GET', '/youth/applications', [$youthPortal, 'applications']);
$router->add('POST', '/youth/applications', [$youthPortal, 'createApplication']);
$router->add('GET', '/youth/applications/{id}', [$youthPortal, 'application']);
$router->add('POST', '/youth/applications/{id}/resubmit', [$youthPortal, 'resubmitApplication']);
$router->add('POST', '/youth/applications/{id}/requirements/{requirementId}/file', [$youthPortal, 'uploadRequirementFile']);
$router->add('GET', '/youth/applications/{id}/requirements/{requirementId}/file', [$youthPortal, 'requirementFile']);
$router->add('DELETE', '/youth/applications/{id}/requirements/{requirementId}/file', [$youthPortal, 'deleteRequirementFile']);
$router->add('GET', '/youth/notifications', [$youthPortal, 'notifications']);
$router->add('POST', '/youth/notifications/read-all', [$youthPortal, 'markAllNotificationsRead']);
$router->add('POST', '/youth/notifications/{id}/read', [$youthPortal, 'markNotificationRead']);
$router->add('DELETE', '/youth/notifications/{id}', [$youthPortal, 'deleteNotification']);

$router->add('GET', '/admin/dashboard', [$adminController, 'dashboard']);
$router->add('GET', '/admin/organizations', [$adminController, 'organizations']);
$router->add('POST', '/admin/organizations', [$adminController, 'storeOrganization']);
$router->add('GET', '/admin/organizations/{id}', [$adminController, 'showOrganization']);
$router->add('POST', '/admin/organizations/{id}/approve', [$adminController, 'approveOrganization']);
$router->add('POST', '/admin/organizations/{id}/status', [$adminController, 'setOrganizationStatus']);
$router->add('GET', '/admin/organizations/{id}/activity', [$adminController, 'organizationActivity']);
$router->add('GET', '/admin/organizations/{id}/subscription', [$adminController, 'organizationSubscription']);
$router->add('POST', '/admin/organizations/{id}/subscription/plan', [$adminController, 'changeOrganizationPlan']);
$router->add('POST', '/admin/organizations/{id}/subscription/extend', [$adminController, 'extendOrganizationSubscription']);
$router->add('POST', '/admin/organizations/{id}/subscription/cancel', [$adminController, 'cancelOrganizationSubscription']);
$router->add('GET', '/admin/plans', [$adminController, 'plans']);
$router->add('POST', '/admin/subscriptions/run-cycle', [$adminController, 'runSubscriptionCycle']);
$router->add('GET', '/admin/activity', [$adminController, 'activity']);
$router->add('GET', '/admin/errors', [$adminController, 'errors']);
$router->add('GET', '/admin/backups', [$adminController, 'backups']);
$router->add('POST', '/admin/backups', [$adminController, 'createBackup']);
$router->add('GET', '/admin/backups/download', [$adminController, 'downloadBackup']);
$router->add('GET', '/admin/payment-transactions', [$paymentController, 'adminTransactions']);
$router->add('POST', '/admin/payment-transactions/reconcile', [$paymentController, 'adminReconcileByReference']);
$router->add('POST', '/admin/payment-transactions/{id}/reconcile', [$paymentController, 'adminReconcile']);
$router->add('GET', '/admin/users', [$adminController, 'users']);
$router->add('POST', '/admin/users/{id}/status', [$adminController, 'setUserStatus']);
$router->add('POST', '/admin/users/{id}/reset-password', [$adminController, 'resetUserPassword']);

$router->add('GET', '/sk/youth', [$youthController, 'index']);
$router->add('POST', '/sk/youth', [$youthController, 'store']);
$router->add('POST', '/sk/youth/import', [$youthController, 'import']);
$router->add('POST', '/sk/youth/import/preview', [$youthController, 'importPreview']);
$router->add('GET', '/sk/youth/{id}', [$youthController, 'show']);
$router->add('PUT', '/sk/youth/{id}', [$youthController, 'update']);
$router->add('PATCH', '/sk/youth/{id}', [$youthController, 'update']);
$router->add('DELETE', '/sk/youth/{id}', [$youthController, 'destroy']);
$router->add('POST', '/sk/youth/{id}/archive', [$youthController, 'archive']);
$router->add('POST', '/sk/youth/{id}/restore', [$youthController, 'restore']);
$router->add('POST', '/sk/youth/{id}/photo', [$youthController, 'uploadPhoto']);
$router->add('GET', '/sk/youth/{id}/photo', [$youthController, 'photo']);
$router->add('DELETE', '/sk/youth/{id}/photo', [$youthController, 'deletePhoto']);
$router->add('POST', '/sk/youth/{id}/document', [$youthController, 'uploadDocument']);
$router->add('GET', '/sk/youth/{id}/document', [$youthController, 'document']);
$router->add('DELETE', '/sk/youth/{id}/document', [$youthController, 'deleteDocument']);

$router->add('GET', '/sk/programs', [$programController, 'index']);
$router->add('POST', '/sk/programs', [$programController, 'store']);
$router->add('GET', '/sk/programs/{id}', [$programController, 'show']);
$router->add('PUT', '/sk/programs/{id}', [$programController, 'update']);
$router->add('PATCH', '/sk/programs/{id}', [$programController, 'update']);
$router->add('DELETE', '/sk/programs/{id}', [$programController, 'destroy']);
$router->add('POST', '/sk/programs/{id}/status', [$programController, 'setStatus']);
$router->add('POST', '/sk/programs/{id}/invitations', [$programController, 'invite']);
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
$router->add('POST', '/sk/assistance/{id}/invitations', [$assistanceController, 'invite']);
$router->add('POST', '/sk/assistance/{id}/beneficiaries', [$assistanceController, 'storeBeneficiary']);
$router->add('DELETE', '/sk/beneficiaries/{id}', [$assistanceController, 'destroyBeneficiary']);
$router->add('POST', '/sk/requirements', [$assistanceController, 'storeRequirement']);
$router->add('PATCH', '/sk/requirements/{id}', [$assistanceController, 'updateRequirement']);
$router->add('DELETE', '/sk/requirements/{id}', [$assistanceController, 'destroyRequirement']);

$router->add('GET', '/sk/applications', [$applicationController, 'index']);
$router->add('POST', '/sk/applications', [$applicationController, 'store']);
$router->add('GET', '/sk/applications/{id}', [$applicationController, 'show']);
$router->add('GET', '/sk/applications/{id}/requirements/{requirementId}/file', [$applicationController, 'requirementFile']);
$router->add('PATCH', '/sk/applications/{id}', [$applicationController, 'update']);
$router->add('POST', '/sk/applications/{id}/status', [$applicationController, 'setStatus']);
$router->add('POST', '/sk/applications/{id}/approve', [$applicationController, 'approve']);
$router->add('POST', '/sk/applications/{id}/reject', [$applicationController, 'reject']);
$router->add('PATCH', '/sk/application-requirements/{id}', [$applicationController, 'reviewRequirement']);
$router->add('POST', '/sk/submissions/{id}/review', [$applicationController, 'reviewRequirement']);

$router->add('GET', '/sk/notifications', [$notificationController, 'index']);
$router->add('GET', '/sk/deliveries/sms', [$notificationController, 'smsDeliveries']);
$router->add('GET', '/sk/deliveries/email', [$notificationController, 'emailDeliveries']);
$router->add('POST', '/sk/notifications/read-all', [$notificationController, 'markAllRead']);
$router->add('GET', '/sk/notifications/{id}', [$notificationController, 'show']);
$router->add('PATCH', '/sk/notifications/{id}/read', [$notificationController, 'markRead']);
$router->add('POST', '/sk/notifications/{id}/read', [$notificationController, 'markRead']);
$router->add('DELETE', '/sk/notifications/{id}', [$notificationController, 'destroy']);

$router->add('GET', '/sk/dashboard', [$dashboardController, 'dashboard']);
$router->add('GET', '/sk/activity', [$dashboardController, 'activity']);
$router->add('GET', '/sk/reports', [$dashboardController, 'reports']);
$router->add('GET', '/sk/reports/youth', [$dashboardController, 'reportYouth']);
$router->add('GET', '/sk/reports/programs', [$dashboardController, 'reportPrograms']);
$router->add('GET', '/sk/reports/assistance', [$dashboardController, 'reportAssistance']);
$router->add('GET', '/sk/reports/applications', [$dashboardController, 'reportApplications']);
$router->add('GET', '/sk/reports/attendance', [$dashboardController, 'reportAttendance']);
foreach (['youth', 'programs', 'assistance', 'applications', 'attendance'] as $reportType) {
    foreach (['csv', 'xlsx'] as $reportFormat) {
        $router->add(
            'GET',
            "/sk/reports/{$reportType}/{$reportFormat}",
            static function (array $params = []) use ($dashboardController, $reportType, $reportFormat): void {
                $dashboardController->reportExport($reportType, $reportFormat);
            }
        );
    }
}

$router->add('GET', '/sk/organization', [$organizationController, 'show']);
$router->add('PATCH', '/sk/organization', [$organizationController, 'update']);

$router->add('GET', '/sk/subscription/usage', [$subscriptionController, 'usage']);
$router->add('GET', '/sk/subscription', [$subscriptionController, 'show']);
$router->add('GET', '/sk/subscription/payment-options', [$paymentController, 'options']);
$router->add('GET', '/sk/subscription/checkouts', [$paymentController, 'myCheckouts']);
$router->add('POST', '/sk/subscription/checkout', [$paymentController, 'createCheckout']);
$router->add('GET', '/sk/subscription/checkout/{id}', [$paymentController, 'showCheckout']);
$router->add('POST', '/sk/subscription/checkout/{id}/cancel', [$paymentController, 'cancelCheckout']);
$router->add('POST', '/payments/paymongo/webhook', [$paymentController, 'webhook']);

$router->add('GET', '/sk/account', [$userController, 'account']);
$router->add('PATCH', '/sk/account', [$userController, 'updateAccount']);

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
