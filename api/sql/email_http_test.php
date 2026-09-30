<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$config = require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'YouthSync\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use YouthSync\Applications\ApplicationService;
use YouthSync\Assistance\AssistanceService;
use YouthSync\Infra\SchemaPatches;
use YouthSync\Notifications\EmailService;
use YouthSync\Notifications\EmailTransport;
use YouthSync\Notifications\NotificationService;
use YouthSync\Notifications\SmsService;
use YouthSync\Notifications\SmsTransport;

require dirname(__DIR__) . '/src/Notifications/SmsService.php';
require dirname(__DIR__) . '/src/Notifications/EmailService.php';

final class FakeSmsTransport implements SmsTransport
{
    public int $calls = 0;

    public function post(string $url, array $fields): array
    {
        $this->calls++;
        return ['httpStatus' => 200, 'body' => '[{"message_id":"sms-fake-1"}]'];
    }
}

final class FakeEmailTransport implements EmailTransport
{
    public int $calls = 0;
    public int $httpStatus = 200;
    public string $body = '{"id":"email-1"}';
    public bool $throw = false;
    public string $lastUrl = '';
    /** @var array<string, string> */
    public array $lastHeaders = [];
    public string $lastJson = '';

    public function post(string $url, array $headers, string $jsonBody): array
    {
        $this->calls++;
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastJson = $jsonBody;
        if ($this->throw) {
            throw new RuntimeException('network down');
        }
        if (str_contains($url, 're_secret') || str_contains($url, 'secret-key')) {
            throw new RuntimeException('api key leaked into url');
        }
        return ['httpStatus' => $this->httpStatus, 'body' => $this->body];
    }
}

$failed = [];
$check = static function (string $name, bool $ok) use (&$failed): void {
    echo $name . '=' . ($ok ? 'ok' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $name;
    }
};

$check(
    'config_keys',
    isset($config['resend_api_key'], $config['resend_from_email'], $config['resend_from_name'], $config['resend_base_url'])
);
$check(
    'config_base_default',
    $config['resend_base_url'] === '' || str_starts_with((string) $config['resend_base_url'], 'https://')
);
$check('config_key_is_string', is_string($config['resend_api_key']));
$check('config_from_name_default', (string) $config['resend_from_name'] === 'YouthSync' || (string) $config['resend_from_name'] !== '');

$missing = new EmailService('', 'noreply@example.test', 'YouthSync', 'https://api.resend.com');
$missingResult = $missing->send('youth@example.test', 'Hello', '<p>Hi</p>', 'Hi');
$check(
    'missing_api_key',
    $missing->isConfigured() === false
        && $missingResult['ok'] === false
        && $missingResult['error'] === 'Email provider is not configured.'
);

$noFrom = new EmailService('re_secret', '', 'YouthSync');
$check('missing_from_email', $noFrom->isConfigured() === false);

$badEmail = new EmailService('re_secret', 'noreply@example.test');
$badEmailResult = $badEmail->send('not-an-email', 'Hello', '<p>Hi</p>', 'Hi');
$check(
    'invalid_recipient_email',
    $badEmailResult['ok'] === false && str_contains((string) $badEmailResult['error'], 'email')
);

$fake = new FakeEmailTransport();
$okMail = new EmailService('re_secret', 'noreply@example.test', 'YouthSync', 'https://api.resend.com', $fake);
$okResult = $okMail->send('Youth@Example.TEST', 'Application submitted', '<p>Submitted</p>', 'Submitted');
$check(
    'email_success',
    $okResult['ok'] === true && $okResult['providerReference'] === 'email-1' && $fake->calls === 1
);
$check('success_hides_key', !str_contains((string) json_encode($okResult), 're_secret'));
$check(
    'authorization_not_in_url',
    str_contains($fake->lastUrl, '/emails') && !str_contains($fake->lastUrl, 're_secret')
);
$check(
    'payload_has_no_key',
    !str_contains($fake->lastJson, 're_secret') && str_contains($fake->lastJson, 'youth@example.test')
);

$fake->httpStatus = 422;
$fake->body = '{"message":"re_secret leaked"}';
$failResult = $okMail->send('youth@example.test', 'nope', '<p>x</p>', 'x');
$check(
    'email_api_failure',
    $failResult['ok'] === false
        && $failResult['status'] === 'failed'
        && !str_contains((string) json_encode($failResult), 're_secret')
);

$fake->httpStatus = 200;
$fake->throw = true;
$down = $okMail->send('youth@example.test', 'down', '<p>x</p>', 'x');
$check('email_unavailable', $down['ok'] === false && $down['error'] === 'Email provider is unavailable.');

$pdo = youthsync_pdo();
SchemaPatches::apply($pdo);

