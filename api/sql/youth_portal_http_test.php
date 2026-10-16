<?php
declare(strict_types=1);

/**
 * Youth portal and admin password-reset checks against the live YouthSync API.
 *
 * Creates uniquely named programs, assistance, a second youth, notifications,
 * and one application, then deletes only those ids. Restores the labeled
 * staff test account password after the admin reset checks.
 * Does not drop tables, truncate, or change demo admin, SK, or youth1 passwords.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = 'http://localhost/YouthSync/api';
$stamp = date('YmdHis');
$youthEmail = 'youth-auth-test@example.test';
$youthPassword = 'YouthAuthTest1!';
$adminEmail = 'admin@skmms.test';
$adminPassword = 'YouthSync1!';
$skEmail = 'sk1@demo.test';
$skPassword = 'YouthSync1!';
$staffEmail = 'sk-usermgmt-20261005172213@example.test';
$staffPassword = 'StaffMgmt1!';

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';
$pdo = youthsync_pdo();

$failed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed): void {
    $detail = preg_replace('/YS-[A-F0-9]{8}/', '[redacted]', $detail) ?? $detail;
    $detail = preg_replace('/YTS-\d{6}/', '[redacted]', $detail) ?? $detail;
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}";
    if (!$ok && $detail !== '') {
        echo ' — ' . $detail;
    }
    echo "\n";
    if (!$ok) {
        $failed++;
    }
};

function portal_req(string $method, string $url, ?string $json, string $cookie): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'body' => $err, 'json' => null];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($raw, $headerSize);
    curl_close($ch);
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
}

function portal_detail(array $row): string
{
    $code = (string) ($row['json']['error']['code'] ?? '');
    $message = (string) ($row['json']['error']['message'] ?? '');
    $snippet = $message !== '' ? $message : substr(preg_replace('/\s+/', ' ', (string) $row['body']) ?? '', 0, 180);
    return 'status=' . $row['status'] . ($code !== '' ? " code={$code}" : '') . " {$snippet}";
}

function portal_jar(string $name): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . '.txt';
    @unlink($path);
    return $path;
}

function portal_data(array $row): array
{
    $data = $row['json']['data'] ?? null;
    return is_array($data) ? $data : [];
}

function portal_items(array $row): array
{
    $items = portal_data($row)['items'] ?? [];
    return is_array($items) ? $items : [];
}

$created = [
    'program' => 0,
    'draft' => 0,
    'foreignProgram' => 0,
    'assistance' => 0,
    'application' => 0,
    'otherApplication' => 0,
    'youth' => 0,
    'youthUser' => 0,
    'notes' => [],
];

$sk = portal_jar('ys-portal-sk');
$youth = portal_jar('ys-portal-youth');
$youthB = portal_jar('ys-portal-youth-b');
$admin = portal_jar('ys-portal-admin');
$anon = portal_jar('ys-portal-anon');
$staffSession = portal_jar('ys-portal-staff');

$cleanup = static function () use ($pdo, &$created, $staffEmail, $staffPassword): void {
    try {
    $appIds = array_values(array_filter([$created['application'], $created['otherApplication']]));
    foreach ($appIds as $appId) {
        $pdo->prepare('DELETE FROM sms_deliveries WHERE event_id = :id AND event_code LIKE :code')->execute([
            'id' => $appId,
            'code' => 'application.%',
        ]);
        $pdo->prepare('DELETE FROM email_deliveries WHERE event_id = :id AND event_code LIKE :code')->execute([
            'id' => $appId,
            'code' => 'application.%',
        ]);
        $pdo->prepare('DELETE FROM application_submissions WHERE application_id = :id')->execute(['id' => $appId]);
        $pdo->prepare('DELETE FROM notifications WHERE related_entity_type = :type AND related_entity_id = :id')->execute([
            'type' => 'application',
            'id' => $appId,
        ]);
        $pdo->prepare('DELETE FROM applications WHERE id = :id')->execute(['id' => $appId]);
    }
    foreach ($created['notes'] as $noteId) {
        $pdo->prepare('DELETE FROM notifications WHERE id = :id')->execute(['id' => $noteId]);
    }
    if ($created['assistance'] > 0) {
        $pdo->prepare('DELETE FROM beneficiaries WHERE assistance_id = :id')->execute(['id' => $created['assistance']]);
        $pdo->prepare('DELETE FROM assistance_requirements WHERE assistance_id = :id')->execute(['id' => $created['assistance']]);
        $pdo->prepare('DELETE FROM assistance_programs WHERE id = :id')->execute(['id' => $created['assistance']]);
    }
    foreach (['program', 'draft', 'foreignProgram'] as $key) {
        if ($created[$key] > 0) {
            $pdo->prepare('DELETE FROM programs WHERE id = :id')->execute(['id' => $created[$key]]);
        }
    }
    if ($created['youth'] > 0) {
        $pdo->prepare('DELETE FROM notifications WHERE youth_id = :id')->execute(['id' => $created['youth']]);
        $linked = $pdo->prepare('SELECT id FROM users WHERE youth_id = :id');
        $linked->execute(['id' => $created['youth']]);
        foreach ($linked->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $pdo->prepare('DELETE FROM organization_users WHERE user_id = :id')->execute(['id' => (int) $userId]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => (int) $userId]);
        }
        $pdo->prepare('DELETE FROM youth WHERE id = :id')->execute(['id' => $created['youth']]);
    }
    } catch (Throwable $e) {
        fwrite(STDERR, 'cleanup warning: ' . $e->getMessage() . "\n");
    } finally {
    $staff = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = :email LIMIT 1');
    $staff->execute(['email' => $staffEmail]);
    $row = $staff->fetch();
    if ($row && !password_verify($staffPassword, (string) $row['password_hash'])) {
        $pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id')->execute([
            'hash' => password_hash($staffPassword, PASSWORD_DEFAULT),
            'id' => (int) $row['id'],
        ]);
    }
    }
};

try {
    $login = portal_req('POST', "$base/auth/login", json_encode([
        'email' => $youthEmail,
        'password' => $youthPassword,
    ]), $youth);
    $check('youth_login', $login['status'] === 200, portal_detail($login));
    $bad = portal_req('POST', "$base/auth/login", json_encode([
        'email' => $youthEmail,
        'password' => 'not-the-password',
    ]), portal_jar('ys-portal-bad'));
    $check('youth_wrong_password', $bad['status'] === 401, portal_detail($bad));
    $me = portal_req('GET', "$base/auth/me", null, $youth);
    $meData = portal_data($me);
    $youthId = (int) ($meData['youth']['id'] ?? 0);
    $orgId = (int) ($meData['organization']['id'] ?? 0);
    $check(
        'youth_me',
        $me['status'] === 200 && ($meData['role'] ?? '') === 'YOUTH' && $youthId > 0 && $orgId > 0,
        portal_detail($me)
    );

    $skLogin = portal_req('POST', "$base/auth/login", json_encode([
        'email' => $skEmail,
        'password' => $skPassword,
    ]), $sk);
    $check('sk_login_for_setup', $skLogin['status'] === 200, portal_detail($skLogin));
    $skMe = portal_data(portal_req('GET', "$base/auth/me", null, $sk));
    $skOrg = (int) ($skMe['organization']['id'] ?? 0);
    $check('sk_org_matches_youth_org', $skOrg === $orgId && $orgId > 0, "skOrg={$skOrg} youthOrg={$orgId}");

    $programName = "Portal Program {$stamp}";
    $draftName = "Portal Draft {$stamp}";
    $foreignName = "Portal Foreign {$stamp}";
    $assistName = "Portal Aid {$stamp}";
    $createdProgram = portal_req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => $programName,
        'scheduledOn' => '2026-12-20',
        'location' => 'Portal Court',
        'status' => 'published',
        'organization_id' => 99999,
    ]), $sk);
    $created['program'] = (int) (portal_data($createdProgram)['id'] ?? 0);
    $check('setup_published_program', $createdProgram['status'] === 201 && $created['program'] > 0, portal_detail($createdProgram));

    $createdDraft = portal_req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => $draftName,
        'scheduledOn' => '2026-12-21',
        'location' => 'Portal Court',
        'status' => 'draft',
    ]), $sk);
    $created['draft'] = (int) (portal_data($createdDraft)['id'] ?? 0);
    $check('setup_draft_program', $createdDraft['status'] === 201 && $created['draft'] > 0, portal_detail($createdDraft));

    $otherOrg = (int) $pdo->query('SELECT id FROM organizations WHERE id <> ' . (int) $orgId . ' ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($otherOrg > 0) {
        $pdo->prepare(
            'INSERT INTO programs (organization_id, kind, name, status, scheduled_on, location)
             VALUES (:org, :kind, :name, :status, :scheduled_on, :location)'
        )->execute([
            'org' => $otherOrg,
            'kind' => 'program',
            'name' => $foreignName,
            'status' => 'published',
            'scheduled_on' => '2026-12-22',
            'location' => 'Other hall',
        ]);
        $created['foreignProgram'] = (int) $pdo->lastInsertId();
    }
    $check('setup_foreign_program', $created['foreignProgram'] > 0, "otherOrg={$otherOrg}");

    $assist = portal_req('POST', "$base/sk/assistance", json_encode([
        'name' => $assistName,
        'category' => 'financial',
        'status' => 'open',
        'slots' => 5,
        'deadline' => '2026-12-31',
        'requirements' => 'Barangay certificate',
        'organization_id' => 99999,
    ]), $sk);
    $created['assistance'] = (int) (portal_data($assist)['id'] ?? 0);
    $check('setup_assistance', $assist['status'] === 201 && $created['assistance'] > 0, portal_detail($assist));

    $secondEmail = "youth-portal-{$stamp}@example.test";
    $second = portal_req('POST', "$base/sk/youth", json_encode([
        'firstName' => 'Portal',
        'lastName' => 'Second' . substr($stamp, -4),
        'birthDate' => '2006-04-04',
        'address' => 'Portal test address',
        'contact' => '09170007721',
        'interests' => ['Sports'],
        'createAccount' => true,
        'accountEmail' => $secondEmail,
        'organization_id' => 99999,
    ]), $sk);
    $secondData = portal_data($second);
    $created['youth'] = (int) ($secondData['youth']['id'] ?? 0);
    $secondPassword = (string) ($secondData['temporaryPassword'] ?? '');
    $check(
        'setup_second_youth',
        $second['status'] === 201 && $created['youth'] > 0 && $secondPassword !== '',
        portal_detail($second)
    );
    if ($created['youth'] > 0) {
        $userLookup = $pdo->prepare('SELECT id FROM users WHERE youth_id = :id LIMIT 1');
        $userLookup->execute(['id' => $created['youth']]);
        $created['youthUser'] = (int) $userLookup->fetchColumn();
    }

    $otherApp = portal_req('POST', "$base/sk/applications", json_encode([
        'assistanceId' => $created['assistance'],
        'youthId' => $created['youth'],
        'remarks' => 'other youth',
    ]), $sk);
    $created['otherApplication'] = (int) (portal_data($otherApp)['id'] ?? 0);
    $check('setup_other_application', $otherApp['status'] === 201 && $created['otherApplication'] > 0, portal_detail($otherApp));

    $note = $pdo->prepare(
        'INSERT INTO notifications (organization_id, youth_id, type, title, message)
         VALUES (:org, :youth, :type, :title, :message)'
    );
    $note->execute([
        'org' => $orgId,
        'youth' => $youthId,
        'type' => 'system',
        'title' => "Portal note {$stamp}",
        'message' => 'Own notification',
    ]);
    $ownNote = (int) $pdo->lastInsertId();
    $created['notes'][] = $ownNote;
    $note->execute([
        'org' => $orgId,
        'youth' => $created['youth'],
        'type' => 'system',
        'title' => "Portal other note {$stamp}",
        'message' => 'Other notification',
    ]);
    $otherNote = (int) $pdo->lastInsertId();
    $created['notes'][] = $otherNote;

    $programs = portal_req('GET', "$base/youth/programs?perPage=100&organization_id={$otherOrg}&youth_id=1", null, $youth);
    $programItems = portal_items($programs);
    $names = array_map(static fn ($row) => (string) ($row['name'] ?? ''), $programItems);
    $check('youth_lists_allowed_programs', $programs['status'] === 200 && in_array($programName, $names, true), portal_detail($programs));
    $check('youth_hides_draft_program', !in_array($draftName, $names, true));
    $check('youth_hides_foreign_program', !in_array($foreignName, $names, true));
    $hasUsage = false;
    foreach ($programItems as $row) {
        if (isset($row['usage']) || isset($row['plan'])) {
            $hasUsage = true;
        }
    }
    $check('youth_programs_omit_plan_usage', !$hasUsage);
    $foreignGet = portal_req('GET', "$base/youth/programs/{$created['foreignProgram']}?organization_id={$otherOrg}", null, $youth);
    $check('youth_cannot_read_foreign_program', $foreignGet['status'] === 404, portal_detail($foreignGet));
    $ownGet = portal_req('GET', "$base/youth/programs/{$created['program']}", null, $youth);
    $check('youth_reads_own_program', $ownGet['status'] === 200 && (portal_data($ownGet)['name'] ?? '') === $programName, portal_detail($ownGet));

    $assistanceList = portal_req('GET', "$base/youth/assistance?organization_id={$otherOrg}", null, $youth);
    $assistNames = array_map(static fn ($row) => (string) ($row['name'] ?? ''), portal_items($assistanceList));
    $visibleAssist = null;
    foreach (portal_items($assistanceList) as $row) {
        if ((int) ($row['id'] ?? 0) === $created['assistance']) {
            $visibleAssist = $row;
        }
    }
    $check('youth_lists_open_assistance', $assistanceList['status'] === 200 && in_array($assistName, $assistNames, true), portal_detail($assistanceList));
    $check(
        'youth_assistance_hides_beneficiaries',
        is_array($visibleAssist) && !array_key_exists('beneficiaries', $visibleAssist)
    );

    $apps = portal_req('GET', "$base/youth/applications?perPage=100&youth_id={$created['youth']}&organization_id={$otherOrg}", null, $youth);
    $appIds = array_map(static fn ($row) => (int) ($row['id'] ?? 0), portal_items($apps));
    $check('youth_lists_own_applications', $apps['status'] === 200 && !in_array($created['otherApplication'], $appIds, true), portal_detail($apps));

    $apply = portal_req('POST', "$base/youth/applications", json_encode([
        'assistanceId' => $created['assistance'],
        'youthId' => $created['youth'],
        'organization_id' => $otherOrg,
        'status' => 'approved',
    ]), $youth);
    $applyData = portal_data($apply);
    $created['application'] = (int) ($applyData['id'] ?? 0);
    $check(
        'youth_creates_own_application',
        $apply['status'] === 201
            && $created['application'] > 0
            && (int) ($applyData['youthId'] ?? 0) === $youthId
            && ($applyData['status'] ?? '') === 'pending',
        portal_detail($apply)
    );
    $ownApp = portal_req('GET', "$base/youth/applications/{$created['application']}", null, $youth);
    $check('youth_reads_own_application', $ownApp['status'] === 200 && (int) (portal_data($ownApp)['youthId'] ?? 0) === $youthId, portal_detail($ownApp));
    $hiddenApp = portal_req('GET', "$base/youth/applications/{$created['otherApplication']}", null, $youth);
    $check('youth_cannot_read_other_application', $hiddenApp['status'] === 404, portal_detail($hiddenApp));
    $modifyOther = portal_req('POST', "$base/sk/applications/{$created['otherApplication']}/status", json_encode([
        'status' => 'approved',
    ]), $youth);
    $check('youth_cannot_modify_other_application', $modifyOther['status'] === 403, portal_detail($modifyOther));
    $approveOwn = portal_req('POST', "$base/sk/applications/{$created['application']}/approve", '{}', $youth);
    $check('youth_cannot_approve', $approveOwn['status'] === 403, portal_detail($approveOwn));
    $rejectOwn = portal_req('POST', "$base/sk/applications/{$created['application']}/reject", '{}', $youth);
    $check('youth_cannot_reject', $rejectOwn['status'] === 403, portal_detail($rejectOwn));
    $fakeApprove = portal_req('POST', "$base/youth/applications/{$created['application']}/approve", '{}', $youth);
    $check('youth_has_no_approve_route', $fakeApprove['status'] === 404, portal_detail($fakeApprove));

    $notes = portal_req('GET', "$base/youth/notifications?perPage=100&youth_id={$created['youth']}&organization_id={$otherOrg}", null, $youth);
    $noteTitles = array_map(static fn ($row) => (string) ($row['title'] ?? ''), portal_items($notes));
    $check('youth_lists_own_notifications', $notes['status'] === 200 && in_array("Portal note {$stamp}", $noteTitles, true), portal_detail($notes));
    $check('youth_hides_other_notifications', !in_array("Portal other note {$stamp}", $noteTitles, true));
    $mark = portal_req('POST', "$base/youth/notifications/{$ownNote}/read", '{}', $youth);
    $check('youth_marks_own_notification', $mark['status'] === 200 && !empty(portal_data($mark)['isRead']), portal_detail($mark));
    $markOther = portal_req('POST', "$base/youth/notifications/{$otherNote}/read", '{}', $youth);
    $check('youth_cannot_mark_other_notification', $markOther['status'] === 404, portal_detail($markOther));
    $readAll = portal_req('POST', "$base/youth/notifications/read-all", '{}', $youth);
    $otherStill = $pdo->prepare('SELECT is_read FROM notifications WHERE id = :id');
    $otherStill->execute(['id' => $otherNote]);
    $check(
        'youth_read_all_does_not_touch_other',
        $readAll['status'] === 200 && (int) $otherStill->fetchColumn() === 0,
        portal_detail($readAll)
    );
    $deleteOther = portal_req('DELETE', "$base/youth/notifications/{$otherNote}", null, $youth);
    $check('youth_cannot_delete_other_notification', $deleteOther['status'] === 404, portal_detail($deleteOther));

    $skYouthRoute = portal_req('GET', "$base/youth/programs", null, $sk);
    $check('sk_cannot_use_youth_programs', $skYouthRoute['status'] === 403, portal_detail($skYouthRoute));
    $skAppsRoute = portal_req('GET', "$base/youth/applications", null, $sk);
    $check('sk_cannot_use_youth_applications', $skAppsRoute['status'] === 403, portal_detail($skAppsRoute));

    $adminLogin = portal_req('POST', "$base/auth/login", json_encode([
        'email' => $adminEmail,
        'password' => $adminPassword,
    ]), $admin);
    $check('admin_login', $adminLogin['status'] === 200, portal_detail($adminLogin));
    $adminYouth = portal_req('GET', "$base/youth/notifications?youth_id={$youthId}", null, $admin);
    $check(
        'admin_cannot_read_youth_notifications',
        $adminYouth['status'] === 403 && !str_contains((string) $adminYouth['body'], "Portal note {$stamp}"),
        portal_detail($adminYouth)
    );
    $anonPrograms = portal_req('GET', "$base/youth/programs", null, $anon);
    $check('anonymous_youth_programs', $anonPrograms['status'] === 401, portal_detail($anonPrograms));

    $bLogin = portal_req('POST', "$base/auth/login", json_encode([
        'email' => $secondEmail,
        'password' => $secondPassword,
    ]), $youthB);
    $bMe = portal_data(portal_req('GET', "$base/auth/me", null, $youthB));
    $check('second_youth_login', $bLogin['status'] === 200 && (int) ($bMe['youth']['id'] ?? 0) === $created['youth'], portal_detail($bLogin));
    $bHidden = portal_req('GET', "$base/youth/applications/{$created['application']}", null, $youthB);
    $check('second_youth_cannot_read_first_application', $bHidden['status'] === 404, portal_detail($bHidden));
    $bOwn = portal_req('GET', "$base/youth/applications/{$created['otherApplication']}", null, $youthB);
    $check('second_youth_reads_own_application', $bOwn['status'] === 200, portal_detail($bOwn));

    $staffStmt = $pdo->prepare(
        'SELECT u.id, u.role_id, u.youth_id, r.code AS role_code
         FROM users u INNER JOIN roles r ON r.id = u.role_id
         WHERE u.email = :email LIMIT 1'
    );
    $staffStmt->execute(['email' => $staffEmail]);
    $staffBefore = $staffStmt->fetch();
    $staffId = (int) ($staffBefore['id'] ?? 0);
    $check('staff_test_account_present', $staffId > 0 && ($staffBefore['role_code'] ?? '') === 'SK_OFFICIAL');

    $reset = portal_req('POST', "$base/admin/users/{$staffId}/reset-password", '{}', $admin);
    $resetData = portal_data($reset);
    $temporary = (string) ($resetData['temporaryPassword'] ?? '');
    $check(
        'admin_resets_staff_password',
        $reset['status'] === 200 && preg_match('/^YS-[A-F0-9]{8}$/', $temporary) === 1 && !array_key_exists('password_hash', $resetData),
        portal_detail($reset)
    );
    $hashStmt = $pdo->prepare('SELECT password_hash, must_change_password, role_id, youth_id FROM users WHERE id = :id');
    $hashStmt->execute(['id' => $staffId]);
    $after = $hashStmt->fetch() ?: [];
    $hash = (string) ($after['password_hash'] ?? '');
    $check('reset_password_is_hashed', $temporary !== '' && $hash !== $temporary && password_verify($temporary, $hash));
    $check(
        'reset_sets_must_change_and_keeps_role',
        (int) ($after['must_change_password'] ?? 0) === 1
            && (int) ($after['role_id'] ?? 0) === (int) ($staffBefore['role_id'] ?? -1)
            && (string) ($after['youth_id'] ?? '') === (string) ($staffBefore['youth_id'] ?? '')
    );
    $log = is_file(dirname(__DIR__) . '/logs/app.log') ? (string) file_get_contents(dirname(__DIR__) . '/logs/app.log') : '';
    $logHit = $pdo->prepare('SELECT COUNT(*) FROM activity_logs WHERE entity_type = :type AND entity_id = :id AND description LIKE :needle');
    $logHit->execute(['type' => 'user', 'id' => $staffId, 'needle' => '%' . $temporary . '%']);
    $check(
        'plaintext_password_not_stored_or_logged',
        $temporary !== '' && !str_contains($log, $temporary) && (int) $logHit->fetchColumn() === 0 && !str_contains($hash, $temporary)
    );
    $oldLogin = portal_req('POST', "$base/auth/login", json_encode([
        'email' => $staffEmail,
        'password' => $staffPassword,
    ]), portal_jar('ys-portal-old'));
    $check('old_staff_password_rejected', $oldLogin['status'] === 401, portal_detail($oldLogin));
    $newLogin = portal_req('POST', "$base/auth/login", json_encode([
        'email' => $staffEmail,
        'password' => $temporary,
    ]), $staffSession);
    $check('temporary_password_works', $newLogin['status'] === 200, portal_detail($newLogin));
    portal_req('POST', "$base/auth/logout", '{}', $staffSession);

    $skReset = portal_req('POST', "$base/admin/users/{$staffId}/reset-password", '{}', $sk);
    $check('sk_cannot_admin_reset', $skReset['status'] === 403, portal_detail($skReset));
    $youthReset = portal_req('POST', "$base/admin/users/{$staffId}/reset-password", '{}', $youth);
    $check('youth_cannot_admin_reset', $youthReset['status'] === 403, portal_detail($youthReset));
    $anonReset = portal_req('POST', "$base/admin/users/{$staffId}/reset-password", '{}', $anon);
    $check('anonymous_cannot_admin_reset', $anonReset['status'] === 401, portal_detail($anonReset));

    $self = portal_data(portal_req('GET', "$base/auth/me", null, $admin));
    $selfId = (int) ($self['user']['id'] ?? 0);
    $selfReset = portal_req('POST', "$base/admin/users/{$selfId}/reset-password", '{}', $admin);
    $check('admin_cannot_reset_self', $selfReset['status'] === 422, portal_detail($selfReset));

    $restore = portal_req('PATCH', "$base/sk/users/{$staffId}", json_encode(['password' => $staffPassword]), $sk);
    $hashStmt->execute(['id' => $staffId]);
    $restored = $hashStmt->fetch() ?: [];
    $check(
        'staff_password_restored',
        $restore['status'] === 200 && password_verify($staffPassword, (string) ($restored['password_hash'] ?? '')) && (int) ($restored['must_change_password'] ?? 1) === 0,
        portal_detail($restore)
    );

    $out = portal_req('POST', "$base/auth/logout", '{}', $youth);
    $check('youth_logout', $out['status'] === 200, portal_detail($out));
    $afterOut = portal_req('GET', "$base/auth/me", null, $youth);
    $check('youth_me_after_logout', $afterOut['status'] === 401, portal_detail($afterOut));

    $youthAgain = portal_jar('ys-portal-youth-again');
    $again = portal_req('POST', "$base/auth/login", json_encode([
        'email' => $youthEmail,
        'password' => $youthPassword,
    ]), $youthAgain);
    $againMe = portal_req('GET', "$base/auth/me", null, $youthAgain);
    $profile = portal_req('GET', "$base/youth/profile", null, $youthAgain);
    $regPrograms = portal_req('GET', "$base/youth/programs?perPage=20", null, $youthAgain);
    $regApps = portal_req('GET', "$base/youth/applications?perPage=20", null, $youthAgain);
    $regNotes = portal_req('GET', "$base/youth/notifications?perPage=20", null, $youthAgain);
    $check('regression_youth_login', $again['status'] === 200, portal_detail($again));
    $check('regression_youth_me', $againMe['status'] === 200 && (portal_data($againMe)['role'] ?? '') === 'YOUTH', portal_detail($againMe));
    $check('regression_youth_profile', $profile['status'] === 200 && (int) (portal_data($profile)['youth']['id'] ?? 0) === $youthId, portal_detail($profile));
    $check('regression_youth_programs', $regPrograms['status'] === 200, portal_detail($regPrograms));
    $check('regression_youth_applications', $regApps['status'] === 200, portal_detail($regApps));
    $check('regression_youth_notifications', $regNotes['status'] === 200, portal_detail($regNotes));
    portal_req('POST', "$base/auth/logout", '{}', $youthAgain);

    $adminMe = portal_req('GET', "$base/auth/me", null, $admin);
    $adminOrgs = portal_req('GET', "$base/admin/organizations?perPage=5", null, $admin);
    $adminUsers = portal_req('GET', "$base/admin/users?perPage=5", null, $admin);
    $check('regression_admin_me', $adminMe['status'] === 200 && (portal_data($adminMe)['role'] ?? '') === 'SYSTEM_ADMIN', portal_detail($adminMe));
    $check('regression_admin_organizations', $adminOrgs['status'] === 200, portal_detail($adminOrgs));
    $check('regression_admin_users', $adminUsers['status'] === 200, portal_detail($adminUsers));
    $adminOut = portal_req('POST', "$base/auth/logout", '{}', $admin);
    $check('regression_admin_logout', $adminOut['status'] === 200 && portal_req('GET', "$base/auth/me", null, $admin)['status'] === 401, portal_detail($adminOut));

    $skYouth = portal_req('GET', "$base/sk/youth?perPage=5&organization_id={$otherOrg}", null, $sk);
    $skPrograms = portal_req('GET', "$base/sk/programs?perPage=50&organization_id={$otherOrg}", null, $sk);
    $skProgramNames = array_map(static fn ($row) => (string) ($row['name'] ?? ''), portal_items($skPrograms));
    $skApplications = portal_req('GET', "$base/sk/applications?perPage=5&organization_id={$otherOrg}", null, $sk);
    $skNotes = portal_req('GET', "$base/sk/notifications?perPage=5", null, $sk);
    $skAdmin = portal_req('GET', "$base/admin/users?perPage=5", null, $sk);
    $check('regression_sk_me', portal_req('GET', "$base/auth/me", null, $sk)['status'] === 200);
    $check('regression_sk_youth', $skYouth['status'] === 200, portal_detail($skYouth));
    $check('regression_sk_programs', $skPrograms['status'] === 200 && in_array($programName, $skProgramNames, true) && !in_array($foreignName, $skProgramNames, true), portal_detail($skPrograms));
    $check('regression_sk_applications', $skApplications['status'] === 200, portal_detail($skApplications));
    $check('regression_sk_notifications', $skNotes['status'] === 200, portal_detail($skNotes));
    $check('regression_sk_admin_forbidden', $skAdmin['status'] === 403, portal_detail($skAdmin));
    portal_req('POST', "$base/auth/logout", '{}', $sk);

    $reg = portal_jar('ys-portal-reg');
    $regBody = json_encode([
        'barangay' => 'PortalReg' . $stamp,
        'municipality' => 'AuthTown',
        'province' => 'Laguna',
        'chairperson' => 'Portal Chair',
        'email' => 'portalreg' . $stamp . '@example.test',
        'contact' => '09181230000',
        'ownerName' => 'Portal Owner',
        'ownerEmail' => 'portalowner' . $stamp . '@example.test',
        'ownerPassword' => 'PortalReg1!',
        'confirmPassword' => 'PortalReg1!',
        'acceptTerms' => true,
        'acceptPrivacy' => true,
    ]);
    $registered = portal_req('POST', "$base/auth/register", $regBody, $reg);
    $regData = portal_data($registered);
    $pendingId = (int) ($regData['organization']['id'] ?? 0);
    $check(
        'regression_register_pending',
        $registered['status'] === 201 && ($regData['organization']['status'] ?? '') === 'pending' && $pendingId > 0,
        portal_detail($registered)
    );
    $regMe = portal_req('GET', "$base/auth/me", null, $reg);
    $check('regression_register_no_session', $regMe['status'] === 401, portal_detail($regMe));
    if ($pendingId > 0) {
        $status = $pdo->prepare('SELECT status FROM organizations WHERE id = :id');
        $status->execute(['id' => $pendingId]);
        $check('regression_register_stays_pending', (string) $status->fetchColumn() === 'pending');
    }
    echo "KEPT pending organization id={$pendingId}\n";
} finally {
    $cleanup();
    $staff = $pdo->prepare('SELECT password_hash, must_change_password FROM users WHERE email = :email LIMIT 1');
    $staff->execute(['email' => $staffEmail]);
    $final = $staff->fetch() ?: [];
    $ok = password_verify($staffPassword, (string) ($final['password_hash'] ?? '')) && (int) ($final['must_change_password'] ?? 1) === 0;
    $check('cleanup_staff_password_known', $ok);
    $leftYouth = 0;
    if ($created['youth'] > 0) {
        $left = $pdo->prepare('SELECT COUNT(*) FROM youth WHERE id = :id');
        $left->execute(['id' => $created['youth']]);
        $leftYouth = (int) $left->fetchColumn();
    }
    $check('cleanup_second_youth_removed', $leftYouth === 0);
    $leftProgram = 0;
    if ($created['foreignProgram'] > 0) {
        $left = $pdo->prepare('SELECT COUNT(*) FROM programs WHERE id = :id');
        $left->execute(['id' => $created['foreignProgram']]);
        $leftProgram = (int) $left->fetchColumn();
    }
    $check('cleanup_foreign_program_removed', $leftProgram === 0);
}

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
