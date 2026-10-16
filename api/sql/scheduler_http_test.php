<?php
declare(strict_types=1);

/**
 * Scheduler: job table, POST /cron/run, and the CLI runner.
 * A due fixture organization is expired. Every other organization is restored
 * to the plan, sub_status, and expires_at captured before the run.
 */

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

$pdo = youthsync_pdo();
YouthSync\Infra\SchemaPatches::apply($pdo);

$secret = (string) ($config['cron_secret'] ?? '');
if (strlen($secret) < 16) {
    fwrite(STDERR, "STOP: CRON_SECRET is missing or shorter than 16 characters.\n");
    exit(2);
}

$base = rtrim((string) (getenv('YOUTHSYNC_API_BASE') ?: 'http://localhost/YouthSync/api'), '/');
$stamp = date('YmdHis');
$dueEmail = "schedjob-due-{$stamp}@example.test";
$futureEmail = "schedjob-future-{$stamp}@example.test";
$failures = [];

$snapshot = $pdo->query('SELECT id, plan, sub_status, expires_at FROM organizations')->fetchAll(PDO::FETCH_ASSOC);
$restore = static function () use ($pdo, $snapshot, $dueEmail, $futureEmail): void {
    $stmt = $pdo->prepare(
        'UPDATE organizations SET plan = :plan, sub_status = :sub_status, expires_at = :expires_at WHERE id = :id'
    );
    foreach ($snapshot as $row) {
        $stmt->execute([
            'plan' => $row['plan'],
            'sub_status' => $row['sub_status'],
            'expires_at' => $row['expires_at'],
            'id' => $row['id'],
        ]);
    }
    $drop = $pdo->prepare('DELETE FROM organizations WHERE email = :email');
    $drop->execute(['email' => $dueEmail]);
    $drop->execute(['email' => $futureEmail]);
};

function req(string $method, string $url, ?string $json, array $headers, string $cookie = ''): array
{
    $ch = curl_init($url);
    $http = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $http,
    ]);
    if ($cookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    }
    if ($json !== null) {
        $http[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $http);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'body' => $err];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'body' => substr($raw, $headerSize)];
}

function expect(array &$failures, string $name, bool $ok, string $detail = ''): void
{
    if ($ok) {
        echo "PASS {$name}\n";
        return;
    }
    $failures[] = $name;
    echo "FAIL {$name}" . ($detail !== '' ? " {$detail}" : '') . "\n";
}

