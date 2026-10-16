<?php
declare(strict_types=1);

/**
 * Subscription Plans HTTP check (admin change plan / extend / cancel / run cycle,
 * SK subscription view, PLAN_LIMIT_REACHED on every limited resource).
 *
 * Laptop-safe:
 * - Every plan change, extension, cancellation, limit and QR check runs on ONE
 *   temporary organization created here through POST /admin/organizations.
 * - No real organization's plan, status, expiry or lifetime QR counter is changed
 *   (they are snapshotted and compared at the end).
 * - Cleanup deletes only rows of that temporary organization id and the temporary
 *   users created here (exact ids, double-checked against their test emails).
 * - No SMS or email is sent (checked: no delivery rows for the temp organization).
 *
 * Optional: set YOUTHSYNC_API_BASE to override http://localhost/YouthSync/api.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$base = rtrim((string) (getenv('YOUTHSYNC_API_BASE') ?: 'http://localhost/YouthSync/api'), '/');
$demoPassword = 'YouthSync1!';
$youthEmail = 'youth-auth-test@example.test';
$youthPassword = 'YouthAuthTest1!';
$ownerPassword = 'SubPlanTest1!';
$stamp = date('YmdHis') . substr(bin2hex(random_bytes(2)), 0, 3);
$prefix = 'sp-test-';
$orgEmail = "{$prefix}org-{$stamp}@example.test";
$ownerEmail = "{$prefix}owner-{$stamp}@example.test";
$barangay = "SubPlan{$stamp}";

$pdo = youthsync_pdo();

$leftover = $pdo->query("SELECT COUNT(*) FROM organizations WHERE email LIKE 'sp-test-%@example.test'")->fetchColumn()
    + $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'sp-test-%@example.test'")->fetchColumn();
if ((int) $leftover > 0) {
    fwrite(STDERR, "STOP: rows from an earlier subscription_plans run (sp-test-*@example.test) exist. No cleanup was performed.\n");
    exit(2);
}

$failed = 0;
$passed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed, &$passed): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}";
    if (!$ok && $detail !== '') {
        echo ' - ' . $detail;
    }
    echo "\n";
    $ok ? $passed++ : $failed++;
};

/** @var list<string> $bodies response bodies scanned for secrets at the end */
$bodies = [];

function sp_req(string $method, string $url, mixed $body, string $cookie, bool $scan = true): array
{
    global $bodies;
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        unset($ch);
        return ['status' => 0, 'body' => $err, 'json' => null];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    unset($ch);
    $text = substr($raw, $headerSize);
    if ($scan) {
        $bodies[] = $text;
    }
    $decoded = json_decode($text, true);
    return ['status' => $status, 'body' => $text, 'json' => is_array($decoded) ? $decoded : null];
}

function sp_data(array $r): array
{
    $d = $r['json']['data'] ?? null;
    return is_array($d) ? $d : [];
}

function sp_err(array $r): array
{
    $e = $r['json']['error'] ?? null;
    return is_array($e) ? $e : [];
}

function sp_detail(array $r): string
{
    $e = sp_err($r);
    return 'status=' . $r['status'] . (isset($e['code']) ? ' code=' . $e['code'] : '') . ' ' . substr((string) ($e['message'] ?? $r['body']), 0, 300);
}

function sp_jar(): string
{
    $path = tempnam(sys_get_temp_dir(), 'yssp');
    if ($path === false) {
        throw new RuntimeException('Could not create a cookie jar.');
    }
    return $path;
}

function sp_null(array $data, array $path): bool
{
    foreach ($path as $i => $key) {
        if (!is_array($data) || !array_key_exists($key, $data)) {
            return false;
        }
        if ($i === count($path) - 1) {
            return $data[$key] === null;
        }
        $data = $data[$key];
    }
    return false;
}

function sp_limit_error(array $r, string $resource, ?int $usage, int $limit, string $plan): bool
{
    $e = sp_err($r);
    return $r['status'] === 403
        && ($r['json']['success'] ?? true) === false
        && ($e['code'] ?? '') === 'PLAN_LIMIT_REACHED'
        && ($e['resource'] ?? '') === $resource
        && ($usage === null ? is_int($e['usage'] ?? null) : ($e['usage'] ?? null) === $usage)
        && ($e['limit'] ?? null) === $limit
        && ($e['plan'] ?? '') === $plan
        && is_string($e['message'] ?? null) && $e['message'] !== '';
}

$sqlValue = static function (string $sql, array $params = []) use ($pdo): mixed {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};
$orgRow = static function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT id, plan, sub_status, cycle, started_on, expires_at, qr_uses, status FROM organizations WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: [];
};
$dbUsage = static function (int $id) use ($sqlValue): array {
    return [
        'youth' => (int) $sqlValue('SELECT COUNT(*) FROM youth WHERE organization_id = :o AND archived = 0', ['o' => $id]),
        'accounts' => (int) $sqlValue(
            "SELECT COUNT(*) FROM organization_users ou JOIN users u ON u.id = ou.user_id JOIN roles r ON r.id = u.role_id
             WHERE ou.organization_id = :o AND ou.status = 'active' AND r.code = 'SK_OFFICIAL'",
            ['o' => $id]
        ),
        'programs' => (int) $sqlValue("SELECT COUNT(*) FROM programs WHERE organization_id = :o AND status IN ('draft','published','ongoing')", ['o' => $id]),
        'assistance' => (int) $sqlValue("SELECT COUNT(*) FROM assistance_programs WHERE organization_id = :o AND status IN ('draft','open','full')", ['o' => $id]),
        'qr' => (int) $sqlValue('SELECT qr_uses FROM organizations WHERE id = :o', ['o' => $id]),
    ];
};
$dateAdd = static function (string $base, string $interval) use ($sqlValue): string {
    return (string) $sqlValue("SELECT DATE_ADD(:b, INTERVAL {$interval})", ['b' => $base]);
};
$today = (string) $sqlValue('SELECT CURDATE()');

