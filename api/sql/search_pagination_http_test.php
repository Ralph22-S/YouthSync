<?php
declare(strict_types=1);

/**
 * Search / filter / pagination HTTP test for every list endpoint.
 *
 * Run from api/sql with Apache + MySQL up:
 *   php search_pagination_http_test.php
 * Optional: set YOUTHSYNC_API_BASE to override http://localhost/YouthSync/api.
 *
 * Contract under test (api/src/Http/Pagination.php):
 * - Every list returns items + page, perPage, total, pages, totalPages.
 * - total is counted after filters/search and before LIMIT; totalPages =
 *   max(1, ceil(total / perPage)) (total = 0 gives 1); a page past the end is
 *   an empty page with the requested page number, never an error.
 * - page < 1 / non-numeric becomes 1; perPage < 1 / non-numeric becomes the
 *   endpoint default; perPage above the max is capped (SK 100, admin 200).
 * - Search is literal (% and _ are not wildcards).
 * - Organization always comes from the session, never from the client.
 *
 * Expected values come from independent SQL, so the script also runs on a
 * database with different demo data. It creates its own records through the
 * API, all tagged SrchPg<stamp>: 7 youth, 5 draft programs/events and 3 draft
 * assistance programs in the signed-in SK organization. Each is tracked by its
 * exact id and deleted at the end. No application is submitted, nothing is
 * published, and nothing here sends SMS or email. Activity-log rows written by
 * the create/delete calls are kept, as with the other suites.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

$base = rtrim((string) (getenv('YOUTHSYNC_API_BASE') ?: 'http://localhost/YouthSync/api'), '/');
$pdo = youthsync_pdo();

const SP_PASSWORD = 'YouthSync1!';
const SP_YOUTH_EMAIL = 'youth-auth-test@example.test';
const SP_YOUTH_PASSWORD = 'YouthAuthTest1!';

$leftover = (int) $pdo->query("SELECT
    (SELECT COUNT(*) FROM youth WHERE last_name LIKE 'SrchPg%')
  + (SELECT COUNT(*) FROM programs WHERE name LIKE 'SrchPg%')
  + (SELECT COUNT(*) FROM assistance_programs WHERE name LIKE 'SrchPg%')")->fetchColumn();
if ($leftover > 0) {
    fwrite(STDERR, "STOP: pre-existing SrchPg test records found. No cleanup was performed.\n");
    exit(2);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
$cookies = [];
foreach (['anon', 'sk1', 'sk2', 'admin', 'youth'] as $who) {
    $cookies[$who] = $tmp . 'ys_srchpg_' . $who . '.txt';
    @unlink($cookies[$who]);
}

/** @param list<string> $extraHeaders */
function sp_req(string $method, string $url, ?string $json, string $cookie, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    $headers = array_merge(['Accept: application/json'], $extraHeaders);
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
        return ['status' => 0, 'body' => $err, 'data' => []];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($raw, $headerSize);
    curl_close($ch);
    $decoded = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'data' => is_array($decoded) ? $decoded : []];
}

function sp_data(array $r): array
{
    $d = $r['data']['data'] ?? [];
    return is_array($d) ? $d : [];
}

/** @return list<int> */
function sp_ids(array $data): array
{
    return array_map(static fn ($i) => (int) ($i['id'] ?? 0), $data['items'] ?? []);
}

/** Keys that must never appear in a list response. */
function sp_secret_keys(mixed $value, string $path = ''): array
{
    $found = [];
    if (!is_array($value)) {
        return $found;
    }
    foreach ($value as $key => $child) {
        // mustChangePassword is a public boolean flag, not a credential.
        if (is_string($key) && $key !== 'mustChangePassword'
            && preg_match('/password|passwd|api_?key|secret|session|remember|hash/i', $key)) {
            $found[] = $path . $key;
        }
        $found = array_merge($found, sp_secret_keys($child, $path . $key . '.'));
    }
    return $found;
}

/** Same literal LIKE pattern the API builds (ESCAPE '!'). */
function sp_like(string $term): string
{
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
}

$failed = 0;
$passed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed, &$passed): void {
    echo ($ok ? 'PASS' : 'FAIL') . " {$name}" . ($detail !== '' && !$ok ? ' :: ' . substr($detail, 0, 600) : '') . "\n";
    $ok ? $passed++ : $failed++;
};
$same = static function (string $name, mixed $actual, mixed $expected) use ($check): void {
    $check($name, $actual === $expected, 'actual=' . json_encode($actual) . ' expected=' . json_encode($expected));
};
$one = static function (string $sql, array $params = []) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};
$col = static function (string $sql, array $params = []) use ($pdo): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};
$get = static function (string $who, string $path, array $headers = []) use ($base, &$cookies): array {
    return sp_req('GET', $base . $path, null, $cookies[$who], $headers);
};
/** Contract shape + arithmetic for one list response. */
$meta = static function (string $name, array $r, int $expectTotal, int $expectPage, int $expectPerPage) use ($check): array {
    $d = sp_data($r);
    $total = (int) ($d['total'] ?? -1);
    $perPage = (int) ($d['perPage'] ?? 0);
    $pages = max(1, (int) ceil($expectTotal / max(1, $expectPerPage)));
    $rows = count($d['items'] ?? []);
    $expectRows = max(0, min($expectPerPage, $expectTotal - ($expectPage - 1) * $expectPerPage));
    $ok = $r['status'] === 200
        && is_array($d['items'] ?? null)
        && array_key_exists('page', $d) && array_key_exists('perPage', $d) && array_key_exists('total', $d)
        && array_key_exists('pages', $d) && array_key_exists('totalPages', $d)
        && (int) $d['page'] === $expectPage && $perPage === $expectPerPage && $total === $expectTotal
        && (int) $d['totalPages'] === $pages && (int) $d['pages'] === $pages
        && $rows === $expectRows && $rows <= $perPage;
    $check($name, $ok, json_encode([
        'status' => $r['status'], 'page' => $d['page'] ?? null, 'perPage' => $d['perPage'] ?? null, 'total' => $d['total'] ?? null,
        'pages' => $d['pages'] ?? null, 'totalPages' => $d['totalPages'] ?? null, 'rows' => $rows,
        'expect' => [$expectTotal, $expectPage, $expectPerPage, $pages, $expectRows],
    ]) . ' ' . substr($r['body'], 0, 200));
    return $d;
};

// ---- sign in -----------------------------------------------------------------
$login = static function (string $who, string $email, string $password) use ($base, &$cookies): int {
    $r = sp_req('POST', "$base/auth/login", json_encode(['email' => $email, 'password' => $password]), $cookies[$who]);
    return $r['status'];
};
$check('login_sk1', $login('sk1', 'sk1@demo.test', SP_PASSWORD) === 200);
$check('login_sk2', $login('sk2', 'sk2@demo.test', SP_PASSWORD) === 200);
$check('login_admin', $login('admin', 'admin@demo.test', SP_PASSWORD) === 200);
$youthLogin = $login('youth', SP_YOUTH_EMAIL, SP_YOUTH_PASSWORD);
$check('login_youth', $youthLogin === 200, (string) $youthLogin);

$sk1User = $one("SELECT id FROM users WHERE email = 'sk1@demo.test'");
$org1 = $one('SELECT organization_id FROM organization_users WHERE user_id = :u ORDER BY id LIMIT 1', ['u' => $sk1User]);
$org2 = $one("SELECT ou.organization_id FROM organization_users ou JOIN users u ON u.id = ou.user_id WHERE u.email = 'sk2@demo.test' ORDER BY ou.id LIMIT 1");
$check('orgs_resolved', $org1 > 0 && $org2 > 0 && $org1 !== $org2, json_encode([$org1, $org2]));

