<?php
declare(strict_types=1);

/**
 * Dashboard statistics HTTP test: GET /sk/dashboard and GET /admin/dashboard.
 *
 * Run from api/sql with Apache + MySQL up:
 *   php dashboard_statistics_http_test.php
 * Optional: set YOUTHSYNC_API_BASE to override http://localhost/YouthSync/api.
 *
 * Every number returned by the API is compared with an independent direct SQL
 * query against the same database. The test then creates a temporary youth
 * record, program, event and assistance program through the API, checks that
 * the dashboards move by exactly one, deletes those exact ids through the API,
 * and checks that the counts return. No application is submitted and nothing
 * here sends SMS or email. activity_logs rows written by the temporary
 * create/delete actions are the audit trail and are left in place (ids printed).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$base = rtrim((string) (getenv('YOUTHSYNC_API_BASE') ?: 'http://localhost/YouthSync/api'), '/');
$pdo = youthsync_pdo();

const DS_PASSWORD = 'YouthSync1!';
const DS_YOUTH_EMAIL = 'youth-auth-test@example.test';
const DS_YOUTH_PASSWORD = 'YouthAuthTest1!';
const DS_TAG = 'DashStat';

$leftover = (int) $pdo->query("SELECT
    (SELECT COUNT(*) FROM youth WHERE first_name = 'DashStat')
  + (SELECT COUNT(*) FROM programs WHERE name LIKE 'DashStat %')
  + (SELECT COUNT(*) FROM assistance_programs WHERE name LIKE 'DashStat %')")->fetchColumn();
if ($leftover > 0) {
    fwrite(STDERR, "STOP: pre-existing DashStat test records found. No cleanup was performed.\n");
    exit(2);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
$cookies = [];
foreach (['anon', 'sk1', 'sk2', 'admin', 'youth'] as $who) {
    $cookies[$who] = $tmp . 'ys_dstat_' . $who . '.txt';
    @unlink($cookies[$who]);
}

/** @param list<string> $extraHeaders */
function ds_req(string $method, string $url, ?string $json, string $cookie, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    $headers = array_merge(['Accept: application/json'], $extraHeaders);
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
        return ['status' => 0, 'body' => $err, 'data' => []];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($raw, $headerSize);
    curl_close($ch);
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'data' => is_array($decoded) ? $decoded : []];
}

function ds_data(array $r): array
{
    $d = $r['data']['data'] ?? [];
    return is_array($d) ? $d : [];
}

/** Normalises a { key: count } map for comparison (sorted keys, int values). */
function ds_map(mixed $map): array
{
    $out = [];
    foreach (is_array($map) ? $map : [] as $k => $v) {
        $out[(string) $k] = $v;
    }
    ksort($out);
    return $out;
}

/** True when every leaf of the array is an int. */
function ds_all_ints(mixed $value): bool
{
    if (!is_array($value)) {
        return is_int($value);
    }
    foreach ($value as $child) {
        if (!ds_all_ints($child)) {
            return false;
        }
    }
    return true;
}

$failed = 0;
$passed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed, &$passed): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}" . ($detail !== '' && !$ok ? ' :: ' . substr($detail, 0, 600) : '') . "\n";
    $ok ? $passed++ : $failed++;
};
$same = static function (string $name, mixed $actual, mixed $expected) use ($check): void {
    $check($name, $actual === $expected, 'api=' . json_encode($actual) . ' sql=' . json_encode($expected));
};

// ---- independent SQL --------------------------------------------------------

$one = static function (string $sql, array $params = []) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};
$groups = static function (string $sql, array $params = []) use ($pdo): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$k, $n]) {
        $key = (string) ($k ?? '');
        $out[$key === '' ? '(blank)' : $key] = (int) $n;
    }
    ksort($out);
    return $out;
};

