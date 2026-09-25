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

use YouthSync\Infra\SchemaPatches;
use YouthSync\Notifications\NotificationService;
use YouthSync\Notifications\SmsService;
use YouthSync\Notifications\SmsTransport;

require dirname(__DIR__) . '/src/Notifications/SmsService.php';

final class FakeSmsTransport implements SmsTransport
{
    public int $calls = 0;
    public int $httpStatus = 200;
    public string $body = '[{"message_id":"msg-1"}]';
    public bool $throw = false;

    public function post(string $url, array $fields): array
    {
        $this->calls++;
        if ($this->throw) {
            throw new RuntimeException('network down');
        }
        if (isset($fields['apikey']) && str_contains($url, 'apikey')) {
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

$check('config_keys', isset($config['semaphore_api_key'], $config['semaphore_sender_name'], $config['semaphore_base_url']));
$check('config_base_default', $config['semaphore_base_url'] === '' || str_starts_with((string) $config['semaphore_base_url'], 'https://'));
$check('config_key_is_string', is_string($config['semaphore_api_key']));

$missing = new SmsService('', '', 'https://api.semaphore.co');
$missingResult = $missing->send('09171234567', 'hello');
$check('missing_config', $missingResult['ok'] === false && $missingResult['error'] === 'SMS provider is not configured.');

$badPhone = new SmsService('key', 'YOUTHSYNC');
$badPhoneResult = $badPhone->send('123', 'hello');
$check('missing_phone', $badPhoneResult['ok'] === false && str_contains((string) $badPhoneResult['error'], 'phone'));

$fake = new FakeSmsTransport();
$okSms = new SmsService('secret-key', 'YOUTHSYNC', 'https://api.semaphore.co', $fake);
$okResult = $okSms->send('09171234567', 'Application submitted');
$check('sms_success', $okResult['ok'] === true && $okResult['providerReference'] === 'msg-1' && $fake->calls === 1);
$check('success_hides_key', !str_contains(json_encode($okResult), 'secret-key'));

$fake->httpStatus = 500;
$fake->body = '{"apikey":"secret-key"}';
$failResult = $okSms->send('639171234567', 'nope');
$check('sms_api_failure', $failResult['ok'] === false && $failResult['status'] === 'failed' && !str_contains(json_encode($failResult), 'secret-key'));

$fake->httpStatus = 200;
$fake->throw = true;
$down = $okSms->send('09171234567', 'down');
$check('sms_unavailable', $down['ok'] === false && $down['error'] === 'SMS provider is unavailable.');

$pdo = youthsync_pdo();
SchemaPatches::apply($pdo);
$fake->throw = false;
$fake->httpStatus = 200;
$fake->body = '[{"message_id":"msg-9"}]';
$fake->calls = 0;
$notes = new NotificationService($pdo, new SmsService('secret-key', 'YOUTHSYNC', 'https://api.semaphore.co', $fake));
$eventId = 990001;
$pdo->prepare(
    'DELETE FROM sms_deliveries WHERE organization_id = 1 AND event_id = :id'
)->execute(['id' => $eventId]);

$first = $notes->deliverSmsOnce(1, 'application.submitted', $eventId, '09170001111', 'Your application was submitted.');
$second = $notes->deliverSmsOnce(1, 'application.submitted', $eventId, '09170001111', 'Your application was submitted.');
$rowStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM sms_deliveries WHERE organization_id = 1 AND event_code = :code AND event_id = :id'
);
$rowStmt->execute(['code' => 'application.submitted', 'id' => $eventId]);
$rowCount = (int) $rowStmt->fetchColumn();
$check('delivery_recorded', ($first['ok'] ?? false) === true && ($first['status'] ?? '') === 'sent' && $rowCount === 1);
$check('duplicate_skipped', ($second['duplicate'] ?? false) === true && $fake->calls === 1);

$skipped = $notes->deliverSmsOnce(1, 'application.approved', $eventId, '', 'Approved');
$skipStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM sms_deliveries WHERE organization_id = 1 AND event_code = 'application.approved' AND event_id = :id"
);
$skipStmt->execute(['id' => $eventId]);
$check('blank_phone_no_row', ($skipped['status'] ?? '') === 'skipped' && (int) $skipStmt->fetchColumn() === 0);

$created = $notes->notifySk(1, [
    'type' => 'applications',
    'title' => 'SmsTest in-app still works',
    'message' => 'inbox row',
]);
$check('notification_still_created', (int) ($created['id'] ?? 0) > 0);
if (!empty($created['id'])) {
    $pdo->prepare('DELETE FROM notifications WHERE id = :id')->execute(['id' => (int) $created['id']]);
}
$pdo->prepare('DELETE FROM sms_deliveries WHERE organization_id = 1 AND event_id = :id')->execute(['id' => $eventId]);

if ($failed) {
    echo "RESULT=FAIL\n";
    exit(1);
}
echo "RESULT=PASS\n";
exit(0);
