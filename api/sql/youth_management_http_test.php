<?php
declare(strict_types=1);

/**
 * Safe Youth Management checks against the live YouthSync API.
 *
 * Creates youth rows with a Ymgt prefix and deletes only those ids.
 * Restores the dedicated youth-auth-test profile fields it changes.
 * Does not change demo passwords or delete other records.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = 'http://localhost/YouthSync/api';
$adminEmail = 'admin@skmms.test';
$skEmail = 'sk1@demo.test';
$sk2Email = 'sk2@demo.test';
$demoPassword = 'YouthSync1!';
$youthEmail = 'youth-auth-test@example.test';
$youthPassword = 'YouthAuthTest1!';
$stamp = date('YmdHis');

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';
$pdo = youthsync_pdo();

$failed = 0;
$createdIds = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}";
    if (!$ok && $detail !== '') {
        echo " — {$detail}";
    }
    echo "\n";
    if (!$ok) {
        $failed++;
    }
};

function ym_req(string $method, string $url, ?string $json, string $cookie): array
{
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
        return ['status' => 0, 'headers' => '', 'body' => $err, 'json' => null, 'type' => ''];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $headerSize);
    $decoded = json_decode($body, true);
    return [
        'status' => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body' => $body,
        'json' => is_array($decoded) ? $decoded : null,
        'type' => $type,
    ];
}

function ym_upload(string $url, string $path, string $filename, string $cookie, string $mime): array
{
    $ch = curl_init($url);
    $post = ['file' => new CURLFile($path, $mime, $filename)];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'body' => $err, 'json' => null, 'type' => ''];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $headerSize);
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null, 'type' => $type];
}

function ym_detail(array $row): string
{
    $code = (string) ($row['json']['error']['code'] ?? '');
    $message = (string) ($row['json']['error']['message'] ?? '');
    $snippet = $message !== '' ? $message : substr(preg_replace('/\s+/', ' ', (string) $row['body']) ?? '', 0, 180);
    return 'status=' . $row['status'] . ($code !== '' ? " code={$code}" : '') . " {$snippet}";
}

function ym_data(array $row): array
{
    $data = $row['json']['data'] ?? null;
    return is_array($data) ? $data : [];
}

function ym_jar(string $name): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . '-' . getmypid() . '.txt';
    @unlink($path);
    return $path;
}

function ym_youth(string $first, string $last, string $birth = '2004-03-15'): array
{
    return [
        'firstName' => $first,
        'middleName' => '',
        'lastName' => $last,
        'birthDate' => $birth,
        'gender' => 'Female',
        'address' => 'Ymgt test address',
        'contact' => '09170001122',
        'email' => '',
        'civilStatus' => 'Single',
        'educationStatus' => 'Currently Studying',
        'education' => 'Senior High School',
        'school' => 'Ymgt School',
        'employment' => 'Student',
        'interests' => ['Community service'],
        'skills' => ['First aid'],
        'preferredActivities' => ['Sports'],
        'guardianName' => 'Ymgt Guardian',
        'guardianEmployment' => 'Retired',
        'familyIncome' => 1000,
        'familyMembers' => 3,
    ];
}

function ym_jpeg(string $path, int $red, int $green, int $blue): void
{
    if (!function_exists('imagejpeg')) {
        throw new RuntimeException('GD is required to build a test JPEG.');
    }
    $image = imagecreatetruecolor(16, 16);
    $color = imagecolorallocate($image, $red, $green, $blue);
    imagefilledrectangle($image, 0, 0, 15, 15, $color);
    imagejpeg($image, $path, 90);
    imagedestroy($image);
}

$anon = ym_jar('ym-anon');
$sk = ym_jar('ym-sk');
$sk2 = ym_jar('ym-sk2');
$admin = ym_jar('ym-admin');
$youth = ym_jar('ym-youth');
$jpegA = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "ym-a-{$stamp}.jpg";
$jpegB = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "ym-b-{$stamp}.jpg";
$textFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "ym-bad-{$stamp}.txt";
$bigFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "ym-big-{$stamp}.bin";
$pdfFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "ym-doc-{$stamp}.pdf";
$originalProfile = null;
$rawProfile = null;
$dedicatedYouthId = 0;

try {
    ym_jpeg($jpegA, 200, 20, 20);
    ym_jpeg($jpegB, 20, 20, 200);
    file_put_contents($textFile, 'not an image');
    file_put_contents($bigFile, str_repeat('A', 2097152 + 32));
    file_put_contents($pdfFile, "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 9 9] >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

    $r = ym_req('GET', "$base/sk/youth", null, $anon);
    $check('anonymous_sk_youth_rejected', $r['status'] === 401, ym_detail($r));
    $r = ym_req('GET', "$base/youth/profile", null, $anon);
    $check('anonymous_youth_profile_rejected', $r['status'] === 401, ym_detail($r));
    $r = ym_req('PATCH', "$base/youth/profile", '{}', $anon);
    $check('anonymous_youth_profile_patch_rejected', $r['status'] === 401, ym_detail($r));
    $r = ym_upload("$base/youth/profile/photo", $jpegA, 'photo.jpg', $anon, 'image/jpeg');
    $check('anonymous_photo_upload_rejected', $r['status'] === 401, ym_detail($r));

    $r = ym_req('POST', "$base/auth/login", json_encode(['email' => $adminEmail, 'password' => $demoPassword]), $admin);
    $check('admin_login', $r['status'] === 200 && (ym_data($r)['role'] ?? '') === 'SYSTEM_ADMIN', ym_detail($r));
    $r = ym_req('GET', "$base/auth/me", null, $admin);
    $check('admin_me', $r['status'] === 200 && (ym_data($r)['role'] ?? '') === 'SYSTEM_ADMIN', ym_detail($r));
    $r = ym_req('GET', "$base/sk/youth", null, $admin);
    $check('admin_sk_youth_forbidden', $r['status'] === 403, ym_detail($r));
    $r = ym_req('POST', "$base/sk/youth", json_encode(ym_youth('Ymgt', 'Admin' . $stamp)), $admin);
    $check('admin_sk_youth_create_forbidden', $r['status'] === 403, ym_detail($r));
    $r = ym_req('GET', "$base/youth/profile", null, $admin);
    $check('admin_youth_profile_forbidden', $r['status'] === 403, ym_detail($r));

    $r = ym_req('POST', "$base/auth/login", json_encode(['email' => $skEmail, 'password' => $demoPassword]), $sk);
    $check('sk_login', $r['status'] === 200 && (ym_data($r)['role'] ?? '') === 'SK_OFFICIAL', ym_detail($r));
    $skMe = ym_req('GET', "$base/auth/me", null, $sk);
    $skOrg = (int) (ym_data($skMe)['organization']['id'] ?? 0);
    $check('sk_me', $skMe['status'] === 200 && (ym_data($skMe)['role'] ?? '') === 'SK_OFFICIAL' && $skOrg > 0, ym_detail($skMe));
    $r = ym_req('GET', "$base/youth/profile", null, $sk);
    $check('sk_youth_profile_forbidden', $r['status'] === 403, ym_detail($r));
    $r = ym_req('GET', "$base/admin/users", null, $sk);
    $check('sk_admin_users_forbidden', $r['status'] === 403, ym_detail($r));

    $payload = ym_youth('Ymgt', 'Rec' . $stamp);
    $r = ym_req('POST', "$base/sk/youth", json_encode($payload), $sk);
    $created = ym_data($r)['youth'] ?? ym_data($r);
    $youthId = (int) ($created['id'] ?? 0);
    if ($youthId > 0) {
        $createdIds[] = $youthId;
    }
    $check('sk_create', $r['status'] === 201 && $youthId > 0 && ($created['lastName'] ?? '') === 'Rec' . $stamp, ym_detail($r));
    $check('sk_create_guardian_retired', ($created['guardianEmployment'] ?? '') === 'Retired', ym_detail($r));

    $r = ym_req('POST', "$base/sk/youth", json_encode($payload), $sk);
    $check('sk_duplicate_rejected', $r['status'] === 409, ym_detail($r));

    $r = ym_req('GET', "$base/sk/youth?q=" . rawurlencode('Rec' . $stamp), null, $sk);
    $items = ym_data($r)['items'] ?? [];
    $found = false;
    foreach ($items as $item) {
        if ((int) ($item['id'] ?? 0) === $youthId) {
            $found = true;
        }
    }
    $check('sk_search', $r['status'] === 200 && $found, ym_detail($r));

    $r = ym_req('GET', "$base/sk/youth/{$youthId}", null, $sk);
    $check('sk_get', $r['status'] === 200 && (int) (ym_data($r)['id'] ?? 0) === $youthId, ym_detail($r));

    $payload['address'] = 'Ymgt updated address';
    $payload['photo'] = 'data:image/jpeg;base64,AAAA';
    $payload['organization_id'] = 2;
    $payload['youth_id'] = 1;
    $r = ym_req('PATCH', "$base/sk/youth/{$youthId}", json_encode($payload), $sk);
    $updated = ym_data($r);
    $check(
        'sk_update',
        $r['status'] === 200 && ($updated['address'] ?? '') === 'Ymgt updated address' && !is_string($updated['photo'] ?? null),
        ym_detail($r)
    );

    $r = ym_req('POST', "$base/sk/youth/{$youthId}/archive", '{}', $sk);
    $check('sk_archive', $r['status'] === 200 && (ym_data($r)['archived'] ?? false) === true, ym_detail($r));
    $r = ym_req('GET', "$base/sk/youth?archived=1&q=" . rawurlencode('Rec' . $stamp), null, $sk);
    $archivedFound = false;
    foreach (ym_data($r)['items'] ?? [] as $item) {
        if ((int) ($item['id'] ?? 0) === $youthId) {
            $archivedFound = true;
        }
    }
    $check('sk_archived_list', $r['status'] === 200 && $archivedFound, ym_detail($r));
    $r = ym_req('POST', "$base/sk/youth/{$youthId}/restore", '{}', $sk);
    $check('sk_restore', $r['status'] === 200 && (ym_data($r)['archived'] ?? true) === false, ym_detail($r));

    $importRow = ym_youth('Ymgt', 'Imp' . $stamp, '2005-04-16');
    $r = ym_req('POST', "$base/sk/youth/import", json_encode(['rows' => [$importRow]]), $sk);
    $importedItems = ym_data($r)['items'] ?? [];
    $importId = (int) ($importedItems[0]['id'] ?? 0);
    if ($importId > 0) {
        $createdIds[] = $importId;
    }
    $check('sk_import', $r['status'] === 201 && (ym_data($r)['imported'] ?? 0) === 1 && $importId > 0, ym_detail($r));
    $r = ym_req('POST', "$base/sk/youth/import", json_encode(['rows' => [$importRow]]), $sk);
    $check('sk_import_duplicate_rejected', $r['status'] === 409, ym_detail($r));

    $r = ym_upload("$base/sk/youth/{$youthId}/photo", $jpegA, '../../secret.php.jpg', $sk, 'image/jpeg');
    $photoMeta = ym_data($r);
    $check(
        'sk_photo_upload',
        $r['status'] === 200
            && ($photoMeta['type'] ?? '') === 'image/jpeg'
            && str_contains((string) ($photoMeta['url'] ?? ''), "/sk/youth/{$youthId}/photo")
            && !str_contains((string) ($photoMeta['name'] ?? ''), '..')
            && !str_contains((string) ($photoMeta['url'] ?? ''), 'storage'),
        ym_detail($r)
    );
    $r = ym_req('GET', "$base/sk/youth/{$youthId}/photo", null, $sk);
    $check(
        'sk_photo_get',
        $r['status'] === 200 && str_starts_with($r['body'], "\xFF\xD8") && str_contains(strtolower($r['type']), 'image/jpeg'),
        'status=' . $r['status'] . ' type=' . $r['type']
    );
    $r = ym_upload("$base/sk/youth/{$youthId}/photo", $textFile, 'notes.txt', $sk, 'text/plain');
    $check('sk_photo_rejects_text', $r['status'] === 422, ym_detail($r));
    $r = ym_upload("$base/sk/youth/{$youthId}/photo", $bigFile, 'big.jpg', $sk, 'image/jpeg');
    $check('sk_photo_rejects_oversize', $r['status'] === 422, ym_detail($r));
    $r = ym_req('GET', "$base/sk/youth/{$youthId}/photo", null, $anon);
    $check('anonymous_photo_get_rejected', $r['status'] === 401, ym_detail($r));

    $r = ym_upload("$base/sk/youth/{$youthId}/document", $pdfFile, '../../escape.pdf', $sk, 'application/pdf');
    $docMeta = ym_data($r);
    $pdfOk = $r['status'] === 200 && ($docMeta['type'] ?? '') === 'application/pdf' && !str_contains((string) ($docMeta['url'] ?? ''), 'storage');
    if (!$pdfOk && $r['status'] === 422) {
        $r = ym_upload("$base/sk/youth/{$youthId}/document", $jpegB, 'support.jpg', $sk, 'image/jpeg');
        $docMeta = ym_data($r);
        $pdfOk = $r['status'] === 200 && str_starts_with((string) ($docMeta['type'] ?? ''), 'image/');
        $check('sk_document_upload_image_fallback', $pdfOk, ym_detail($r));
    } else {
        $check('sk_document_upload', $pdfOk, ym_detail($r));
    }
    $r = ym_req('GET', "$base/sk/youth/{$youthId}/document", null, $sk);
    $check('sk_document_get', $r['status'] === 200 && $r['body'] !== '', 'status=' . $r['status']);
    $r = ym_upload("$base/sk/youth/{$youthId}/document", $textFile, 'notes.txt', $sk, 'text/plain');
    $check('sk_document_rejects_text', $r['status'] === 422, ym_detail($r));

    $r = ym_req('POST', "$base/auth/login", json_encode(['email' => $sk2Email, 'password' => $demoPassword]), $sk2);
    $sk2Me = ym_req('GET', "$base/auth/me", null, $sk2);
    $sk2Org = (int) (ym_data($sk2Me)['organization']['id'] ?? 0);
    $check('sk2_login_other_org', $r['status'] === 200 && $sk2Org > 0 && $sk2Org !== $skOrg, ym_detail($r) . " org={$sk2Org}");
    $r = ym_req('GET', "$base/sk/youth/{$youthId}", null, $sk2);
    $check('sk2_cannot_read_other_org_youth', $r['status'] === 404, ym_detail($r));
    $r = ym_req('PATCH', "$base/sk/youth/{$youthId}", json_encode($payload), $sk2);
    $check('sk2_cannot_update_other_org_youth', $r['status'] === 404, ym_detail($r));
    $r = ym_req('GET', "$base/sk/youth/{$youthId}/photo", null, $sk2);
    $check('sk2_cannot_read_other_org_photo', $r['status'] === 404, ym_detail($r));
    $r = ym_req('GET', "$base/sk/youth/{$youthId}/document", null, $sk2);
    $check('sk2_cannot_read_other_org_document', $r['status'] === 404, ym_detail($r));
    $r = ym_req('GET', "$base/sk/youth/{$youthId}/photo", null, $admin);
    $check('admin_cannot_read_sk_photo', $r['status'] === 403, ym_detail($r));

    $r = ym_req('POST', "$base/auth/login", json_encode(['email' => $youthEmail, 'password' => $youthPassword]), $youth);
    $check('youth_login', $r['status'] === 200 && (ym_data($r)['role'] ?? '') === 'YOUTH', ym_detail($r));
    $r = ym_req('GET', "$base/auth/me", null, $youth);
    $check('youth_me', $r['status'] === 200 && (ym_data($r)['role'] ?? '') === 'YOUTH', ym_detail($r));
    $r = ym_req('GET', "$base/sk/youth", null, $youth);
    $check('youth_sk_list_forbidden', $r['status'] === 403, ym_detail($r));
    $r = ym_req('GET', "$base/admin/users", null, $youth);
    $check('youth_admin_users_forbidden', $r['status'] === 403, ym_detail($r));
    $r = ym_req('GET', "$base/sk/youth/{$youthId}/photo", null, $youth);
    $check('youth_cannot_read_other_youth_photo', $r['status'] === 403, ym_detail($r));
    $r = ym_req('GET', "$base/sk/youth/{$youthId}/document", null, $youth);
    $check('youth_cannot_read_other_youth_document', $r['status'] === 403, ym_detail($r));

    $profile = ym_req('GET', "$base/youth/profile", null, $youth);
    $profileYouth = ym_data($profile)['youth'] ?? [];
    $dedicatedYouthId = (int) ($profileYouth['id'] ?? 0);
    $originalOrg = (int) ($profileYouth['organizationId'] ?? ym_data($profile)['organization']['id'] ?? 0);
    $originalProfile = $profileYouth;
    $check('youth_profile_get', $profile['status'] === 200 && $dedicatedYouthId > 0 && $dedicatedYouthId !== $youthId, ym_detail($profile));
    $rawStmt = $pdo->prepare(
        'SELECT occupation, guardian_employment, interests, priority_level, priority_score, priority_reasons
         FROM youth WHERE id = :id'
    );
    $rawStmt->execute(['id' => $dedicatedYouthId]);
    $rawProfile = $rawStmt->fetch() ?: null;

    $marker = 'YmgtOcc' . $stamp;
    $patch = $profileYouth;
    if (!is_array($patch['interests'] ?? null) || $patch['interests'] === []) {
        $patch['interests'] = ['Community service'];
    }
    $patch['occupation'] = $marker;
    $patch['guardianEmployment'] = 'Retired';
    $patch['youth_id'] = $youthId;
    $patch['organization_id'] = $sk2Org > 0 ? $sk2Org : 999999;
    $patch['role'] = 'SYSTEM_ADMIN';
    $patch['status'] = 'inactive';
    $patch['archived'] = true;
    $patch['id'] = $youthId;
    $r = ym_req('PATCH', "$base/youth/profile", json_encode($patch), $youth);
    $after = ym_data($r)['youth'] ?? [];
    $check(
        'youth_profile_patch_own_record',
        $r['status'] === 200
            && (int) ($after['id'] ?? 0) === $dedicatedYouthId
            && ($after['occupation'] ?? '') === $marker
            && ($after['guardianEmployment'] ?? '') === 'Retired'
            && (int) ($after['organizationId'] ?? 0) === $originalOrg,
        ym_detail($r)
    );
    $r = ym_req('GET', "$base/sk/youth/{$youthId}", null, $sk);
    $check(
        'youth_patch_does_not_change_other_youth',
        $r['status'] === 200 && ($r['json']['data']['occupation'] ?? '') !== $marker && ($r['json']['data']['lastName'] ?? '') === 'Rec' . $stamp,
        ym_detail($r)
    );

    $r = ym_upload("$base/youth/profile/photo", $jpegB, '../other.jpg', $youth, 'image/jpeg');
    $ownPhoto = ym_data($r);
    $check(
        'youth_photo_upload',
        $r['status'] === 200
            && ($ownPhoto['url'] ?? '') === '/youth/profile/photo'
            && !str_contains((string) ($ownPhoto['name'] ?? ''), '..'),
        ym_detail($r)
    );
    $r = ym_req('GET', "$base/youth/profile/photo", null, $youth);
    $check('youth_photo_get', $r['status'] === 200 && str_starts_with($r['body'], "\xFF\xD8"), 'status=' . $r['status']);
    $r = ym_req('GET', "$base/youth/profile/photo", null, $sk);
    $check('sk_cannot_read_youth_profile_photo', $r['status'] === 403, ym_detail($r));
    $r = ym_req('GET', "$base/youth/profile/photo", null, $anon);
    $check('anonymous_own_photo_rejected', $r['status'] === 401, ym_detail($r));
    $r = ym_upload("$base/youth/profile/photo", $textFile, 'bad.txt', $youth, 'text/plain');
    $check('youth_photo_rejects_text', $r['status'] === 422, ym_detail($r));
    $r = ym_req('DELETE', "$base/youth/profile/photo", null, $youth);
    $check('youth_photo_delete', $r['status'] === 200, ym_detail($r));
    $r = ym_req('GET', "$base/youth/profile/photo", null, $youth);
    $check('youth_photo_gone', $r['status'] === 404, ym_detail($r));

    $r = ym_req('GET', "$base/youth/programs", null, $youth);
    $check('youth_programs_still_available', $r['status'] === 200, ym_detail($r));
    $r = ym_req('GET', "$base/youth/applications", null, $youth);
    $check('youth_applications_still_available', $r['status'] === 200, ym_detail($r));
    $r = ym_req('GET', "$base/youth/notifications", null, $youth);
    $check('youth_notifications_still_available', $r['status'] === 200, ym_detail($r));
} finally {
    if (is_array($originalProfile) && $dedicatedYouthId > 0) {
        $restore = $originalProfile;
        unset($restore['id'], $restore['organizationId'], $restore['code'], $restore['photo'], $restore['archived']);
        if (!is_array($restore['interests'] ?? null) || $restore['interests'] === []) {
            $restore['interests'] = ['Community service'];
        }
        ym_req('PATCH', "$base/youth/profile", json_encode($restore), $youth);
        if (is_array($rawProfile)) {
            $pdo->prepare(
                'UPDATE youth
                 SET occupation = :occupation,
                     guardian_employment = :guardian,
                     interests = :interests,
                     priority_level = :level,
                     priority_score = :score,
                     priority_reasons = :reasons
                 WHERE id = :id'
            )->execute([
                'occupation' => $rawProfile['occupation'],
                'guardian' => $rawProfile['guardian_employment'],
                'interests' => $rawProfile['interests'],
                'level' => $rawProfile['priority_level'],
                'score' => $rawProfile['priority_score'],
                'reasons' => $rawProfile['priority_reasons'],
                'id' => $dedicatedYouthId,
            ]);
        }
        $checkRow = $pdo->prepare(
            'SELECT occupation, guardian_employment, interests, priority_level, priority_score, priority_reasons
             FROM youth WHERE id = :id'
        );
        $checkRow->execute(['id' => $dedicatedYouthId]);
        $back = $checkRow->fetch() ?: [];
        $check(
            'youth_profile_restored',
            is_array($rawProfile)
                && ($back['occupation'] ?? null) === $rawProfile['occupation']
                && ($back['guardian_employment'] ?? null) === $rawProfile['guardian_employment']
                && ($back['interests'] ?? null) === $rawProfile['interests']
                && ($back['priority_level'] ?? null) === $rawProfile['priority_level']
                && (string) ($back['priority_score'] ?? '') === (string) $rawProfile['priority_score']
                && ($back['priority_reasons'] ?? null) === $rawProfile['priority_reasons']
        );
        ym_req('DELETE', "$base/youth/profile/photo", null, $youth);
    }

    foreach ($createdIds as $id) {
        $deleted = ym_req('DELETE', "$base/sk/youth/{$id}", null, $sk);
        $check('cleanup_youth_' . $id, $deleted['status'] === 200, ym_detail($deleted));
    }

    if ($createdIds !== []) {
        $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
        $left = $pdo->prepare("SELECT COUNT(*) FROM youth WHERE id IN ({$placeholders})");
        $left->execute($createdIds);
        $check('cleanup_youth_rows_gone', (int) $left->fetchColumn() === 0);
        if ($pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'youth_files'")->fetchColumn()) {
            $files = $pdo->prepare("SELECT COUNT(*) FROM youth_files WHERE youth_id IN ({$placeholders})");
            $files->execute($createdIds);
            $check('cleanup_file_rows_gone', (int) $files->fetchColumn() === 0);
        }
    }

    foreach ([$jpegA, $jpegB, $textFile, $bigFile, $pdfFile, $anon, $sk, $sk2, $admin, $youth] as $path) {
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }
    }
}

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
