<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();
$base = 'http://localhost/YouthSync/api';
$stamp = date('YmdHis');
$prefix = "AppHttp {$stamp}";
$failures = 0;
$programIds = [];
$applicationIds = [];
$otherUser = null;
$otherHash = null;
$otherMustChange = null;

$ids = static function (string $table) use ($pdo): array {
    $rows = $pdo->query("SELECT id FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows ?: []);
};

$beforeApplications = $ids('applications');
$beforePrograms = $ids('assistance_programs');
$beforeBeneficiaries = $ids('beneficiaries');
$beforeSubmissions = $ids('application_submissions');

$check = static function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok) {
        echo "PASS {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL {$name}" . ($detail !== '' ? " {$detail}" : '') . "\n";
};

$req = static function (string $method, string $url, ?string $json, string $cookie): array {
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
        return ['status' => 0, 'body' => $err];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'body' => substr($raw, $headerSize)];
};

$data = static function (array $response): array {
    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        return [];
    }
    $payload = $decoded['data'] ?? $decoded;
    return is_array($payload) ? $payload : [];
};

$detail = static function (array $response): string {
    return 'status=' . $response['status'] . ' body=' . substr($response['body'], 0, 400);
};

$login = static function (string $email, string $password, string $cookie) use ($req, $base): array {
    return $req('POST', "$base/auth/login", json_encode([
        'email' => $email,
        'password' => $password,
    ]), $cookie);
};

$containsId = static function (array $response, int $id) use ($data): bool {
    foreach (($data($response)['items'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $id) {
            return true;
        }
    }
    return false;
};

$cookie = static function (string $name): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . '.txt';
};