$smsBefore = $one('SELECT COUNT(*) FROM sms_deliveries');
$emailBefore = $one('SELECT COUNT(*) FROM email_deliveries');
$notifBefore = $one('SELECT COUNT(*) FROM notifications');
$appsBefore = $one('SELECT COUNT(*) FROM applications');

// ---- auth --------------------------------------------------------------------
$skLists = ['/sk/youth', '/sk/programs', '/sk/events', '/sk/assistance', '/sk/applications', '/sk/notifications',
    '/sk/users', '/sk/activity', '/sk/deliveries/sms', '/sk/deliveries/email'];
$adminLists = ['/admin/organizations', '/admin/users', '/admin/activity', '/admin/payment-transactions'];
$anon = [];
foreach (array_merge($skLists, $adminLists) as $path) {
    $anon[$path] = $get('anon', $path . '?page=1&perPage=5')['status'];
}
$check('auth_anon_401_every_list', array_unique(array_values($anon)) === [401], json_encode($anon));
$youthCodes = [];
foreach (array_merge($skLists, $adminLists) as $path) {
    $youthCodes[$path] = $get('youth', $path . '?q=a')['status'];
}
$check('auth_youth_403_every_sk_and_admin_list', array_unique(array_values($youthCodes)) === [403], json_encode($youthCodes));
$skOnAdmin = [];
foreach ($adminLists as $path) {
    $skOnAdmin[$path] = $get('sk1', $path . '?perPage=5')['status'];
}
$check('auth_sk_403_on_admin_lists', array_unique(array_values($skOnAdmin)) === [403], json_encode($skOnAdmin));
$adminOnSk = [];
foreach ($skLists as $path) {
    $adminOnSk[$path] = $get('admin', $path . '?perPage=5')['status'];
}
$check('auth_admin_403_on_sk_lists', array_unique(array_values($adminOnSk)) === [403], json_encode($adminOnSk));
$skOk = [];
foreach ($skLists as $path) {
    $d = sp_data($r = $get('sk1', $path . '?perPage=5'));
    $skOk[$path] = $r['status'] === 200 && isset($d['totalPages'], $d['pages']) && $d['totalPages'] === $d['pages'];
}
$check('auth_sk_200_every_sk_list_with_totalPages', !in_array(false, $skOk, true), json_encode($skOk));
$adminOk = [];
foreach ($adminLists as $path) {
    $d = sp_data($r = $get('admin', $path . '?perPage=5'));
    $adminOk[$path] = $r['status'] === 200 && isset($d['totalPages'], $d['pages']) && $d['totalPages'] === $d['pages'];
}
$check('auth_admin_200_every_admin_list_with_totalPages', !in_array(false, $adminOk, true), json_encode($adminOk));

// ---- temp records --------------------------------------------------------------
$stamp = date('His') . random_int(100, 999);
$tag = 'SrchPg' . $stamp;
$created = ['youth' => [], 'programs' => [], 'assistance' => [], 'users' => []];

$reportBefore = sp_data($get('sk1', '/sk/reports/youth'));

$youthSpec = [
    ['Alpha', 'Student', 'SrchPgSchool' . $stamp],
    ['Bravo', 'Unemployed', ''],
    ['Charlie', 'Student', 'SrchPgSchool' . $stamp],
    ['Delta', 'Unemployed', ''],
    ['Echo', 'Student', ''],
    ['Foxtrot', 'Unemployed', ''],
    ['Golf', 'Unemployed', ''],
];
foreach ($youthSpec as $i => [$first, $employment, $school]) {
    $r = sp_req('POST', "$base/sk/youth?organization_id={$org2}", json_encode([
        'firstName' => $first . $stamp,
        'lastName' => $tag,
        'birthDate' => date('Y-m-d', strtotime('-' . (18 + $i) . ' years -10 days')),
        'gender' => 'Female',
        'address' => 'SrchPg test address',
        'contact' => '0917' . random_int(1000000, 9999999),
        'interests' => ['Sports'],
        'employment' => $employment,
        'educationStatus' => $employment === 'Student' ? 'Currently Studying' : 'Not Currently Studying',
        'education' => 'College',
        'school' => $school,
        'organization_id' => $org2,
    ]), $cookies['sk1']);
    $id = (int) (sp_data($r)['youth']['id'] ?? sp_data($r)['id'] ?? 0);
    if ($id > 0) {
        $created['youth'][] = $id;
    }
}
$check('temp_youth_created_7', count($created['youth']) === 7, json_encode($created['youth']));
$inOrg1 = $one('SELECT COUNT(*) FROM youth WHERE organization_id = :org AND id IN (' . implode(',', array_map('intval', $created['youth'] ?: [0])) . ')', ['org' => $org1]);
$same('temp_youth_in_session_org_despite_spoof', $inOrg1, 7);

foreach ([['Event One', 'event', 'Hall ' . $stamp], ['Event Two', 'event', 'Court'], ['Event Three', 'event', 'Court'],
    ['Program Alpha', 'program', 'Court'], ['Program Beta', 'program', 'Court']] as [$name, $kind, $location]) {
    $r = sp_req('POST', "$base/sk/programs", json_encode([
        'name' => "{$tag} {$name}",
        'kind' => $kind,
        'category' => 'Sports',
        'status' => 'draft',
        'scheduledOn' => date('Y-m-d', strtotime('+9 days')),
        'location' => $location,
        'organization_id' => $org2,
    ]), $cookies['sk1']);
    $id = (int) (sp_data($r)['id'] ?? 0);
    if ($id > 0) {
        $created['programs'][] = $id;
    }
}
$check('temp_programs_created_5', count($created['programs']) === 5, json_encode($created['programs']));

foreach ([['Scholarship A', 'scholarship', 'SrchPgDesc' . $stamp], ['Aid B', 'financial', 'Plain'], ['Aid C', 'financial', 'Plain']] as [$name, $category, $desc]) {
    $r = sp_req('POST', "$base/sk/assistance", json_encode([
        'name' => "{$tag} {$name}",
        'category' => $category,
        'status' => 'draft',
        'description' => $desc,
        'slots' => 5,
        'amount' => 1000,
        'organization_id' => $org2,
    ]), $cookies['sk1']);
    $id = (int) (sp_data($r)['id'] ?? 0);
    if ($id > 0) {
        $created['assistance'][] = $id;
    }
}
$check('temp_assistance_created_3', count($created['assistance']) === 3, json_encode($created['assistance']));

$youthIds = $created['youth'];
$youthDesc = $youthIds;
rsort($youthDesc);
$youthAsc = $youthIds;
sort($youthAsc);