$leftoverY = $pdo->query(
    "SELECT id FROM youth WHERE first_name = 'EmailHttp' AND last_name LIKE 'Test%'"
)->fetchAll();
$leftoverA = $pdo->query(
    "SELECT id FROM assistance_programs WHERE name LIKE 'EmailHttp Test%'"
)->fetchAll();
if ($leftoverY || $leftoverA) {
    fwrite(STDERR, "STOP: pre-existing EmailHttp test records found. No cleanup was performed.\n");
    exit(2);
}

$fake->throw = false;
$fake->httpStatus = 200;
$fake->body = '{"id":"email-9"}';
$fake->calls = 0;
$smsFake = new FakeSmsTransport();
$notes = new NotificationService(
    $pdo,
    new SmsService('sms-secret', 'YOUTHSYNC', 'https://api.semaphore.co', $smsFake),
    new EmailService('re_secret', 'noreply@example.test', 'YouthSync', 'https://api.resend.com', $fake)
);

$eventId = 880001;
$pdo->prepare('DELETE FROM email_deliveries WHERE organization_id IN (1, 2) AND event_id = :id')->execute(['id' => $eventId]);
$pdo->prepare('DELETE FROM sms_deliveries WHERE organization_id IN (1, 2) AND event_id = :id')->execute(['id' => $eventId]);

$unconfigured = new NotificationService(
    $pdo,
    new SmsService('sms-secret', 'YOUTHSYNC', 'https://api.semaphore.co', $smsFake),
    new EmailService('', 'noreply@example.test', 'YouthSync', 'https://api.resend.com', $fake)
);
$skipCfg = $unconfigured->deliverEmailOnce(1, 'application.submitted', $eventId, 'youth@example.test', 'Title', 'Message');
$skipStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM email_deliveries WHERE organization_id = 1 AND event_code = :code AND event_id = :id'
);
$skipStmt->execute(['code' => 'application.submitted', 'id' => $eventId]);
$check(
    'unconfigured_skips_without_row',
    ($skipCfg['status'] ?? '') === 'skipped' && (int) $skipStmt->fetchColumn() === 0
);

$first = $notes->deliverEmailOnce(1, 'application.submitted', $eventId, 'Youth@Example.TEST', 'Submitted', 'Your application was submitted.', 'Aid');
$second = $notes->deliverEmailOnce(1, 'application.submitted', $eventId, 'youth@example.test', 'Submitted again', 'Different text must not resend.', 'Aid');
$rowStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM email_deliveries WHERE organization_id = 1 AND event_code = :code AND event_id = :id'
);
$rowStmt->execute(['code' => 'application.submitted', 'id' => $eventId]);
$rowCount = (int) $rowStmt->fetchColumn();
$check(
    'delivery_recorded',
    ($first['ok'] ?? false) === true && ($first['status'] ?? '') === 'sent' && $rowCount === 1
);
$check('duplicate_skipped', ($second['duplicate'] ?? false) === true && $fake->calls === 1);

$otherOrg = $notes->deliverEmailOnce(2, 'application.submitted', $eventId, 'youth@example.test', 'Submitted', 'Org two', 'Aid');
$isoStmt = $pdo->prepare(
    'SELECT organization_id FROM email_deliveries WHERE event_code = :code AND event_id = :id ORDER BY organization_id'
);
$isoStmt->execute(['code' => 'application.submitted', 'id' => $eventId]);
$orgs = array_map('intval', $isoStmt->fetchAll(PDO::FETCH_COLUMN));
$check(
    'organization_isolation',
    ($otherOrg['ok'] ?? false) === true && $orgs === [1, 2]
);

$skipped = $notes->deliverEmailOnce(1, 'application.approved', $eventId, '', 'Approved', 'Approved');
$blankStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM email_deliveries WHERE organization_id = 1 AND event_code = 'application.approved' AND event_id = :id"
);
$blankStmt->execute(['id' => $eventId]);
$check('blank_email_no_row', ($skipped['status'] ?? '') === 'skipped' && (int) $blankStmt->fetchColumn() === 0);

$fake->httpStatus = 500;
$fake->body = '{"error":"re_secret"}';
$failOnce = $notes->deliverEmailOnce(1, 'application.rejected', $eventId, 'fail@example.test', 'Rejected', 'Not approved');
$failRow = $pdo->prepare(
    "SELECT status, error_message FROM email_deliveries WHERE organization_id = 1 AND event_code = 'application.rejected' AND event_id = :id"
);
$failRow->execute(['id' => $eventId]);
$failStored = $failRow->fetch();
$check(
    'failed_delivery_recorded',
    ($failOnce['status'] ?? '') === 'failed'
        && is_array($failStored)
        && ($failStored['status'] ?? '') === 'failed'
        && !str_contains((string) ($failStored['error_message'] ?? ''), 're_secret')
);
$fake->httpStatus = 200;
$fake->body = '{"id":"email-app"}';

