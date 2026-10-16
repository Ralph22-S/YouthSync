<?php
declare(strict_types=1);

/**
 * Safe Assistance HTTP check.
 *
 * Uses existing demo SK, admin, and youth sessions. Creates assistance programs,
 * one custom type, and one beneficiary, then deletes only those rows.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$base = 'http://localhost/YouthSync/api';
$adminEmail = 'admin@skmms.test';
$skEmail = 'sk1@demo.test';
$sk2Email = 'sk2@demo.test';
$youthEmail = 'youth-auth-test@example.test';
$demoPassword = 'YouthSync1!';
$youthPassword = 'YouthAuthTest1!';
$stamp = date('YmdHis');

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';
$pdo = youthsync_pdo();

$failed = 0;
$programIds = [];
$typeIds = [];
$beneficiaryIds = [];

$check = static function (string $name, bool $ok, string $detail = '') use (&$failed): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}";
    if (!$ok && $detail !== '') {
        echo ' — ' . $detail;
    }
    echo "\n";
    if (!$ok) {
        $failed++;
    }
};

function as_req(string $method, string $url, ?string $json, string $cookie): array
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
        return ['status' => 0, 'body' => $err, 'json' => null];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $headerSize);
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
}

function as_data(array $row): array
{
    $data = $row['json']['data'] ?? null;
    return is_array($data) ? $data : [];
}

function as_detail(array $row): string
{
    $code = (string) ($row['json']['error']['code'] ?? '');
    $message = (string) ($row['json']['error']['message'] ?? '');
    return 'status=' . $row['status'] . ($code !== '' ? " code={$code}" : '') . ' ' . substr($message, 0, 180);
}

function as_jar(): string
{
    $path = tempnam(sys_get_temp_dir(), 'ysasst');
    return $path === false ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ysasst-' . bin2hex(random_bytes(4)) : $path;
}

function as_ids(PDO $pdo, string $table): array
{
    return array_map('intval', $pdo->query("SELECT id FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
}

$beforePrograms = as_ids($pdo, 'assistance_programs');
$beforeTypes = as_ids($pdo, 'assistance_types');
$beforeBeneficiaries = as_ids($pdo, 'beneficiaries');
$beforeActivity = as_ids($pdo, 'programs');
echo 'assistance_before=' . count($beforePrograms)
    . ' types_before=' . count($beforeTypes)
    . ' beneficiaries_before=' . count($beforeBeneficiaries) . "\n";

$admin = as_jar();
$sk = as_jar();
$sk2 = as_jar();
$youth = as_jar();
$anon = as_jar();

try {
    $anonGet = as_req('GET', "$base/sk/assistance", null, $anon);
    $check('anonymous_assistance_401', $anonGet['status'] === 401, as_detail($anonGet));
    $anonTypes = as_req('GET', "$base/sk/assistance-types", null, $anon);
    $check('anonymous_types_401', $anonTypes['status'] === 401, as_detail($anonTypes));
    $anonBen = as_req('DELETE', "$base/sk/beneficiaries/1", null, $anon);
    $check('anonymous_beneficiary_401', $anonBen['status'] === 401, as_detail($anonBen));

    $adminLogin = as_req('POST', "$base/auth/login", json_encode(['email' => $adminEmail, 'password' => $demoPassword]), $admin);
    $check('admin_login', $adminLogin['status'] === 200, as_detail($adminLogin));
    $adminList = as_req('GET', "$base/sk/assistance", null, $admin);
    $check('admin_assistance_403', $adminList['status'] === 403, as_detail($adminList));
    $adminType = as_req('POST', "$base/sk/assistance-types", json_encode(['name' => 'Nope']), $admin);
    $check('admin_types_403', $adminType['status'] === 403, as_detail($adminType));

    $youthLogin = as_req('POST', "$base/auth/login", json_encode(['email' => $youthEmail, 'password' => $youthPassword]), $youth);
    $check('youth_login', $youthLogin['status'] === 200, as_detail($youthLogin));
    $youthSk = as_req('GET', "$base/sk/assistance", null, $youth);
    $check('youth_sk_assistance_403', $youthSk['status'] === 403, as_detail($youthSk));
    $youthMe = as_req('GET', "$base/auth/me", null, $youth);
    $youthOrg = (int) (as_data($youthMe)['organization']['id'] ?? as_data($youthMe)['youth']['organizationId'] ?? 0);

    $skLogin = as_req('POST', "$base/auth/login", json_encode(['email' => $skEmail, 'password' => $demoPassword]), $sk);
    $check('sk_login', $skLogin['status'] === 200, as_detail($skLogin));
    $me = as_req('GET', "$base/auth/me", null, $sk);
    $orgId = (int) (as_data($me)['organization']['id'] ?? 0);
    $check('sk_org', $me['status'] === 200 && $orgId > 0, as_detail($me));

    $sk2Login = as_req('POST', "$base/auth/login", json_encode(['email' => $sk2Email, 'password' => $demoPassword]), $sk2);
    $check('sk2_login', $sk2Login['status'] === 200, as_detail($sk2Login));
    $me2 = as_req('GET', "$base/auth/me", null, $sk2);
    $org2 = (int) (as_data($me2)['organization']['id'] ?? 0);
    $check('sk2_other_org', $org2 > 0 && $org2 !== $orgId, as_detail($me2));

    $youthStmt = $pdo->prepare(
        'SELECT id FROM youth WHERE organization_id = :org AND archived = 0 ORDER BY id LIMIT 1'
    );
    $youthStmt->execute(['org' => $orgId]);
    $youthId = (int) $youthStmt->fetchColumn();
    $check('existing_youth_in_org', $youthId > 0, 'no active youth in organization ' . $orgId);

    $otherYouth = $pdo->prepare(
        'SELECT id FROM youth WHERE organization_id = :org AND archived = 0 ORDER BY id LIMIT 1'
    );
    $otherYouth->execute(['org' => $org2]);
    $otherYouthId = (int) $otherYouth->fetchColumn();

    $name = "AssistHttp Program {$stamp}";
    $created = as_req('POST', "$base/sk/assistance", json_encode([
        'name' => $name,
        'category' => 'scholarship',
        'status' => 'draft',
        'description' => 'Assistance HTTP test',
        'slots' => 5,
        'amount' => 1000,
        'organization_id' => $org2,
        'org_id' => 999,
    ]), $sk);
    $program = as_data($created);
    $programId = (int) ($program['id'] ?? 0);
    if ($programId > 0) {
        $programIds[] = $programId;
    }
    $check('create_program_201', $created['status'] === 201 && $programId > 0 && ($program['status'] ?? '') === 'draft', as_detail($created));
    $check('create_no_password_hash', stripos($created['body'], 'password_hash') === false);
    $rowStmt = $pdo->prepare('SELECT organization_id, name, status, description FROM assistance_programs WHERE id = :id');
    $rowStmt->execute(['id' => $programId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('create_scoped_to_session_org', (int) ($row['organization_id'] ?? 0) === $orgId, json_encode($row));

    $listed = as_req('GET', "$base/sk/assistance?organization_id={$org2}", null, $sk);
    $found = false;
    foreach ((as_data($listed)['items'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $programId) {
            $found = true;
        }
    }
    $check('list_includes_created', $listed['status'] === 200 && $found, as_detail($listed));

    $shown = as_req('GET', "$base/sk/assistance/{$programId}", null, $sk);
    $check('read_program', $shown['status'] === 200 && (as_data($shown)['name'] ?? '') === $name, as_detail($shown));

    $updated = as_req('PATCH', "$base/sk/assistance/{$programId}", json_encode([
        'name' => $name,
        'description' => 'Updated assistance',
        'category' => 'scholarship',
        'status' => 'draft',
        'organization_id' => $org2,
    ]), $sk);
    $check('update_program', $updated['status'] === 200 && (as_data($updated)['description'] ?? '') === 'Updated assistance', as_detail($updated));
    $fresh = as_req('GET', "$base/sk/assistance/{$programId}", null, $sk);
    $check('fresh_get_keeps_update', (as_data($fresh)['description'] ?? '') === 'Updated assistance', as_detail($fresh));

    $opened = as_req('PATCH', "$base/sk/assistance/{$programId}", json_encode(['status' => 'open']), $sk);
    $check('status_open', $opened['status'] === 200 && (as_data($opened)['status'] ?? '') === 'open', as_detail($opened));

    $youthList = as_req('GET', "$base/youth/assistance", null, $youth);
    $youthSees = false;
    foreach ((as_data($youthList)['items'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $programId) {
            $youthSees = true;
        }
    }
    $sameOrgYouth = $youthOrg === $orgId;
    $check('youth_portal_lists_open_program', $youthList['status'] === 200 && $youthSees === $sameOrgYouth, as_detail($youthList) . " youthOrg={$youthOrg} skOrg={$orgId}");
    if ($sameOrgYouth) {
        $youthItem = as_req('GET', "$base/youth/assistance/{$programId}", null, $youth);
        $check('youth_portal_reads_program', $youthItem['status'] === 200 && (as_data($youthItem)['name'] ?? '') === $name, as_detail($youthItem));
    }

    $archived = as_req('POST', "$base/sk/assistance/{$programId}/archive", json_encode(['organization_id' => $org2]), $sk);
    $check('archive_program', $archived['status'] === 200 && (as_data($archived)['status'] ?? '') === 'archived', as_detail($archived));
    $youthHidden = as_req('GET', "$base/youth/assistance/{$programId}", null, $youth);
    $check('archived_hidden_from_youth', $youthHidden['status'] === 404, as_detail($youthHidden));

    $restored = as_req('PATCH', "$base/sk/assistance/{$programId}", json_encode(['status' => 'draft']), $sk);
    $check('restore_to_draft', $restored['status'] === 200 && (as_data($restored)['status'] ?? '') === 'draft', as_detail($restored));

    $invalid = as_req('POST', "$base/sk/assistance", json_encode(['name' => '']), $sk);
    $check('invalid_program_422', $invalid['status'] === 422, as_detail($invalid));
    $duplicate = as_req('POST', "$base/sk/assistance", json_encode([
        'name' => $name,
        'category' => 'scholarship',
        'status' => 'draft',
    ]), $sk);
    $check('duplicate_program_409', $duplicate['status'] === 409, as_detail($duplicate));

    $typeName = "AssistHttp Type {$stamp}";
    $typeCreated = as_req('POST', "$base/sk/assistance-types", json_encode([
        'name' => $typeName,
        'category' => 'financial',
        'organization_id' => $org2,
        'is_system' => 1,
    ]), $sk);
    $type = as_data($typeCreated);
    $typeId = (int) ($type['id'] ?? 0);
    if ($typeId > 0) {
        $typeIds[] = $typeId;
    }
    $check('create_type_201', $typeCreated['status'] === 201 && $typeId > 0 && ($type['name'] ?? '') === $typeName, as_detail($typeCreated));
    $typeRow = $pdo->prepare('SELECT organization_id, is_system, name FROM assistance_types WHERE id = :id');
    $typeRow->execute(['id' => $typeId]);
    $typeDb = $typeRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('type_not_system_and_scoped', (int) ($typeDb['organization_id'] ?? 0) === $orgId && (int) ($typeDb['is_system'] ?? 1) === 0, json_encode($typeDb));

    $typeList = as_req('GET', "$base/sk/assistance-types?organization_id={$org2}", null, $sk);
    $typeFound = false;
    foreach ((as_data($typeList)['items'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $typeId) {
            $typeFound = true;
        }
    }
    $check('list_types_includes_created', $typeList['status'] === 200 && $typeFound, as_detail($typeList));
    $sk2Types = as_req('GET', "$base/sk/assistance-types", null, $sk2);
    $sk2SeesType = false;
    foreach ((as_data($sk2Types)['items'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $typeId) {
            $sk2SeesType = true;
        }
    }
    $check('other_sk_cannot_list_type', $sk2Types['status'] === 200 && $sk2SeesType === false, as_detail($sk2Types));
    $badType = as_req('POST', "$base/sk/assistance-types", json_encode(['name' => '']), $sk);
    $check('invalid_type_422', $badType['status'] === 422, as_detail($badType));

    $beneficiary = as_req('POST', "$base/sk/assistance/{$programId}/beneficiaries", json_encode([
        'youthId' => $youthId,
        'status' => 'applied',
        'remarks' => 'HTTP test beneficiary',
        'organization_id' => $org2,
    ]), $sk);
    $ben = as_data($beneficiary);
    $benId = (int) ($ben['id'] ?? 0);
    if ($benId > 0) {
        $beneficiaryIds[] = $benId;
    }
    $check('create_beneficiary_201', $beneficiary['status'] === 201 && $benId > 0, as_detail($beneficiary));
    $withBen = as_req('GET', "$base/sk/assistance/{$programId}", null, $sk);
    $benListed = false;
    foreach ((as_data($withBen)['beneficiaries'] ?? []) as $item) {
        if ((int) ($item['id'] ?? 0) === $benId) {
            $benListed = true;
        }
    }
    $check('read_beneficiary_on_program', $withBen['status'] === 200 && $benListed, as_detail($withBen));
    $benRow = $pdo->prepare('SELECT organization_id, assistance_id, youth_id, status FROM beneficiaries WHERE id = :id');
    $benRow->execute(['id' => $benId]);
    $benDb = $benRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('beneficiary_scoped', (int) ($benDb['organization_id'] ?? 0) === $orgId && (int) ($benDb['youth_id'] ?? 0) === $youthId, json_encode($benDb));

    $updatedBen = as_req('POST', "$base/sk/assistance/{$programId}/beneficiaries", json_encode([
        'youthId' => $youthId,
        'status' => 'approved',
        'remarks' => 'Approved in test',
    ]), $sk);
    $check('update_beneficiary', $updatedBen['status'] === 201 && (as_data($updatedBen)['status'] ?? '') === 'approved', as_detail($updatedBen));

    if ($otherYouthId > 0) {
        $foreignYouth = as_req('POST', "$base/sk/assistance/{$programId}/beneficiaries", json_encode([
            'youthId' => $otherYouthId,
            'status' => 'applied',
            'organization_id' => $org2,
        ]), $sk);
        $check('other_org_youth_rejected', $foreignYouth['status'] === 404, as_detail($foreignYouth));
    }

    $crossRead = as_req('GET', "$base/sk/assistance/{$programId}", null, $sk2);
    $check('other_sk_cannot_read', $crossRead['status'] === 404, as_detail($crossRead));
    $crossUpdate = as_req('PATCH', "$base/sk/assistance/{$programId}", json_encode([
        'name' => 'Hacked',
        'status' => 'open',
        'organization_id' => $orgId,
    ]), $sk2);
    $check('other_sk_cannot_update', $crossUpdate['status'] === 404, as_detail($crossUpdate));
    $crossArchive = as_req('POST', "$base/sk/assistance/{$programId}/archive", '{}', $sk2);
    $check('other_sk_cannot_archive', $crossArchive['status'] === 404, as_detail($crossArchive));
    $crossBen = as_req('DELETE', "$base/sk/beneficiaries/{$benId}", null, $sk2);
    $check('other_sk_cannot_delete_beneficiary', $crossBen['status'] === 404, as_detail($crossBen));

    $otherName = "AssistHttp Other {$stamp}";
    $other = as_req('POST', "$base/sk/assistance", json_encode([
        'name' => $otherName,
        'category' => 'other',
        'status' => 'open',
        'organization_id' => $orgId,
    ]), $sk2);
    $otherId = (int) (as_data($other)['id'] ?? 0);
    if ($otherId > 0) {
        $programIds[] = $otherId;
    }
    $check('other_org_create_201', $other['status'] === 201 && $otherId > 0, as_detail($other));
    $youthOther = as_req('GET', "$base/youth/assistance/{$otherId}", null, $youth);
    $check('youth_cannot_read_other_org', $youthOther['status'] === 404, as_detail($youthOther));
    $skCannotSeeOther = as_req('GET', "$base/sk/assistance/{$otherId}", null, $sk);
    $check('sk_cannot_read_other_org_program', $skCannotSeeOther['status'] === 404, as_detail($skCannotSeeOther));

    $deletedBen = as_req('DELETE', "$base/sk/beneficiaries/{$benId}", null, $sk);
    $check('delete_beneficiary', $deletedBen['status'] === 200, as_detail($deletedBen));
    $deleted = as_req('DELETE', "$base/sk/assistance/{$programId}", null, $sk);
    $check('delete_program', $deleted['status'] === 200, as_detail($deleted));
    $gone = as_req('GET', "$base/sk/assistance/{$programId}", null, $sk);
    $check('deleted_program_404', $gone['status'] === 404, as_detail($gone));
    $deletedOther = as_req('DELETE', "$base/sk/assistance/{$otherId}", null, $sk2);
    $check('delete_other_program', $deletedOther['status'] === 200, as_detail($deletedOther));
} finally {
    foreach ($beneficiaryIds as $id) {
        $pdo->prepare('DELETE FROM beneficiaries WHERE id = :id AND remarks IN (:a, :b)')->execute([
            'id' => $id,
            'a' => 'HTTP test beneficiary',
            'b' => 'Approved in test',
        ]);
    }
    foreach ($programIds as $id) {
        $pdo->prepare("DELETE FROM beneficiaries WHERE assistance_id = :id AND organization_id IN (SELECT organization_id FROM assistance_programs WHERE id = :id2 AND name LIKE 'AssistHttp %')")->execute([
            'id' => $id,
            'id2' => $id,
        ]);
        $pdo->prepare("DELETE FROM assistance_requirements WHERE assistance_id = :id AND organization_id IN (SELECT organization_id FROM assistance_programs WHERE id = :id2 AND name LIKE 'AssistHttp %')")->execute([
            'id' => $id,
            'id2' => $id,
        ]);
        $pdo->prepare("DELETE FROM assistance_programs WHERE id = :id AND name LIKE 'AssistHttp %'")->execute(['id' => $id]);
    }
    foreach ($typeIds as $id) {
        $pdo->prepare("DELETE FROM assistance_types WHERE id = :id AND is_system = 0 AND name LIKE 'AssistHttp %'")->execute(['id' => $id]);
    }
    foreach ([$admin, $sk, $sk2, $youth, $anon] as $cookie) {
        @unlink($cookie);
    }
}

$afterPrograms = as_ids($pdo, 'assistance_programs');
$afterTypes = as_ids($pdo, 'assistance_types');
$afterBeneficiaries = as_ids($pdo, 'beneficiaries');
$afterActivity = as_ids($pdo, 'programs');
$left = $pdo->query("SELECT id, name FROM assistance_programs WHERE name LIKE 'AssistHttp %'")->fetchAll(PDO::FETCH_ASSOC);
$leftTypes = $pdo->query("SELECT id, name FROM assistance_types WHERE name LIKE 'AssistHttp %'")->fetchAll(PDO::FETCH_ASSOC);

$check('assistance_count_restored', $beforePrograms === $afterPrograms, 'before=' . count($beforePrograms) . ' after=' . count($afterPrograms));
$check('types_count_restored', $beforeTypes === $afterTypes, 'before=' . count($beforeTypes) . ' after=' . count($afterTypes));
$check('beneficiaries_count_restored', $beforeBeneficiaries === $afterBeneficiaries, 'before=' . count($beforeBeneficiaries) . ' after=' . count($afterBeneficiaries));
$check('programs_untouched', $beforeActivity === $afterActivity);
$check('no_test_rows_left', $left === [] && $leftTypes === [], json_encode([$left, $leftTypes]));

echo 'assistance_after=' . count($afterPrograms)
    . ' types_after=' . count($afterTypes)
    . ' beneficiaries_after=' . count($afterBeneficiaries) . "\n";
echo 'created_programs=' . implode(',', $programIds)
    . ' created_types=' . implode(',', $typeIds)
    . ' created_beneficiaries=' . implode(',', $beneficiaryIds) . "\n";
echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
