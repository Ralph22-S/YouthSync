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
$prefix = "ReqHttp {$stamp}";
$failures = 0;
$programIds = [];
$requirementIds = [];
$applicationIds = [];

$ids = static function (string $table) use ($pdo): array {
    $rows = $pdo->query("SELECT id FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows ?: []);
};

$beforeRequirements = $ids('assistance_requirements');
$beforePrograms = $ids('assistance_programs');
$beforeApplications = $ids('applications');
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

$cookie = static function (string $name) use ($stamp): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . $stamp . '.txt';
};

$anon = $cookie('ys_req_anon_');
$admin = $cookie('ys_req_admin_');
$sk = $cookie('ys_req_sk_');
$sk2 = $cookie('ys_req_sk2_');
$youth = $cookie('ys_req_youth_');

try {
    $anonPost = $req('POST', "$base/sk/requirements", json_encode(['name' => 'Anon']), $anon);
    $check('anon_create_401', $anonPost['status'] === 401, $detail($anonPost));
    $anonPatch = $req('PATCH', "$base/sk/requirements/1", json_encode(['name' => 'Anon']), $anon);
    $check('anon_update_401', $anonPatch['status'] === 401, $detail($anonPatch));

    $adminLogin = $login('admin@skmms.test', 'YouthSync1!', $admin);
    $check('admin_login', $adminLogin['status'] === 200, $detail($adminLogin));
    $adminPost = $req('POST', "$base/sk/requirements", json_encode(['name' => 'Admin']), $admin);
    $check('admin_sk_route_403', $adminPost['status'] === 403, $detail($adminPost));

    $youthLogin = $login('youth-auth-test@example.test', 'YouthAuthTest1!', $youth);
    $check('youth_login', $youthLogin['status'] === 200, $detail($youthLogin));
    $youthPost = $req('POST', "$base/sk/requirements", json_encode(['name' => 'Youth']), $youth);
    $check('youth_sk_route_403', $youthPost['status'] === 403, $detail($youthPost));
    $youthPatchReview = $req('PATCH', "$base/sk/application-requirements/1", json_encode(['status' => 'verified']), $youth);
    $check('youth_review_403', $youthPatchReview['status'] === 403, $detail($youthPatchReview));

    $skLogin = $login('sk1@demo.test', 'YouthSync1!', $sk);
    $check('sk_login', $skLogin['status'] === 200, $detail($skLogin));
    $me = $data($req('GET', "$base/auth/me", null, $sk));
    $orgId = (int) ($me['organization']['id'] ?? $me['organizationId'] ?? 0);
    $check('sk_org', $orgId > 0);

    $sk2Login = $login('sk2@demo.test', 'YouthSync1!', $sk2);
    $check('sk2_login', $sk2Login['status'] === 200, $detail($sk2Login));
    $me2 = $data($req('GET', "$base/auth/me", null, $sk2));
    $org2 = (int) ($me2['organization']['id'] ?? $me2['organizationId'] ?? 0);
    $check('sk2_other_org', $org2 > 0 && $org2 !== $orgId, 'org2=' . $org2);

    $createdProgram = $req('POST', "$base/sk/assistance", json_encode([
        'name' => $prefix,
        'category' => 'scholarship',
        'status' => 'open',
        'description' => 'Requirements HTTP test',
        'slots' => 5,
        'organization_id' => $org2,
    ]), $sk);
    $program = $data($createdProgram);
    $programId = (int) ($program['id'] ?? 0);
    if ($programId > 0) {
        $programIds[] = $programId;
    }
    $check('program_created', $createdProgram['status'] === 201 && $programId > 0, $detail($createdProgram));

    $invalid = $req('POST', "$base/sk/requirements", json_encode([
        'targetType' => 'assistance',
        'targetId' => $programId,
        'name' => '',
        'accepts' => 'text/plain',
        'organization_id' => $org2,
    ]), $sk);
    $check('invalid_requirement_422', $invalid['status'] === 422, $detail($invalid));

    $missingProgram = $req('POST', "$base/sk/requirements", json_encode([
        'targetType' => 'program',
        'targetId' => 999999999,
        'name' => $prefix . ' Program',
    ]), $sk);
    $check('missing_program_404', $missingProgram['status'] === 404, $detail($missingProgram));

    $created = $req('POST', "$base/sk/requirements", json_encode([
        'targetType' => 'assistance',
        'targetId' => $programId,
        'name' => $prefix . ' Valid ID',
        'description' => 'Bring a valid ID.',
        'required' => true,
        'accepts' => 'image/*,application/pdf',
        'organization_id' => $org2,
        'organizationId' => 999,
    ]), $sk);
    $requirement = $data($created);
    $requirementId = (int) ($requirement['id'] ?? 0);
    if ($requirementId > 0) {
        $requirementIds[] = $requirementId;
    }
    $check('create_201', $created['status'] === 201 && $requirementId > 0, $detail($created));
    $check('create_no_password', stripos($created['body'], 'password_hash') === false);
    $rowStmt = $pdo->prepare('SELECT organization_id, assistance_id, name, description, required, accepts FROM assistance_requirements WHERE id = :id');
    $rowStmt->execute(['id' => $requirementId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('persisted_session_org', (int) ($row['organization_id'] ?? 0) === $orgId, json_encode($row));
    $check('persisted_program', (int) ($row['assistance_id'] ?? 0) === $programId, json_encode($row));
    $check('spoofed_org_ignored', (int) ($row['organization_id'] ?? 0) !== $org2 || $org2 === $orgId);

    $shown = $req('GET', "$base/sk/assistance/{$programId}", null, $sk);
    $found = false;
    foreach (($data($shown)['requirementItems'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $requirementId) {
            $found = true;
        }
    }
    $check('read_on_program', $shown['status'] === 200 && $found, $detail($shown));

    $duplicate = $req('POST', "$base/sk/requirements", json_encode([
        'targetType' => 'assistance',
        'targetId' => $programId,
        'name' => $prefix . ' Valid ID',
        'description' => 'Second copy allowed by the current rules.',
        'required' => false,
        'accepts' => 'application/pdf',
    ]), $sk);
    $duplicateId = (int) ($data($duplicate)['id'] ?? 0);
    if ($duplicateId > 0) {
        $requirementIds[] = $duplicateId;
    }
    $check('duplicate_name_follows_service', $duplicate['status'] === 201 && $duplicateId > 0, $detail($duplicate));
    if ($duplicateId > 0) {
        $removedDup = $req('DELETE', "$base/sk/requirements/{$duplicateId}", null, $sk);
        $check('delete_duplicate', $removedDup['status'] === 200, $detail($removedDup));
        $requirementIds = array_values(array_filter($requirementIds, static fn (int $id): bool => $id !== $duplicateId));
    }

    $updated = $req('PATCH', "$base/sk/requirements/{$requirementId}", json_encode([
        'name' => $prefix . ' Valid Government ID',
        'description' => 'Government-issued ID.',
        'required' => false,
        'accepts' => 'application/pdf',
        'organization_id' => $org2,
    ]), $sk);
    $check('update_200', $updated['status'] === 200 && ($data($updated)['name'] ?? '') === $prefix . ' Valid Government ID', $detail($updated));
    $rowStmt->execute(['id' => $requirementId]);
    $updatedRow = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('update_persisted', ($updatedRow['name'] ?? '') === $prefix . ' Valid Government ID'
        && ($updatedRow['accepts'] ?? '') === 'application/pdf'
        && (int) ($updatedRow['required'] ?? 1) === 0
        && (int) ($updatedRow['organization_id'] ?? 0) === $orgId, json_encode($updatedRow));

    $missing = $req('PATCH', "$base/sk/requirements/999999999", json_encode(['name' => 'Missing']), $sk);
    $check('missing_update_404', $missing['status'] === 404, $detail($missing));
    $missingDelete = $req('DELETE', "$base/sk/requirements/999999999", null, $sk);
    $check('missing_delete_404', $missingDelete['status'] === 404, $detail($missingDelete));

    $sk2Patch = $req('PATCH', "$base/sk/requirements/{$requirementId}", json_encode([
        'name' => 'Stolen',
        'organization_id' => $orgId,
    ]), $sk2);
    $check('sk2_update_404', $sk2Patch['status'] === 404, $detail($sk2Patch));
    $sk2Delete = $req('DELETE', "$base/sk/requirements/{$requirementId}", null, $sk2);
    $check('sk2_delete_404', $sk2Delete['status'] === 404, $detail($sk2Delete));
    $sk2Read = $req('GET', "$base/sk/assistance/{$programId}?organization_id={$orgId}", null, $sk2);
    $check('sk2_program_404', $sk2Read['status'] === 404, $detail($sk2Read));

    $youthList = $req('GET', "$base/youth/assistance", null, $youth);
    $youthSees = false;
    foreach (($data($youthList)['items'] ?? []) as $item) {
        foreach (($item['requirementItems'] ?? []) as $reqItem) {
            if ((int) ($reqItem['id'] ?? 0) === $requirementId && ($reqItem['name'] ?? '') === $prefix . ' Valid Government ID') {
                $youthSees = true;
            }
        }
    }
    $check('youth_sees_requirement', $youthList['status'] === 200 && $youthSees, $detail($youthList));

    $applied = $req('POST', "$base/youth/applications", json_encode([
        'assistanceId' => $programId,
        'youthId' => 1,
        'organization_id' => $org2,
        'status' => 'approved',
    ]), $youth);
    $applicationId = (int) ($data($applied)['id'] ?? 0);
    if ($applicationId > 0) {
        $applicationIds[] = $applicationId;
    }
    $check('youth_application', $applied['status'] === 201, $detail($applied));
    $appDetail = $req('GET', "$base/sk/applications/{$applicationId}", null, $sk);
    $submission = $data($appDetail)['submissions'][0] ?? [];
    $submissionId = (int) ($submission['id'] ?? 0);
    $check('submission_seeded', $appDetail['status'] === 200 && $submissionId > 0
        && (int) ($submission['requirementId'] ?? 0) === $requirementId
        && (int) ($submission['applicationId'] ?? 0) === $applicationId, $detail($appDetail));

    $sk2Review = $req('PATCH', "$base/sk/application-requirements/{$submissionId}", json_encode([
        'status' => 'verified',
        'organization_id' => $orgId,
    ]), $sk2);
    $check('sk2_review_404', $sk2Review['status'] === 404, $detail($sk2Review));

    $reviewed = $req('PATCH', "$base/sk/application-requirements/{$submissionId}", json_encode([
        'status' => 'verified',
        'remarks' => 'Checked in the requirements HTTP test.',
        'organization_id' => $org2,
        'requirement_id' => 1,
        'application_id' => 1,
    ]), $sk);
    $check('review_verified', $reviewed['status'] === 200 && ($data($reviewed)['status'] ?? '') === 'verified', $detail($reviewed));
    $subStmt = $pdo->prepare('SELECT organization_id, application_id, requirement_id, status FROM application_submissions WHERE id = :id');
    $subStmt->execute(['id' => $submissionId]);
    $subRow = $subStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('review_persisted', ($subRow['status'] ?? '') === 'verified'
        && (int) ($subRow['organization_id'] ?? 0) === $orgId
        && (int) ($subRow['application_id'] ?? 0) === $applicationId
        && (int) ($subRow['requirement_id'] ?? 0) === $requirementId, json_encode($subRow));

    $blockedDelete = $req('DELETE', "$base/sk/requirements/{$requirementId}", null, $sk);
    $check('delete_blocked_while_submitted', $blockedDelete['status'] >= 400, $detail($blockedDelete));

    if ($applicationIds) {
        $appPlace = implode(',', array_fill(0, count($applicationIds), '?'));
        $pdo->prepare("DELETE FROM application_submissions WHERE application_id IN ($appPlace)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM notifications WHERE related_entity_type = 'application' AND related_entity_id IN ($appPlace)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM sms_deliveries WHERE event_code LIKE 'application.%' AND event_id IN ($appPlace)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM email_deliveries WHERE event_code LIKE 'application.%' AND event_id IN ($appPlace)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM applications WHERE id IN ($appPlace)")->execute($applicationIds);
        $applicationIds = [];
    }

    $deleted = $req('DELETE', "$base/sk/requirements/{$requirementId}", null, $sk);
    $check('delete_200', $deleted['status'] === 200, $detail($deleted));
    $afterDelete = $req('GET', "$base/sk/assistance/{$programId}", null, $sk);
    $stillThere = false;
    foreach (($data($afterDelete)['requirementItems'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $requirementId) {
            $stillThere = true;
        }
    }
    $check('deleted_not_listed', !$stillThere, $detail($afterDelete));
    $gone = $pdo->prepare('SELECT id FROM assistance_requirements WHERE id = :id');
    $gone->execute(['id' => $requirementId]);
    $check('deleted_from_database', $gone->fetch() === false);
    $requirementIds = array_values(array_filter($requirementIds, static fn (int $id): bool => $id !== $requirementId));
} finally {
    if ($applicationIds) {
        $appPlace = implode(',', array_fill(0, count($applicationIds), '?'));
        $pdo->prepare("DELETE FROM application_submissions WHERE application_id IN ($appPlace)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM notifications WHERE related_entity_type = 'application' AND related_entity_id IN ($appPlace)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM applications WHERE id IN ($appPlace)")->execute($applicationIds);
    }
    if ($requirementIds) {
        $reqPlace = implode(',', array_fill(0, count($requirementIds), '?'));
        $pdo->prepare("DELETE FROM application_submissions WHERE requirement_id IN ($reqPlace)")->execute($requirementIds);
        $pdo->prepare("DELETE FROM assistance_requirements WHERE id IN ($reqPlace)")->execute($requirementIds);
    }
    if ($programIds) {
        $progPlace = implode(',', array_fill(0, count($programIds), '?'));
        $pdo->prepare("DELETE FROM application_submissions WHERE application_id IN (SELECT id FROM applications WHERE assistance_id IN ($progPlace))")->execute($programIds);
        $pdo->prepare("DELETE FROM beneficiaries WHERE assistance_id IN ($progPlace)")->execute($programIds);
        $pdo->prepare("DELETE FROM assistance_requirements WHERE assistance_id IN ($progPlace)")->execute($programIds);
        $appLeft = $pdo->prepare("SELECT id FROM applications WHERE assistance_id IN ($progPlace)");
        $appLeft->execute($programIds);
        $leftIds = array_map('intval', $appLeft->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($leftIds) {
            $leftPlace = implode(',', array_fill(0, count($leftIds), '?'));
            $pdo->prepare("DELETE FROM application_submissions WHERE application_id IN ($leftPlace)")->execute($leftIds);
            $pdo->prepare("DELETE FROM applications WHERE id IN ($leftPlace)")->execute($leftIds);
        }
        $pdo->prepare("DELETE FROM assistance_programs WHERE id IN ($progPlace) AND name LIKE 'ReqHttp %'")->execute($programIds);
    }

    $check('requirements_unchanged', $beforeRequirements === $ids('assistance_requirements'));
    $check('programs_unchanged', $beforePrograms === $ids('assistance_programs'));
    $check('applications_unchanged', $beforeApplications === $ids('applications'));
    $check('submissions_unchanged', $beforeSubmissions === $ids('application_submissions'));
}

if ($failures > 0) {
    echo "FAILED {$failures}\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