$anon = $cookie('ys_app_anon_' . $stamp);
$admin = $cookie('ys_app_admin_' . $stamp);
$sk = $cookie('ys_app_sk_' . $stamp);
$sk2 = $cookie('ys_app_sk2_' . $stamp);
$youth = $cookie('ys_app_youth_' . $stamp);
$youthB = $cookie('ys_app_youthb_' . $stamp);
foreach ([$anon, $admin, $sk, $sk2, $youth, $youthB] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

try {
    $anonGet = $req('GET', "$base/youth/applications", null, $anon);
    $check('anon_list_401', $anonGet['status'] === 401, $detail($anonGet));
    $anonPost = $req('POST', "$base/youth/applications", json_encode(['assistanceId' => 1]), $anon);
    $check('anon_create_401', $anonPost['status'] === 401, $detail($anonPost));

    $adminLogin = $login('admin@skmms.test', 'YouthSync1!', $admin);
    $check('admin_login', $adminLogin['status'] === 200, $detail($adminLogin));
    $adminGet = $req('GET', "$base/youth/applications", null, $admin);
    $check('admin_youth_route_403', $adminGet['status'] === 403, $detail($adminGet));

    $skLogin = $login('sk1@demo.test', 'YouthSync1!', $sk);
    $check('sk_login', $skLogin['status'] === 200, $detail($skLogin));
    $skYouth = $req('POST', "$base/youth/applications", json_encode(['assistanceId' => 1]), $sk);
    $check('sk_youth_route_403', $skYouth['status'] === 403, $detail($skYouth));
    $me = $data($req('GET', "$base/auth/me", null, $sk));
    $orgId = (int) ($me['organization']['id'] ?? $me['organizationId'] ?? 0);
    $check('sk_org', $orgId > 0, $detail($req('GET', "$base/auth/me", null, $sk)));

    $sk2Login = $login('sk2@demo.test', 'YouthSync1!', $sk2);
    $check('sk2_login', $sk2Login['status'] === 200, $detail($sk2Login));
    $me2 = $data($req('GET', "$base/auth/me", null, $sk2));
    $org2 = (int) ($me2['organization']['id'] ?? $me2['organizationId'] ?? 0);
    $check('sk2_other_org', $org2 > 0 && $org2 !== $orgId, 'org2=' . $org2);

    $youthLogin = $login('youth-auth-test@example.test', 'YouthAuthTest1!', $youth);
    $check('youth_login', $youthLogin['status'] === 200, $detail($youthLogin));
    $youthMe = $data($req('GET', "$base/auth/me", null, $youth));
    $youthId = (int) ($youthMe['youth']['id'] ?? $youthMe['user']['youthId'] ?? 0);
    $check('youth_id', $youthId > 0, json_encode($youthMe));
    $youthSk = $req('GET', "$base/sk/applications", null, $youth);
    $check('youth_sk_route_403', $youthSk['status'] === 403, $detail($youthSk));

    $program = $data($created = $req('POST', "$base/sk/assistance", json_encode([
        'name' => $prefix . ' Approve',
        'category' => 'scholarship',
        'status' => 'open',
        'description' => 'Applications HTTP test',
        'slots' => 5,
        'amount' => 500,
        'organization_id' => $org2,
        'org_id' => 999,
    ]), $sk));
    $programId = (int) ($program['id'] ?? 0);
    if ($programId > 0) {
        $programIds[] = $programId;
    }
    $check('create_open_program', $created['status'] === 201 && $programId > 0 && ($program['status'] ?? '') === 'open', $detail($created));

    $rejectProgram = $data($createdReject = $req('POST', "$base/sk/assistance", json_encode([
        'name' => $prefix . ' Reject',
        'category' => 'scholarship',
        'status' => 'open',
        'description' => 'Applications HTTP reject path',
        'slots' => 5,
        'organization_id' => $org2,
    ]), $sk));
    $rejectProgramId = (int) ($rejectProgram['id'] ?? 0);
    if ($rejectProgramId > 0) {
        $programIds[] = $rejectProgramId;
    }
    $check('create_reject_program', $createdReject['status'] === 201 && $rejectProgramId > 0, $detail($createdReject));

    $spoofedYouth = $youthId + 1000;
    $createdApp = $req('POST', "$base/youth/applications", json_encode([
        'assistanceId' => $programId,
        'youthId' => $spoofedYouth,
        'youth_id' => $spoofedYouth,
        'organization_id' => $org2,
        'organizationId' => $org2,
        'status' => 'approved',
        'role' => 'SYSTEM_ADMIN',
    ]), $youth);
    $application = $data($createdApp);
    $applicationId = (int) ($application['id'] ?? 0);
    if ($applicationId > 0) {
        $applicationIds[] = $applicationId;
    }
    $check('youth_create_201', $createdApp['status'] === 201 && $applicationId > 0, $detail($createdApp));
    $check('youth_create_pending', ($application['status'] ?? '') === 'pending', $detail($createdApp));
    $check('youth_create_no_password', stripos($createdApp['body'], 'password_hash') === false);
    $rowStmt = $pdo->prepare('SELECT organization_id, youth_id, assistance_id, status FROM applications WHERE id = :id');
    $rowStmt->execute(['id' => $applicationId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('persisted_pending', ($row['status'] ?? '') === 'pending', json_encode($row));
    $check('persisted_session_youth', (int) ($row['youth_id'] ?? 0) === $youthId, json_encode($row));
    $check('persisted_session_org', (int) ($row['organization_id'] ?? 0) === $orgId, json_encode($row));
    $check('spoofed_youth_ignored', (int) ($row['youth_id'] ?? 0) !== $spoofedYouth);

    $listed = $req('GET', "$base/youth/applications?organization_id={$org2}&youth_id={$spoofedYouth}", null, $youth);
    $check('youth_list_includes', $listed['status'] === 200 && $containsId($listed, $applicationId), $detail($listed));
    $shown = $req('GET', "$base/youth/applications/{$applicationId}", null, $youth);
    $check('youth_read', $shown['status'] === 200 && ($data($shown)['status'] ?? '') === 'pending', $detail($shown));

    $duplicate = $req('POST', "$base/youth/applications", json_encode([
        'assistanceId' => $programId,
    ]), $youth);
    $check('duplicate_409', $duplicate['status'] === 409, $detail($duplicate));
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE assistance_id = :aid AND youth_id = :youth');
    $countStmt->execute(['aid' => $programId, 'youth' => $youthId]);
    $check('duplicate_one_row', (int) $countStmt->fetchColumn() === 1);

    $skList = $req('GET', "$base/sk/applications?organization_id={$org2}", null, $sk);
    $check('sk_sees_own', $skList['status'] === 200 && $containsId($skList, $applicationId), $detail($skList));
    $sk2List = $req('GET', "$base/sk/applications?organization_id={$orgId}", null, $sk2);
    $check('sk2_list_hides', $sk2List['status'] === 200 && !$containsId($sk2List, $applicationId), $detail($sk2List));
    $sk2Read = $req('GET', "$base/sk/applications/{$applicationId}", null, $sk2);
    $check('sk2_read_404', $sk2Read['status'] === 404, $detail($sk2Read));
    $sk2Approve = $req('POST', "$base/sk/applications/{$applicationId}/approve", json_encode([
        'organization_id' => $orgId,
    ]), $sk2);
    $check('sk2_approve_404', $sk2Approve['status'] === 404, $detail($sk2Approve));

    $needs = $req('POST', "$base/sk/applications/{$applicationId}/status", json_encode([
        'status' => 'needs_resubmission',
        'remarks' => 'Please resubmit this test application.',
        'organization_id' => $org2,
    ]), $sk);
    $check('sk_needs_resubmission', $needs['status'] === 200 && ($data($needs)['status'] ?? '') === 'needs_resubmission', $detail($needs));
    $youthSees = $req('GET', "$base/youth/applications/{$applicationId}", null, $youth);
    $check('youth_sees_resubmission', ($data($youthSees)['status'] ?? '') === 'needs_resubmission', $detail($youthSees));

    $tooSoon = $req('POST', "$base/youth/applications/{$applicationId}/resubmit", json_encode([
        'status' => 'approved',
        'youth_id' => $spoofedYouth,
        'organization_id' => $org2,
    ]), $youth);
    $resubmitted = $tooSoon;
    $check('youth_resubmit', $resubmitted['status'] === 200 && ($data($resubmitted)['status'] ?? '') === 'pending', $detail($resubmitted));
    $rowStmt->execute(['id' => $applicationId]);
    $afterResubmit = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('resubmit_pending_persisted', ($afterResubmit['status'] ?? '') === 'pending' && (int) ($afterResubmit['youth_id'] ?? 0) === $youthId, json_encode($afterResubmit));
    $again = $req('POST', "$base/youth/applications/{$applicationId}/resubmit", '{}', $youth);
    $check('resubmit_only_when_requested', $again['status'] === 422, $detail($again));

    $approved = $req('POST', "$base/sk/applications/{$applicationId}/approve", json_encode([
        'organization_id' => $org2,
        'status' => 'rejected',
    ]), $sk);
    $check('sk_approve', $approved['status'] === 200 && ($data($approved)['status'] ?? '') === 'approved', $detail($approved));
    $rowStmt->execute(['id' => $applicationId]);
    $approvedRow = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('approve_persisted', ($approvedRow['status'] ?? '') === 'approved', json_encode($approvedRow));
    $youthApproved = $req('GET', "$base/youth/applications/{$applicationId}", null, $youth);
    $check('youth_sees_approved', ($data($youthApproved)['status'] ?? '') === 'approved', $detail($youthApproved));

    $rejectApp = $req('POST', "$base/youth/applications", json_encode([
        'assistanceId' => $rejectProgramId,
        'status' => 'approved',
        'youthId' => $spoofedYouth,
    ]), $youth);
    $rejectApplicationId = (int) ($data($rejectApp)['id'] ?? 0);
    if ($rejectApplicationId > 0) {
        $applicationIds[] = $rejectApplicationId;
    }
    $check('youth_reject_path_create', $rejectApp['status'] === 201 && ($data($rejectApp)['status'] ?? '') === 'pending', $detail($rejectApp));
    $rejected = $req('POST', "$base/sk/applications/{$rejectApplicationId}/reject", json_encode([
        'remarks' => 'Rejected by the applications HTTP test.',
        'organization_id' => $orgId,
    ]), $sk);
    $check('sk_reject', $rejected['status'] === 200 && ($data($rejected)['status'] ?? '') === 'rejected', $detail($rejected));
    $youthRejected = $req('GET', "$base/youth/applications/{$rejectApplicationId}", null, $youth);
    $check('youth_sees_rejected', ($data($youthRejected)['status'] ?? '') === 'rejected', $detail($youthRejected));

    $otherStmt = $pdo->prepare(
        "SELECT u.id, u.email, u.password_hash, u.must_change_password, u.youth_id, y.organization_id
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         INNER JOIN youth y ON y.id = u.youth_id
         WHERE r.code = 'YOUTH' AND u.status = 'active' AND y.archived = 0
           AND u.email <> 'youth-auth-test@example.test'
         ORDER BY u.id
         LIMIT 1"
    );
    $otherStmt->execute();
    $otherUser = $otherStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $check('second_youth_available', is_array($otherUser) && (int) ($otherUser['id'] ?? 0) > 0);
    if (is_array($otherUser)) {
        $otherHash = (string) $otherUser['password_hash'];
        $otherMustChange = (int) $otherUser['must_change_password'];
        $temp = 'AppHttpYouthB1!';
        $pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id')
            ->execute(['hash' => password_hash($temp, PASSWORD_DEFAULT), 'id' => (int) $otherUser['id']]);
        $otherLogin = $login((string) $otherUser['email'], $temp, $youthB);
        $check('youth_b_login', $otherLogin['status'] === 200, $detail($otherLogin));
        $otherRead = $req('GET', "$base/youth/applications/{$applicationId}", null, $youthB);
        $check('youth_b_cannot_read', $otherRead['status'] === 404, $detail($otherRead));
        $otherCreate = $req('POST', "$base/youth/applications", json_encode([
            'assistanceId' => $programId,
            'youthId' => $youthId,
            'youth_id' => $youthId,
            'organization_id' => $orgId,
            'status' => 'approved',
        ]), $youthB);
        $otherCreatedId = (int) ($data($otherCreate)['id'] ?? 0);
        if ($otherCreatedId > 0) {
            $applicationIds[] = $otherCreatedId;
            $ownStmt = $pdo->prepare('SELECT youth_id, organization_id, status FROM applications WHERE id = :id');
            $ownStmt->execute(['id' => $otherCreatedId]);
            $own = $ownStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $check('youth_b_cannot_own_as_a', (int) ($own['youth_id'] ?? 0) === (int) $otherUser['youth_id'] && (int) ($own['youth_id'] ?? 0) !== $youthId, json_encode($own));
            $check('youth_b_status_pending', ($own['status'] ?? '') === 'pending');
        } else {
            $check('youth_b_cross_org_blocked', in_array($otherCreate['status'], [404, 422], true), $detail($otherCreate));
        }
    }
} finally {
    if (is_array($otherUser) && $otherHash !== null) {
        $pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = :flag WHERE id = :id')
            ->execute([
                'hash' => $otherHash,
                'flag' => $otherMustChange,
                'id' => (int) $otherUser['id'],
            ]);
    }

    if ($applicationIds) {
        $appPlace = implode(',', array_fill(0, count($applicationIds), '?'));
        $pdo->prepare("DELETE FROM application_submissions WHERE application_id IN ($appPlace)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM notifications WHERE related_entity_type = 'application' AND related_entity_id IN ($appPlace)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM sms_deliveries WHERE event_code LIKE 'application.%' AND event_id IN ($appPlace)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM email_deliveries WHERE event_code LIKE 'application.%' AND event_id IN ($appPlace)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM applications WHERE id IN ($appPlace)")->execute($applicationIds);
    }
    if ($programIds) {
        $progPlace = implode(',', array_fill(0, count($programIds), '?'));
        $pdo->prepare("DELETE FROM beneficiaries WHERE assistance_id IN ($progPlace)")->execute($programIds);
        $pdo->prepare("DELETE FROM assistance_requirements WHERE assistance_id IN ($progPlace)")->execute($programIds);
        $pdo->prepare("DELETE FROM assistance_programs WHERE id IN ($progPlace) AND name LIKE 'AppHttp %'")->execute($programIds);
    }

    $afterApplications = $ids('applications');
    $afterPrograms = $ids('assistance_programs');
    $afterBeneficiaries = $ids('beneficiaries');
    $afterSubmissions = $ids('application_submissions');
    $check('applications_unchanged', $beforeApplications === $afterApplications, 'before=' . implode(',', $beforeApplications) . ' after=' . implode(',', $afterApplications));
    $check('programs_unchanged', $beforePrograms === $afterPrograms, 'before=' . implode(',', $beforePrograms) . ' after=' . implode(',', $afterPrograms));
    $check('beneficiaries_unchanged', $beforeBeneficiaries === $afterBeneficiaries);
    $check('submissions_unchanged', $beforeSubmissions === $afterSubmissions);
    $left = $pdo->query("SELECT id, name FROM assistance_programs WHERE name LIKE 'AppHttp %'")->fetchAll(PDO::FETCH_ASSOC);
    $check('no_apphttp_programs', $left === [] || $left === false, json_encode($left));
}

if ($failures > 0) {
    echo "FAILED {$failures}\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