$smsBeforeApp = $smsFake->calls;
$emailBeforeApp = $fake->calls;

$pdo->prepare(
    "INSERT INTO youth (
        organization_id, code, first_name, last_name, birth_date, address, contact, email, interests
    ) VALUES (
        1, 'YTH-EML1', 'EmailHttp', 'TestA', '2005-06-01', 'EmailHttp address', '09178881101',
        'youth.emailhttp.a@example.test', '[\"Education & training\"]'
    )"
)->execute();
$youthId = (int) $pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO youth (
        organization_id, code, first_name, last_name, birth_date, address, contact, email, interests
    ) VALUES (
        1, 'YTH-EML2', 'EmailHttp', 'TestB', '2004-06-01', 'EmailHttp address', '09178881102',
        NULL, '[\"Education & training\"]'
    )"
)->execute();
$youthNoEmail = (int) $pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO assistance_programs (organization_id, name, category, status, slots, deadline)
     VALUES (1, 'EmailHttp Test Scholarship', 'scholarship', 'open', 10, '2027-12-31')"
)->execute();
$assistId = (int) $pdo->lastInsertId();

$apps = new ApplicationService($pdo, new AssistanceService($pdo), $notes);
$created = $apps->create(1, [
    'assistanceId' => $assistId,
    'youthId' => $youthId,
    'remarks' => 'EmailHttp apply A',
    'email' => 'attacker@evil.test',
]);
$appId = (int) ($created['id'] ?? 0);
$check('application_created', $appId > 0 && ($created['status'] ?? '') === 'pending');

$recipientStmt = $pdo->prepare(
    "SELECT event_code, recipient_email FROM email_deliveries WHERE organization_id = 1 AND event_id = :id ORDER BY event_code"
);
$recipientStmt->execute(['id' => $appId]);
$recipients = $recipientStmt->fetchAll();
$byCode = [];
foreach ($recipients as $row) {
    $byCode[$row['event_code']] = $row['recipient_email'];
}
$orgEmailStmt = $pdo->query('SELECT email FROM organizations WHERE id = 1');
$orgEmail = strtolower((string) $orgEmailStmt->fetchColumn());
$check(
    'correct_recipients',
    ($byCode['application.submitted'] ?? '') === 'youth.emailhttp.a@example.test'
        && ($byCode['application.received'] ?? '') === $orgEmail
        && !in_array('attacker@evil.test', $byCode, true)
);

$check(
    'sms_still_sent_on_create',
    $smsFake->calls === $smsBeforeApp + 2
);
$check(
    'email_sent_on_create',
    $fake->calls === $emailBeforeApp + 2
);

$noteCount = $pdo->prepare(
    'SELECT COUNT(*) FROM notifications WHERE related_entity_type = :type AND related_entity_id = :id AND organization_id = 1'
);
$noteCount->execute(['type' => 'application', 'id' => $appId]);
$check('in_app_notification_still_created', (int) $noteCount->fetchColumn() >= 1);

$approved = $apps->setStatus(1, $appId, 1, ['status' => 'approved', 'remarks' => 'EmailHttp approved']);
$check('application_approved', ($approved['status'] ?? '') === 'approved');
$approveStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM email_deliveries WHERE organization_id = 1 AND event_code = 'application.approved' AND event_id = :id AND recipient_email = :email"
);
$approveStmt->execute(['id' => $appId, 'email' => 'youth.emailhttp.a@example.test']);
$check('approved_email_recorded', (int) $approveStmt->fetchColumn() === 1);

$emailAfterApprove = $fake->calls;
$smsAfterApprove = $smsFake->calls;
$apps->setStatus(1, $appId, 1, ['status' => 'approved', 'remarks' => 'EmailHttp approved again']);
$approveAgainStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM email_deliveries WHERE organization_id = 1 AND event_code = 'application.approved' AND event_id = :id"
);
$approveAgainStmt->execute(['id' => $appId]);
$check(
    'duplicate_application_event_no_resend',
    (int) $approveAgainStmt->fetchColumn() === 1
        && $fake->calls === $emailAfterApprove
        && $smsFake->calls === $smsAfterApprove
);