try {
    $insert = $pdo->prepare(
        "INSERT INTO organizations
            (name, barangay, municipality, province, chairperson, email, contact, status, plan, sub_status, cycle, expires_at)
         VALUES
            (:name, 'Sched', 'Sched', 'Laguna', 'Sched Job', :email, '09170000000', 'active', 'premium', 'trial', 'monthly', :expires)"
    );
    $insert->execute([
        'name' => "SchedJob Due {$stamp}",
        'email' => $dueEmail,
        'expires' => date('Y-m-d', strtotime('-2 days')),
    ]);
    $dueId = (int) $pdo->lastInsertId();
    $insert->execute([
        'name' => "SchedJob Future {$stamp}",
        'email' => $futureEmail,
        'expires' => date('Y-m-d', strtotime('+400 days')),
    ]);
    $futureId = (int) $pdo->lastInsertId();

    $anon = req('POST', $base . '/cron/run', '{}', []);
    $anonBody = json_decode($anon['body'], true);
    expect($failures, 'anonymous_rejected', $anon['status'] === 401 && ($anonBody['error']['code'] ?? '') === 'UNAUTHENTICATED', (string) $anon['status']);

    $wrong = req('POST', $base . '/cron/run', '{}', ['X-Cron-Secret: not-the-secret']);
    expect($failures, 'wrong_secret_rejected', $wrong['status'] === 401, (string) $wrong['status']);

    $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys_sched_sk.txt';
    @unlink($jar);
    $login = req('POST', $base . '/auth/login', json_encode([
        'email' => 'sk1@demo.test',
        'password' => 'YouthSync1!',
    ]), [], $jar);
    $session = req('POST', $base . '/cron/run', '{"organization_id":1,"user_id":1,"role":"SYSTEM_ADMIN"}', [], $jar);
    expect($failures, 'session_without_secret_rejected', $session['status'] === 401, (string) $session['status'] . ' login=' . $login['status']);

    $run = req(
        'POST',
        $base . '/cron/run',
        '{"organization_id":' . $futureId . ',"code":"subscription.expire"}',
        ['X-Cron-Secret: ' . $secret]
    );
    $payload = json_decode($run['body'], true);
    $ran = $payload['data']['ran'] ?? null;
    $codes = [];
    if (is_array($ran)) {
        foreach ($ran as $job) {
            $codes[(string) ($job['code'] ?? '')] = (string) ($job['status'] ?? '');
        }
    }
    expect(
        $failures,
        'cron_runs_due_jobs',
        $run['status'] === 200 && ($codes['subscription.expire'] ?? '') === 'ok' && ($codes['scheduler.heartbeat'] ?? '') === 'ok',
        'status=' . $run['status'] . ' body=' . substr($run['body'], 0, 400)
    );

    $dueStatus = (string) $pdo->query('SELECT sub_status FROM organizations WHERE id = ' . $dueId)->fetchColumn();
    $futureStatus = (string) $pdo->query('SELECT sub_status FROM organizations WHERE id = ' . $futureId)->fetchColumn();
    expect($failures, 'due_fixture_expired', $dueStatus === 'expired', $dueStatus);
    expect($failures, 'future_fixture_unchanged', $futureStatus === 'trial', $futureStatus);
    expect($failures, 'response_has_no_organization_id', !str_contains($run['body'], $dueEmail) && !str_contains($run['body'], $futureEmail));

    $jobs = $pdo->query("SELECT code, last_status FROM scheduled_jobs WHERE code IN ('subscription.expire','scheduler.heartbeat')")->fetchAll(PDO::FETCH_KEY_PAIR);
    expect($failures, 'job_rows_recorded', ($jobs['subscription.expire'] ?? '') === 'ok' && ($jobs['scheduler.heartbeat'] ?? '') === 'ok');

    $cli = shell_exec('C:\\xampp\\php\\php.exe ' . escapeshellarg(dirname(__DIR__) . '/bin/scheduler.php') . ' 2>&1');
    expect($failures, 'cli_idle_when_nothing_due', is_string($cli) && str_contains($cli, 'ran=0'), trim((string) $cli));
} catch (Throwable $e) {
    $failures[] = 'exception';
    echo 'FAIL exception ' . $e->getMessage() . "\n";
} finally {
    $restore();
    $after = $pdo->query('SELECT id, plan, sub_status, expires_at FROM organizations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $before = [];
    foreach ($snapshot as $row) {
        $before[(int) $row['id']] = [
            (string) $row['plan'],
            (string) $row['sub_status'],
            $row['expires_at'] === null ? null : (string) $row['expires_at'],
        ];
    }
    $mismatch = 0;
    foreach ($after as $row) {
        $id = (int) $row['id'];
        if (!isset($before[$id])) {
            $mismatch++;
            continue;
        }
        $now = [
            (string) $row['plan'],
            (string) $row['sub_status'],
            $row['expires_at'] === null ? null : (string) $row['expires_at'],
        ];
        if ($now !== $before[$id]) {
            $mismatch++;
        }
    }
    if (count($after) !== count($before)) {
        $mismatch++;
    }
    expect($failures, 'legitimate_orgs_restored', $mismatch === 0, 'mismatches=' . $mismatch);
    $left = (int) $pdo->query(
        "SELECT COUNT(*) FROM organizations WHERE email IN (" . $pdo->quote($dueEmail) . ',' . $pdo->quote($futureEmail) . ')'
    )->fetchColumn();
    expect($failures, 'fixtures_removed', $left === 0, 'left=' . $left);
}

echo $failures === [] ? "ALL PASSED\n" : ("FAILED " . count($failures) . "\n");
exit($failures === [] ? 0 : 1);