/** Expected /sk/dashboard numbers for one organization and SK user, straight from SQL. */
$sqlSk = static function (int $org, int $user) use ($one, $groups, $pdo): array {
    $p = ['o' => $org];
    $c = static fn (string $table, string $where = '1 = 1', array $extra = []) => $one(
        "SELECT COUNT(*) FROM {$table} WHERE organization_id = :o AND {$where}",
        $p + $extra
    );
    $s = [];
    $s['youth'] = ['total' => $c('youth'), 'active' => $c('youth', 'archived = 0'), 'archived' => $c('youth', 'archived = 1')];
    $s['programs'] = ['total' => $c('programs')];
    foreach (['draft', 'published', 'ongoing', 'completed', 'archived'] as $st) {
        $s['programs'][$st] = $c('programs', 'status = :st', ['st' => $st]);
    }
    $s['programs']['upcoming'] = $c('programs', "status IN ('published', 'ongoing') AND scheduled_on >= CURDATE()");
    $s['programs']['programs'] = $c('programs', "kind = 'program'");
    $s['programs']['events'] = $c('programs', "kind = 'event'");
    $s['assistance'] = [
        'total' => $c('assistance_programs'),
        'open' => $c('assistance_programs', "status = 'open'"),
        'active' => $c('assistance_programs', "status IN ('draft', 'open', 'full')"),
    ];
    $s['beneficiaries'] = ['total' => $c('beneficiaries')];
    foreach (['approved', 'released', 'applied', 'rejected'] as $st) {
        $s['beneficiaries'][$st] = $c('beneficiaries', 'status = :st', ['st' => $st]);
    }
    $s['applications'] = ['total' => $c('applications')];
    foreach (['pending' => 'pending', 'approved' => 'approved', 'rejected' => 'rejected', 'withdrawn' => 'withdrawn', 'needsResubmission' => 'needs_resubmission'] as $k => $st) {
        $s['applications'][$k] = $c('applications', 'status = :st', ['st' => $st]);
    }
    $s['applications']['programRegistrations'] = $c('applications', "program_id IS NOT NULL AND status NOT IN ('rejected', 'withdrawn')");
    $s['applications']['assistanceApplications'] = $c('applications', 'assistance_id IS NOT NULL');
    $s['notifications'] = ['unread' => $c('notifications', 'youth_id IS NULL AND (user_id IS NULL OR user_id = :u) AND is_read = 0', ['u' => $user])];
    $s['attendance'] = ['total' => $c('attendance')];
    foreach (['present', 'absent', 'excused', 'pending'] as $st) {
        $s['attendance'][$st] = $c('attendance', 'status = :st', ['st' => $st]);
    }

    $h = [
        'families' => $one("SELECT COUNT(DISTINCT guardian_name) FROM youth WHERE organization_id = :o AND archived = 0 AND guardian_name <> ''", $p),
        'unemployedYouth' => $c('youth', "archived = 0 AND employment = 'Unemployed'"),
        'unemployedParents' => $c('youth', "archived = 0 AND guardian_employment = 'Unemployed'"),
        'highPriorityYouth' => $c('youth', "archived = 0 AND priority_level = 'High'"),
        'scholarshipBeneficiaries' => $one(
            "SELECT COUNT(DISTINCT b.id) FROM beneficiaries b JOIN assistance_programs a ON a.id = b.assistance_id
             WHERE b.organization_id = :o AND a.organization_id = :o2 AND a.category = 'scholarship' AND b.status IN ('approved', 'released')",
            $p + ['o2' => $org]
        ),
        'upcomingPrograms' => $s['programs']['upcoming'],
        'activeAssistance' => $s['assistance']['active'],
    ];

    $age = ['15-17' => 0, '18-24' => 0, '25-30' => 0, 'other' => 0];
    $stmt = $pdo->prepare('SELECT TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) FROM youth WHERE organization_id = :o AND archived = 0');
    $stmt->execute($p);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $years) {
        $y = (int) $years;
        $age[$y >= 15 && $y <= 17 ? '15-17' : ($y >= 18 && $y <= 24 ? '18-24' : ($y >= 25 && $y <= 30 ? '25-30' : 'other'))]++;
    }
    ksort($age);
    $yg = static fn (string $col) => $groups("SELECT {$col}, COUNT(*) FROM youth WHERE organization_id = :o AND archived = 0 GROUP BY {$col}", $p);

    $up = $pdo->prepare(
        "SELECT p.id,
                (SELECT COUNT(*) FROM applications a WHERE a.organization_id = p.organization_id AND a.program_id = p.id AND a.status NOT IN ('rejected', 'withdrawn')) AS reg,
                (SELECT COUNT(*) FROM attendance t WHERE t.organization_id = p.organization_id AND t.program_id = p.id AND t.status = 'present') AS present
         FROM programs p
         WHERE p.organization_id = :o AND p.status IN ('published', 'ongoing') AND p.scheduled_on >= CURDATE()
         ORDER BY p.scheduled_on ASC, p.id ASC LIMIT 5"
    );
    $up->execute($p);
    $upcoming = array_map(static fn ($r) => ['id' => (int) $r['id'], 'registeredCount' => (int) $r['reg'], 'presentCount' => (int) $r['present']], $up->fetchAll());

    $nw = $pdo->prepare('SELECT id FROM youth WHERE organization_id = :o AND archived = 0 ORDER BY created_at DESC, id DESC LIMIT 3');
    $nw->execute($p);

    return [
        'summary' => $s,
        'highlights' => $h,
        'youth' => [
            'ageGroups' => $age,
            'gender' => $yg('gender'),
            'education' => $yg('education'),
            'employment' => $yg('employment'),
            'priority' => $yg('priority_level'),
        ],
        'programs' => [
            'byStatus' => $groups('SELECT status, COUNT(*) FROM programs WHERE organization_id = :o GROUP BY status', $p),
            'byKind' => $groups('SELECT kind, COUNT(*) FROM programs WHERE organization_id = :o GROUP BY kind', $p),
            'upcoming' => $upcoming,
        ],
        'assistance' => [
            'byStatus' => $groups('SELECT status, COUNT(*) FROM assistance_programs WHERE organization_id = :o GROUP BY status', $p),
            'byCategory' => $groups('SELECT category, COUNT(*) FROM assistance_programs WHERE organization_id = :o GROUP BY category', $p),
            'beneficiariesByStatus' => $groups('SELECT status, COUNT(*) FROM beneficiaries WHERE organization_id = :o GROUP BY status', $p),
            'beneficiariesByCategory' => $groups(
                'SELECT a.category, COUNT(DISTINCT b.id) FROM beneficiaries b JOIN assistance_programs a ON a.id = b.assistance_id AND a.organization_id = b.organization_id
                 WHERE b.organization_id = :o GROUP BY a.category', $p),
            'awardedBeneficiariesByCategory' => $groups(
                "SELECT a.category, COUNT(DISTINCT b.id) FROM beneficiaries b JOIN assistance_programs a ON a.id = b.assistance_id AND a.organization_id = b.organization_id
                 WHERE b.organization_id = :o AND b.status IN ('approved', 'released') GROUP BY a.category", $p),
        ],
        'applications' => ['byStatus' => $groups('SELECT status, COUNT(*) FROM applications WHERE organization_id = :o GROUP BY status', $p)],
        'attendance' => ['byStatus' => $groups('SELECT status, COUNT(*) FROM attendance WHERE organization_id = :o GROUP BY status', $p)],
        'newestYouth' => array_map('intval', $nw->fetchAll(PDO::FETCH_COLUMN)),
    ];
};

/** Compares a /sk/dashboard payload with the SQL expectation, field by field. */
$compareSk = static function (string $label, array $api, array $sql, int $org, int $user) use ($same, $check, $pdo): void {
    foreach ($sql['summary'] as $group => $fields) {
        foreach ($fields as $field => $value) {
            $same("{$label}_summary_{$group}_{$field}", $api['summary'][$group][$field] ?? null, $value);
        }
    }
    foreach ($sql['highlights'] as $field => $value) {
        $same("{$label}_highlights_{$field}", $api['highlights'][$field] ?? null, $value);
    }
    foreach (['youth', 'programs', 'assistance', 'applications', 'attendance'] as $section) {
        foreach ($sql[$section] as $field => $value) {
            if ($field === 'upcoming') {
                continue;
            }
            $same("{$label}_{$section}_{$field}", ds_map($api[$section][$field] ?? null), $value);
        }
    }
    $upcoming = array_map(static fn ($r) => [
        'id' => $r['id'] ?? null,
        'registeredCount' => $r['registeredCount'] ?? null,
        'presentCount' => $r['presentCount'] ?? null,
    ], $api['programs']['upcoming'] ?? []);
    $same("{$label}_programs_upcoming_list", $upcoming, $sql['programs']['upcoming']);
    $same("{$label}_newestYouth", array_map(static fn ($r) => $r['id'] ?? null, $api['newestYouth'] ?? []), $sql['newestYouth']);

    // Recent feed: every item belongs to this organization; notifications are this user's inbox only.
    $ok = true;
    $bad = [];
    $tables = ['youth' => 'youth', 'program' => 'programs', 'application' => 'applications', 'attendance' => 'attendance', 'notification' => 'notifications'];
    foreach ($api['recent'] ?? [] as $item) {
        $table = $tables[$item['source'] ?? ''] ?? null;
        if ($table === null) {
            $ok = false;
            $bad[] = $item;
            continue;
        }
        $extra = $table === 'notifications' ? ' AND youth_id IS NULL AND (user_id IS NULL OR user_id = :u)' : '';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id = :id AND organization_id = :o{$extra}");
        $stmt->execute(['id' => (int) $item['id'], 'o' => $org] + ($extra !== '' ? ['u' => $user] : []));
        if ((int) $stmt->fetchColumn() !== 1) {
            $ok = false;
            $bad[] = $item;
        }
    }
    $check("{$label}_recent_scoped_to_org_and_inbox", $ok && count($api['recent'] ?? []) <= 6, json_encode($bad));
};

$sqlAdmin = static function () use ($pdo, $one, $groups): array {
    $orgs = static fn (string $where) => $one("SELECT COUNT(*) FROM organizations WHERE {$where}");
    $perOrg = [];
    foreach ($pdo->query('SELECT o.id, (SELECT COUNT(*) FROM youth y WHERE y.organization_id = o.id AND y.archived = 0) AS n FROM organizations o') as $r) {
        $perOrg[(int) $r['id']] = (int) $r['n'];
    }
    ksort($perOrg);
    return [
        'organizations' => [
            'total' => $orgs('1 = 1'),
            'pending' => $orgs("status = 'pending'"),
            'activeSubscriptions' => $orgs("sub_status IN ('active', 'trial')"),
            'expiringSoon' => $orgs("sub_status IN ('active', 'trial') AND expires_at >= CURDATE() AND expires_at <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)"),
            'expired' => $orgs("sub_status = 'expired'"),
            'trial' => $orgs("sub_status = 'trial'"),
            'freePlan' => $orgs("plan = 'free'"),
            'basicActive' => $orgs("plan = 'basic' AND sub_status = 'active'"),
            'premiumActive' => $orgs("plan = 'premium' AND sub_status = 'active'"),
        ],
        'orgByPlan' => $groups('SELECT plan, COUNT(*) FROM organizations GROUP BY plan'),
        'orgBySub' => $groups('SELECT sub_status, COUNT(*) FROM organizations GROUP BY sub_status'),
        'orgByStatus' => $groups('SELECT status, COUNT(*) FROM organizations GROUP BY status'),
        'users' => [
            'total' => $one('SELECT COUNT(*) FROM users'),
            'active' => $one("SELECT COUNT(*) FROM users WHERE status = 'active'"),
        ],
        'usersByRole' => $groups('SELECT r.code, COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id GROUP BY r.code'),
        'youth' => [
            'total' => $one('SELECT COUNT(*) FROM youth'),
            'active' => $one('SELECT COUNT(*) FROM youth WHERE archived = 0'),
            'archived' => $one('SELECT COUNT(*) FROM youth WHERE archived = 1'),
        ],
        'programs' => [
            'total' => $one('SELECT COUNT(*) FROM programs'),
            'programs' => $one("SELECT COUNT(*) FROM programs WHERE kind = 'program'"),
            'events' => $one("SELECT COUNT(*) FROM programs WHERE kind = 'event'"),
        ],
        'programsByStatus' => $groups('SELECT status, COUNT(*) FROM programs GROUP BY status'),
        'applications' => ['total' => $one('SELECT COUNT(*) FROM applications')],
        'applicationsByStatus' => $groups('SELECT status, COUNT(*) FROM applications GROUP BY status'),
        // Revenue is PayMongo-verified only (the manual / offline ledger was removed).
        'payments' => [
            'onlineVerifiedCount' => $one("SELECT COUNT(*) FROM payment_transactions WHERE verified_at IS NOT NULL AND status IN ('paid', 'partially_refunded')"),
            'onlineVerified' => ((int) $pdo->query(
                "SELECT COALESCE(SUM(amount_centavos - COALESCE(refunded_centavos, 0)), 0) FROM payment_transactions
                 WHERE verified_at IS NOT NULL AND status IN ('paid', 'partially_refunded')"
            )->fetchColumn()) / 100,
        ],
        'youthPerOrganization' => $perOrg,
    ];
};

$compareAdmin = static function (string $label, array $api, array $sql) use ($same, $check): void {
    foreach (['organizations', 'users', 'youth', 'programs', 'applications'] as $group) {
        foreach ($sql[$group] as $field => $value) {
            $same("{$label}_{$group}_{$field}", $api[$group][$field] ?? null, $value);
        }
    }
    $byPlan = array_filter(ds_map($api['organizations']['byPlan'] ?? []), static fn ($n) => $n > 0);
    $byStatus = array_filter(ds_map($api['organizations']['byStatus'] ?? []), static fn ($n) => $n > 0);
    $same("{$label}_organizations_byPlan", $byPlan, $sql['orgByPlan']);
    $same("{$label}_organizations_byStatus", $byStatus, $sql['orgByStatus']);
    $same("{$label}_organizations_bySubscriptionStatus", ds_map($api['organizations']['bySubscriptionStatus'] ?? []), $sql['orgBySub']);
    $same("{$label}_users_byRole", ds_map($api['users']['byRole'] ?? []), $sql['usersByRole']);
    $same("{$label}_programs_byStatus", ds_map($api['programs']['byStatus'] ?? []), $sql['programsByStatus']);
    $same("{$label}_applications_byStatus", ds_map($api['applications']['byStatus'] ?? []), $sql['applicationsByStatus']);
    $same("{$label}_payments_online_verified_count", $api['payments']['onlineVerifiedCount'] ?? null, $sql['payments']['onlineVerifiedCount']);
    $check("{$label}_payments_online_verified", abs((float) ($api['payments']['onlineVerified'] ?? -1) - $sql['payments']['onlineVerified']) < 0.005);
    $same("{$label}_payments_source", $api['payments']['source'] ?? null, 'paymongo_verified');
    $check("{$label}_payments_no_manual_ledger_keys", is_array($api['payments'] ?? null)
        && array_intersect(['recorded', 'paid', 'collected', 'collectedByPlan', 'manualVerified', 'manualUnverified'], array_keys($api['payments'])) === []);

    $rows = $api['youthPerOrganization'] ?? [];
    $perOrg = [];
    foreach ($rows as $row) {
        $perOrg[(int) ($row['organizationId'] ?? 0)] = $row['youth'] ?? null;
    }
    ksort($perOrg);
    $check("{$label}_youth_per_org_one_row_per_org", count($rows) === count($perOrg) && count($rows) === $sql['organizations']['total'], count($rows) . ' rows, ' . count($perOrg) . ' unique');
    $same("{$label}_youth_per_org_matches_group_by", $perOrg, $sql['youthPerOrganization']);
    $check("{$label}_youth_per_org_includes_zero_youth_orgs", in_array(0, $sql['youthPerOrganization'], true) ? in_array(0, $perOrg, true) : true);
    $check("{$label}_youth_per_org_sum_equals_active_youth", array_sum($perOrg) === $sql['youth']['active']);
};

// ---- auth and role protection ---------------------------------------------

$r = ds_req('GET', "$base/sk/dashboard", null, $cookies['anon']);
$check('anon_sk_dashboard_401', $r['status'] === 401, (string) $r['status']);
$check('anon_sk_dashboard_error_format', ($r['data']['success'] ?? null) === false && isset($r['data']['error']['code']), $r['body']);
$r = ds_req('GET', "$base/admin/dashboard", null, $cookies['anon']);
$check('anon_admin_dashboard_401', $r['status'] === 401, (string) $r['status']);

$login = static function (string $who, string $email, string $password) use ($base, $cookies, $check): void {
    $r = ds_req('POST', "$base/auth/login", json_encode(['email' => $email, 'password' => $password]), $cookies[$who]);
    $check("login_{$who}", $r['status'] === 200, (string) $r['status']);
};
$login('sk1', 'sk1@demo.test', DS_PASSWORD);
$login('sk2', 'sk2@demo.test', DS_PASSWORD);
$login('admin', 'admin@skmms.test', DS_PASSWORD);
$login('youth', DS_YOUTH_EMAIL, DS_YOUTH_PASSWORD);

$me = static fn (string $who) => ds_data(ds_req('GET', "$base/auth/me", null, $cookies[$who]));
$sk1Me = $me('sk1');
$sk2Me = $me('sk2');
$org1 = (int) ($sk1Me['organization']['id'] ?? 0);
$org2 = (int) ($sk2Me['organization']['id'] ?? 0);
$user1 = (int) ($sk1Me['user']['id'] ?? 0);
$user2 = (int) ($sk2Me['user']['id'] ?? 0);
$check('sk1_sk2_different_orgs', $org1 > 0 && $org2 > 0 && $org1 !== $org2, "{$org1}/{$org2}");

$r = ds_req('GET', "$base/sk/dashboard", null, $cookies['youth']);
$check('youth_sk_dashboard_403', $r['status'] === 403, (string) $r['status']);
$r = ds_req('GET', "$base/sk/dashboard", null, $cookies['admin']);
$check('admin_sk_dashboard_403', $r['status'] === 403, (string) $r['status']);
$r = ds_req('GET', "$base/admin/dashboard", null, $cookies['sk1']);
$check('sk_admin_dashboard_403', $r['status'] === 403, (string) $r['status']);
$check('sk_admin_dashboard_error_format', ($r['data']['success'] ?? null) === false && isset($r['data']['error']['code']), $r['body']);
$r = ds_req('GET', "$base/admin/dashboard", null, $cookies['youth']);
$check('youth_admin_dashboard_403', $r['status'] === 403, (string) $r['status']);

// ---- SK dashboard vs SQL -----------------------------------------------------

$skDash = static fn (string $who, string $query = '', ?string $body = null, array $headers = []) => ds_req('GET', "$base/sk/dashboard{$query}", $body, $cookies[$who], $headers);

$r = $skDash('sk1');
$check('sk1_dashboard_200', $r['status'] === 200, (string) $r['status']);
$sk1 = ds_data($r);
$check('sk1_summary_all_integers', ds_all_ints($sk1['summary'] ?? null) && ds_all_ints($sk1['highlights'] ?? null), json_encode($sk1['summary'] ?? null));
$compareSk('sk1', $sk1, $sqlSk($org1, $user1), $org1, $user1);

$r = $skDash('sk2');
$check('sk2_dashboard_200', $r['status'] === 200, (string) $r['status']);
$sk2 = ds_data($r);
$compareSk('sk2', $sk2, $sqlSk($org2, $user2), $org2, $user2);

// Spoofing: organization_id / user_id in query, body and headers are ignored.
$spoofQuery = "?organization_id={$org2}&orgId={$org2}&organizationId={$org2}&org_id={$org2}&user_id={$user2}&userId={$user2}";
$spoofBody = json_encode(['organization_id' => $org2, 'organizationId' => $org2, 'user_id' => $user2, 'userId' => $user2]);
$spoofHeaders = ["X-Organization-Id: {$org2}", "X-Org-Id: {$org2}", "X-User-Id: {$user2}", "Organization-Id: {$org2}"];
$r = $skDash('sk1', $spoofQuery);
$check('sk1_spoof_query_200', $r['status'] === 200, (string) $r['status']);
$same('sk1_spoof_query_ignored', ds_data($r)['summary'] ?? null, $sk1['summary'] ?? null);
$r = $skDash('sk1', '', $spoofBody);
$same('sk1_spoof_body_ignored', ds_data($r)['summary'] ?? null, $sk1['summary'] ?? null);
$r = $skDash('sk1', '', null, $spoofHeaders);
$same('sk1_spoof_headers_ignored', ds_data($r)['summary'] ?? null, $sk1['summary'] ?? null);
$r = $skDash('sk1', $spoofQuery, $spoofBody, $spoofHeaders);
$same('sk1_spoof_all_ignored_highlights', ds_data($r)['highlights'] ?? null, $sk1['highlights'] ?? null);
$r = $skDash('sk2', "?organization_id={$org1}&user_id={$user1}", json_encode(['organization_id' => $org1]), ["X-Organization-Id: {$org1}"]);
$same('sk2_spoof_org1_ignored', ds_data($r)['summary'] ?? null, $sk2['summary'] ?? null);

// Other organizations with data (attendance, beneficiaries, ...): no SK password is known
// for them, so the same comparison runs against DashboardService directly.
spl_autoload_register(static function (string $class): void {
    $prefix = 'YouthSync\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
$service = new YouthSync\Dashboard\DashboardService($pdo);
$otherOrgs = array_map('intval', $pdo->query(
    'SELECT id FROM organizations o WHERE EXISTS (SELECT 1 FROM youth y WHERE y.organization_id = o.id)
        OR EXISTS (SELECT 1 FROM programs p WHERE p.organization_id = o.id)
        OR EXISTS (SELECT 1 FROM assistance_programs a WHERE a.organization_id = o.id)
     ORDER BY id'
)->fetchAll(PDO::FETCH_COLUMN));
foreach (array_diff($otherOrgs, [$org1, $org2]) as $otherOrg) {
    $compareSk("service_org{$otherOrg}", $service->dashboard($otherOrg, 0, []), $sqlSk($otherOrg, 0), $otherOrg, 0);
}

// ---- admin dashboard vs SQL --------------------------------------------------

$r = ds_req('GET', "$base/admin/dashboard", null, $cookies['admin']);
$check('admin_dashboard_200', $r['status'] === 200, (string) $r['status']);
$admin = ds_data($r);
$compareAdmin('admin', $admin, $sqlAdmin());

$orgList = [];
$page = 1;
do {
    $lr = ds_data(ds_req('GET', "$base/admin/organizations?page={$page}&perPage=100", null, $cookies['admin']));
    foreach ($lr['items'] ?? [] as $o) {
        $orgList[(int) $o['id']] = (int) $o['youthCount'];
    }
    $page++;
} while ($page <= (int) ($lr['pages'] ?? 1));
ksort($orgList);
$perOrgApi = [];
foreach ($admin['youthPerOrganization'] ?? [] as $row) {
    $perOrgApi[(int) $row['organizationId']] = (int) $row['youth'];
}
ksort($perOrgApi);
$same('admin_youth_per_org_matches_org_listing_youthCount', $perOrgApi, $orgList);

// Monitoring data source: the activity list is the live activity_logs table.
$r = ds_req('GET', "$base/admin/activity?page=1&perPage=5", null, $cookies['admin']);
$act = ds_data($r);
$same('monitoring_activity_total_matches_db', $act['total'] ?? null, $one('SELECT COUNT(*) FROM activity_logs'));
$same('monitoring_activity_newest_id_matches_db', (int) ($act['items'][0]['id'] ?? 0), $one('SELECT id FROM activity_logs ORDER BY created_at DESC, id DESC LIMIT 1'));

// ---- frontend sources: no mock or client-computed dashboard numbers ----------

$front = dirname(__DIR__, 2) . '/youth-sync/src';
$skPage = @file_get_contents($front . '/pages/sk/Dashboard.jsx');
$adminPage = @file_get_contents($front . '/pages/admin/Admin.jsx');
if ($skPage !== false && $adminPage !== false) {
    $check('ui_sk_dashboard_no_mock_import', !str_contains($skPage, 'data/mock'));
    $check('ui_sk_dashboard_uses_sk_dashboard_endpoint', str_contains($skPage, "api.get('/sk/dashboard'"));
    $check('ui_sk_dashboard_no_client_collections', !preg_match('/scoped\(|db\.notifications|\?\? youth\.length/', $skPage));
    preg_match('/export function AdminDashboard\(\)[\s\S]*?\nexport function Organizations/', $adminPage, $m);
    $adminDash = $m[0] ?? '';
    $check('ui_admin_dashboard_uses_admin_dashboard_endpoint', str_contains($adminDash, "api.get('/admin/dashboard'"));
    $check('ui_admin_dashboard_no_empty_youth_array', $adminDash !== '' && !str_contains($adminDash, 'db.youth'));
    $check('ui_monitoring_sample_labels', str_contains($adminPage, 'System events (sample data)') && str_contains($adminPage, 'Activity log (live)'));
    $check('ui_monitoring_no_fake_backup_success', !str_contains($adminPage, 'Backup checkpoint recorded'));
} else {
    echo "SKIP ui source checks (youth-sync/src not found next to api/)\n";
}

// ---- create -> count -> delete ------------------------------------------------

$created = ['youth' => [], 'programs' => [], 'assistance' => []];
$suffix = date('YmdHis') . random_int(100, 999);
$maxActivityBefore = $one('SELECT COALESCE(MAX(id), 0) FROM activity_logs');
$maxNotificationBefore = $one('SELECT COALESCE(MAX(id), 0) FROM notifications');
$maxSmsBefore = $one('SELECT COALESCE(MAX(id), 0) FROM sms_deliveries');
$maxEmailBefore = $one('SELECT COALESCE(MAX(id), 0) FROM email_deliveries');

$adminPerOrg = static function (array $adminData, int $org): ?int {
    foreach ($adminData['youthPerOrganization'] ?? [] as $row) {
        if ((int) $row['organizationId'] === $org) {
            return (int) $row['youth'];
        }
    }
    return null;
};
$base1 = $sk1;
$base2 = $sk2;
$baseAdmin = $admin;

$r = ds_req('POST', "$base/sk/youth?organization_id={$org2}", json_encode([
    'firstName' => DS_TAG,
    'lastName' => 'Youth' . $suffix,
    'birthDate' => date('Y-m-d', strtotime('-20 years -30 days')),
    'gender' => 'Female',
    'address' => 'Purok DashStat',
    'contact' => '0917' . random_int(1000000, 9999999),
    'interests' => ['Sports'],
    'employment' => 'Unemployed',
    'educationStatus' => 'Not Currently Studying',
    'education' => 'College',
    'guardianName' => 'DashStat Guardian ' . $suffix,
    'guardianEmployment' => 'Unemployed',
    'organization_id' => $org2,
    'organizationId' => $org2,
]), $cookies['sk1']);
$youthId = (int) (ds_data($r)['youth']['id'] ?? ds_data($r)['id'] ?? 0);
if ($youthId > 0) {
    $created['youth'][] = $youthId;
}
$check('temp_youth_created', $r['status'] === 201 && $youthId > 0, substr($r['body'], 0, 300));
$check('temp_youth_in_session_org', $one('SELECT organization_id FROM youth WHERE id = :id', ['id' => $youthId]) === $org1);

$after = ds_data($skDash('sk1'));
$same('cycle_youth_total_plus_1', $after['summary']['youth']['total'] ?? null, $base1['summary']['youth']['total'] + 1);
$same('cycle_youth_active_plus_1', $after['summary']['youth']['active'] ?? null, $base1['summary']['youth']['active'] + 1);
$same('cycle_youth_archived_same', $after['summary']['youth']['archived'] ?? null, $base1['summary']['youth']['archived']);
$same('cycle_families_plus_1', $after['highlights']['families'] ?? null, $base1['highlights']['families'] + 1);
$same('cycle_unemployed_youth_plus_1', $after['highlights']['unemployedYouth'] ?? null, $base1['highlights']['unemployedYouth'] + 1);
$same('cycle_unemployed_parents_plus_1', $after['highlights']['unemployedParents'] ?? null, $base1['highlights']['unemployedParents'] + 1);
$same('cycle_age_18_24_plus_1', $after['youth']['ageGroups']['18-24'] ?? null, $base1['youth']['ageGroups']['18-24'] + 1);
$same('cycle_newest_youth_first', (int) ($after['newestYouth'][0]['id'] ?? 0), $youthId);
$same('cycle_org2_unchanged_after_youth', ds_data($skDash('sk2'))['summary'] ?? null, $base2['summary']);
$adminAfter = ds_data(ds_req('GET', "$base/admin/dashboard", null, $cookies['admin']));
$same('cycle_admin_youth_active_plus_1', $adminAfter['youth']['active'] ?? null, $baseAdmin['youth']['active'] + 1);
$same('cycle_admin_youth_per_org1_plus_1', $adminPerOrg($adminAfter, $org1), $adminPerOrg($baseAdmin, $org1) + 1);
$same('cycle_admin_youth_per_org2_same', $adminPerOrg($adminAfter, $org2), $adminPerOrg($baseAdmin, $org2));

$r = ds_req('POST', "$base/sk/programs", json_encode([
    'name' => DS_TAG . ' Program ' . $suffix,
    'category' => 'Sports',
    'status' => 'draft',
    'scheduledOn' => date('Y-m-d', strtotime('+3 days')),
    'location' => 'DashStat court',
    'organization_id' => $org2,
]), $cookies['sk1']);
$programId = (int) (ds_data($r)['id'] ?? 0);
if ($programId > 0) {
    $created['programs'][] = $programId;
}
$check('temp_program_created', $r['status'] === 201 && $programId > 0, substr($r['body'], 0, 300));
$mid = ds_data($skDash('sk1'));
$same('cycle_programs_total_plus_1', $mid['summary']['programs']['total'] ?? null, $base1['summary']['programs']['total'] + 1);
$same('cycle_programs_kind_program_plus_1', $mid['summary']['programs']['programs'] ?? null, $base1['summary']['programs']['programs'] + 1);
$same('cycle_programs_draft_plus_1', $mid['summary']['programs']['draft'] ?? null, $base1['summary']['programs']['draft'] + 1);
$same('cycle_upcoming_same_while_draft', $mid['summary']['programs']['upcoming'] ?? null, $base1['summary']['programs']['upcoming']);

$r = ds_req('POST', "$base/sk/programs/{$programId}/status", json_encode(['status' => 'published']), $cookies['sk1']);
$check('temp_program_published', $r['status'] === 200, (string) $r['status']);
$mid = ds_data($skDash('sk1'));
$same('cycle_published_plus_1', $mid['summary']['programs']['published'] ?? null, $base1['summary']['programs']['published'] + 1);
$same('cycle_draft_back', $mid['summary']['programs']['draft'] ?? null, $base1['summary']['programs']['draft']);
$same('cycle_upcoming_plus_1', $mid['summary']['programs']['upcoming'] ?? null, $base1['summary']['programs']['upcoming'] + 1);
$same('cycle_highlight_upcoming_plus_1', $mid['highlights']['upcomingPrograms'] ?? null, $base1['highlights']['upcomingPrograms'] + 1);
$check('cycle_upcoming_list_contains_program', in_array($programId, array_map(static fn ($p) => (int) $p['id'], $mid['programs']['upcoming'] ?? []), true)
    || count($mid['programs']['upcoming'] ?? []) === 5);

$r = ds_req('POST', "$base/sk/events", json_encode([
    'name' => DS_TAG . ' Event ' . $suffix,
    'category' => 'Sports',
    'status' => 'draft',
    'scheduledOn' => date('Y-m-d', strtotime('+4 days')),
    'location' => 'DashStat hall',
]), $cookies['sk1']);
$eventId = (int) (ds_data($r)['id'] ?? 0);
if ($eventId > 0) {
    $created['programs'][] = $eventId;
}
$check('temp_event_created', $r['status'] === 201 && $eventId > 0, substr($r['body'], 0, 300));

$r = ds_req('POST', "$base/sk/assistance", json_encode([
    'name' => DS_TAG . ' Assistance ' . $suffix,
    'category' => 'other',
    'status' => 'draft',
    'slots' => 2,
    'organization_id' => $org2,
]), $cookies['sk1']);
$assistId = (int) (ds_data($r)['id'] ?? 0);
if ($assistId > 0) {
    $created['assistance'][] = $assistId;
}
$check('temp_assistance_created', $r['status'] === 201 && $assistId > 0, substr($r['body'], 0, 300));

$mid = ds_data($skDash('sk1'));
$same('cycle_events_plus_1', $mid['summary']['programs']['events'] ?? null, $base1['summary']['programs']['events'] + 1);
$same('cycle_programs_total_plus_2', $mid['summary']['programs']['total'] ?? null, $base1['summary']['programs']['total'] + 2);
$same('cycle_assistance_total_plus_1', $mid['summary']['assistance']['total'] ?? null, $base1['summary']['assistance']['total'] + 1);
$same('cycle_assistance_active_plus_1', $mid['highlights']['activeAssistance'] ?? null, $base1['highlights']['activeAssistance'] + 1);
$compareSk('cycle_mid_sk1', $mid, $sqlSk($org1, $user1), $org1, $user1);
$same('cycle_org2_unchanged_after_programs', ds_data($skDash('sk2'))['summary'] ?? null, $base2['summary']);
$adminMid = ds_data(ds_req('GET', "$base/admin/dashboard", null, $cookies['admin']));
$same('cycle_admin_programs_plus_1', $adminMid['programs']['programs'] ?? null, $baseAdmin['programs']['programs'] + 1);
$same('cycle_admin_events_plus_1', $adminMid['programs']['events'] ?? null, $baseAdmin['programs']['events'] + 1);
$compareAdmin('cycle_mid_admin', $adminMid, $sqlAdmin());

// ---- cleanup: exact ids through the API ------------------------------------

foreach ($created['assistance'] as $id) {
    $r = ds_req('DELETE', "$base/sk/assistance/{$id}", null, $cookies['sk1']);
    $check("cleanup_assistance_{$id}", $r['status'] === 200 && $one('SELECT COUNT(*) FROM assistance_programs WHERE id = :id', ['id' => $id]) === 0, (string) $r['status']);
}
foreach ($created['programs'] as $id) {
    $r = ds_req('DELETE', "$base/sk/programs/{$id}", null, $cookies['sk1']);
    $check("cleanup_program_{$id}", $r['status'] === 200 && $one('SELECT COUNT(*) FROM programs WHERE id = :id', ['id' => $id]) === 0, (string) $r['status']);
}
foreach ($created['youth'] as $id) {
    $r = ds_req('DELETE', "$base/sk/youth/{$id}", null, $cookies['sk1']);
    $check("cleanup_youth_{$id}", $r['status'] === 200 && $one('SELECT COUNT(*) FROM youth WHERE id = :id', ['id' => $id]) === 0, (string) $r['status']);
}

$final = ds_data($skDash('sk1'));
$same('cycle_sk1_summary_restored', $final['summary'] ?? null, $base1['summary']);
$same('cycle_sk1_highlights_restored', $final['highlights'] ?? null, $base1['highlights']);
$finalAdmin = ds_data(ds_req('GET', "$base/admin/dashboard", null, $cookies['admin']));
foreach (['youth', 'programs', 'applications', 'users'] as $group) {
    $same("cycle_admin_{$group}_restored", $finalAdmin[$group] ?? null, $baseAdmin[$group] ?? null);
}
$same('cycle_admin_youth_per_org_restored', $finalAdmin['youthPerOrganization'] ?? null, $baseAdmin['youthPerOrganization'] ?? null);
$same('no_notifications_created', $one('SELECT COUNT(*) FROM notifications WHERE id > :id', ['id' => $maxNotificationBefore]), 0);
$same('no_sms_created', $one('SELECT COUNT(*) FROM sms_deliveries WHERE id > :id', ['id' => $maxSmsBefore]), 0);
$same('no_email_created', $one('SELECT COUNT(*) FROM email_deliveries WHERE id > :id', ['id' => $maxEmailBefore]), 0);
$activityIds = array_map('intval', $pdo->query("SELECT id FROM activity_logs WHERE id > {$maxActivityBefore} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

foreach ($cookies as $file) {
    @unlink($file);
}

echo "\nTemporary ids (deletion is checked by the cleanup_* lines above): youth " . json_encode($created['youth']) . ', programs/events ' . json_encode($created['programs'])
    . ', assistance ' . json_encode($created['assistance']) . "\n";
echo 'activity_logs rows written by this run (audit trail, kept): '
    . ($activityIds === [] ? 'none' : min($activityIds) . '-' . max($activityIds) . ' (' . count($activityIds) . ')') . "\n";
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