// ---- youth: pagination ---------------------------------------------------------
$q = rawurlencode($stamp);
$p1 = $meta('youth_page1_meta', $get('sk1', "/sk/youth?q={$q}&perPage=3&page=1"), 7, 1, 3);
$p2 = $meta('youth_page2_meta', $get('sk1', "/sk/youth?q={$q}&perPage=3&page=2"), 7, 2, 3);
$p3 = $meta('youth_page3_meta', $get('sk1', "/sk/youth?q={$q}&perPage=3&page=3"), 7, 3, 3);
$same('youth_pages_newest_first_exact_ids', array_merge(sp_ids($p1), sp_ids($p2), sp_ids($p3)), $youthDesc);
$check('youth_page1_page2_different_rows', array_intersect(sp_ids($p1), sp_ids($p2)) === [] && sp_ids($p1) !== []);
$p4 = $meta('youth_beyond_last_page_empty', $get('sk1', "/sk/youth?q={$q}&perPage=3&page=4"), 7, 4, 3);
$same('youth_beyond_last_page_items_empty', $p4['items'] ?? null, []);
$huge = sp_data($get('sk1', "/sk/youth?q={$q}&perPage=3&page=99999999999999999999"));
$check('youth_huge_page_integer_safe_empty', ($huge['page'] ?? null) === 1000000 && ($huge['items'] ?? null) === [] && ($huge['total'] ?? null) === 7, json_encode([$huge['page'] ?? null, $huge['total'] ?? null]));
foreach (['0' => 'zero', '-3' => 'negative', 'abc' => 'text', '1.5' => 'decimal'] as $bad => $label) {
    $d = sp_data($r = $get('sk1', "/sk/youth?q={$q}&perPage=3&page=" . rawurlencode((string) $bad)));
    $check("youth_invalid_page_{$label}_is_page_1", $r['status'] === 200 && ($d['page'] ?? null) === 1 && sp_ids($d) === sp_ids($p1), json_encode([$r['status'], $d['page'] ?? null]));
}
$meta('youth_perPage_below_default_2', $get('sk1', "/sk/youth?q={$q}&perPage=2"), 7, 1, 2);
$meta('youth_perPage_zero_uses_default_15', $get('sk1', "/sk/youth?q={$q}&perPage=0"), 7, 1, 15);
$meta('youth_perPage_text_uses_default_15', $get('sk1', "/sk/youth?q={$q}&perPage=lots"), 7, 1, 15);
$meta('youth_perPage_negative_uses_default_15', $get('sk1', "/sk/youth?q={$q}&perPage=-50"), 7, 1, 15);
$meta('youth_perPage_at_max_100', $get('sk1', "/sk/youth?q={$q}&perPage=100"), 7, 1, 100);
$meta('youth_perPage_above_max_capped_100', $get('sk1', "/sk/youth?q={$q}&perPage=100000"), 7, 1, 100);
$meta('youth_per_page_alias_capped', $get('sk1', "/sk/youth?q={$q}&per_page=5000"), 7, 1, 100);
$orgActive = $one('SELECT COUNT(*) FROM youth WHERE organization_id = :org AND archived = 0', ['org' => $org1]);
$full = $meta('youth_unfiltered_total_matches_sql', $get('sk1', '/sk/youth?perPage=100'), $orgActive, 1, 100);
$sqlFirst = $col('SELECT id FROM youth WHERE organization_id = :org AND archived = 0 ORDER BY created_at DESC, id DESC LIMIT 5 OFFSET 5', ['org' => $org1]);
$same('youth_unfiltered_page2_rows_match_sql_order', sp_ids(sp_data($get('sk1', '/sk/youth?perPage=5&page=2'))), $sqlFirst);

// ---- youth: sort ------------------------------------------------------------------
$byName = sp_data($get('sk1', "/sk/youth?q={$q}&sort=name&perPage=10"));
$same('youth_sort_name_order', array_map(static fn ($i) => (string) $i['firstName'], $byName['items'] ?? []),
    array_map(static fn ($s) => $s[0] . $stamp, $youthSpec));
$same('youth_sort_oldest_order', sp_ids(sp_data($get('sk1', "/sk/youth?q={$q}&sort=oldest&perPage=10"))), $youthAsc);

// ---- youth: search ----------------------------------------------------------------
$single = $meta('youth_search_single_match', $get('sk1', '/sk/youth?q=' . rawurlencode('Charlie' . $stamp)), 1, 1, 15);
$same('youth_search_single_match_id', sp_ids($single), [$created['youth'][2]]);
$meta('youth_search_full_name', $get('sk1', '/sk/youth?q=' . rawurlencode("Delta{$stamp} {$tag}")), 1, 1, 15);
$school = $meta('youth_search_school_two_matches', $get('sk1', '/sk/youth?q=' . rawurlencode('SrchPgSchool' . $stamp)), 2, 1, 15);
$same('youth_search_school_exact_ids', sp_ids($school), [$created['youth'][2], $created['youth'][0]]);
$meta('youth_search_no_results', $get('sk1', '/sk/youth?q=' . rawurlencode($tag . 'zzz')), 0, 1, 15);
$meta('youth_search_no_results_page2_still_ok', $get('sk1', '/sk/youth?q=' . rawurlencode($tag . 'zzz') . '&page=2'), 0, 2, 15);
$pctExpected = $one("SELECT COUNT(*) FROM youth WHERE organization_id = :org AND archived = 0 AND (first_name LIKE :a ESCAPE '!' OR last_name LIKE :b ESCAPE '!' OR CONCAT(first_name, ' ', last_name) LIKE :c ESCAPE '!' OR school LIKE :d ESCAPE '!' OR address LIKE :e ESCAPE '!' OR contact LIKE :f ESCAPE '!' OR code LIKE :g ESCAPE '!')",
    ['org' => $org1, 'a' => sp_like('%'), 'b' => sp_like('%'), 'c' => sp_like('%'), 'd' => sp_like('%'), 'e' => sp_like('%'), 'f' => sp_like('%'), 'g' => sp_like('%')]);
$pct = sp_data($get('sk1', '/sk/youth?q=%25&perPage=100'));
$check('youth_search_percent_is_literal', ($pct['total'] ?? -1) === $pctExpected && $pctExpected < $orgActive, json_encode([$pct['total'] ?? null, $pctExpected, $orgActive]));
// "_" in place of one stamp digit would match every temp youth if it were a wildcard.
$und = sp_data($get('sk1', '/sk/youth?q=' . rawurlencode($tag === '' ? '_' : substr($tag, 0, 8) . '_' . substr($tag, 9))));
$same('youth_search_underscore_is_literal', $und['total'] ?? null, 0);
$sp = $meta('youth_search_plus_pagination_page3', $get('sk1', "/sk/youth?q={$q}&perPage=2&page=4"), 7, 4, 2);
$same('youth_search_plus_pagination_last_row', sp_ids($sp), [min($youthIds)]);

// ---- youth: filters -----------------------------------------------------------------
$students = array_values(array_filter($youthDesc, static fn ($id) => in_array(array_search($id, $created['youth'], true), [0, 2, 4], true)));
$st = $meta('youth_filter_employment_plus_search', $get('sk1', "/sk/youth?q={$q}&employment=Student"), 3, 1, 15);
$same('youth_filter_employment_exact_ids', sp_ids($st), $students);
$stp = $meta('youth_filter_plus_pagination_page2', $get('sk1', "/sk/youth?q={$q}&employment=Student&perPage=2&page=2"), 3, 2, 2);
$same('youth_filter_plus_pagination_row', sp_ids($stp), [end($students)]);
$empSql = $one("SELECT COUNT(*) FROM youth WHERE organization_id = :org AND archived = 0 AND employment = 'Unemployed'", ['org' => $org1]);
$meta('youth_filter_employment_total_vs_sql', $get('sk1', '/sk/youth?employment=Unemployed&perPage=5'), $empSql, 1, 5);
$prio = (string) ($pdo->query("SELECT priority_level FROM youth WHERE id = " . (int) $created['youth'][0])->fetchColumn() ?: 'Medium');
$prioSql = $one('SELECT COUNT(*) FROM youth WHERE organization_id = :org AND archived = 0 AND priority_level = :p', ['org' => $org1, 'p' => $prio]);
$meta('youth_filter_priority_total_vs_sql', $get('sk1', '/sk/youth?priority=' . rawurlencode($prio) . '&perPage=5'), $prioSql, 1, 5);
$prioTag = $one('SELECT COUNT(*) FROM youth WHERE organization_id = :org AND archived = 0 AND priority_level = :p AND last_name = :t', ['org' => $org1, 'p' => $prio, 't' => $tag]);
$meta('youth_filter_priority_plus_search', $get('sk1', '/sk/youth?priority=' . rawurlencode($prio) . "&q={$q}"), $prioTag, 1, 15);