$jars = [];
$anon = $jars[] = sp_jar();
$admin = $jars[] = sp_jar();
$sk1 = $jars[] = sp_jar();
$youth = $jars[] = sp_jar();
$owner = $jars[] = sp_jar();

$orgId = 0;
$tempEmails = [$ownerEmail];
$adminActions = 0; // successful admin subscription actions on the temp org (one audit row each)
$realSnapshot = [];
$tableCounts = static function () use ($pdo): array {
    $out = [];
    foreach (['organizations', 'users', 'organization_users', 'youth', 'programs', 'assistance_programs', 'assistance_types', 'attendance',
        'attendance_qr_tokens', 'beneficiaries', 'applications', 'notifications', 'payments', 'sms_deliveries', 'email_deliveries'] as $t) {
        $out[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    }
    return $out;
};
$logIdStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM activity_logs')->fetchColumn();
$beforeCounts = null;

try {
    // ---- Auth: anonymous, youth and SK cannot use the admin subscription actions ----
    foreach ([
        ['GET', '/admin/plans', null],
        ['GET', '/admin/organizations/1/subscription', null],
        ['POST', '/admin/organizations/1/subscription/plan', ['plan' => 'free']],
        ['POST', '/admin/organizations/1/subscription/extend', ['days' => 5]],
        ['POST', '/admin/organizations/1/subscription/cancel', []],
        ['POST', '/admin/subscriptions/run-cycle', []],
    ] as [$m, $path, $b]) {
        $r = sp_req($m, $base . $path, $b, $anon);
        $check('anon_401 ' . $m . ' ' . $path, $r['status'] === 401, sp_detail($r));
    }
    $r = sp_req('GET', "$base/sk/subscription", null, $anon);
    $check('anon_401 GET /sk/subscription', $r['status'] === 401, sp_detail($r));

    $r = sp_req('POST', "$base/auth/login", ['email' => 'admin@skmms.test', 'password' => $demoPassword], $admin, false);
    $check('admin_login', $r['status'] === 200, sp_detail($r));
    $adminUserId = (int) (sp_data($r)['user']['id'] ?? sp_data($r)['id'] ?? 0);
    if ($adminUserId < 1) {
        $adminUserId = (int) $sqlValue("SELECT id FROM users WHERE email = 'admin@skmms.test'");
    }
    $r = sp_req('POST', "$base/auth/login", ['email' => 'sk1@demo.test', 'password' => $demoPassword], $sk1, false);
    $check('sk1_login', $r['status'] === 200, sp_detail($r));
    $r = sp_req('POST', "$base/auth/login", ['email' => $youthEmail, 'password' => $youthPassword], $youth, false);
    $check('youth_login', $r['status'] === 200, sp_detail($r));

    // Sweep lapsed terms the normal way (the admin list does this without logging) so the
    // real-organization snapshot below is stable for the rest of the run.
    $r = sp_req('GET', "$base/admin/organizations?perPage=1", null, $admin);
    $check('admin_list_ok', $r['status'] === 200, sp_detail($r));
    foreach ($pdo->query('SELECT id, plan, sub_status, cycle, started_on, expires_at, qr_uses, status FROM organizations ORDER BY id')->fetchAll() as $row) {
        $realSnapshot[(int) $row['id']] = $row;
    }
    $beforeCounts = $tableCounts();
    $org1Before = $orgRow(1);

    foreach (['youth' => $youth, 'sk1' => $sk1] as $who => $jar) {
        $r = sp_req('POST', "$base/admin/organizations/1/subscription/plan", ['plan' => 'premium', 'cycle' => 'yearly'], $jar);
        $check("{$who}_change_plan_forbidden", $r['status'] === 403, sp_detail($r));
        $r = sp_req('POST', "$base/admin/organizations/1/subscription/extend", ['days' => 30], $jar);
        $check("{$who}_extend_forbidden", $r['status'] === 403, sp_detail($r));
        $r = sp_req('POST', "$base/admin/organizations/1/subscription/cancel", [], $jar);
        $check("{$who}_cancel_forbidden", $r['status'] === 403, sp_detail($r));
    }
    $r = sp_req('GET', "$base/admin/organizations/1/subscription", null, $sk1);
    $check('sk1_admin_view_forbidden', $r['status'] === 403, sp_detail($r));
    $r = sp_req('POST', "$base/admin/subscriptions/run-cycle", [], $sk1);
    $check('sk1_run_cycle_forbidden', $r['status'] === 403, sp_detail($r));
    $r = sp_req('POST', "$base/sk/subscription", ['plan' => 'premium', 'status' => 'active'], $sk1);
    $check('sk_has_no_self_plan_change_route', in_array($r['status'], [404, 405], true), sp_detail($r));
    $check('real_org1_unchanged_after_forbidden_calls', $orgRow(1) == $org1Before);

    // ---- Plan catalog (display data from the backend) ----
    $r = sp_req('GET', "$base/admin/plans", null, $admin);
    $plans = sp_data($r);
    $byCode = [];
    foreach ($plans['items'] ?? [] as $p) {
        $byCode[$p['code'] ?? ''] = $p;
    }
    $check('admin_plans_catalog', $r['status'] === 200 && array_keys($byCode) === ['free', 'basic', 'premium']
        && ($plans['cycles'] ?? null) === ['monthly', 'yearly'] && ($plans['maxExtendDays'] ?? 0) === 365, sp_detail($r));
    $check('catalog_prices', ($byCode['free']['priceMonthly'] ?? -1) === 0 && ($byCode['basic']['priceMonthly'] ?? 0) === 999
        && ($byCode['basic']['priceYearly'] ?? 0) === 9990 && ($byCode['premium']['priceMonthly'] ?? 0) === 1999
        && ($byCode['premium']['priceYearly'] ?? 0) === 19990 && ($byCode['basic']['currency'] ?? '') === 'PHP',
        json_encode(array_map(static fn ($p) => [$p['priceMonthly'] ?? null, $p['priceYearly'] ?? null, $p['currency'] ?? null], $byCode)));
    $check('catalog_limits_match_enforcement', ($byCode['free']['limits'] ?? null) === ['youth' => 20, 'accounts' => 1, 'programs' => 1, 'assistance' => 1, 'qr' => 1]
        && ($byCode['basic']['limits'] ?? null) === ['youth' => 250, 'accounts' => 3, 'programs' => null, 'assistance' => 20, 'qr' => 3]
        && ($byCode['premium']['limits'] ?? null) === ['youth' => null, 'accounts' => null, 'programs' => null, 'assistance' => null, 'qr' => null],
        json_encode(array_map(static fn ($p) => $p['limits'] ?? null, $byCode)));
    $check('catalog_bullets_present', count($byCode['basic']['bullets'] ?? []) > 0 && count($byCode['premium']['bullets'] ?? []) > 0
        && ($byCode['free']['features']['csvImport'] ?? true) === false && ($byCode['basic']['features']['csvImport'] ?? false) === true);

    // ---- Temporary organization (admin-created: Premium 7-day trial) ----
    $r = sp_req('POST', "$base/admin/organizations", [
        'barangay' => $barangay,
        'municipality' => 'Testville',
        'province' => 'Laguna',
        'chairperson' => 'Subscription Test Chair',
        'email' => $orgEmail,
        'contact' => '09170002001',
        'ownerName' => 'SubPlan Owner',
        'ownerEmail' => $ownerEmail,
        'ownerPassword' => $ownerPassword,
    ], $admin, false); // the create response carries the temporary password by design
    $orgId = (int) (sp_data($r)['id'] ?? 0);
    $check('temp_org_created', $r['status'] === 201 && $orgId > 0, sp_detail($r));
    if ($orgId < 1) {
        throw new RuntimeException('Temp organization was not created; stopping.');
    }
    echo "temp_org_id={$orgId}\n";

    $r = sp_req('POST', "$base/auth/login", ['email' => $ownerEmail, 'password' => $ownerPassword], $owner, false);
    $check('temp_owner_login', $r['status'] === 200, sp_detail($r));

    $skView = static function () use ($base, $owner): array {
        return sp_data(sp_req('GET', "$base/sk/subscription", null, $owner));
    };
    $adminItem = static function () use ($base, $admin, $barangay, $orgId): array {
        $list = sp_data(sp_req('GET', "$base/admin/organizations?perPage=50&q=" . rawurlencode($barangay), null, $admin));
        foreach ($list['items'] ?? [] as $item) {
            if ((int) ($item['id'] ?? 0) === $orgId) {
                return $item;
            }
        }
        return [];
    };

    $v = $skView();
    $check('trial_view', ($v['plan'] ?? '') === 'premium' && ($v['status'] ?? '') === 'trial' && ($v['daysRemaining'] ?? null) === 7
        && sp_null($v, ['limits', 'youth']) && ($v['features']['csvImport'] ?? false) === true, json_encode([$v['plan'] ?? null, $v['status'] ?? null, $v['daysRemaining'] ?? null]));
    $check('sk_catalog_equals_admin_catalog', ($v['plans'] ?? null) === ($plans['items'] ?? false));
    $check('billing_is_honest', ($v['billing']['onlinePayment'] ?? true) === false && is_string($v['billing']['note'] ?? null));
    $usageView = sp_data(sp_req('GET', "$base/sk/subscription/usage", null, $owner));
    $check('usage_endpoint_same_view', ($usageView['usage'] ?? 1) === ($v['usage'] ?? 2) && ($usageView['limits'] ?? 1) === ($v['limits'] ?? 2));

    // ---- Seed records on Premium (unlimited) ----
    $r = sp_req('POST', "$base/sk/youth", [
        'firstName' => 'SpTest', 'lastName' => 'Spoof', 'birthDate' => '2005-05-05', 'address' => 'Test address',
        'contact' => '09170003000', 'interests' => ['Education & training'], 'organization_id' => 1,
    ], $owner);
    $spoofYouthId = (int) (sp_data($r)['youth']['id'] ?? 0);
    $check('spoofed_org_id_ignored_on_create', $r['status'] === 201 && $spoofYouthId > 0
        && (int) $sqlValue('SELECT organization_id FROM youth WHERE id = :id', ['id' => $spoofYouthId]) === $orgId, sp_detail($r));

    $rows = [];
    for ($i = 1; $i <= 20; $i++) {
        $rows[] = ['firstName' => 'SpTest', 'lastName' => sprintf('Row%03d', $i), 'birthDate' => '2005-05-05', 'address' => 'Test address',
            'contact' => sprintf('0917%07d', 3000 + $i)];
    }
    $r = sp_req('POST', "$base/sk/youth/import", ['rows' => $rows], $owner);
    $check('seed_import_20_youth', $r['status'] === 201 && $dbUsage($orgId)['youth'] === 21, sp_detail($r));

    foreach ([1, 2] as $n) {
        $r = sp_req('POST', "$base/sk/programs", ['kind' => 'program', 'name' => "SpTest Program {$n}", 'scheduledOn' => '2026-12-0' . $n,
            'location' => 'Hall', 'status' => 'draft'], $owner);
        $check("seed_program_{$n}", $r['status'] === 201, sp_detail($r));
        $r = sp_req('POST', "$base/sk/assistance", ['name' => "SpTest Assistance {$n}", 'category' => 'scholarship', 'status' => 'draft',
            'description' => 'Subscription test', 'slots' => 5, 'amount' => 1000], $owner);
        $check("seed_assistance_{$n}", $r['status'] === 201, sp_detail($r));
    }
    $staffEmail = "{$prefix}staff-{$stamp}@example.test";
    $tempEmails[] = $staffEmail;
    $r = sp_req('POST', "$base/sk/users", ['firstName' => 'SpTest', 'lastName' => 'Staff', 'email' => $staffEmail], $owner, false);
    $check('seed_staff_account', $r['status'] === 201, sp_detail($r));

    $v = $skView();
    $actual = $dbUsage($orgId);
    $check('usage_equals_db_counts', ($v['usage'] ?? null) === $actual && $actual === ['youth' => 21, 'accounts' => 2, 'programs' => 2, 'assistance' => 2, 'qr' => 0],
        json_encode(['api' => $v['usage'] ?? null, 'db' => $actual]));
    $detail = sp_data(sp_req('GET', "$base/admin/organizations/{$orgId}", null, $admin));
    $check('admin_detail_usage_equals_sk_usage', ($detail['usage'] ?? null) === $actual, json_encode($detail['usage'] ?? null));

    // ---- Change plan: validation ----
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/plan", ['plan' => 'gold'], $admin);
    $check('change_plan_invalid_plan_422', $r['status'] === 422 && isset(sp_err($r)['fields']['plan']), sp_detail($r));
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/plan", ['plan' => 'basic', 'cycle' => 'weekly'], $admin);
    $check('change_plan_invalid_cycle_422', $r['status'] === 422 && isset(sp_err($r)['fields']['cycle']), sp_detail($r));
    $r = sp_req('POST', "$base/admin/organizations/999999/subscription/plan", ['plan' => 'basic'], $admin);
    $check('change_plan_unknown_org_404', $r['status'] === 404, sp_detail($r));
    $r = sp_req('GET', "$base/admin/organizations/999999/subscription", null, $admin);
    $check('admin_view_unknown_org_404', $r['status'] === 404, sp_detail($r));
    $check('failed_validation_changed_nothing', ($orgRow($orgId)['plan'] ?? '') === 'premium' && ($orgRow($orgId)['sub_status'] ?? '') === 'trial');

    // ---- Change plan: trial -> Basic yearly (body tries to target org 1; ignored) ----
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/plan", ['plan' => 'basic', 'cycle' => 'yearly', 'organization_id' => 1, 'id' => 1], $admin);
    $d = sp_data($r);
    $s = $d['subscription'] ?? [];
    $adminActions += $r['status'] === 200 ? 1 : 0;
    $check('change_to_basic_yearly', $r['status'] === 200 && ($s['plan'] ?? '') === 'basic' && ($s['status'] ?? '') === 'active'
        && ($s['cycle'] ?? '') === 'yearly' && ($s['startedOn'] ?? '') === $today && ($s['expiresAt'] ?? '') === $dateAdd($today, '12 MONTH')
        && ($d['previous']['plan'] ?? '') === 'premium' && ($d['previous']['status'] ?? '') === 'trial'
        && sp_null($d, ['warning']) && (int) ($d['organization']['id'] ?? 0) === $orgId, sp_detail($r));
    $check('spoofed_org_in_admin_body_ignored', $orgRow(1) == $org1Before);
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/plan", ['plan' => 'basic', 'cycle' => 'yearly'], $admin);
    $check('same_active_plan_409', $r['status'] === 409, sp_detail($r));

    $v = $skView();
    $item = $adminItem();
    $dbDays = (int) $sqlValue('SELECT DATEDIFF(expires_at, CURDATE()) FROM organizations WHERE id = :id', ['id' => $orgId]);
    $check('days_remaining_consistent_sk_admin_db', ($v['daysRemaining'] ?? null) === $dbDays && ($item['daysRemaining'] ?? null) === $dbDays
        && ($item['subStatus'] ?? '') === 'active' && ($item['plan'] ?? '') === 'basic' && ($item['expiresAt'] ?? '') === ($v['expiresAt'] ?? 'x'),
        json_encode(['sk' => $v['daysRemaining'] ?? null, 'admin' => $item['daysRemaining'] ?? null, 'db' => $dbDays]));
    $check('sk_sees_basic_limits', ($v['limits'] ?? null) === ($byCode['basic']['limits'] ?? false) && ($v['restrictedToFree'] ?? true) === false);

    // ---- Upgrade Basic -> Premium monthly ----
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/plan", ['plan' => 'premium'], $admin);
    $s = sp_data($r)['subscription'] ?? [];
    $adminActions += $r['status'] === 200 ? 1 : 0;
    $check('upgrade_to_premium_monthly_default_cycle', $r['status'] === 200 && ($s['plan'] ?? '') === 'premium' && ($s['cycle'] ?? '') === 'monthly'
        && ($s['expiresAt'] ?? '') === $dateAdd($today, '1 MONTH') && sp_null($s, ['limits', 'youth']), sp_detail($r));

    // ---- Downgrade to Free while over the Free limits ----
    $before = $dbUsage($orgId);
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/plan", ['plan' => 'free'], $admin);
    $d = sp_data($r);
    $s = $d['subscription'] ?? [];
    $adminActions += $r['status'] === 200 ? 1 : 0;
    $check('downgrade_to_free_allowed', $r['status'] === 200 && ($s['plan'] ?? '') === 'free' && ($s['status'] ?? '') === 'free'
        && sp_null($s, ['expiresAt']) && ($s['cycle'] ?? '') === 'none', sp_detail($r));
    $check('downgrade_warning_lists_usage_vs_limit', ($d['overLimit'] ?? null) === [
        'youth' => ['usage' => 21, 'limit' => 20], 'accounts' => ['usage' => 2, 'limit' => 1],
        'programs' => ['usage' => 2, 'limit' => 1], 'assistance' => ['usage' => 2, 'limit' => 1],
    ] && is_string($d['warning'] ?? null) && str_contains($d['warning'], 'youth 21/20') && str_contains($d['warning'], 'kept'), json_encode([$d['overLimit'] ?? null, $d['warning'] ?? null]));
    $check('downgrade_kept_all_data', $dbUsage($orgId) === $before);
    $v = $skView();
    $check('sk_view_over_limit_flags', ($v['reached'] ?? []) === ['youth' => true, 'accounts' => true, 'programs' => true, 'assistance' => true, 'qr' => false]
        && ($v['remaining']['youth'] ?? -1) === 0 && isset($v['overLimit']['youth']) && ($v['features']['csvImport'] ?? true) === false,
        json_encode([$v['reached'] ?? null, $v['remaining'] ?? null]));
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/plan", ['plan' => 'free'], $admin);
    $check('already_free_409', $r['status'] === 409, sp_detail($r));

    // ---- PLAN_LIMIT_REACHED on every limited resource, and no partial records ----
    $r = sp_req('POST', "$base/sk/youth", ['firstName' => 'SpTest', 'lastName' => 'Blocked', 'birthDate' => '2005-05-05',
        'address' => 'Test address', 'contact' => '09170003999', 'interests' => ['Education & training']], $owner);
    $check('youth_create_blocked_shape', sp_limit_error($r, 'youth', 21, 20, 'free'), sp_detail($r));
    $check('youth_no_partial_row', (int) $sqlValue("SELECT COUNT(*) FROM youth WHERE organization_id = :o AND last_name = 'Blocked'", ['o' => $orgId]) === 0);
    $r = sp_req('POST', "$base/sk/programs", ['kind' => 'program', 'name' => 'SpTest Program Blocked', 'scheduledOn' => '2026-12-09', 'status' => 'draft'], $owner);
    $check('program_create_blocked_shape', sp_limit_error($r, 'programs', 2, 1, 'free'), sp_detail($r));
    $check('program_no_partial_row', (int) $sqlValue("SELECT COUNT(*) FROM programs WHERE organization_id = :o AND name = 'SpTest Program Blocked'", ['o' => $orgId]) === 0);
    $r = sp_req('POST', "$base/sk/assistance", ['name' => 'SpTest Assistance Blocked', 'category' => 'scholarship', 'status' => 'draft',
        'description' => 'blocked', 'slots' => 5, 'amount' => 1000], $owner);
    $check('assistance_create_blocked_shape', sp_limit_error($r, 'assistance', 2, 1, 'free'), sp_detail($r));
    $check('assistance_no_partial_row', (int) $sqlValue("SELECT COUNT(*) FROM assistance_programs WHERE organization_id = :o AND name = 'SpTest Assistance Blocked'", ['o' => $orgId]) === 0);
    $blockedStaff = "{$prefix}blocked-{$stamp}@example.test";
    $tempEmails[] = $blockedStaff;
    $r = sp_req('POST', "$base/sk/users", ['firstName' => 'SpTest', 'lastName' => 'Blocked', 'email' => $blockedStaff], $owner);
    $check('account_create_blocked_shape', sp_limit_error($r, 'accounts', 2, 1, 'free'), sp_detail($r));
    $check('account_no_partial_user', (int) $sqlValue('SELECT COUNT(*) FROM users WHERE email = :e', ['e' => $blockedStaff]) === 0);
    // QR: only this temp organization's counter is touched (set to the Free limit directly).
    $pdo->prepare('UPDATE organizations SET qr_uses = 1 WHERE id = :id')->execute(['id' => $orgId]);
    $check('usage_qr_reflects_counter', ($skView()['usage']['qr'] ?? -1) === 1);
    $r = sp_req('POST', "$base/sk/attendance/scan", ['token' => 'not-a-real-token', 'sessionToken' => 'not-a-session'], $owner);
    $check('qr_scan_blocked_shape', sp_limit_error($r, 'qr', 1, 1, 'free'), sp_detail($r));
    $check('qr_counter_not_incremented', $dbUsage($orgId)['qr'] === 1);
    $r = sp_req('POST', "$base/sk/youth/import", ['rows' => [$rows[0]]], $owner);
    $check('csv_import_on_free_keeps_PLAN_FEATURE', $r['status'] === 403 && (sp_err($r)['code'] ?? '') === 'PLAN_FEATURE', sp_detail($r));

    // Archive is allowed while over the limit; restoring past the limit is blocked.
    $r = sp_req('POST', "$base/sk/youth/{$spoofYouthId}/archive", [], $owner);
    $check('archive_allowed_over_limit', $r['status'] === 200 && (int) $sqlValue('SELECT archived FROM youth WHERE id = :id', ['id' => $spoofYouthId]) === 1, sp_detail($r));
    $r = sp_req('POST', "$base/sk/youth/{$spoofYouthId}/restore", [], $owner);
    $check('restore_past_limit_blocked_shape', sp_limit_error($r, 'youth', 20, 20, 'free'), sp_detail($r));
    $check('restore_blocked_left_record_archived', (int) $sqlValue('SELECT archived FROM youth WHERE id = :id', ['id' => $spoofYouthId]) === 1);

    // ---- Extend: Free cannot be extended ----
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/extend", ['days' => 30], $admin);
    $check('extend_free_422', $r['status'] === 422 && (sp_err($r)['fields']['status'] ?? '') === 'free', sp_detail($r));

    // ---- Basic monthly; CSV overflow is blocked with requested/remaining ----
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/plan", ['plan' => 'basic', 'cycle' => 'monthly'], $admin);
    $adminActions += $r['status'] === 200 ? 1 : 0;
    $check('change_to_basic_monthly', $r['status'] === 200 && sp_null(sp_data($r), ['warning'])
        && (sp_data($r)['subscription']['expiresAt'] ?? '') === $dateAdd($today, '1 MONTH'), sp_detail($r));
    $activeYouth = $dbUsage($orgId)['youth'];
    $bulk = [];
    $want = 250 - $activeYouth + 1;
    for ($i = 1; $i <= $want; $i++) {
        $bulk[] = ['firstName' => 'SpTest', 'lastName' => sprintf('Bulk%03d', $i), 'birthDate' => '2004-04-04', 'address' => 'Test address'];
    }
    $r = sp_req('POST', "$base/sk/youth/import", ['rows' => $bulk], $owner);
    $e = sp_err($r);
    $check('csv_overflow_blocked_shape', sp_limit_error($r, 'youth', $activeYouth, 250, 'basic') && ($e['requested'] ?? 0) === $want
        && ($e['remaining'] ?? -1) === $want - 1, sp_detail($r));
    $check('csv_overflow_no_partial_rows', (int) $sqlValue("SELECT COUNT(*) FROM youth WHERE organization_id = :o AND last_name LIKE 'Bulk%'", ['o' => $orgId]) === 0);

    // ---- Concurrency: 4 parallel SK account creates with 1 slot left (Basic: 3 accounts) ----
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 1; $i <= 4; $i++) {
        $email = "{$prefix}race{$i}-{$stamp}@example.test";
        $tempEmails[] = $email;
        $ch = curl_init("$base/sk/users");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $owner, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['firstName' => 'SpTest', 'lastName' => "Race{$i}", 'email' => $email]),
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);
    $codes = [];
    $limitCodes = 0;
    foreach ($handles as $ch) {
        $codes[] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $j = json_decode((string) curl_multi_getcontent($ch), true);
        $limitCodes += (($j['error']['code'] ?? '') === 'PLAN_LIMIT_REACHED') ? 1 : 0;
        curl_multi_remove_handle($mh, $ch);
    }
    unset($mh, $handles, $ch);
    sort($codes);
    $check('parallel_creates_one_wins', $codes === [201, 403, 403, 403] && $limitCodes === 3, json_encode($codes));
    $check('parallel_creates_never_exceed_limit', $dbUsage($orgId)['accounts'] === 3, json_encode($dbUsage($orgId)));

    // ---- Extend: validation ----
    foreach ([
        'extend_missing_input_422' => [],
        'extend_both_inputs_422' => ['days' => 5, 'expiresAt' => '2027-01-01'],
        'extend_zero_days_422' => ['days' => 0],
        'extend_366_days_422' => ['days' => 366],
        'extend_text_days_422' => ['days' => 'ten'],
        'extend_fractional_days_422' => ['days' => '2.5'],
        'extend_impossible_date_422' => ['expiresAt' => '2027-02-30'],
        'extend_bad_date_format_422' => ['expiresAt' => '01/02/2027'],
    ] as $name => $body) {
        $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/extend", $body, $admin);
        $check($name, $r['status'] === 422 && ($r['json']['success'] ?? true) === false, sp_detail($r));
    }
    $current = (string) $orgRow($orgId)['expires_at'];
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/extend", ['expiresAt' => $current], $admin);
    $check('extend_date_not_after_expiry_422', $r['status'] === 422, sp_detail($r));
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/extend", ['expiresAt' => $dateAdd($current, '366 DAY')], $admin);
    $check('extend_date_beyond_365_422', $r['status'] === 422, sp_detail($r));
    $check('extend_validation_changed_nothing', (string) $orgRow($orgId)['expires_at'] === $current);

    // ---- Extend: active term extends from its current expiry ----
    $startedBefore = (string) $orgRow($orgId)['started_on'];
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/extend", ['days' => 10], $admin);
    $d = sp_data($r);
    $adminActions += $r['status'] === 200 ? 1 : 0;
    $check('extend_active_by_days_from_expiry', $r['status'] === 200 && ($d['subscription']['expiresAt'] ?? '') === $dateAdd($current, '10 DAY')
        && ($d['extendedFrom'] ?? '') === $current && ($d['subscription']['status'] ?? '') === 'active'
        && (string) $orgRow($orgId)['started_on'] === $startedBefore, sp_detail($r));
    $target = $dateAdd($current, '15 DAY');
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/extend", ['expiresAt' => $target], $admin);
    $adminActions += $r['status'] === 200 ? 1 : 0;
    $check('extend_active_to_exact_date', $r['status'] === 200 && (sp_data($r)['subscription']['expiresAt'] ?? '') === $target, sp_detail($r));
    $v = $skView();
    $item = $adminItem();
    $dbDays = (int) $sqlValue('SELECT DATEDIFF(expires_at, CURDATE()) FROM organizations WHERE id = :id', ['id' => $orgId]);
    $check('extended_days_consistent_sk_admin_db', ($v['daysRemaining'] ?? null) === $dbDays && ($item['daysRemaining'] ?? null) === $dbDays,
        json_encode(['sk' => $v['daysRemaining'] ?? null, 'admin' => $item['daysRemaining'] ?? null, 'db' => $dbDays]));

    // ---- Expired (term ended 3 days ago): Free limits, status expired, data kept ----
    $pdo->prepare("UPDATE organizations SET sub_status = 'active', expires_at = DATE_SUB(CURDATE(), INTERVAL 3 DAY) WHERE id = :id")->execute(['id' => $orgId]);
    $v = $skView();
    $check('expired_view', ($v['status'] ?? '') === 'expired' && ($v['plan'] ?? '') === 'basic' && ($v['restrictedToFree'] ?? false) === true
        && sp_null($v, ['daysRemaining']) && ($v['expiresIn'] ?? 0) === -3 && ($v['limits'] ?? null) === ($byCode['free']['limits'] ?? false),
        json_encode([$v['status'] ?? null, $v['daysRemaining'] ?? null, $v['expiresIn'] ?? null]));
    $item = $adminItem();
    $check('admin_list_shows_expired', ($item['subStatus'] ?? '') === 'expired' && sp_null($item, ['daysRemaining']) && ($item['restrictedToFree'] ?? false) === true);
    $check('expired_kept_data', $dbUsage($orgId)['youth'] === $activeYouth);
    $r = sp_req('POST', "$base/sk/programs", ['kind' => 'program', 'name' => 'SpTest Program Expired', 'scheduledOn' => '2026-12-09', 'status' => 'draft'], $owner);
    $check('expired_create_blocked_free_limit', sp_limit_error($r, 'programs', 2, 1, 'free'), sp_detail($r));

    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/extend", ['days' => 30], $admin);
    $d = sp_data($r);
    $adminActions += $r['status'] === 200 ? 1 : 0;
    $check('extend_expired_from_today_reactivates', $r['status'] === 200 && ($d['subscription']['status'] ?? '') === 'active'
        && ($d['subscription']['expiresAt'] ?? '') === $dateAdd($today, '30 DAY') && ($d['extendedFrom'] ?? '') === $today
        && ($d['subscription']['startedOn'] ?? '') === $today && ($d['previous']['status'] ?? '') === 'expired', sp_detail($r));

    // ---- Trial: extending keeps the trial ----
    $pdo->prepare("UPDATE organizations SET plan = 'premium', sub_status = 'trial', cycle = 'trial', expires_at = DATE_ADD(CURDATE(), INTERVAL 2 DAY) WHERE id = :id")->execute(['id' => $orgId]);
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/extend", ['days' => 5], $admin);
    $d = sp_data($r);
    $adminActions += $r['status'] === 200 ? 1 : 0;
    $check('extend_trial_keeps_trial', $r['status'] === 200 && ($d['subscription']['status'] ?? '') === 'trial' && ($d['subscription']['cycle'] ?? '') === 'trial'
        && ($d['subscription']['daysRemaining'] ?? 0) === 7, sp_detail($r));
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/plan", ['plan' => 'basic', 'cycle' => 'monthly'], $admin);
    $adminActions += $r['status'] === 200 ? 1 : 0;
    $check('trial_plan_change_becomes_active', $r['status'] === 200 && (sp_data($r)['subscription']['status'] ?? '') === 'active', sp_detail($r));

    // ---- Run cycle: expires only lapsed terms ----
    sp_req('GET', "$base/admin/organizations?perPage=1", null, $admin);
    $pdo->prepare('UPDATE organizations SET expires_at = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = :id')->execute(['id' => $orgId]);
    $r = sp_req('POST', "$base/admin/subscriptions/run-cycle", [], $admin);
    $d = sp_data($r);
    $ids = array_map(static fn ($o) => (int) ($o['id'] ?? 0), $d['organizations'] ?? []);
    $adminActions += in_array($orgId, $ids, true) ? 1 : 0;
    $check('run_cycle_expires_lapsed_temp_org', $r['status'] === 200 && in_array($orgId, $ids, true) && ($orgRow($orgId)['sub_status'] ?? '') === 'expired'
        && ($d['today'] ?? '') === $today, sp_detail($r));
    $allLapsed = true;
    foreach ($d['organizations'] ?? [] as $o) {
        $allLapsed = $allLapsed && (string) ($o['expiresAt'] ?? '9999') < $today;
    }
    $check('run_cycle_only_lapsed_terms', $allLapsed && ($d['expired'] ?? -1) === count($ids));
    $r = sp_req('POST', "$base/admin/subscriptions/run-cycle", [], $admin);
    $check('run_cycle_idempotent', $r['status'] === 200 && !in_array($orgId, array_map(static fn ($o) => (int) ($o['id'] ?? 0), sp_data($r)['organizations'] ?? []), true), sp_detail($r));

    // ---- Cancel: immediate, Free limits, expiry kept ----
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/plan", ['plan' => 'premium', 'cycle' => 'yearly'], $admin);
    $adminActions += $r['status'] === 200 ? 1 : 0;
    $check('resubscribe_after_expiry', $r['status'] === 200 && (sp_data($r)['subscription']['status'] ?? '') === 'active', sp_detail($r));
    $beforeCancel = $orgRow($orgId);
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/cancel", ['reason' => 'Subscription test cancel'], $admin);
    $d = sp_data($r);
    $adminActions += $r['status'] === 200 ? 1 : 0;
    $afterCancel = $orgRow($orgId);
    $check('cancel_immediate', $r['status'] === 200 && ($d['subscription']['status'] ?? '') === 'cancelled' && ($d['previous']['status'] ?? '') === 'active', sp_detail($r));
    $check('cancel_keeps_plan_cycle_expiry', ($afterCancel['plan'] ?? '') === 'premium' && ($afterCancel['cycle'] ?? '') === 'yearly'
        && ($afterCancel['expires_at'] ?? '') === ($beforeCancel['expires_at'] ?? 'x') && ($d['subscription']['expiresAt'] ?? '') === ($beforeCancel['expires_at'] ?? 'x'));
    $v = $skView();
    $check('cancelled_free_limits', ($v['limits'] ?? null) === ($byCode['free']['limits'] ?? false) && ($v['restrictedToFree'] ?? false) === true
        && sp_null($v, ['daysRemaining']) && ($v['statusLabel'] ?? '') !== '');
    $r = sp_req('POST', "$base/sk/assistance", ['name' => 'SpTest Assistance Cancelled', 'category' => 'scholarship', 'status' => 'draft',
        'description' => 'blocked', 'slots' => 5, 'amount' => 1000], $owner);
    $check('cancelled_create_blocked', sp_limit_error($r, 'assistance', 2, 1, 'free'), sp_detail($r));
    $item = $adminItem();
    $check('admin_list_shows_cancelled', ($item['subStatus'] ?? '') === 'cancelled' && ($item['restrictedToFree'] ?? false) === true);
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/cancel", [], $admin);
    $check('cancel_twice_409', $r['status'] === 409, sp_detail($r));
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/extend", ['days' => 10], $admin);
    $check('extend_cancelled_422', $r['status'] === 422 && (sp_err($r)['fields']['status'] ?? '') === 'cancelled', sp_detail($r));
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/plan", ['plan' => 'free'], $admin);
    $adminActions += $r['status'] === 200 ? 1 : 0;
    $check('cancelled_to_free', $r['status'] === 200, sp_detail($r));
    $r = sp_req('POST', "$base/admin/organizations/{$orgId}/subscription/cancel", [], $admin);
    $check('cancel_free_422', $r['status'] === 422, sp_detail($r));

    $r = sp_req('GET', "$base/admin/organizations/{$orgId}/subscription", null, $admin);
    $check('admin_subscription_view', $r['status'] === 200 && (int) (sp_data($r)['organization']['id'] ?? 0) === $orgId
        && (sp_data($r)['subscription']['usage'] ?? null) === $dbUsage($orgId), sp_detail($r));

    // ---- Audit rows ----
    $logs = $pdo->prepare("SELECT user_id, actor_role, action, category, description FROM activity_logs WHERE organization_id = :o AND category = 'subscription' ORDER BY id");
    $logs->execute(['o' => $orgId]);
    $logs = $logs->fetchAll();
    $check('one_audit_row_per_successful_action', count($logs) === $adminActions, 'logs=' . count($logs) . " actions={$adminActions}");
    $actorsOk = $logs !== [];
    $secretFree = true;
    foreach ($logs as $log) {
        $actorsOk = $actorsOk && (int) $log['user_id'] === $adminUserId && $log['actor_role'] === 'system_admin' && $log['action'] === 'update';
        $secretFree = $secretFree && !str_contains($log['description'], $ownerPassword) && !str_contains($log['description'], 'password')
            && !str_contains($log['description'], '$2y$');
    }
    $check('audit_rows_admin_actor', $actorsOk, json_encode($logs[0] ?? null));
    $check('audit_rows_no_secrets', $secretFree);
    $check('no_subscription_audit_on_real_orgs', (int) $sqlValue(
        "SELECT COUNT(*) FROM activity_logs WHERE id > :start AND category = 'subscription' AND organization_id <> :o",
        ['start' => $logIdStart, 'o' => $orgId]
    ) === 0);

    $leak = false;
    foreach ($bodies as $b) {
        $leak = $leak || str_contains($b, 'password_hash') || str_contains($b, $ownerPassword) || str_contains($b, $demoPassword) || str_contains($b, '$2y$');
    }
    $check('responses_have_no_secrets', !$leak);
    $check('no_sms_or_email_for_temp_org', (int) $sqlValue('SELECT COUNT(*) FROM sms_deliveries WHERE organization_id = :o', ['o' => $orgId]) === 0
        && (int) $sqlValue('SELECT COUNT(*) FROM email_deliveries WHERE organization_id = :o', ['o' => $orgId]) === 0);

    $realNow = [];
    foreach ($pdo->query('SELECT id, plan, sub_status, cycle, started_on, expires_at, qr_uses, status FROM organizations ORDER BY id')->fetchAll() as $row) {
        if ((int) $row['id'] !== $orgId) {
            $realNow[(int) $row['id']] = $row;
        }
    }
    $check('real_orgs_subscription_and_qr_unchanged', $realNow == $realSnapshot);
} catch (Throwable $e) {
    $check('no_exception', false, $e->getMessage());
} finally {
    // ---- Cleanup: only the temp organization id and the temp users (exact ids + test emails) ----
    $cleanupOk = true;
    if ($orgId > 0 && (string) $sqlValue('SELECT email FROM organizations WHERE id = :id', ['id' => $orgId]) === $orgEmail) {
        $userIds = [];
        foreach ($tempEmails as $email) {
            $id = (int) $sqlValue('SELECT id FROM users WHERE email = :e', ['e' => $email]);
            if ($id > 0) {
                $userIds[$id] = $email;
            }
        }
        $byOrg = [
            'DELETE FROM attendance WHERE organization_id = :o',
            'DELETE FROM attendance_qr_tokens WHERE organization_id = :o',
            'DELETE FROM beneficiaries WHERE organization_id = :o',
            'DELETE FROM application_submissions WHERE organization_id = :o',
            'DELETE FROM applications WHERE organization_id = :o',
            'DELETE FROM assistance_requirements WHERE organization_id = :o',
            'DELETE FROM assistance_programs WHERE organization_id = :o',
            'DELETE FROM assistance_types WHERE organization_id = :o',
            'DELETE FROM youth_files WHERE organization_id = :o',
            'DELETE FROM notifications WHERE organization_id = :o',
            'DELETE FROM programs WHERE organization_id = :o AND parent_program_id IS NOT NULL',
            'DELETE FROM programs WHERE organization_id = :o',
            'DELETE FROM youth WHERE organization_id = :o',
            'DELETE FROM payment_transactions WHERE organization_id = :o',
            'DELETE FROM payments WHERE organization_id = :o',
            'DELETE FROM activity_logs WHERE organization_id = :o',
            'DELETE FROM organization_users WHERE organization_id = :o',
        ];
        try {
            foreach ($byOrg as $sql) {
                $pdo->prepare($sql)->execute(['o' => $orgId]);
            }
            foreach ($userIds as $id => $email) {
                $pdo->prepare('DELETE FROM notifications WHERE user_id = :id')->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :id')->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM activity_logs WHERE user_id = :id')->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM organization_users WHERE user_id = :id')->execute(['id' => $id]);
                $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :e')->execute(['id' => $id, 'e' => $email]);
            }
            $pdo->prepare('DELETE FROM organizations WHERE id = :id AND email = :e')->execute(['id' => $orgId, 'e' => $orgEmail]);
        } catch (Throwable $e) {
            $cleanupOk = false;
            echo 'cleanup_error=' . $e->getMessage() . "\n";
        }
        $left = (int) $sqlValue('SELECT COUNT(*) FROM organizations WHERE id = :id', ['id' => $orgId])
            + (int) $sqlValue("SELECT COUNT(*) FROM users WHERE email LIKE 'sp-test-%@example.test'")
            + (int) $sqlValue('SELECT COUNT(*) FROM youth WHERE organization_id = :id', ['id' => $orgId])
            + (int) $sqlValue('SELECT COUNT(*) FROM activity_logs WHERE organization_id = :id', ['id' => $orgId]);
        $check('cleanup_removed_temp_rows', $cleanupOk && $left === 0, "left={$left}");
    }
    if ($beforeCounts !== null) {
        $after = $tableCounts();
        $check('table_counts_restored', $after === $beforeCounts, json_encode(array_diff_assoc($after, $beforeCounts)));
    }
    foreach ($jars as $jar) {
        if (is_file($jar)) {
            unlink($jar);
        }
    }
}

echo "\npassed={$passed} failed={$failed}\n";
echo $failed === 0 ? "RESULT=PASS\n" : "RESULT=FAIL\n";
exit($failed === 0 ? 0 : 1);
