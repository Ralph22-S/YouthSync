<?php
declare(strict_types=1);

/**
 * Application requirement document upload.
 * Creates only stamped rows and deletes those exact ids and files.
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
$prefix = "ReqFile {$stamp}";
$failures = 0;
$assistanceIds = [];
$activityIds = [];
$requirementIds = [];
$applicationIds = [];
$submissionIds = [];
$storageNames = [];
$createdYouthId = 0;
$createdUserId = 0;
$accountEmail = 'reqfile-' . $stamp . '@example.test';

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
$beforeYouth = $ids('youth');
$beforeUsers = $ids('users');

$check = static function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}";
    if (!$ok && $detail !== '') {
        echo " — {$detail}";
    }
    echo "\n";
    if (!$ok) {
        $failures++;
    }
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
        return ['status' => 0, 'body' => $err, 'json' => null, 'contentType' => ''];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $body = substr($raw, $headerSize);
    $decoded = json_decode($body, true);
    return [
        'status' => $status,
        'body' => $body,
        'json' => is_array($decoded) ? $decoded : null,
        'contentType' => $contentType,
    ];
};

$upload = static function (string $url, string $path, string $mime, string $filename, string $cookie) use ($req): array {
    $ch = curl_init($url);
    $file = new CURLFile($path, $mime, $filename);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => ['file' => $file],
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'body' => $err, 'json' => null, 'contentType' => ''];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $body = substr($raw, $headerSize);
    $decoded = json_decode($body, true);
    return [
        'status' => $status,
        'body' => $body,
        'json' => is_array($decoded) ? $decoded : null,
        'contentType' => $contentType,
    ];
};

$data = static function (array $row): array {
    $payload = $row['json']['data'] ?? null;
    return is_array($payload) ? $payload : [];
};
$detail = static function (array $row): string {
    $code = (string) ($row['json']['error']['code'] ?? '');
    $message = (string) ($row['json']['error']['message'] ?? '');
    $snippet = $message !== '' ? $message : substr(preg_replace('/\s+/', ' ', (string) $row['body']) ?? '', 0, 180);
    return 'status=' . $row['status'] . ($code !== '' ? " code={$code}" : '') . " {$snippet}";
};
$login = static function (string $email, string $password, string $cookie) use ($req, $base): array {
    return $req('POST', "$base/auth/login", json_encode([
        'email' => $email,
        'password' => $password,
    ]), $cookie);
};
$cookie = static function (string $name) use ($stamp): string {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . $stamp . '-' . getmypid() . '.txt';
    @unlink($path);
    return $path;
};
$storageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'application_requirements';
$remember = static function (int $submissionId) use (&$storageNames, $pdo): void {
    if ($submissionId < 1) {
        return;
    }
    $stmt = $pdo->prepare('SELECT file_ref FROM application_submissions WHERE id = :id');
    $stmt->execute(['id' => $submissionId]);
    $name = (string) $stmt->fetchColumn();
    if ($name !== '') {
        $storageNames[$name] = true;
    }
};

$pdfPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "reqfile-{$stamp}.pdf";
$pngPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "reqfile-{$stamp}.png";
$txtPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "reqfile-{$stamp}.txt";
$bigPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "reqfile-{$stamp}-big.pdf";
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
file_put_contents($txtPath, 'not a document');
$big = fopen($bigPath, 'wb');
fwrite($big, '%PDF-1.4 ');
fwrite($big, str_repeat('A', (8 * 1024 * 1024) + 32));
fclose($big);

$anon = $cookie('ys_reqfile_anon_');
$admin = $cookie('ys_reqfile_admin_');
$sk = $cookie('ys_reqfile_sk_');
$sk2 = $cookie('ys_reqfile_sk2_');
$youth = $cookie('ys_reqfile_youth_');
$youthB = $cookie('ys_reqfile_youthb_');

try {
    $youthLogin = $login('youth-auth-test@example.test', 'YouthAuthTest1!', $youth);
    $check('youth_login', $youthLogin['status'] === 200, $detail($youthLogin));
    $youthMe = $data($req('GET', "$base/auth/me", null, $youth));
    $youthId = (int) ($youthMe['user']['youthId'] ?? $youthMe['youthId'] ?? $youthMe['youth']['id'] ?? 0);
    $check('youth_id', $youthId > 0, json_encode($youthMe));

    $adminLogin = $login('admin@skmms.test', 'YouthSync1!', $admin);
    $check('admin_login', $adminLogin['status'] === 200, $detail($adminLogin));
    $skLogin = $login('sk1@demo.test', 'YouthSync1!', $sk);
    $me = $data($req('GET', "$base/auth/me", null, $sk));
    $orgId = (int) ($me['organization']['id'] ?? 0);
    $check('sk_login', $skLogin['status'] === 200 && $orgId > 0, $detail($skLogin));
    $sk2Login = $login('sk2@demo.test', 'YouthSync1!', $sk2);
    $me2 = $data($req('GET', "$base/auth/me", null, $sk2));
    $org2 = (int) ($me2['organization']['id'] ?? 0);
    $check('sk2_other_org', $sk2Login['status'] === 200 && $org2 > 0 && $org2 !== $orgId, $detail($sk2Login));

    $created = $req('POST', "$base/sk/youth", json_encode([
        'firstName' => 'ReqFile',
        'lastName' => 'Other' . $stamp,
        'birthDate' => '2006-04-04',
        'gender' => 'Female',
        'address' => 'Requirement file test address',
        'contact' => '09180001111',
        'civilStatus' => 'Single',
        'educationStatus' => 'Currently Studying',
        'education' => 'Senior High School',
        'employment' => 'Student',
        'interests' => ['Community service'],
        'createAccount' => true,
        'accountEmail' => $accountEmail,
        'organization_id' => $org2,
    ]), $sk);
    $createdData = $data($created);
    $temporary = (string) ($createdData['temporaryPassword'] ?? $createdData['temporary_password'] ?? '');
    $createdYouthId = (int) ($createdData['id'] ?? $createdData['youth']['id'] ?? 0);
    $createdUserId = (int) ($createdData['user']['id'] ?? 0);
    $check('other_youth_created', $created['status'] === 201 && $createdYouthId > 0 && $temporary !== ''
        && !str_contains((string) $created['body'], 'password_hash'), $detail($created));
    $otherLogin = $login($accountEmail, $temporary, $youthB);
    $check('other_youth_login', $otherLogin['status'] === 200, $detail($otherLogin));

    $programCreated = $req('POST', "$base/sk/programs", json_encode([
        'kind' => 'program',
        'name' => $prefix . ' Program',
        'category' => 'Leadership',
        'scheduledOn' => '2026-12-20',
        'startsAt' => '08:00',
        'endsAt' => '12:00',
        'location' => 'Barangay Hall',
        'description' => 'Requirement file program',
        'status' => 'published',
        'organization_id' => $org2,
    ]), $sk);
    $programId = (int) ($data($programCreated)['id'] ?? 0);
    if ($programId > 0) {
        $activityIds[] = $programId;
    }
    $check('program_created', $programCreated['status'] === 201 && ($data($programCreated)['kind'] ?? '') === 'program', $detail($programCreated));

    $programReq = $req('POST', "$base/sk/requirements", json_encode([
        'targetType' => 'program',
        'targetId' => $programId,
        'name' => $prefix . ' Waiver',
        'description' => 'Signed waiver',
        'required' => true,
        'accepts' => 'image/*,application/pdf',
    ]), $sk);
    $programRequirementId = (int) ($data($programReq)['id'] ?? 0);
    if ($programRequirementId > 0) {
        $requirementIds[] = $programRequirementId;
    }
    $deleteReq = $req('POST', "$base/sk/requirements", json_encode([
        'targetType' => 'program',
        'targetId' => $programId,
        'name' => $prefix . ' Optional note',
        'required' => false,
        'accepts' => 'application/pdf',
    ]), $sk);
    $deleteRequirementId = (int) ($data($deleteReq)['id'] ?? 0);
    if ($deleteRequirementId > 0) {
        $requirementIds[] = $deleteRequirementId;
    }
    $check('program_requirements', $programReq['status'] === 201 && $deleteReq['status'] === 201, $detail($programReq));

    $apply = $req('POST', "$base/youth/applications", json_encode([
        'programId' => $programId,
        'youthId' => 1,
        'organization_id' => $org2,
        'status' => 'approved',
    ]), $youth);
    $application = $data($apply);
    $applicationId = (int) ($application['id'] ?? 0);
    if ($applicationId > 0) {
        $applicationIds[] = $applicationId;
    }
    $submissionId = 0;
    $deleteSubmissionId = 0;
    foreach ($application['submissions'] ?? [] as $submission) {
        $sid = (int) ($submission['id'] ?? 0);
        if ($sid > 0) {
            $submissionIds[] = $sid;
        }
        if ((int) ($submission['requirementId'] ?? 0) === $programRequirementId) {
            $submissionId = $sid;
        }
        if ((int) ($submission['requirementId'] ?? 0) === $deleteRequirementId) {
            $deleteSubmissionId = $sid;
        }
    }
    $check('program_apply_pending', $apply['status'] === 201
        && ($application['status'] ?? '') === 'pending'
        && (int) ($application['youthId'] ?? 0) === $youthId
        && $submissionId > 0, $detail($apply));

    $fileUrl = "$base/youth/applications/{$applicationId}/requirements/{$programRequirementId}/file";
    $skFileUrl = "$base/sk/applications/{$applicationId}/requirements/{$programRequirementId}/file";
    $anonFile = $req('GET', $fileUrl, null, $anon);
    $check('anonymous_rejected', $anonFile['status'] === 401, $detail($anonFile));
    $adminFile = $req('GET', $skFileUrl, null, $admin);
    $check('admin_rejected', $adminFile['status'] === 403, $detail($adminFile));

    $badType = $upload($fileUrl, $txtPath, 'text/plain', 'notes.txt', $youth);
    $check('unsupported_type_rejected', $badType['status'] === 422, $detail($badType));
    $tooBig = $upload($fileUrl, $bigPath, 'application/pdf', 'huge.pdf', $youth);
    $check('oversized_rejected', $tooBig['status'] === 422, $detail($tooBig));

    $uploaded = $upload($fileUrl, $pdfPath, 'application/pdf', 'Parent Waiver.pdf', $youth);
    $uploadedData = $data($uploaded);
    $check('pdf_upload_success', $uploaded['status'] === 200
        && ($uploadedData['status'] ?? '') === 'submitted'
        && ($uploadedData['hasFile'] ?? false) === true
        && ($uploadedData['fileName'] ?? '') !== ''
        && !str_contains((string) $uploaded['body'], 'password_hash')
        && !str_contains((string) $uploaded['body'], 'application_requirements'), $detail($uploaded));
    $check('application_stays_pending', ($data($req('GET', "$base/youth/applications/{$applicationId}", null, $youth))['status'] ?? '') === 'pending');

    $rowStmt = $pdo->prepare('SELECT file_name, file_type, file_size, file_ref, status FROM application_submissions WHERE id = :id');
    $rowStmt->execute(['id' => $submissionId]);
    $stored = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $storageName = (string) ($stored['file_ref'] ?? '');
    $storageNames[$storageName] = true;
    $storedPath = $storageDir . DIRECTORY_SEPARATOR . $storageName;
    $check('metadata_stored', ($stored['status'] ?? '') === 'submitted'
        && ($stored['file_type'] ?? '') === 'application/pdf'
        && (int) ($stored['file_size'] ?? 0) > 0
        && preg_match('/^[a-f0-9]{32}\.pdf$/', $storageName) === 1
        && $storageName !== ($stored['file_name'] ?? ''), json_encode($stored));
    $check('physical_file_exists', $storageName !== '' && is_file($storedPath));
    $check('response_hides_storage_name', $storageName !== '' && !str_contains((string) $uploaded['body'], $storageName));

    $own = $req('GET', $fileUrl, null, $youth);
    $check('youth_can_download', $own['status'] === 200
        && str_contains(strtolower($own['contentType']), 'application/pdf')
        && str_starts_with((string) $own['body'], '%PDF'), $detail($own));
    $other = $req('GET', $fileUrl, null, $youthB);
    $check('other_youth_rejected', $other['status'] === 404, $detail($other));
    $otherUpload = $upload($fileUrl, $pdfPath, 'application/pdf', 'stolen.pdf', $youthB);
    $check('other_youth_upload_rejected', $otherUpload['status'] === 404, $detail($otherUpload));
    $cross = $req('GET', $skFileUrl, null, $sk2);
    $check('other_org_sk_rejected', $cross['status'] === 404, $detail($cross));
    $skOwn = $req('GET', $skFileUrl, null, $sk);
    $check('same_org_sk_can_download', $skOwn['status'] === 200
        && str_starts_with((string) $skOwn['body'], '%PDF'), $detail($skOwn));

    $replaced = $upload($fileUrl, $pngPath, 'image/png', 'waiver-photo.png', $youth);
    $rowStmt->execute(['id' => $submissionId]);
    $replacedRow = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $newName = (string) ($replacedRow['file_ref'] ?? '');
    $storageNames[$newName] = true;
    $check('replacement_success', $replaced['status'] === 200
        && ($data($replaced)['status'] ?? '') === 'submitted'
        && ($data($replaced)['fileType'] ?? '') === 'image/png'
        && $newName !== $storageName
        && preg_match('/^[a-f0-9]{32}\.png$/', $newName) === 1, $detail($replaced));
    clearstatcache(true, $storedPath);
    $newPath = $storageDir . DIRECTORY_SEPARATOR . $newName;
    $check('old_file_removed', $storageName !== '' && !is_file($storedPath) && is_file($newPath));

    $reviewed = $req('PATCH', "$base/sk/application-requirements/{$submissionId}", json_encode([
        'status' => 'verified',
    ]), $sk);
    $check('sk_verify_after_upload', $reviewed['status'] === 200 && ($data($reviewed)['status'] ?? '') === 'verified', $detail($reviewed));
    $lockedUpload = $upload($fileUrl, $pdfPath, 'application/pdf', 'again.pdf', $youth);
    $check('verified_upload_rejected', $lockedUpload['status'] === 422, $detail($lockedUpload));

    $deleteUrl = "$base/youth/applications/{$applicationId}/requirements/{$deleteRequirementId}/file";
    $pngOnPdf = $upload($deleteUrl, $pngPath, 'image/png', 'note.png', $youth);
    $check('pdf_only_rejects_png', $pngOnPdf['status'] === 422, $detail($pngOnPdf));
    $deletedUpload = $upload($deleteUrl, $pdfPath, 'application/pdf', 'optional-note.pdf', $youth);
    $rowStmt->execute(['id' => $deleteSubmissionId]);
    $deleteStored = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $deleteName = (string) ($deleteStored['file_ref'] ?? '');
    $storageNames[$deleteName] = true;
    $removed = $req('DELETE', $deleteUrl, null, $youth);
    $check('delete_clears_metadata', $deletedUpload['status'] === 200
        && $removed['status'] === 200
        && ($data($removed)['hasFile'] ?? true) === false
        && ($data($removed)['status'] ?? '') === 'missing'
        && ($data($removed)['fileName'] ?? 'x') === '', $detail($removed));
    $check('delete_removes_file', $deleteName !== '' && !is_file($storageDir . DIRECTORY_SEPARATOR . $deleteName));
    $check('delete_keeps_application', ($data($req('GET', "$base/youth/applications/{$applicationId}", null, $youth))['status'] ?? '') === 'pending');

    $approved = $req('POST', "$base/sk/applications/{$applicationId}/approve", json_encode([]), $sk);
    $check('approval_unchanged', $approved['status'] === 200 && ($data($approved)['status'] ?? '') === 'approved', $detail($approved));
    $check('beneficiaries_unchanged', $ids('beneficiaries') === $beforeBeneficiaries);
    $afterApprove = $upload($fileUrl, $pdfPath, 'application/pdf', 'late.pdf', $youth);
    $check('approved_upload_rejected', $afterApprove['status'] === 422, $detail($afterApprove));

    $assistanceCreated = $req('POST', "$base/sk/assistance", json_encode([
        'name' => $prefix . ' Assistance',
        'category' => 'scholarship',
        'status' => 'open',
        'description' => 'Requirement file assistance',
        'slots' => 3,
    ]), $sk);
    $assistanceId = (int) ($data($assistanceCreated)['id'] ?? 0);
    if ($assistanceId > 0) {
        $assistanceIds[] = $assistanceId;
    }
    $assistReq = $req('POST', "$base/sk/requirements", json_encode([
        'targetType' => 'assistance',
        'targetId' => $assistanceId,
        'name' => $prefix . ' Valid ID',
        'required' => true,
        'accepts' => 'image/*,application/pdf',
    ]), $sk);
    $assistRequirementId = (int) ($data($assistReq)['id'] ?? 0);
    if ($assistRequirementId > 0) {
        $requirementIds[] = $assistRequirementId;
    }
    $assistApply = $req('POST', "$base/youth/applications", json_encode(['assistanceId' => $assistanceId]), $youth);
    $assistApplicationId = (int) ($data($assistApply)['id'] ?? 0);
    if ($assistApplicationId > 0) {
        $applicationIds[] = $assistApplicationId;
    }
    $assistSubmissionId = 0;
    foreach ($data($assistApply)['submissions'] ?? [] as $submission) {
        $sid = (int) ($submission['id'] ?? 0);
        if ($sid > 0) {
            $submissionIds[] = $sid;
            $assistSubmissionId = $sid;
        }
    }
    $assistUrl = "$base/youth/applications/{$assistApplicationId}/requirements/{$assistRequirementId}/file";
    $assistUpload = $upload($assistUrl, $pdfPath, 'application/pdf', 'valid-id.pdf', $youth);
    $remember($assistSubmissionId);
    $assistReview = $req('PATCH', "$base/sk/application-requirements/{$assistSubmissionId}", json_encode(['status' => 'verified']), $sk);
    $check('assistance_file_flow', $assistApply['status'] === 201
        && $assistUpload['status'] === 200
        && ($data($assistUpload)['status'] ?? '') === 'submitted'
        && $assistReview['status'] === 200
        && ($data($assistReview)['status'] ?? '') === 'verified', $detail($assistUpload) . ' ' . $detail($assistReview));

    $eventCreated = $req('POST', "$base/sk/events", json_encode([
        'name' => $prefix . ' Event',
        'category' => 'Sports & Recreation',
        'scheduledOn' => '2026-12-21',
        'startsAt' => '09:00',
        'endsAt' => '11:00',
        'location' => 'Plaza',
        'status' => 'published',
    ]), $sk);
    $eventId = (int) ($data($eventCreated)['id'] ?? 0);
    if ($eventId > 0) {
        $activityIds[] = $eventId;
    }
    $eventReq = $req('POST', "$base/sk/requirements", json_encode([
        'targetType' => 'program',
        'targetId' => $eventId,
        'name' => $prefix . ' Clearance',
        'required' => true,
        'accepts' => 'image/*',
    ]), $sk);
    $eventRequirementId = (int) ($data($eventReq)['id'] ?? 0);
    if ($eventRequirementId > 0) {
        $requirementIds[] = $eventRequirementId;
    }
    $check('event_created', $eventCreated['status'] === 201 && ($data($eventCreated)['kind'] ?? '') === 'event', $detail($eventCreated));
    $eventApply = $req('POST', "$base/youth/applications", json_encode(['programId' => $eventId]), $youth);
    $eventApplicationId = (int) ($data($eventApply)['id'] ?? 0);
    if ($eventApplicationId > 0) {
        $applicationIds[] = $eventApplicationId;
    }
    $eventSubmissionId = 0;
    foreach ($data($eventApply)['submissions'] ?? [] as $submission) {
        $sid = (int) ($submission['id'] ?? 0);
        if ($sid > 0) {
            $submissionIds[] = $sid;
            $eventSubmissionId = $sid;
        }
    }
    $eventUrl = "$base/youth/applications/{$eventApplicationId}/requirements/{$eventRequirementId}/file";
    $eventPdf = $upload($eventUrl, $pdfPath, 'application/pdf', 'clearance.pdf', $youth);
    $eventUpload = $upload($eventUrl, $pngPath, 'image/png', 'clearance.png', $youth);
    $remember($eventSubmissionId);
    $eventSk = $req('GET', "$base/sk/applications/{$eventApplicationId}/requirements/{$eventRequirementId}/file", null, $sk);
    $check('event_file_flow', $eventApply['status'] === 201
        && $eventPdf['status'] === 422
        && $eventUpload['status'] === 200
        && ($data($eventUpload)['fileType'] ?? '') === 'image/png'
        && $eventSk['status'] === 200
        && str_contains(strtolower($eventSk['contentType']), 'image/png'), $detail($eventUpload));
} finally {
    foreach (array_keys($storageNames) as $name) {
        if (preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', (string) $name) === 1) {
            $path = $storageDir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
    if ($submissionIds !== []) {
        $marks = implode(',', array_fill(0, count($submissionIds), '?'));
        $pdo->prepare("DELETE FROM application_submissions WHERE id IN ({$marks})")->execute($submissionIds);
    }
    if ($applicationIds !== []) {
        $marks = implode(',', array_fill(0, count($applicationIds), '?'));
        $pdo->prepare("DELETE FROM application_submissions WHERE application_id IN ({$marks})")->execute($applicationIds);
        $pdo->prepare("DELETE FROM applications WHERE id IN ({$marks})")->execute($applicationIds);
    }
    if ($requirementIds !== []) {
        $marks = implode(',', array_fill(0, count($requirementIds), '?'));
        $pdo->prepare("DELETE FROM application_submissions WHERE requirement_id IN ({$marks})")->execute($requirementIds);
        $pdo->prepare("DELETE FROM assistance_requirements WHERE id IN ({$marks})")->execute($requirementIds);
    }
    if ($assistanceIds !== []) {
        $marks = implode(',', array_fill(0, count($assistanceIds), '?'));
        $pdo->prepare("DELETE FROM assistance_programs WHERE id IN ({$marks})")->execute($assistanceIds);
    }
    if ($activityIds !== []) {
        $marks = implode(',', array_fill(0, count($activityIds), '?'));
        $pdo->prepare("DELETE FROM programs WHERE id IN ({$marks})")->execute($activityIds);
    }
    if ($createdUserId > 0) {
        $pdo->prepare('DELETE FROM organization_users WHERE user_id = :id')->execute(['id' => $createdUserId]);
        $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :email')->execute([
            'id' => $createdUserId,
            'email' => $accountEmail,
        ]);
    }
    if ($createdYouthId > 0) {
        $pdo->prepare("DELETE FROM youth WHERE id = :id AND first_name = 'ReqFile'")->execute(['id' => $createdYouthId]);
    }
    foreach ([$pdfPath, $pngPath, $txtPath, $bigPath, $anon, $admin, $sk, $sk2, $youth, $youthB] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    $check('requirements_unchanged', $ids('assistance_requirements') === $beforeRequirements);
    $check('assistance_unchanged', $ids('assistance_programs') === $beforeAssistance);
    $check('programs_unchanged', $ids('programs') === $beforePrograms);
    $check('applications_unchanged', $ids('applications') === $beforeApplications);
    $check('submissions_unchanged', $ids('application_submissions') === $beforeSubmissions);
    $check('beneficiaries_still_unchanged', $ids('beneficiaries') === $beforeBeneficiaries);
    $check('youth_unchanged', $ids('youth') === $beforeYouth);
    $check('users_unchanged', $ids('users') === $beforeUsers);
}

echo $failures === 0 ? "ALL PASSED\n" : "FAILED {$failures}\n";
exit($failures === 0 ? 0 : 1);