// Reports compatibility: the export is not a page. Measured before archiving.
$reportAfter = sp_data($get('sk1', '/sk/reports/youth'));
$beforeRows = (int) ($reportBefore['export']['rowCount'] ?? -1);
$afterRows = (int) ($reportAfter['export']['rowCount'] ?? -1);
$same('reports_youth_rowCount_plus_7', $afterRows - $beforeRows, 7);
$csv = $get('sk1', '/sk/reports/youth/csv');
$csvLines = array_values(array_filter(preg_split("/\r\n/", (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv['body'])), static fn ($l) => $l !== ''));
$csvRows = max(0, count($csvLines) - 1);
$check('reports_csv_rows_equal_full_rowCount_not_a_page', $csv['status'] === 200 && $csvRows === $afterRows && $csvRows > 15,
    json_encode(['csvRows' => $csvRows, 'rowCount' => $afterRows]));
$check('reports_csv_contains_every_temp_youth', count(array_filter($csvLines, static fn ($l) => str_contains($l, $tag))) === 7);

// Archive one: archived filter.
$r = sp_req('POST', "$base/sk/youth/{$created['youth'][6]}/archive", '{}', $cookies['sk1']);
$check('archive_temp_youth', $r['status'] === 200, substr($r['body'], 0, 200));
$arch = $meta('youth_filter_archived_plus_search', $get('sk1', "/sk/youth?q={$q}&archived=1"), 1, 1, 15);
$same('youth_filter_archived_exact_id', sp_ids($arch), [$created['youth'][6]]);
$meta('youth_filter_active_after_archive', $get('sk1', "/sk/youth?q={$q}&archived=0"), 6, 1, 15);

// ---- programs / events ------------------------------------------------------------
$progDesc = $created['programs'];
rsort($progDesc);
$pp1 = $meta('programs_search_page1', $get('sk1', "/sk/programs?q={$q}&perPage=2&page=1&sort=newest"), 5, 1, 2);
$pp2 = $meta('programs_search_page2', $get('sk1', "/sk/programs?q={$q}&perPage=2&page=2&sort=newest"), 5, 2, 2);
$pp3 = $meta('programs_search_page3', $get('sk1', "/sk/programs?q={$q}&perPage=2&page=3&sort=newest"), 5, 3, 2);
$same('programs_pages_exact_ids', array_merge(sp_ids($pp1), sp_ids($pp2), sp_ids($pp3)), $progDesc);
$meta('programs_beyond_last_page_empty', $get('sk1', "/sk/programs?q={$q}&perPage=2&page=9"), 5, 9, 2);
$ev = $meta('programs_filter_kind_event_plus_search', $get('sk1', "/sk/programs?q={$q}&kind=event"), 3, 1, 50);
$same('programs_filter_kind_event_ids', array_values(array_intersect($progDesc, sp_ids($ev))), array_values(array_intersect($progDesc, array_slice($created['programs'], 0, 3))));
$meta('events_route_forces_kind_event', $get('sk1', "/sk/events?q={$q}&kind=program"), 3, 1, 50);
$meta('programs_filter_status_draft_plus_search', $get('sk1', "/sk/programs?q={$q}&status=draft"), 5, 1, 50);
$meta('programs_filter_status_published_plus_search', $get('sk1', "/sk/programs?q={$q}&status=published"), 0, 1, 50);
$meta('programs_filter_kind_plus_pagination', $get('sk1', "/sk/programs?q={$q}&kind=program&perPage=1&page=2"), 2, 2, 1);
$loc = $meta('programs_search_location_single', $get('sk1', '/sk/programs?q=' . rawurlencode('Hall ' . $stamp)), 1, 1, 50);
$same('programs_search_location_id', sp_ids($loc), [$created['programs'][0]]);
$names = array_map(static fn ($i) => (string) $i['name'], sp_data($get('sk1', "/sk/programs?q={$q}&sort=name"))['items'] ?? []);
$sortedNames = $names;
sort($sortedNames, SORT_STRING);
$check('programs_sort_name', count($names) === 5 && $names === $sortedNames, json_encode($names));
$progSql = $one('SELECT COUNT(*) FROM programs WHERE organization_id = :org', ['org' => $org1]);
$meta('programs_unfiltered_total_vs_sql', $get('sk1', '/sk/programs?perPage=3'), $progSql, 1, 3);
$defaultOrder = $col('SELECT id FROM programs WHERE organization_id = :org ORDER BY scheduled_on DESC, id DESC LIMIT 3', ['org' => $org1]);
$same('programs_default_order_unchanged', sp_ids(sp_data($get('sk1', '/sk/programs?perPage=3'))), $defaultOrder);
$progCat = $one("SELECT COUNT(*) FROM programs WHERE organization_id = :org AND (name LIKE :a ESCAPE '!' OR location LIKE :b ESCAPE '!' OR category LIKE :c ESCAPE '!')",
    ['org' => $org1, 'a' => sp_like('Sports'), 'b' => sp_like('Sports'), 'c' => sp_like('Sports')]);
$meta('programs_search_category_vs_sql_was_500_before', $get('sk1', '/sk/programs?q=Sports&perPage=2'), $progCat, 1, 2);
// registeredCount (the list screen's "Registered" column) replaces one attendance request per activity.
$regBad = [];
$regRows = sp_data($get('sk1', '/sk/programs?perPage=100'))['items'] ?? [];
foreach ($regRows as $item) {
    $sql = $one('SELECT COUNT(*) FROM attendance WHERE organization_id = :org AND program_id = :p', ['org' => $org1, 'p' => (int) $item['id']]);
    if (($item['registeredCount'] ?? null) !== $sql) {
        $regBad[] = [$item['id'], $item['registeredCount'] ?? null, $sql];
    }
}
$check('programs_registeredCount_vs_sql_every_row', $regRows !== [] && $regBad === [], json_encode($regBad));
$tempReg = array_column(sp_data($get('sk1', "/sk/programs?q={$q}"))['items'] ?? [], 'registeredCount');
$same('programs_registeredCount_zero_for_new_activities', $tempReg, [0, 0, 0, 0, 0]);