$noEmailApp = $apps->create(1, [
    'assistanceId' => $assistId,
    'youthId' => $youthNoEmail,
    'remarks' => 'EmailHttp apply B',
]);
$appB = (int) ($noEmailApp['id'] ?? 0);
$noYouthEmailStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM email_deliveries WHERE organization_id = 1 AND event_code = 'application.submitted' AND event_id = :id"
);
$noYouthEmailStmt->execute(['id' => $appB]);
$orgReceivedB = $pdo->prepare(
    "SELECT COUNT(*) FROM email_deliveries WHERE organization_id = 1 AND event_code = 'application.received' AND event_id = :id"
);
$orgReceivedB->execute(['id' => $appB]);
$check(
    'youth_without_email_skips_submitted',
    $appB > 0 && (int) $noYouthEmailStmt->fetchColumn() === 0 && (int) $orgReceivedB->fetchColumn() === 1
);

$rejected = $apps->setStatus(1, $appB, 1, ['status' => 'rejected', 'remarks' => 'EmailHttp rejected']);
$check('application_rejected_without_youth_email', ($rejected['status'] ?? '') === 'rejected');

$createdInbox = $notes->notifySk(1, [
    'type' => 'applications',
    'title' => 'EmailTest in-app still works',
    'message' => 'inbox row',
]);
$check('notification_still_created', (int) ($createdInbox['id'] ?? 0) > 0);

$pdo->prepare(
    "INSERT INTO assistance_programs (organization_id, name, category, status, slots, deadline)
     VALUES (1, 'EmailHttp Test Aid', 'financial', 'open', 10, '2027-12-31')"
)->execute();
$assistResub = (int) $pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO youth (
        organization_id, code, first_name, last_name, birth_date, address, contact, email, interests
    ) VALUES (
        1, 'YTH-EML3', 'EmailHttp', 'TestC', '2003-06-01', 'EmailHttp address', '09178881103',
        'youth.emailhttp.c@example.test', '[\"Education & training\"]'
    )"
)->execute();
$youthC = (int) $pdo->lastInsertId();
$appCRow = $apps->create(1, [
    'assistanceId' => $assistResub,
    'youthId' => $youthC,
    'remarks' => 'EmailHttp apply C',
]);
$appC = (int) ($appCRow['id'] ?? 0);
$resub = $apps->setStatus(1, $appC, 1, ['status' => 'needs_resubmission', 'remarks' => 'replace file']);
$resubStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM email_deliveries WHERE organization_id = 1 AND event_code = 'application.needs_resubmission' AND event_id = :id AND recipient_email = :email"
);
$resubStmt->execute(['id' => $appC, 'email' => 'youth.emailhttp.c@example.test']);
$check(
    'needs_resubmission_email',
    ($resub['status'] ?? '') === 'needs_resubmission' && (int) $resubStmt->fetchColumn() === 1
);

$ids = array_filter([$appId, $appB, $appC]);
if ($ids) {
    $in = implode(',', array_map('intval', $ids));
    $pdo->exec("DELETE FROM email_deliveries WHERE organization_id = 1 AND event_id IN ({$in})");
    $pdo->exec("DELETE FROM sms_deliveries WHERE organization_id = 1 AND event_id IN ({$in})");
    $pdo->exec("DELETE FROM notifications WHERE organization_id = 1 AND related_entity_type = 'application' AND related_entity_id IN ({$in})");
    $pdo->exec("DELETE FROM application_submissions WHERE organization_id = 1 AND application_id IN ({$in})");
    $pdo->exec("DELETE FROM applications WHERE organization_id = 1 AND id IN ({$in})");
    $pdo->exec("DELETE FROM beneficiaries WHERE organization_id = 1 AND youth_id IN ({$youthId}, {$youthNoEmail}, {$youthC})");
}
$pdo->prepare('DELETE FROM email_deliveries WHERE organization_id IN (1, 2) AND event_id = :id')->execute(['id' => $eventId]);
$pdo->prepare('DELETE FROM sms_deliveries WHERE organization_id IN (1, 2) AND event_id = :id')->execute(['id' => $eventId]);
if (!empty($createdInbox['id'])) {
    $pdo->prepare('DELETE FROM notifications WHERE id = :id')->execute(['id' => (int) $createdInbox['id']]);
}
$assistIds = array_filter([$assistId, $assistResub]);
if ($assistIds) {
    $ain = implode(',', array_map('intval', $assistIds));
    $pdo->exec("DELETE FROM assistance_requirements WHERE organization_id = 1 AND assistance_id IN ({$ain})");
    $pdo->exec("DELETE FROM assistance_programs WHERE organization_id = 1 AND id IN ({$ain})");
}
$youthIds = array_filter([$youthId, $youthNoEmail, $youthC]);
if ($youthIds) {
    $yin = implode(',', array_map('intval', $youthIds));
    $pdo->exec("DELETE FROM youth WHERE organization_id = 1 AND id IN ({$yin})");
}

if ($failed) {
    echo "RESULT=FAIL\n";
    exit(1);
}
echo "RESULT=PASS\n";
exit(0);
