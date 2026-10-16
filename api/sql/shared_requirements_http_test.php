<?php
declare(strict_types=1);

/**
 * Shared requirements for assistance programs, regular programs, and events.
 * Creates only stamped rows and deletes those exact ids.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$pdo = youthsync_pdo();
$base = 'http://localhost/YouthSync/api';
$stamp = date('YmdHis');
$prefix = "SharedReq {$stamp}";
$failures = 0;
$assistanceIds = [];
$activityIds = [];
$requirementIds = [];
$applicationIds = [];
$submissionIds = [];

$ids = static function (string $table) use ($pdo): array {
    $rows = $pdo->query("SELECT id FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows ?: []);
};

$beforeRequirements = $ids('assistance_requirements');
$beforeAssistance = $ids('assistance_programs');
$beforePrograms = $ids('programs');
$beforeApplications = $ids('applications');
$beforeSubmissions = $ids('application_submissions');
$beforeBeneficiaries = $ids('beneficiaries');

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
        CURLOPT_TIMEOUT => 30,
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
    return 'status=' . $response['status'] . ' body=' . substr($response['body'], 0, 500);
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

$findItem = static function (array $payload, int $id): ?array {
    $items = $payload['items'] ?? $payload;
    if (!is_array($items)) {
        return null;
    }
    foreach ($items as $item) {
        if (is_array($item) && (int) ($item['id'] ?? 0) === $id) {
            return $item;
        }
    }
    return null;
};

$anon = $cookie('ys_shared_anon_');
$admin = $cookie('ys_shared_admin_');
$sk = $cookie('ys_shared_sk_');
$sk2 = $cookie('ys_shared_sk2_');
$youth = $cookie('ys_shared_youth_');

try {
    $anonPost = $req('POST', "$base/sk/requirements", json_encode(['name' => 'Anon']), $anon);
    $check('anon_create_401', $anonPost['status'] === 401, $detail($anonPost));
    $anonApply = $req('POST', "$base/youth/applications", json_encode(['programId' => 1]), $anon);
    $check('anon_apply_401', $anonApply['status'] === 401, $detail($anonApply));

    $adminLogin = $login('admin@skmms.test', 'YouthSync1!', $admin);
    $check('admin_login', $adminLogin['status'] === 200, $detail($adminLogin));
    $adminPost = $req('POST', "$base/sk/requirements", json_encode(['name' => 'Admin']), $admin);
    $check('admin_sk_route_403', $adminPost['status'] === 403, $detail($adminPost));

    $youthLogin = $login('youth-auth-test@example.test', 'YouthAuthTest1!', $youth);
    $check('youth_login', $youthLogin['status'] === 200, $detail($youthLogin));
    $youthMe = $data($req('GET', "$base/auth/me", null, $youth));
    $youthId = (int) ($youthMe['user']['youthId'] ?? $youthMe['youthId'] ?? $youthMe['youth']['id'] ?? 0);
    $check('youth_id', $youthId > 0, json_encode($youthMe));
    $youthPost = $req('POST', "$base/sk/requirements", json_encode(['name' => 'Youth']), $youth);
    $check('youth_sk_route_403', $youthPost['status'] === 403, $detail($youthPost));
    $youthReview = $req('PATCH', "$base/sk/application-requirements/1", json_encode(['status' => 'verified']), $youth);
    $check('youth_review_403', $youthReview['status'] === 403, $detail($youthReview));

    $skLogin = $login('sk1@demo.test', 'YouthSync1!', $sk);
    $check('sk_login', $skLogin['status'] === 200, $detail($skLogin));
    $me = $data($req('GET', "$base/auth/me", null, $sk));
    $orgId = (int) ($me['organization']['id'] ?? $me['organizationId'] ?? 0);
    $check('sk_org', $orgId > 0);

    $sk2Login = $login('sk2@demo.test', 'YouthSync1!', $sk2);
    $check('sk2_login', $sk2Login['status'] === 200, $detail($sk2Login));
    $me2 = $data($req('GET', "$base/auth/me", null, $sk2));
    $org2 = (int) ($me2['organization']['id'] ?? 0);
    $check('sk2_other_org', $org2 > 0 && $org2 !== $orgId);

    $assistanceCreated = $req('POST', "$base/sk/assistance", json_encode([
        'name' => $prefix . ' Assistance',
        'category' => 'scholarship',
        'status' => 'open',
        'description' => 'Shared requirements assistance',
        'slots' => 5,
        'organization_id' => $org2,
    ]), $sk);
    $assistance = $data($assistanceCreated);
    $assistanceId = (int) ($assistance['id'] ?? 0);
    if ($assistanceId > 0) {
        $assistanceIds[] = $assistanceId;
    }
    $check('assistance_created', $assistanceCreated['status'] === 201 && $assistanceId > 0, $detail($assistanceCreated));

    $assistReq = $req('POST', "$base/sk/requirements", json_encode([
        'targetType' => 'assistance',
        'targetId' => $assistanceId,
        'name' => $prefix . ' Valid ID',
        'description' => 'Government ID',
        'required' => true,
        'accepts' => 'image/*,application/pdf',
        'organization_id' => $org2,
    ]), $sk);
    $assistRequirement = $data($assistReq);
    $assistRequirementId = (int) ($assistRequirement['id'] ?? 0);
    if ($assistRequirementId > 0) {
        $requirementIds[] = $assistRequirementId;
    }
    $check('assistance_requirement_201', $assistReq['status'] === 201
        && ($assistRequirement['targetType'] ?? '') === 'assistance'
        && (int) ($assistRequirement['targetId'] ?? 0) === $assistanceId, $detail($assistReq));
    $assistRow = $pdo->prepare('SELECT organization_id, assistance_id, program_id, name FROM assistance_requirements WHERE id = :id');
    $assistRow->execute(['id' => $assistRequirementId]);
    $assistStored = $assistRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('assistance_requirement_persisted', (int) ($assistStored['organization_id'] ?? 0) === $orgId
        && (int) ($assistStored['assistance_id'] ?? 0) === $assistanceId
        && ($assistStored['program_id'] ?? null) === null, json_encode($assistStored));

    $assistUpdated = $req('PATCH', "$base/sk/requirements/{$assistRequirementId}", json_encode([
        'name' => $prefix . ' Valid Government ID',
        'description' => 'Updated assistance proof',
        'required' => true,
        'accepts' => 'image/*',
        'organization_id' => $org2,
    ]), $sk);
    $check('assistance_requirement_updated', $assistUpdated['status'] === 200
        && ($data($assistUpdated)['name'] ?? '') === $prefix . ' Valid Government ID', $detail($assistUpdated));
    $crossAssist = $req('PATCH', "$base/sk/requirements/{$assistRequirementId}", json_encode([
        'name' => 'Stolen',
    ]), $sk2);
    $check('assistance_other_org_404', $crossAssist['status'] === 404, $detail($crossAssist));

    $youthAssist = $req('GET', "$base/youth/assistance", null, $youth);
    $youthAssistItem = $findItem($data($youthAssist), $assistanceId);
    $youthAssistNames = array_map(static fn ($item) => (string) ($item['name'] ?? ''), $youthAssistItem['requirementItems'] ?? []);
    $check('youth_sees_assistance_requirement', $youthAssist['status'] === 200
        && in_array($prefix . ' Valid Government ID', $youthAssistNames, true), $detail($youthAssist));

    $assistApply = $req('POST', "$base/youth/applications", json_encode([
        'assistanceId' => $assistanceId,
        'youthId' => 1,
        'organization_id' => $org2,
        'status' => 'approved',
    ]), $youth);
    $assistApplication = $data($assistApply);
    $assistApplicationId = (int) ($assistApplication['id'] ?? 0);
    if ($assistApplicationId > 0) {
        $applicationIds[] = $assistApplicationId;
    }
    foreach ($assistApplication['submissions'] ?? [] as $submission) {
        if ((int) ($submission['id'] ?? 0) > 0) {
            $submissionIds[] = (int) $submission['id'];
        }
    }
    $check('assistance_apply', $assistApply['status'] === 201
        && ($assistApplication['status'] ?? '') === 'pending'
        && (int) ($assistApplication['youthId'] ?? 0) === $youthId, $detail($assistApply));
    $assistSubmissionId = (int) ($assistApplication['submissions'][0]['id'] ?? 0);
    $assistReview = $req('PATCH', "$base/sk/application-requirements/{$assistSubmissionId}", json_encode([
        'status' => 'verified',
        'remarks' => 'Assistance proof accepted',
    ]), $sk);
    $check('assistance_submission_verified', $assistReview['status'] === 200
        && ($data($assistReview)['status'] ?? '') === 'verified', $detail($assistReview));
    $blockedAssist = $req('DELETE', "$base/sk/requirements/{$assistRequirementId}", null, $sk);
    $check('assistance_delete_blocked', $blockedAssist['status'] >= 400, $detail($blockedAssist));

    $programCreated = $req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => $prefix . ' Program',
        'category' => 'Leadership',
        'scheduledOn' => '2026-12-20',
        'startsAt' => '08:00',
        'endsAt' => '12:00',
        'location' => 'Barangay Hall',
        'description' => 'Shared requirement program',
        'status' => 'draft',
        'organization_id' => $org2,
    ]), $sk);
    $program = $data($programCreated);
    $programId = (int) ($program['id'] ?? 0);
    if ($programId > 0) {
        $activityIds[] = $programId;
    }
    $check('program_created', $programCreated['status'] === 201 && ($program['kind'] ?? '') === 'program', $detail($programCreated));

    $draftApply = $req('POST', "$base/youth/applications", json_encode(['programId' => $programId]), $youth);
    $check('draft_program_apply_422', $draftApply['status'] === 422, $detail($draftApply));
    $published = $req('POST', "$base/sk/programs/{$programId}/status", json_encode(['status' => 'published']), $sk);
    $check('program_published', $published['status'] === 200 && ($data($published)['status'] ?? '') === 'published', $detail($published));

    $programReq = $req('POST', "$base/sk/requirements", json_encode([
        'targetType' => 'program',
        'targetId' => $programId,
        'name' => $prefix . ' Waiver',
        'description' => 'Signed waiver',
        'required' => true,
        'accepts' => 'application/pdf',
        'organization_id' => $org2,
    ]), $sk);
    $programRequirement = $data($programReq);
    $programRequirementId = (int) ($programRequirement['id'] ?? 0);
    if ($programRequirementId > 0) {
        $requirementIds[] = $programRequirementId;
    }
    $check('program_requirement_201', $programReq['status'] === 201
        && ($programRequirement['targetType'] ?? '') === 'program'
        && (int) ($programRequirement['targetId'] ?? 0) === $programId, $detail($programReq));
    $programStoredStmt = $pdo->prepare('SELECT organization_id, assistance_id, program_id, name FROM assistance_requirements WHERE id = :id');
    $programStoredStmt->execute(['id' => $programRequirementId]);
    $programStored = $programStoredStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('program_requirement_persisted', (int) ($programStored['organization_id'] ?? 0) === $orgId
        && (int) ($programStored['program_id'] ?? 0) === $programId
        && ($programStored['assistance_id'] ?? null) === null, json_encode($programStored));

    $programShown = $req('GET', "$base/sk/programs/{$programId}", null, $sk);
    $programNames = array_map(static fn ($item) => (string) ($item['name'] ?? ''), $data($programShown)['requirementItems'] ?? []);
    $check('program_requirement_listed', in_array($prefix . ' Waiver', $programNames, true), $detail($programShown));

    $programUpdated = $req('PATCH', "$base/sk/requirements/{$programRequirementId}", json_encode([
        'name' => $prefix . ' Parent Waiver',
        'description' => 'Updated waiver',
        'required' => false,
        'accepts' => 'image/*,application/pdf',
    ]), $sk);
    $check('program_requirement_updated', $programUpdated['status'] === 200
        && ($data($programUpdated)['name'] ?? '') === $prefix . ' Parent Waiver'
        && ($data($programUpdated)['required'] ?? true) === false, $detail($programUpdated));
    $programShownAgain = $req('GET', "$base/sk/programs/{$programId}", null, $sk);
    $programNamesAgain = array_map(static fn ($item) => (string) ($item['name'] ?? ''), $data($programShownAgain)['requirementItems'] ?? []);
    $check('program_requirement_persists_after_update', in_array($prefix . ' Parent Waiver', $programNamesAgain, true), $detail($programShownAgain));
    $crossProgram = $req('DELETE', "$base/sk/requirements/{$programRequirementId}", null, $sk2);
    $check('program_other_org_404', $crossProgram['status'] === 404, $detail($crossProgram));

    $youthProgram = $req('GET', "$base/youth/programs/{$programId}", null, $youth);
    $youthProgramNames = array_map(static fn ($item) => (string) ($item['name'] ?? ''), $data($youthProgram)['requirementItems'] ?? []);
    $check('youth_sees_program_requirement', $youthProgram['status'] === 200
        && in_array($prefix . ' Parent Waiver', $youthProgramNames, true), $detail($youthProgram));

    $programApply = $req('POST', "$base/youth/applications", json_encode([
        'programId' => $programId,
        'youthId' => 1,
        'organization_id' => $org2,
        'status' => 'approved',
    ]), $youth);
    $programApplication = $data($programApply);
    $programApplicationId = (int) ($programApplication['id'] ?? 0);
    if ($programApplicationId > 0) {
        $applicationIds[] = $programApplicationId;
    }
    foreach ($programApplication['submissions'] ?? [] as $submission) {
        if ((int) ($submission['id'] ?? 0) > 0) {
            $submissionIds[] = (int) $submission['id'];
        }
    }
    $programSubmissionId = (int) ($programApplication['submissions'][0]['id'] ?? 0);
    $check('program_apply', $programApply['status'] === 201
        && ($programApplication['status'] ?? '') === 'pending'
        && ($programApplication['targetType'] ?? '') === 'program'
        && (int) ($programApplication['youthId'] ?? 0) === $youthId
        && ($programApplication['submissions'][0]['status'] ?? '') === 'missing', $detail($programApply));
    $duplicateProgram = $req('POST', "$base/youth/applications", json_encode(['programId' => $programId]), $youth);
    $check('program_duplicate_409', $duplicateProgram['status'] === 409, $detail($duplicateProgram));
    $programReview = $req('PATCH', "$base/sk/application-requirements/{$programSubmissionId}", json_encode([
        'status' => 'verified',
        'remarks' => 'Waiver accepted',
    ]), $sk);
    $check('program_submission_verified', $programReview['status'] === 200
        && ($data($programReview)['status'] ?? '') === 'verified', $detail($programReview));
    $programApprove = $req('POST', "$base/sk/applications/{$programApplicationId}/approve", json_encode([
        'status' => 'approved',
        'remarks' => 'Registered',
    ]), $sk);
    $check('program_approved_without_beneficiary', $programApprove['status'] === 200
        && ($data($programApprove)['status'] ?? '') === 'approved', $detail($programApprove));
    $check('beneficiaries_unchanged', $ids('beneficiaries') === $beforeBeneficiaries);
    $blockedProgram = $req('DELETE', "$base/sk/requirements/{$programRequirementId}", null, $sk);
    $check('program_delete_blocked', $blockedProgram['status'] >= 400, $detail($blockedProgram));

    $eventCreated = $req('POST', "$base/sk/events", json_encode([
        'name' => $prefix . ' Event',
        'category' => 'Sports & Recreation',
        'scheduledOn' => '2026-12-21',
        'startsAt' => '09:00',
        'endsAt' => '11:00',
        'location' => 'Plaza',
        'status' => 'published',
        'kind' => 'program',
        'organization_id' => $org2,
    ]), $sk);
    $event = $data($eventCreated);
    $eventId = (int) ($event['id'] ?? 0);
    if ($eventId > 0) {
        $activityIds[] = $eventId;
    }
    $check('event_created', $eventCreated['status'] === 201 && ($event['kind'] ?? '') === 'event', $detail($eventCreated));

    $eventReq = $req('POST', "$base/sk/requirements", json_encode([
        'targetType' => 'program',
        'targetId' => $eventId,
        'name' => $prefix . ' Medical Clearance',
        'description' => 'Bring a medical note',
        'required' => true,
        'accepts' => 'image/*',
    ]), $sk);
    $eventRequirement = $data($eventReq);
    $eventRequirementId = (int) ($eventRequirement['id'] ?? 0);
    if ($eventRequirementId > 0) {
        $requirementIds[] = $eventRequirementId;
    }
    $check('event_requirement_201', $eventReq['status'] === 201
        && ($eventRequirement['targetType'] ?? '') === 'program'
        && (int) ($eventRequirement['targetId'] ?? 0) === $eventId, $detail($eventReq));
    $eventUpdated = $req('PATCH', "$base/sk/requirements/{$eventRequirementId}", json_encode([
        'name' => $prefix . ' Medical Note',
        'description' => 'Updated medical note',
        'required' => true,
        'accepts' => 'image/*,application/pdf',
    ]), $sk);
    $check('event_requirement_updated', $eventUpdated['status'] === 200
        && ($data($eventUpdated)['name'] ?? '') === $prefix . ' Medical Note', $detail($eventUpdated));
    $eventShown = $req('GET', "$base/sk/events/{$eventId}", null, $sk);
    $eventNames = array_map(static fn ($item) => (string) ($item['name'] ?? ''), $data($eventShown)['requirementItems'] ?? []);
    $check('event_requirement_persisted', in_array($prefix . ' Medical Note', $eventNames, true), $detail($eventShown));
    $crossEvent = $req('PATCH', "$base/sk/requirements/{$eventRequirementId}", json_encode(['name' => 'Stolen']), $sk2);
    $check('event_other_org_404', $crossEvent['status'] === 404, $detail($crossEvent));

    $youthEvent = $req('GET', "$base/youth/programs/{$eventId}", null, $youth);
    $youthEventNames = array_map(static fn ($item) => (string) ($item['name'] ?? ''), $data($youthEvent)['requirementItems'] ?? []);
    $check('youth_sees_event_requirement', $youthEvent['status'] === 200
        && ($data($youthEvent)['kind'] ?? '') === 'event'
        && in_array($prefix . ' Medical Note', $youthEventNames, true), $detail($youthEvent));

    $eventApply = $req('POST', "$base/youth/applications", json_encode([
        'programId' => $eventId,
        'status' => 'approved',
        'organizationId' => $org2,
    ]), $youth);
    $eventApplication = $data($eventApply);
    $eventApplicationId = (int) ($eventApplication['id'] ?? 0);
    if ($eventApplicationId > 0) {
        $applicationIds[] = $eventApplicationId;
    }
    foreach ($eventApplication['submissions'] ?? [] as $submission) {
        if ((int) ($submission['id'] ?? 0) > 0) {
            $submissionIds[] = (int) $submission['id'];
        }
    }
    $eventSubmissionId = (int) ($eventApplication['submissions'][0]['id'] ?? 0);
    $check('event_apply', $eventApply['status'] === 201
        && ($eventApplication['status'] ?? '') === 'pending'
        && (int) ($eventApplication['programId'] ?? 0) === $eventId
        && (int) ($eventApplication['youthId'] ?? 0) === $youthId, $detail($eventApply));
    $eventReview = $req('PATCH', "$base/sk/application-requirements/{$eventSubmissionId}", json_encode([
        'status' => 'verified',
    ]), $sk);
    $check('event_submission_verified', $eventReview['status'] === 200
        && ($data($eventReview)['status'] ?? '') === 'verified', $detail($eventReview));
    $youthEventApp = $req('GET', "$base/youth/applications/{$eventApplicationId}", null, $youth);
    $check('youth_sees_reviewed_event_submission', $youthEventApp['status'] === 200
        && ($data($youthEventApp)['submissions'][0]['status'] ?? '') === 'verified', $detail($youthEventApp));

    $emptyName = $req('POST', "$base/sk/requirements", json_encode([
        'targetType' => 'program',
        'targetId' => $programId,
        'name' => '',
    ]), $sk);
    $check('empty_name_422', $emptyName['status'] === 422, $detail($emptyName));
} finally {
    if ($submissionIds !== []) {
        $marks = implode(',', array_fill(0, count($submissionIds), '?'));
        $pdo->prepare("DELETE FROM application_submissions WHERE id IN ({$marks})")->execute($submissionIds);
    }
    if ($applicationIds !== []) {
        $marks = implode(',', array_fill(0, count($applicationIds), '?'));
        $pdo->prepare("DELETE FROM applications WHERE id IN ({$marks})")->execute($applicationIds);
    }
    if ($requirementIds !== []) {
        $marks = implode(',', array_fill(0, count($requirementIds), '?'));
        $pdo->prepare("DELETE FROM assistance_requirements WHERE id IN ({$marks})")->execute($requirementIds);
    }
    foreach ($assistanceIds as $id) {
        $pdo->prepare("DELETE FROM assistance_programs WHERE id = :id AND name LIKE 'SharedReq %'")->execute(['id' => $id]);
    }
    foreach ($activityIds as $id) {
        $pdo->prepare("DELETE FROM programs WHERE id = :id AND name LIKE 'SharedReq %'")->execute(['id' => $id]);
    }
    foreach ([$anon, $admin, $sk, $sk2, $youth] as $file) {
        @unlink($file);
    }
}

$check('requirements_unchanged', $ids('assistance_requirements') === $beforeRequirements);
$check('assistance_unchanged', $ids('assistance_programs') === $beforeAssistance);
$check('programs_unchanged', $ids('programs') === $beforePrograms);
$check('applications_unchanged', $ids('applications') === $beforeApplications);
$check('submissions_unchanged', $ids('application_submissions') === $beforeSubmissions);
$check('beneficiaries_restored', $ids('beneficiaries') === $beforeBeneficiaries);

if ($failures > 0) {
    echo "FAILED {$failures}\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