// ---- assistance -----------------------------------------------------------------
$as = $meta('assistance_search_all_three', $get('sk1', "/sk/assistance?q={$q}"), 3, 1, 50);
$asDesc = $created['assistance'];
rsort($asDesc);
$same('assistance_search_newest_first_ids', sp_ids($as), $asDesc);
$fin = $meta('assistance_filter_category_plus_search', $get('sk1', "/sk/assistance?q={$q}&category=financial"), 2, 1, 50);
$same('assistance_filter_category_ids', sp_ids($fin), [$asDesc[0], $asDesc[1]]);
$meta('assistance_filter_category_plus_pagination', $get('sk1', "/sk/assistance?q={$q}&category=financial&perPage=1&page=2"), 2, 2, 1);
$desc = $meta('assistance_search_description', $get('sk1', '/sk/assistance?q=' . rawurlencode('SrchPgDesc' . $stamp)), 1, 1, 50);
$same('assistance_search_description_id', sp_ids($desc), [$created['assistance'][0]]);
$counts = sp_data($get('sk1', '/sk/assistance?category=financial&q=nothing-matches-' . $stamp))['counts'] ?? [];
$sqlCounts = ['all' => $one('SELECT COUNT(*) FROM assistance_programs WHERE organization_id = :org', ['org' => $org1])];
foreach ($pdo->query('SELECT category, COUNT(*) FROM assistance_programs WHERE organization_id = ' . (int) $org1 . ' GROUP BY category')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
    $sqlCounts[(string) $k] = (int) $v;
}
ksort($counts);
ksort($sqlCounts);
$same('assistance_tab_counts_vs_sql_ignore_tab_and_search', $counts, $sqlCounts);
$meta('assistance_unfiltered_total_vs_sql', $get('sk1', '/sk/assistance?perPage=2'), $sqlCounts['all'], 1, 2);

// ---- applications (read-only: nothing is submitted) -----------------------------
$appFrom = 'applications a INNER JOIN youth y ON y.id = a.youth_id LEFT JOIN assistance_programs ap ON ap.id = a.assistance_id LEFT JOIN programs pr ON pr.id = a.program_id';
$appTotal = $one("SELECT COUNT(*) FROM {$appFrom} WHERE a.organization_id = :org", ['org' => $org1]);
$meta('applications_total_vs_sql', $get('sk1', '/sk/applications?perPage=2'), $appTotal, 1, 2);
$appOrder = $col("SELECT a.id FROM {$appFrom} WHERE a.organization_id = :org ORDER BY a.submitted_at DESC, a.id DESC", ['org' => $org1]);
$all = [];
for ($page = 1; $page <= max(1, (int) ceil($appTotal / 2)); $page++) {
    $all = array_merge($all, sp_ids(sp_data($get('sk1', "/sk/applications?perPage=2&page={$page}"))));
}
$same('applications_walk_every_page_matches_sql_order', $all, $appOrder);
$appCounts = sp_data($get('sk1', '/sk/applications?status=approved&q=nothing-' . $stamp))['counts'] ?? [];
$sqlAppCounts = ['all' => $appTotal];
$stmt = $pdo->prepare("SELECT a.status, COUNT(*) FROM {$appFrom} WHERE a.organization_id = :org GROUP BY a.status");
$stmt->execute(['org' => $org1]);
foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
    $sqlAppCounts[(string) $k] = (int) $v;
}
ksort($appCounts);
ksort($sqlAppCounts);
$same('applications_status_counts_vs_sql', $appCounts, $sqlAppCounts);
foreach (['pending', 'approved'] as $status) {
    $meta("applications_filter_status_{$status}_vs_sql", $get('sk1', "/sk/applications?status={$status}&perPage=1"), $sqlAppCounts[$status] ?? 0, 1, 1);
}
$schol = $one("SELECT COUNT(*) FROM {$appFrom} WHERE a.organization_id = :org AND ap.category = 'scholarship'", ['org' => $org1]);
$assist = $one("SELECT COUNT(*) FROM {$appFrom} WHERE a.organization_id = :org AND a.assistance_id IS NOT NULL AND (ap.category IS NULL OR ap.category <> 'scholarship')", ['org' => $org1]);
$meta('applications_filter_kind_scholarship_vs_sql', $get('sk1', '/sk/applications?kind=scholarship'), $schol, 1, 50);
$meta('applications_filter_kind_assistance_vs_sql', $get('sk1', '/sk/applications?kind=assistance'), $assist, 1, 50);
$check('applications_invalid_kind_422', $get('sk1', '/sk/applications?kind=bogus')['status'] === 422);
$appName = (string) ($pdo->query("SELECT y.last_name FROM {$appFrom} WHERE a.organization_id = " . (int) $org1 . ' ORDER BY a.id LIMIT 1')->fetchColumn() ?: 'nobody-' . $stamp);
$appQ = $one("SELECT COUNT(*) FROM {$appFrom} WHERE a.organization_id = :org AND (y.first_name LIKE :a ESCAPE '!' OR y.last_name LIKE :b ESCAPE '!' OR CONCAT(y.first_name, ' ', y.last_name) LIKE :c ESCAPE '!' OR ap.name LIKE :d ESCAPE '!' OR pr.name LIKE :e ESCAPE '!')",
    ['org' => $org1, 'a' => sp_like($appName), 'b' => sp_like($appName), 'c' => sp_like($appName), 'd' => sp_like($appName), 'e' => sp_like($appName)]);
$check('applications_search_known_name_has_results', $appQ >= ($appTotal > 0 ? 1 : 0));
$meta('applications_search_vs_sql_was_500_before', $get('sk1', '/sk/applications?q=' . rawurlencode($appName)), $appQ, 1, 50);
$meta('applications_search_plus_status', $get('sk1', '/sk/applications?status=bogus-status&q=' . rawurlencode($appName)), 0, 1, 50);
// requirementCount and youth.birthDate (the queue's "Requirements" and age) come with each row.
$reqBad = [];
foreach (sp_data($get('sk1', '/sk/applications?perPage=100'))['items'] ?? [] as $item) {
    $row = $pdo->query('SELECT a.program_id, a.assistance_id, y.birth_date FROM applications a JOIN youth y ON y.id = a.youth_id WHERE a.id = ' . (int) $item['id'])->fetch(PDO::FETCH_ASSOC);
    $sql = (int) ($row['program_id'] ?? 0) > 0
        ? $one('SELECT COUNT(*) FROM assistance_requirements WHERE organization_id = :org AND program_id = :id', ['org' => $org1, 'id' => (int) $row['program_id']])
        : $one('SELECT COUNT(*) FROM assistance_requirements WHERE organization_id = :org AND assistance_id = :id', ['org' => $org1, 'id' => (int) $row['assistance_id']]);
    if (($item['requirementCount'] ?? null) !== $sql || ($item['youth']['birthDate'] ?? null) !== $row['birth_date']) {
        $reqBad[] = [$item['id'], $item['requirementCount'] ?? null, $sql, $item['youth']['birthDate'] ?? null, $row['birth_date']];
    }
}
$check('applications_requirementCount_and_birthDate_vs_sql', $reqBad === [], json_encode($reqBad));

// ---- notifications (read-only) ------------------------------------------------------
$inbox = 'organization_id = :org AND youth_id IS NULL AND (user_id IS NULL OR user_id = :u)';
$nTotal = $one("SELECT COUNT(*) FROM notifications WHERE {$inbox}", ['org' => $org1, 'u' => $sk1User]);
$n1 = $meta('notifications_total_vs_sql', $get('sk1', '/sk/notifications?perPage=4&page=1'), $nTotal, 1, 4);
$nOrder = $col("SELECT id FROM notifications WHERE {$inbox} ORDER BY created_at DESC, id DESC LIMIT 4 OFFSET 4", ['org' => $org1, 'u' => $sk1User]);
$same('notifications_page2_rows_match_sql', sp_ids(sp_data($get('sk1', '/sk/notifications?perPage=4&page=2'))), $nOrder);
$nUnread = $one("SELECT COUNT(*) FROM notifications WHERE {$inbox} AND is_read = 0", ['org' => $org1, 'u' => $sk1User]);
$meta('notifications_filter_unread_vs_sql', $get('sk1', '/sk/notifications?unreadOnly=1&perPage=3'), $nUnread, 1, 3);
$nTitle = (string) ($pdo->query('SELECT title FROM notifications WHERE organization_id = ' . (int) $org1 . ' AND youth_id IS NULL ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 'none-' . $stamp);
$nq = $one("SELECT COUNT(*) FROM notifications WHERE {$inbox} AND (title LIKE :a ESCAPE '!' OR message LIKE :b ESCAPE '!')", ['org' => $org1, 'u' => $sk1User, 'a' => sp_like($nTitle), 'b' => sp_like($nTitle)]);
$meta('notifications_search_vs_sql', $get('sk1', '/sk/notifications?perPage=3&q=' . rawurlencode($nTitle)), $nq, 1, 3);
$meta('notifications_beyond_last_page_empty', $get('sk1', '/sk/notifications?perPage=4&page=' . (max(1, (int) ceil($nTotal / 4)) + 1)), $nTotal, max(1, (int) ceil($nTotal / 4)) + 1, 4);

// ---- SK users / deliveries / activity -------------------------------------------
$skUserFrom = "users u INNER JOIN roles r ON r.id = u.role_id INNER JOIN organization_users ou ON ou.user_id = u.id";
$skUserWhere = "ou.organization_id = :org AND r.code = 'SK_OFFICIAL'";
$skUsers = $one("SELECT COUNT(*) FROM {$skUserFrom} WHERE {$skUserWhere}", ['org' => $org1]);
$meta('sk_users_total_vs_sql', $get('sk1', '/sk/users?perPage=2'), $skUsers, 1, 2);
$me = $meta('sk_users_search_email', $get('sk1', '/sk/users?q=' . rawurlencode('sk1@demo.test')), 1, 1, 50);
$same('sk_users_search_email_id', sp_ids($me), [$sk1User]);

// Two temp accounts so paging, search, status and tenant isolation are all measurable.
foreach (['Alpha', 'Beta'] as $first) {
    $r = sp_req('POST', "$base/sk/users?organization_id={$org2}", json_encode([
        'name' => "SrchPgUser {$first}{$stamp}",
        'email' => "srchpg.user.{$first}.{$stamp}@example.test",
        'password' => 'TempPass123!',
        'role' => 'SK_OFFICIAL',
        'organization_id' => $org2,
    ]), $cookies['sk1']);
    $id = (int) (sp_data($r)['id'] ?? 0);
    if ($id > 0) {
        $created['users'][] = $id;
    }
}
$check('temp_users_created_2', count($created['users']) === 2, json_encode($created['users']));
$tempUsersInOrg1 = $one('SELECT COUNT(*) FROM organization_users WHERE organization_id = :org AND user_id IN (' . implode(',', array_map('intval', $created['users'] ?: [0])) . ')', ['org' => $org1]);
$same('temp_users_in_session_org_despite_spoof', $tempUsersInOrg1, 2);
$deactivated = sp_req('POST', "$base/sk/users/{$created['users'][1]}/toggle-active", '{}', $cookies['sk1']);
$check('temp_user_deactivated', $deactivated['status'] === 200, (string) $deactivated['status']);

$skUsersNow = $skUsers + 2;
$meta('sk_users_after_create_vs_sql', $get('sk1', '/sk/users?perPage=2'), $skUsersNow, 1, 2);
$userOrder = $col("SELECT u.id FROM {$skUserFrom} WHERE {$skUserWhere} ORDER BY u.created_at DESC, u.id DESC LIMIT 2", ['org' => $org1]);
$same('sk_users_newest_first_includes_temp', sp_ids(sp_data($get('sk1', '/sk/users?sort=newest&perPage=2'))), $userOrder);
$meta('sk_users_page2_meta', $get('sk1', '/sk/users?perPage=2&page=2'), $skUsersNow, 2, 2);
$meta('sk_users_beyond_last_page_empty', $get('sk1', '/sk/users?perPage=2&page=' . ((int) ceil($skUsersNow / 2) + 1)), $skUsersNow, (int) ceil($skUsersNow / 2) + 1, 2);
$userQ = $one("SELECT COUNT(*) FROM {$skUserFrom} WHERE {$skUserWhere} AND (u.first_name LIKE :a ESCAPE '!' OR u.last_name LIKE :b ESCAPE '!' OR CONCAT(u.first_name, ' ', u.last_name) LIKE :c ESCAPE '!' OR u.email LIKE :d ESCAPE '!')",
    ['org' => $org1, 'a' => sp_like($stamp), 'b' => sp_like($stamp), 'c' => sp_like($stamp), 'd' => sp_like($stamp)]);
$uq = $meta('sk_users_search_name_vs_sql', $get('sk1', '/sk/users?q=' . rawurlencode($stamp) . '&perPage=1&page=1'), $userQ, 1, 1);
$meta('sk_users_search_page2', $get('sk1', '/sk/users?q=' . rawurlencode($stamp) . '&perPage=1&page=2'), $userQ, 2, 1);
$same('sk_users_search_finds_both_temp', sp_ids(sp_data($get('sk1', '/sk/users?q=' . rawurlencode($stamp) . '&sort=oldest&perPage=10'))), $created['users']);
$emailQ = $meta('sk_users_search_temp_email', $get('sk1', '/sk/users?q=' . rawurlencode("srchpg.user.alpha.{$stamp}@example.test")), 1, 1, 50);
$same('sk_users_search_temp_email_id', sp_ids($emailQ), [$created['users'][0]]);
// "_" in place of one stamp digit would match both temp accounts if it were a wildcard.
$meta('sk_users_search_literal_underscore', $get('sk1', '/sk/users?q=' . rawurlencode(substr($stamp, 0, 2) . '_' . substr($stamp, 3))), 0, 1, 50);
$activeSql = $one("SELECT COUNT(*) FROM {$skUserFrom} WHERE {$skUserWhere} AND u.status = 'active'", ['org' => $org1]);
$inactiveSql = $one("SELECT COUNT(*) FROM {$skUserFrom} WHERE {$skUserWhere} AND u.status = 'inactive'", ['org' => $org1]);
$meta('sk_users_filter_status_active_vs_sql', $get('sk1', '/sk/users?status=active&perPage=1'), $activeSql, 1, 1);
$meta('sk_users_filter_status_inactive_vs_sql', $get('sk1', '/sk/users?status=inactive'), $inactiveSql, 1, 50);
$meta('sk_users_search_plus_status_inactive', $get('sk1', '/sk/users?q=' . rawurlencode($stamp) . '&status=inactive&perPage=1&page=1'), 1, 1, 1);
$meta('sk_users_search_plus_status_active', $get('sk1', '/sk/users?q=' . rawurlencode($stamp) . '&status=active'), 1, 1, 50);
$meta('sk_users_role_youth_matches_nothing', $get('sk1', '/sk/users?role=youth'), 0, 1, 50);
$check('sk_users_invalid_role_422', $get('sk1', '/sk/users?role=bogus')['status'] === 422);
$userCounts = sp_data($get('sk1', '/sk/users?q=nothing-' . $stamp . '&status=inactive'))['counts'] ?? [];
$same('sk_users_counts_vs_sql_ignore_search_and_status', $userCounts, ['all' => $skUsersNow, 'active' => $activeSql, 'inactive' => $inactiveSql]);
$meta('sk_users_perPage_capped_100', $get('sk1', '/sk/users?perPage=999'), $skUsersNow, 1, 100);
$meta('sk_users_invalid_page_normalised', $get('sk1', '/sk/users?page=-3&perPage=2'), $skUsersNow, 1, 2);
$meta('tenant_sk2_users_search_sees_none', $get('sk2', '/sk/users?q=' . rawurlencode($stamp) . "&organization_id={$org1}&org_id={$org1}"), 0, 1, 50);
$sk2Users = $one("SELECT COUNT(*) FROM {$skUserFrom} WHERE {$skUserWhere}", ['org' => $org2]);
$meta('tenant_sk2_users_spoof_total_is_own_org', $get('sk2', "/sk/users?organization_id={$org1}&perPage=1"), $sk2Users, 1, 1);
$sk2Page = [];
for ($page = 1; $page <= max(1, (int) ceil($sk2Users / 2)) + 1; $page++) {
    $sk2Page = array_merge($sk2Page, sp_ids(sp_data($get('sk2', "/sk/users?perPage=2&page={$page}&organization_id={$org1}"))));
}
$foreignUsers = $sk2Page === [] ? 0 : $one('SELECT COUNT(*) FROM organization_users WHERE organization_id <> :org AND user_id IN (' . implode(',', array_map('intval', $sk2Page)) . ')', ['org' => $org2]);
$check('tenant_sk2_users_every_page_only_own_org', $foreignUsers === 0 && count($sk2Page) === $sk2Users, json_encode(['rows' => count($sk2Page), 'foreign' => $foreignUsers, 'expected' => $sk2Users]));
$userBody = $get('sk1', '/sk/users?q=' . rawurlencode($stamp));
$secretsUser = sp_secret_keys(sp_data($userBody));
$check('sk_users_no_password_in_response', $secretsUser === [] && stripos($userBody['body'], 'TempPass') === false && stripos($userBody['body'], '$2y$') === false, json_encode($secretsUser));
$smsTotal = $one('SELECT COUNT(*) FROM sms_deliveries WHERE organization_id = :org', ['org' => $org1]);
$meta('sk_sms_deliveries_total_vs_sql', $get('sk1', '/sk/deliveries/sms?perPage=3'), $smsTotal, 1, 3);
$actTotal = $one('SELECT COUNT(*) FROM activity_logs WHERE organization_id = :org', ['org' => $org1]);
$meta('sk_activity_total_vs_sql', $get('sk1', '/sk/activity?perPage=7'), $actTotal, 1, 7);

// ---- tenant isolation ---------------------------------------------------------------
$org2Youth = $one('SELECT COUNT(*) FROM youth WHERE organization_id = :org AND archived = 0', ['org' => $org2]);
$meta('tenant_sk2_search_sees_none_of_sk1_youth', $get('sk2', "/sk/youth?q={$q}"), 0, 1, 15);
$meta('tenant_sk2_spoofed_org_params_ignored', $get('sk2', "/sk/youth?q={$q}&organization_id={$org1}&org_id={$org1}&orgId={$org1}&organizationId={$org1}"), 0, 1, 15);
$meta('tenant_sk2_spoof_unfiltered_total_is_own_org', $get('sk2', "/sk/youth?organization_id={$org1}&perPage=5"), $org2Youth, 1, 5);
$meta('tenant_sk2_spoof_header_ignored', $get('sk2', "/sk/youth?q={$q}", ["X-Organization-Id: {$org1}"]), 0, 1, 15);
$meta('tenant_sk2_page2_does_not_leak', $get('sk2', "/sk/youth?q={$q}&perPage=1&page=2"), 0, 2, 1);
$meta('tenant_sk2_programs_search', $get('sk2', "/sk/programs?q={$q}&organization_id={$org1}"), 0, 1, 50);
$meta('tenant_sk2_assistance_search', $get('sk2', "/sk/assistance?q={$q}&org_id={$org1}"), 0, 1, 50);
$app2 = $one("SELECT COUNT(*) FROM {$appFrom} WHERE a.organization_id = :org", ['org' => $org2]);
$meta('tenant_sk2_applications_total_is_own_org', $get('sk2', "/sk/applications?organization_id={$org1}"), $app2, 1, 50);
$meta('tenant_sk1_spoof_other_org_still_own', $get('sk1', "/sk/youth?q={$q}&organization_id={$org2}"), 6, 1, 15);
$all1 = [];
for ($page = 1; $page <= max(1, (int) ceil($orgActive / 10)) + 1; $page++) {
    $all1 = array_merge($all1, sp_ids(sp_data($get('sk1', "/sk/youth?perPage=10&page={$page}&organization_id={$org2}"))));
}
$foreign = $all1 === [] ? 0 : $one('SELECT COUNT(*) FROM youth WHERE organization_id <> :org AND id IN (' . implode(',', array_map('intval', $all1)) . ')', ['org' => $org1]);
$check('tenant_every_page_only_own_org_rows', $foreign === 0 && count($all1) === count(array_unique($all1)) && count($all1) === $orgActive - 1,
    json_encode(['rows' => count($all1), 'foreign' => $foreign, 'expected' => $orgActive - 1]));

// ---- admin lists --------------------------------------------------------------------
$orgTotal = $one('SELECT COUNT(*) FROM organizations');
$a1 = $meta('admin_orgs_total_vs_sql', $get('admin', '/admin/organizations?perPage=5&page=1'), $orgTotal, 1, 5);
$a2 = sp_data($get('admin', '/admin/organizations?perPage=5&page=2'));
$same('admin_orgs_page2_rows_match_sql_order', sp_ids($a2), $col('SELECT id FROM organizations ORDER BY name ASC, id ASC LIMIT 5 OFFSET 5'));
$check('admin_orgs_page1_page2_differ', array_intersect(sp_ids($a1), sp_ids($a2)) === []);
$pend = $one("SELECT COUNT(*) FROM organizations WHERE status = 'pending'");
$meta('admin_orgs_filter_status_vs_sql', $get('admin', '/admin/organizations?status=pending&perPage=4'), $pend, 1, 4);
$org1Barangay = (string) ($pdo->query('SELECT barangay FROM organizations WHERE id = ' . (int) $org1)->fetchColumn() ?: '');
$cols = ['name', 'barangay', 'municipality', 'province', 'chairperson', 'email', 'contact'];
$orgQ = $one('SELECT COUNT(*) FROM organizations WHERE ' . implode(' OR ', array_map(static fn ($c) => "{$c} LIKE :{$c} ESCAPE '!'", $cols)),
    array_combine($cols, array_fill(0, count($cols), sp_like($org1Barangay))));
$oq = $meta('admin_orgs_search_vs_sql', $get('admin', '/admin/organizations?q=' . rawurlencode($org1Barangay)), $orgQ, 1, 25);
$check('admin_orgs_search_finds_org1', in_array($org1, sp_ids($oq), true));
$meta('admin_orgs_perPage_capped_200', $get('admin', '/admin/organizations?perPage=999'), $orgTotal, 1, 200);
$meta('admin_orgs_beyond_last_page_empty', $get('admin', '/admin/organizations?perPage=5&page=' . ((int) ceil($orgTotal / 5) + 1)), $orgTotal, (int) ceil($orgTotal / 5) + 1, 5);
$check('admin_orgs_invalid_status_422', $get('admin', '/admin/organizations?status=bogus')['status'] === 422);
$userRows = $one('SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id LEFT JOIN organization_users ou ON ou.user_id = u.id');
$meta('admin_users_total_vs_sql', $get('admin', '/admin/users?perPage=10'), $userRows, 1, 10);
$youthUsers = $one("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id LEFT JOIN organization_users ou ON ou.user_id = u.id WHERE r.code = 'YOUTH'");
$meta('admin_users_filter_role_vs_sql', $get('admin', '/admin/users?role=youth&perPage=3'), $youthUsers, 1, 3);
$u2 = sp_data($get('admin', '/admin/users?perPage=10&page=2'));
$same('admin_users_page2_rows_match_sql', sp_ids($u2), $col('SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id LEFT JOIN organization_users ou ON ou.user_id = u.id ORDER BY u.id ASC, ou.organization_id ASC LIMIT 10 OFFSET 10'));
// organizationName rides on each row (the console no longer downloads every organization for names).
$orgNames = [];
foreach ($pdo->query('SELECT id, name FROM organizations') as $row) {
    $orgNames[(int) $row['id']] = (string) $row['name'];
}
$nameMismatch = [];
$withOrg = 0;
foreach ([1, 2] as $pg) {
    foreach (sp_data($get('admin', "/admin/users?perPage=50&page={$pg}"))['items'] ?? [] as $item) {
        if (!array_key_exists('organizationName', $item)) {
            $nameMismatch[] = "{$item['id']}:missing";
            continue;
        }
        $expected = $item['orgId'] === null ? null : ($orgNames[(int) $item['orgId']] ?? null);
        if ($item['organizationName'] !== $expected) {
            $nameMismatch[] = "{$item['id']}:" . json_encode($item['organizationName']);
        }
        if ($item['orgId'] !== null) {
            $withOrg++;
        }
    }
}
$check('admin_users_organizationName_vs_sql', $nameMismatch === [] && $withOrg > 0, 'withOrg=' . $withOrg . ' bad=' . implode(',', $nameMismatch));
$adm = $meta('admin_users_search_email', $get('admin', '/admin/users?q=' . rawurlencode('admin@demo.test')), 1, 1, 25);
$same('admin_users_search_email_exact', array_column($adm['items'] ?? [], 'email'), ['admin@demo.test']);
$inactive = $one("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id LEFT JOIN organization_users ou ON ou.user_id = u.id WHERE u.status = 'inactive' AND r.code = 'SK_OFFICIAL'");
$meta('admin_users_filter_status_plus_role', $get('admin', '/admin/users?status=inactive&role=sk_official'), $inactive, 1, 25);
$allAct = $one('SELECT COUNT(*) FROM activity_logs');
$act = $meta('admin_activity_total_vs_sql', $get('admin', '/admin/activity?perPage=20'), $allAct, 1, 20);
$loginAct = $one("SELECT COUNT(*) FROM activity_logs WHERE action = 'login'");
$meta('admin_activity_filter_action_vs_sql', $get('admin', '/admin/activity?action=login&perPage=5'), $loginAct, 1, 5);
$actions = $pdo->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$same('admin_activity_filter_options_vs_sql', $act['filters']['actions'] ?? null, array_map('strval', $actions));
$orgAct = $one('SELECT COUNT(*) FROM activity_logs WHERE organization_id = :org', ['org' => $org1]);
$meta('admin_org_activity_scoped_by_route', $get('admin', "/admin/organizations/{$org1}/activity?perPage=5&organization_id={$org2}"), $orgAct, 1, 5);
$payTotal = $one('SELECT COUNT(*) FROM payment_transactions');
$meta('admin_payment_transactions_total_vs_sql', $get('admin', '/admin/payment-transactions?perPage=5'), $payTotal, 1, 5);
$meta('admin_payment_transactions_search_no_match', $get('admin', '/admin/payment-transactions?q=' . rawurlencode('nothing-' . $stamp)), 0, 1, 25);
// The manual / offline ledger list was removed (router 404 for every caller).
$check('admin_manual_payments_list_removed_404', $get('admin', '/admin/payments?perPage=5')['status'] === 404);

// ---- sensitive data ------------------------------------------------------------------
$secrets = [];
foreach (array_merge(
    array_map(static fn ($p) => ['sk1', $p . '?perPage=5'], $skLists),
    array_map(static fn ($p) => ['admin', $p . '?perPage=5'], $adminLists)
) as [$who, $path]) {
    $r = $get($who, $path);
    $keys = sp_secret_keys(sp_data($r));
    if ($keys !== [] || stripos($r['body'], 'password_hash') !== false || stripos($r['body'], '$2y$') !== false) {
        $secrets[$path] = $keys;
    }
}
$check('no_secrets_in_any_list_response', $secrets === [], json_encode($secrets));

// ---- side effects -----------------------------------------------------------------------
$same('no_sms_rows_written', $one('SELECT COUNT(*) FROM sms_deliveries'), $smsBefore);
$same('no_email_rows_written', $one('SELECT COUNT(*) FROM email_deliveries'), $emailBefore);
$same('no_applications_written', $one('SELECT COUNT(*) FROM applications'), $appsBefore);
$same('no_notifications_written', $one('SELECT COUNT(*) FROM notifications'), $notifBefore);

// ---- cleanup by exact id -------------------------------------------------------------------
foreach ($created['youth'] as $id) {
    $r = sp_req('DELETE', "$base/sk/youth/{$id}", null, $cookies['sk1']);
    $check("cleanup_youth_{$id}", $r['status'] === 200, (string) $r['status']);
}
foreach ($created['programs'] as $id) {
    $r = sp_req('DELETE', "$base/sk/programs/{$id}", null, $cookies['sk1']);
    $check("cleanup_program_{$id}", $r['status'] === 200, (string) $r['status']);
}
foreach ($created['assistance'] as $id) {
    $r = sp_req('DELETE', "$base/sk/assistance/{$id}", null, $cookies['sk1']);
    $check("cleanup_assistance_{$id}", $r['status'] === 200, (string) $r['status'] . ' ' . substr($r['body'], 0, 200));
}
foreach ($created['users'] as $id) {
    $r = sp_req('DELETE', "$base/sk/users/{$id}", null, $cookies['sk1']);
    $check("cleanup_user_{$id}", $r['status'] === 200, (string) $r['status'] . ' ' . substr($r['body'], 0, 200));
}
$left = [];
foreach ([['youth', $created['youth']], ['programs', $created['programs']], ['assistance_programs', $created['assistance']], ['users', $created['users']]] as [$table, $ids]) {
    foreach ($ids as $id) {
        if ($one("SELECT COUNT(*) FROM {$table} WHERE id = :id", ['id' => $id]) > 0) {
            $left[] = "{$table}#{$id}";
        }
    }
}
$check('cleanup_complete', $left === [], implode(',', $left));
$same('cleanup_youth_count_restored', $one('SELECT COUNT(*) FROM youth WHERE organization_id = :org AND archived = 0', ['org' => $org1]), $orgActive - 7);
$same('cleanup_report_rowCount_restored', (int) (sp_data($get('sk1', '/sk/reports/youth'))['export']['rowCount'] ?? -1), $beforeRows);

foreach ($cookies as $jar) {
    @unlink($jar);
}
echo 'Created and deleted: youth=' . json_encode($created['youth']) . ' programs=' . json_encode($created['programs'])
    . ' assistance=' . json_encode($created['assistance']) . ' users=' . json_encode($created['users']) . "\n";
echo "Summary: {$passed} passed, {$failed} failed\n";
echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
