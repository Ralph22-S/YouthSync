<?php
declare(strict_types=1);

/**
 * Reports HTTP test: GET /sk/reports, /sk/reports/{type} and the CSV / XLSX
 * exports /sk/reports/{type}/csv and /sk/reports/{type}/xlsx.
 *
 * Run from api/sql with Apache + MySQL up:
 *   php reports_http_test.php
 * Optional: set YOUTHSYNC_API_BASE to override http://localhost/YouthSync/api.
 *
 * - Every JSON total / series is compared with independent SQL (HTTP for the
 *   signed-in organizations, DashboardService directly for every other
 *   organization that has data).
 * - Every export is downloaded and parsed: CSV with fgetcsv, XLSX opened as a
 *   ZIP with a built-in reader (no ZipArchive needed), then sheet XML and shared
 *   strings are read and compared with the JSON report and with the CSV.
 * - Role protection, organization/user spoofing (query, body, headers),
 *   unknown types/formats, invalid filters, empty reports and sensitive fields.
 * - Escaping: one temporary youth is created through the API with a comma, a
 *   quote, a newline and a leading "=", exported, checked, and deleted by its
 *   exact id. One temporary draft program is created/deleted the same way to
 *   check that report counts and export rows move by exactly one.
 * No application is submitted and nothing here sends SMS or email.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'YouthSync\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$base = rtrim((string) (getenv('YOUTHSYNC_API_BASE') ?: 'http://localhost/YouthSync/api'), '/');
$pdo = youthsync_pdo();

const RP_PASSWORD = 'YouthSync1!';
const RP_YOUTH_EMAIL = 'youth-auth-test@example.test';
const RP_YOUTH_PASSWORD = 'YouthAuthTest1!';
const RP_TAG = 'RptEsc';
const RP_TYPES = ['youth', 'programs', 'assistance', 'applications', 'attendance'];

$leftover = (int) $pdo->query("SELECT
    (SELECT COUNT(*) FROM youth WHERE last_name LIKE '%RptEsc%' OR first_name LIKE '%RptEsc%')
  + (SELECT COUNT(*) FROM programs WHERE name LIKE 'RptEsc %')")->fetchColumn();
if ($leftover > 0) {
    fwrite(STDERR, "STOP: pre-existing RptEsc test records found. No cleanup was performed.\n");
    exit(2);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
$cookies = [];
foreach (['anon', 'sk1', 'sk2', 'sk4', 'admin', 'youth'] as $who) {
    $cookies[$who] = $tmp . 'ys_reports_' . $who . '.txt';
    @unlink($cookies[$who]);
}

/** @param list<string> $extraHeaders */
function rp_req(string $method, string $url, ?string $json, string $cookie, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    $headers = array_merge(['Accept: */*'], $extraHeaders);
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
        return ['status' => 0, 'headers' => [], 'body' => $err, 'data' => []];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerBlock = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $parsed = [];
    foreach (preg_split('/\r?\n/', $headerBlock) ?: [] as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $parsed[strtolower(trim($k))] = trim($v);
        }
    }
    $decoded = json_decode($body, true);
    return ['status' => $status, 'headers' => $parsed, 'body' => $body, 'data' => is_array($decoded) ? $decoded : []];
}

function rp_data(array $r): array
{
    $d = $r['data']['data'] ?? [];
    return is_array($d) ? $d : [];
}

function rp_map(mixed $map): array
{
    $out = [];
    foreach (is_array($map) ? $map : [] as $k => $v) {
        $out[(string) $k] = $v;
    }
    ksort($out);
    return $out;
}

/** @return list<list<string>> */
function rp_csv(string $body): array
{
    if (str_starts_with($body, "\xEF\xBB\xBF")) {
        $body = substr($body, 3);
    }
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $body);
    rewind($fh);
    $rows = [];
    while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($row === [null]) {
            continue;
        }
        $rows[] = array_map(static fn ($v) => (string) $v, $row);
    }
    fclose($fh);
    return $rows;
}

/**
 * Minimal ZIP reader (central directory → local headers; stored or deflate).
 * @return array{files: array<string, string>, crcOk: bool, error: string}
 */
function rp_unzip(string $bytes): array
{
    $eocd = strrpos($bytes, "PK\x05\x06");
    if ($eocd === false) {
        return ['files' => [], 'crcOk' => false, 'error' => 'no end of central directory'];
    }
    $end = unpack('Vsig/vdisk/vcdisk/ventries/vtotal/Vsize/Voffset/vcomment', substr($bytes, $eocd, 22));
    $pos = $end['offset'];
    $files = [];
    $crcOk = true;
    for ($i = 0; $i < $end['total']; $i++) {
        $cd = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/viattr/Vattr/Voffset', substr($bytes, $pos, 46));
        if ($cd['sig'] !== 0x02014b50) {
            return ['files' => $files, 'crcOk' => false, 'error' => 'bad central header'];
        }
        $name = substr($bytes, $pos + 46, $cd['nlen']);
        $pos += 46 + $cd['nlen'] + $cd['elen'] + $cd['clen'];
        $lh = unpack('Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen', substr($bytes, $cd['offset'], 30));
        if ($lh['sig'] !== 0x04034b50) {
            return ['files' => $files, 'crcOk' => false, 'error' => 'bad local header'];
        }
        $data = substr($bytes, $cd['offset'] + 30 + $lh['nlen'] + $lh['elen'], $cd['csize']);
        if ($cd['method'] === 8) {
            $data = gzinflate($data);
        } elseif ($cd['method'] !== 0) {
            return ['files' => $files, 'crcOk' => false, 'error' => 'unknown method'];
        }
        if ($data === false || strlen($data) !== $cd['size'] || (crc32($data) & 0xFFFFFFFF) !== $cd['crc']) {
            $crcOk = false;
        }
        $files[$name] = (string) $data;
    }
    return ['files' => $files, 'crcOk' => $crcOk, 'error' => ''];
}

/**
 * @return array{ok: bool, error: string, sheetName: string, rows: list<list<mixed>>, cellTypes: list<list<string>>, hasFormula: bool, sstCount: int, files: list<string>, crcOk: bool}
 */
function rp_xlsx(string $bytes): array
{
    $zip = rp_unzip($bytes);
    $out = ['ok' => false, 'error' => $zip['error'], 'sheetName' => '', 'rows' => [], 'cellTypes' => [], 'hasFormula' => false,
        'sstCount' => 0, 'files' => array_keys($zip['files']), 'crcOk' => $zip['crcOk']];
    $need = ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml', 'xl/styles.xml'];
    foreach ($need as $part) {
        if (!isset($zip['files'][$part])) {
            $out['error'] = 'missing ' . $part;
            return $out;
        }
    }
    $wb = simplexml_load_string($zip['files']['xl/workbook.xml']);
    $sst = simplexml_load_string($zip['files']['xl/sharedStrings.xml']);
    $sheetXml = $zip['files']['xl/worksheets/sheet1.xml'];
    $sheet = simplexml_load_string($sheetXml);
    if ($wb === false || $sst === false || $sheet === false) {
        $out['error'] = 'xml parse failed';
        return $out;
    }
    $out['sheetName'] = (string) $wb->sheets->sheet['name'];
    $strings = [];
    foreach ($sst->si as $si) {
        $text = isset($si->t) ? (string) $si->t : '';
        foreach ($si->r as $run) {
            $text .= (string) $run->t;
        }
        $strings[] = $text;
    }
    $out['sstCount'] = count($strings);
    $out['hasFormula'] = (bool) preg_match('/<f[ >]/', $sheetXml);
    foreach ($sheet->sheetData->row as $row) {
        $rIndex = (int) $row['r'];
        $cells = [];
        $types = [];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)(\d+)$/', (string) $c['r'], $m);
            $col = 0;
            foreach (str_split($m[1]) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $t = (string) $c['t'];
            $v = (string) $c->v;
            if ($t === 's') {
                $cells[$col - 1] = $strings[(int) $v] ?? null;
            } elseif ($t === 'inlineStr') {
                $cells[$col - 1] = (string) $c->is->t;
            } else {
                $cells[$col - 1] = preg_match('/^-?\d+$/', $v) ? (int) $v : (float) $v;
            }
            $types[$col - 1] = $t === '' ? 'n' : $t;
        }
        $out['rows'][$rIndex - 1] = $cells;
        $out['cellTypes'][$rIndex - 1] = $types;
    }
    $out['ok'] = true;
    return $out;
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

// ---- independent SQL ---------------------------------------------------------

$one = static function (string $sql, array $params = []) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};
$col = static function (string $sql, array $params = []) use ($pdo): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};
$group = static function (string $sql, array $params = []) use ($pdo): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$k, $n]) {
        $key = (string) ($k ?? '');
        $key = $key === '' ? '(blank)' : $key;
        $out[$key] = ($out[$key] ?? 0) + (int) $n;
    }
    ksort($out);
    return $out;
};

/**
 * Expected report numbers straight from the tables.
 * $f: from, to, status, kind, category (null = not applied).
 */
$sqlReport = static function (string $type, int $org, array $f = []) use ($one, $group, $col): array {
    $from = $f['from'] ?? null;
    $to = $f['to'] ?? null;
    $status = $f['status'] ?? null;
    $kind = $f['kind'] ?? null;
    $category = $f['category'] ?? null;
    $p = ['o' => $org];
    $date = static function (string $expr) use ($from, $to, &$p): string {
        $sql = '';
        if ($from !== null) {
            $sql .= " AND {$expr} >= :df";
            $p['df'] = $from;
        }
        if ($to !== null) {
            $sql .= " AND {$expr} <= :dt";
            $p['dt'] = $to;
        }
        return $sql;
    };
    switch ($type) {
        case 'youth':
            $w = 'organization_id = :o';
            if ($status === 'active') {
                $w .= ' AND archived = 0';
            } elseif ($status === 'archived') {
                $w .= ' AND archived = 1';
            }
            $w .= $date('DATE(created_at)');
            $ages = ['15-17' => 0, '18-24' => 0, '25-30' => 0, 'other' => 0];
            foreach ($col("SELECT TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) FROM youth WHERE {$w}", $p) as $age) {
                $a = (int) $age;
                $ages[$a >= 15 && $a <= 17 ? '15-17' : ($a >= 18 && $a <= 24 ? '18-24' : ($a >= 25 && $a <= 30 ? '25-30' : 'other'))]++;
            }
            return [
                'totals' => [
                    'total' => $one("SELECT COUNT(*) FROM youth WHERE {$w}", $p),
                    'active' => $one("SELECT COUNT(*) FROM youth WHERE {$w} AND archived = 0", $p),
                    'archived' => $one("SELECT COUNT(*) FROM youth WHERE {$w} AND archived = 1", $p),
                ],
                'series' => [
                    'ageGroups' => $ages,
                    'gender' => $group("SELECT gender, COUNT(*) FROM youth WHERE {$w} GROUP BY gender", $p),
                    'education' => $group("SELECT education, COUNT(*) FROM youth WHERE {$w} GROUP BY education", $p),
                    'employment' => $group("SELECT employment, COUNT(*) FROM youth WHERE {$w} GROUP BY employment", $p),
                    'priority' => $group("SELECT priority_level, COUNT(*) FROM youth WHERE {$w} GROUP BY priority_level", $p),
                ],
                'ids' => $col("SELECT code FROM youth WHERE {$w} ORDER BY last_name, first_name, id", $p),
                'highPriority' => $one("SELECT COUNT(*) FROM youth WHERE {$w} AND priority_level = 'High'", $p),
            ];
        case 'programs':
            $w = 'organization_id = :o';
            if ($status !== null) {
                $w .= ' AND status = :st';
                $p['st'] = $status;
            }
            if ($kind !== null) {
                $w .= ' AND kind = :k';
                $p['k'] = $kind;
            }
            $w .= $date('scheduled_on');
            $ids = $col("SELECT id FROM programs WHERE {$w} ORDER BY scheduled_on DESC, id DESC", $p);
            $in = $ids === [] ? '0' : implode(',', array_map('intval', $ids));
            return [
                'totals' => [
                    'total' => count($ids),
                    'programs' => $one("SELECT COUNT(*) FROM programs WHERE {$w} AND kind = 'program'", $p),
                    'events' => $one("SELECT COUNT(*) FROM programs WHERE {$w} AND kind = 'event'", $p),
                    'registrations' => $one("SELECT COUNT(*) FROM applications WHERE organization_id = :o AND program_id IN ({$in}) AND status NOT IN ('rejected','withdrawn')", ['o' => $org]),
                    'participants' => $one("SELECT COUNT(DISTINCT youth_id) FROM applications WHERE organization_id = :o AND program_id IN ({$in}) AND status NOT IN ('rejected','withdrawn')", ['o' => $org]),
                    'presentAttendance' => $one("SELECT COUNT(*) FROM attendance WHERE organization_id = :o AND program_id IN ({$in}) AND status = 'present'", ['o' => $org]),
                ],
                'series' => [
                    'byStatus' => $group("SELECT status, COUNT(*) FROM programs WHERE {$w} GROUP BY status", $p),
                    'byKind' => $group("SELECT kind, COUNT(*) FROM programs WHERE {$w} GROUP BY kind", $p),
                ],
                'ids' => $ids,
            ];
        case 'assistance':
            $w = 'organization_id = :o';
            if ($status !== null) {
                $w .= ' AND status = :st';
                $p['st'] = $status;
            }
            if ($category !== null) {
                $w .= ' AND category = :c';
                $p['c'] = $category;
            }
            $w .= $date('DATE(created_at)');
            $ids = $col("SELECT id FROM assistance_programs WHERE {$w} ORDER BY id DESC", $p);
            $in = $ids === [] ? '0' : implode(',', array_map('intval', $ids));
            return [
                'totals' => [
                    'total' => count($ids),
                    'beneficiaries' => $one("SELECT COUNT(*) FROM beneficiaries WHERE organization_id = :o AND assistance_id IN ({$in})", ['o' => $org]),
                    'awarded' => $one("SELECT COUNT(*) FROM beneficiaries WHERE organization_id = :o AND assistance_id IN ({$in}) AND status IN ('approved','released')", ['o' => $org]),
                ],
                'series' => [
                    'byStatus' => $group("SELECT status, COUNT(*) FROM assistance_programs WHERE {$w} GROUP BY status", $p),
                    'byCategory' => $group("SELECT category, COUNT(*) FROM assistance_programs WHERE {$w} GROUP BY category", $p),
                    'beneficiariesByStatus' => $group("SELECT status, COUNT(*) FROM beneficiaries WHERE organization_id = :o AND assistance_id IN ({$in}) GROUP BY status", ['o' => $org]),
                    'awardedByCategory' => $group("SELECT ap.category, COUNT(*) FROM beneficiaries b JOIN assistance_programs ap ON ap.id = b.assistance_id WHERE b.organization_id = :o AND b.assistance_id IN ({$in}) AND b.status IN ('approved','released') GROUP BY ap.category", ['o' => $org]),
                ],
                'ids' => $ids,
            ];
        case 'applications':
            $w = 'x.organization_id = :o';
            if ($status !== null) {
                $w .= ' AND x.status = :st';
                $p['st'] = $status;
            }
            $w .= $date('DATE(x.submitted_at)');
            $rows = [];
            $stmtSql = "SELECT x.id, x.assistance_id, x.program_id, pr.kind FROM applications x LEFT JOIN programs pr ON pr.id = x.program_id AND pr.organization_id = x.organization_id WHERE {$w} ORDER BY x.submitted_at DESC, x.id DESC";
            global $pdo;
            $stmt = $pdo->prepare($stmtSql);
            $stmt->execute($p);
            $byTarget = ['assistance' => 0, 'event' => 0, 'program' => 0];
            $ids = [];
            $keep = [];
            foreach ($stmt->fetchAll() as $r) {
                $target = $r['assistance_id'] !== null ? 'assistance' : (($r['kind'] ?? '') === 'event' ? 'event' : 'program');
                if ($kind !== null && $kind !== $target) {
                    continue;
                }
                $byTarget[$target]++;
                $ids[] = (string) $r['id'];
                $keep[] = (int) $r['id'];
            }
            $in = $keep === [] ? '0' : implode(',', $keep);
            return [
                'totals' => ['total' => count($ids)],
                'series' => [
                    'byStatus' => $group("SELECT status, COUNT(*) FROM applications WHERE id IN ({$in}) GROUP BY status"),
                    'byTarget' => $byTarget,
                ],
                'ids' => $ids,
            ];
        default:
            $w = 'x.organization_id = :o';
            if ($status !== null) {
                $w .= ' AND x.status = :st';
                $p['st'] = $status;
            }
            if ($kind !== null) {
                $w .= ' AND pr.kind = :k';
                $p['k'] = $kind;
            }
            $w .= $date('DATE(COALESCE(x.confirmed_at, x.created_at))');
            $j = 'attendance x JOIN programs pr ON pr.id = x.program_id AND pr.organization_id = x.organization_id';
            $byStatus = $group("SELECT x.status, COUNT(*) FROM {$j} WHERE {$w} GROUP BY x.status", $p);
            return [
                'totals' => ['total' => $one("SELECT COUNT(*) FROM {$j} WHERE {$w}", $p), 'present' => $byStatus['present'] ?? 0],
                'series' => [
                    'byStatus' => $byStatus,
                    'byKind' => $group("SELECT pr.kind, COUNT(*) FROM {$j} WHERE {$w} GROUP BY pr.kind", $p),
                ],
                'ids' => $col("SELECT x.id FROM {$j} WHERE {$w} ORDER BY pr.scheduled_on DESC, x.id DESC", $p),
            ];
    }
};

/** Compares a JSON report (HTTP or service) with $sqlReport. */
$compare = static function (string $label, string $type, array $api, array $sql) use ($same, $check): void {
    $same("{$label}_type", $api['type'] ?? null, $type);
    foreach ($sql['totals'] as $k => $v) {
        $same("{$label}_totals_{$k}", $api['totals'][$k] ?? null, $v);
    }
    foreach ($sql['series'] as $k => $v) {
        $same("{$label}_series_{$k}", rp_map($api['series'][$k] ?? []), rp_map($v));
    }
    $same("{$label}_export_rowCount", $api['export']['rowCount'] ?? null, $sql['totals']['total']);
    $check("{$label}_export_columns", is_array($api['export']['columns'] ?? null) && count($api['export']['columns']) > 0);
    if ($type === 'youth') {
        $same("{$label}_highPriority_list", count($api['highPriorityYouth'] ?? []), min(50, $sql['highPriority']));
    }
    if ($type === 'assistance') {
        $same("{$label}_slots_ids", array_map(static fn ($s) => (string) $s['id'], $api['slots'] ?? []), $sql['ids']);
    }
};

// ---- sessions ------------------------------------------------------------------

$manilaToday = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$maxNotificationBefore = $one('SELECT COALESCE(MAX(id), 0) FROM notifications');
$maxSmsBefore = $one('SELECT COALESCE(MAX(id), 0) FROM sms_deliveries');
$maxEmailBefore = $one('SELECT COALESCE(MAX(id), 0) FROM email_deliveries');
$maxApplicationBefore = $one('SELECT COALESCE(MAX(id), 0) FROM applications');
$maxActivityBefore = $one('SELECT COALESCE(MAX(id), 0) FROM activity_logs');

$routesJson = ['/sk/reports?type=youth'];
foreach (RP_TYPES as $t) {
    $routesJson[] = "/sk/reports/{$t}";
}
$routesExport = [];
foreach (RP_TYPES as $t) {
    $routesExport[] = "/sk/reports/{$t}/csv";
    $routesExport[] = "/sk/reports/{$t}/xlsx";
}

foreach (array_merge($routesJson, $routesExport) as $path) {
    $r = rp_req('GET', $base . $path, null, $cookies['anon']);
    $check("anon_401 {$path}", $r['status'] === 401 && ($r['data']['success'] ?? null) === false && !isset($r['headers']['content-disposition']), (string) $r['status']);
}

$login = static function (string $who, string $email, string $password) use ($base, $cookies): bool {
    $r = rp_req('POST', "$base/auth/login", json_encode(['email' => $email, 'password' => $password]), $cookies[$who]);
    return $r['status'] === 200;
};
$check('login_sk1', $login('sk1', 'sk1@demo.test', RP_PASSWORD));
$check('login_sk2', $login('sk2', 'sk2@demo.test', RP_PASSWORD));
$check('login_admin', $login('admin', 'admin@skmms.test', RP_PASSWORD));
$check('login_youth', $login('youth', RP_YOUTH_EMAIL, RP_YOUTH_PASSWORD));
$haveSk4 = $login('sk4', 'sk4@demo.test', RP_PASSWORD);

$me = static fn (string $who) => rp_data(rp_req('GET', "$base/auth/me", null, $cookies[$who]));
$org1 = (int) ($me('sk1')['organization']['id'] ?? 0);
$org2 = (int) ($me('sk2')['organization']['id'] ?? 0);
$check('orgs_resolved', $org1 > 0 && $org2 > 0 && $org1 !== $org2, "org1={$org1} org2={$org2}");

foreach (['youth', 'admin'] as $who) {
    foreach (array_merge($routesJson, $routesExport) as $path) {
        $r = rp_req('GET', $base . $path, null, $cookies[$who]);
        $check("{$who}_403 {$path}", $r['status'] === 403 && !isset($r['headers']['content-disposition']), (string) $r['status']);
    }
}

// ---- JSON reports vs SQL (sk1, sk2) ---------------------------------------------

$reports = [];
foreach (['sk1' => $org1, 'sk2' => $org2] as $who => $org) {
    foreach (RP_TYPES as $t) {
        $r = rp_req('GET', "$base/sk/reports/{$t}", null, $cookies[$who]);
        $check("{$who}_{$t}_200", $r['status'] === 200, (string) $r['status']);
        $q = rp_req('GET', "$base/sk/reports?type={$t}", null, $cookies[$who]);
        $same("{$who}_{$t}_query_equals_path", rp_data($q), rp_data($r));
        $reports[$who][$t] = rp_data($r);
        $compare("{$who}_{$t}", $t, rp_data($r), $sqlReport($t, $org));
    }
}

// ---- filters (sk1) and service-level comparison for every organization ---------

$filterCases = [
    ['youth', ['status' => 'active']],
    ['youth', ['status' => 'archived']],
    ['youth', ['from' => $manilaToday, 'to' => $manilaToday]],
    ['youth', ['from' => '2026-01-01', 'to' => $manilaToday, 'status' => 'active']],
    ['programs', ['kind' => 'event']],
    ['programs', ['kind' => 'program', 'status' => 'published']],
    ['programs', ['from' => $manilaToday]],
    ['assistance', ['category' => 'scholarship']],
    ['assistance', ['status' => 'open', 'category' => 'other']],
    ['applications', ['kind' => 'program']],
    ['applications', ['kind' => 'assistance']],
    ['applications', ['status' => 'pending']],
    ['attendance', ['status' => 'present']],
    ['attendance', ['kind' => 'event']],
    ['attendance', ['from' => '2026-01-01', 'to' => $manilaToday]],
];
foreach ($filterCases as $i => [$t, $f]) {
    $qs = http_build_query($f);
    $r = rp_req('GET', "$base/sk/reports/{$t}?{$qs}", null, $cookies['sk1']);
    $check("filter{$i}_{$t}_200", $r['status'] === 200, $r['body']);
    $compare("filter{$i}_{$t}", $t, rp_data($r), $sqlReport($t, $org1, $f));
    foreach (['status', 'kind', 'category', 'from', 'to'] as $k) {
        if (isset($f[$k])) {
            $same("filter{$i}_{$t}_echo_{$k}", rp_data($r)['filters'][$k] ?? null, $f[$k]);
        }
    }
    $csv = rp_req('GET', "$base/sk/reports/{$t}/csv?{$qs}", null, $cookies['sk1']);
    $same("filter{$i}_{$t}_csv_rows", count(rp_csv($csv['body'])) - 1, rp_data($r)['totals']['total'] ?? -1);
}

$service = new YouthSync\Dashboard\DashboardService($pdo);
$orgsWithData = array_map('intval', $pdo->query(
    'SELECT organization_id FROM youth UNION SELECT organization_id FROM programs UNION SELECT organization_id FROM assistance_programs
     UNION SELECT organization_id FROM applications UNION SELECT organization_id FROM attendance'
)->fetchAll(PDO::FETCH_COLUMN));
sort($orgsWithData);
$serviceFilters = [[], ['status' => 'active'], ['kind' => 'event'], ['kind' => 'program'], ['category' => 'other'], ['status' => 'approved'], ['status' => 'present']];
foreach ($orgsWithData as $org) {
    foreach (RP_TYPES as $t) {
        foreach ($serviceFilters as $fi => $f) {
            $allowed = [
                'youth' => ['status' => ['active']],
                'programs' => ['kind' => ['event', 'program']],
                'assistance' => ['category' => ['other']],
                'applications' => ['kind' => ['event', 'program'], 'status' => ['approved']],
                'attendance' => ['kind' => ['event', 'program'], 'status' => ['present']],
            ][$t];
            $applies = true;
            foreach ($f as $k => $v) {
                if (!in_array($v, $allowed[$k] ?? [], true)) {
                    $applies = false;
                }
            }
            if (!$applies) {
                continue;
            }
            $api = $service->report($org, ['type' => $t] + $f);
            $sql = $sqlReport($t, $org, $f);
            $label = "svc_org{$org}_{$t}_f{$fi}";
            $compare($label, $t, $api, $sql);
            $export = $service->reportExport($org, ['type' => $t] + $f);
            $same("{$label}_rows_equal_total", count($export['rows']), $api['totals']['total']);
            $key = $t === 'youth' ? 'code' : 'id';
            $same("{$label}_row_ids", array_map(static fn ($row) => (string) $row[$key], $export['rows']), $sql['ids']);
            $sum = static fn (string $k) => array_sum(array_map(static fn ($row) => (int) ($row[$k] ?? 0), $export['rows']));
            $countBy = static function (string $k) use ($export): array {
                $out = [];
                foreach ($export['rows'] as $row) {
                    $out[(string) $row[$k]] = ($out[(string) $row[$k]] ?? 0) + 1;
                }
                ksort($out);
                return $out;
            };
            if ($t === 'youth') {
                $same("{$label}_rows_status", $countBy('status'), rp_map(array_filter(['active' => $api['totals']['active'], 'archived' => $api['totals']['archived']])));
            } elseif ($t === 'programs') {
                $same("{$label}_rows_registrations_sum", $sum('registrations'), $api['totals']['registrations']);
                $same("{$label}_rows_present_sum", $sum('present'), $api['totals']['presentAttendance']);
                $same("{$label}_rows_kind", $countBy('kind'), rp_map($api['series']['byKind']));
            } elseif ($t === 'assistance') {
                $same("{$label}_rows_beneficiaries_sum", $sum('beneficiaries'), $api['totals']['beneficiaries']);
                $same("{$label}_rows_awarded_sum", $sum('awarded'), $api['totals']['awarded']);
            } elseif ($t === 'applications') {
                $same("{$label}_rows_target", $countBy('target_type'), rp_map(array_filter($api['series']['byTarget'])));
                $same("{$label}_rows_status", $countBy('status'), rp_map($api['series']['byStatus']));
            } else {
                $same("{$label}_rows_status", $countBy('status'), rp_map($api['series']['byStatus']));
            }
        }
    }
}

// ---- exports (sk1): headers, CSV parse, XLSX zip parse, consistency -------------

$sheetNames = ['youth' => 'Youth Report', 'programs' => 'Programs Report', 'assistance' => 'Assistance Report',
    'applications' => 'Applications Report', 'attendance' => 'Attendance Report'];
$titles = ['youth' => 'Youth Report', 'programs' => 'Programs and Events Report', 'assistance' => 'Assistance Report',
    'applications' => 'Applications Report', 'attendance' => 'Attendance Report'];
$parsedCsv = [];
$parsedXlsx = [];
$noPath = static fn (string $body): bool => !preg_match('#(/workspace|/var/www|htdocs|[A-Z]:\\\\|\.php\b)#i', $body);

foreach (RP_TYPES as $t) {
    $json = $reports['sk1'][$t];
    $columns = $json['export']['columns'] ?? [];
    $sql = $sqlReport($t, $org1);

    $r = rp_req('GET', "$base/sk/reports/{$t}/csv", null, $cookies['sk1']);
    $check("csv_{$t}_200", $r['status'] === 200, (string) $r['status']);
    $same("csv_{$t}_content_type", $r['headers']['content-type'] ?? null, 'text/csv; charset=utf-8');
    $same("csv_{$t}_disposition", $r['headers']['content-disposition'] ?? null, "attachment; filename=\"{$t}_report_{$manilaToday}.csv\"");
    $same("csv_{$t}_content_length", (int) ($r['headers']['content-length'] ?? -1), strlen($r['body']));
    $check("csv_{$t}_no_store", str_contains($r['headers']['cache-control'] ?? '', 'no-store'));
    $check("csv_{$t}_nosniff", ($r['headers']['x-content-type-options'] ?? '') === 'nosniff');
    $check("csv_{$t}_starts_with_bom", str_starts_with($r['body'], "\xEF\xBB\xBF") && !str_starts_with(substr($r['body'], 3), "\xEF\xBB\xBF"));
    $check("csv_{$t}_not_json", $r['data'] === [] && !str_contains($r['body'], '"success"'));
    $check("csv_{$t}_crlf_rows", substr_count($r['body'], "\r\n") >= 1 && str_ends_with($r['body'], "\r\n"));
    $check("csv_{$t}_no_paths_or_warnings", $noPath($r['body']) && !preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $r['body']));
    $rows = rp_csv($r['body']);
    $parsedCsv[$t] = $rows;
    $same("csv_{$t}_header", $rows[0] ?? null, $columns);
    $same("csv_{$t}_row_count", count($rows) - 1, $sql['totals']['total']);
    $same("csv_{$t}_rows_equal_json_rowCount", count($rows) - 1, $json['export']['rowCount'] ?? -1);
    $idCol = $t === 'youth' ? array_search('Code', $columns, true) : array_search('ID', $columns, true);
    $same("csv_{$t}_ids_match_sql", array_map(static fn ($row) => $row[$idCol] ?? null, array_slice($rows, 1)), $sql['ids']);
    $check("csv_{$t}_uniform_width", array_reduce(array_slice($rows, 1), static fn ($ok, $row) => $ok && count($row) === count($columns), true));
    foreach ($columns as $h) {
        $check("csv_{$t}_no_sensitive_header " . $h, !preg_match('/income|password|token|e-?mail|hash/i', $h));
    }
    $check("csv_{$t}_no_password_hash", !str_contains($r['body'], '$2y$'));

    $x = rp_req('GET', "$base/sk/reports/{$t}/xlsx", null, $cookies['sk1']);
    $check("xlsx_{$t}_200", $x['status'] === 200, (string) $x['status']);
    $same("xlsx_{$t}_content_type", $x['headers']['content-type'] ?? null, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $same("xlsx_{$t}_disposition", $x['headers']['content-disposition'] ?? null, "attachment; filename=\"{$t}_report_{$manilaToday}.xlsx\"");
    $same("xlsx_{$t}_content_length", (int) ($x['headers']['content-length'] ?? -1), strlen($x['body']));
    $check("xlsx_{$t}_zip_signature_first_byte", str_starts_with($x['body'], "PK\x03\x04"));
    $book = rp_xlsx($x['body']);
    $parsedXlsx[$t] = $book;
    $check("xlsx_{$t}_opens_as_zip", $book['ok'], $book['error'] . ' ' . json_encode($book['files']));
    $check("xlsx_{$t}_crc_ok", $book['crcOk']);
    $same("xlsx_{$t}_sheet_name", $book['sheetName'], $sheetNames[$t]);
    $check("xlsx_{$t}_title_row", str_starts_with((string) ($book['rows'][0][0] ?? ''), $titles[$t]), (string) ($book['rows'][0][0] ?? ''));
    $check("xlsx_{$t}_generated_row", str_starts_with((string) ($book['rows'][1][0] ?? ''), 'Generated ' . $manilaToday), (string) ($book['rows'][1][0] ?? ''));
    $same("xlsx_{$t}_header_row", array_values($book['rows'][3] ?? []), $columns);
    $dataRows = array_slice($book['rows'], 4, null, false);
    $same("xlsx_{$t}_data_row_count", count($dataRows), $sql['totals']['total']);
    $check("xlsx_{$t}_shared_strings", $book['sstCount'] > 0);
    $check("xlsx_{$t}_no_formulas", !$book['hasFormula']);
    // Every XLSX value equals the CSV value (CSV adds the ' guard to text that starts with = + - @).
    $mismatch = '';
    foreach ($dataRows as $ri => $xrow) {
        $crow = $rows[$ri + 1] ?? [];
        foreach ($columns as $ci => $_) {
            $xv = $xrow[$ci] ?? '';
            $xs = is_float($xv) ? rtrim(rtrim(sprintf('%.6F', $xv), '0'), '.') : (string) $xv;
            $cv = $crow[$ci] ?? '';
            if ($xs !== $cv && "'" . $xs !== $cv) {
                $mismatch = "row {$ri} col {$ci}: xlsx=" . json_encode($xs) . ' csv=' . json_encode($cv);
                break 2;
            }
        }
    }
    $check("xlsx_{$t}_values_equal_csv", $mismatch === '', $mismatch);
}

// numeric cells are numbers in XLSX
$progCols = $reports['sk1']['programs']['export']['columns'] ?? [];
$idIdx = array_search('ID', $progCols, true);
if (count($parsedXlsx['programs']['rows'] ?? []) > 4) {
    $check('xlsx_programs_id_is_numeric_cell', ($parsedXlsx['programs']['cellTypes'][4][$idIdx] ?? '') === 'n' && is_int($parsedXlsx['programs']['rows'][4][$idIdx] ?? null));
}
$youthCols = $reports['sk1']['youth']['export']['columns'] ?? [];
$contactIdx = array_search('Contact number', $youthCols, true);
if (count($parsedXlsx['youth']['rows'] ?? []) > 4) {
    $check('xlsx_youth_contact_kept_as_text', ($parsedXlsx['youth']['cellTypes'][4][$contactIdx] ?? '') === 's');
}
$emails = $col("SELECT email FROM youth WHERE organization_id = :o AND email IS NOT NULL AND email <> ''", ['o' => $org1]);
$leak = array_filter($emails, static fn ($e) => str_contains(implode("\n", array_map(static fn ($r) => implode(',', $r), $parsedCsv['youth'])), $e));
$check('youth_export_has_no_email_values', $leak === [], json_encode(array_values($leak)));
$check('youth_export_has_no_income_column', !in_array('Family income', $youthCols, true));

// ---- export security: spoofing, isolation, unknown type, invalid filter, empty -------

$spoofHeaders = ['X-Organization-Id: ' . $org1, 'X-Org-Id: ' . $org1, 'X-User-Id: 1', 'X-Role: system_admin', 'X-Tenant-Id: ' . $org1];
$spoofQuery = "organization_id={$org1}&orgId={$org1}&organizationId={$org1}&org_id={$org1}&user_id=1&userId=1&role=system_admin";
$spoofBody = json_encode(['organization_id' => $org1, 'orgId' => $org1, 'user_id' => 1, 'role' => 'system_admin']);
$org1Codes = $col('SELECT code FROM youth WHERE organization_id = :o', ['o' => $org1]);
foreach (RP_TYPES as $t) {
    $sql2 = $sqlReport($t, $org2);
    $r = rp_req('GET', "$base/sk/reports/{$t}?{$spoofQuery}", $spoofBody, $cookies['sk2'], $spoofHeaders);
    $compare("spoof_sk2_json_{$t}", $t, rp_data($r), $sql2);
    $c = rp_req('GET', "$base/sk/reports/{$t}/csv?{$spoofQuery}", $spoofBody, $cookies['sk2'], $spoofHeaders);
    $crow = rp_csv($c['body']);
    $same("spoof_sk2_csv_{$t}_rows_are_org2", count($crow) - 1, $sql2['totals']['total']);
    $x = rp_req('GET', "$base/sk/reports/{$t}/xlsx?{$spoofQuery}", $spoofBody, $cookies['sk2'], $spoofHeaders);
    $book = rp_xlsx($x['body']);
    $same("spoof_sk2_xlsx_{$t}_rows_are_org2", count(array_slice($book['rows'], 4)), $sql2['totals']['total']);
    if ($t === 'youth') {
        $leaked = array_filter($org1Codes, static fn ($code) => str_contains($c['body'], (string) $code) || str_contains(json_encode($book['rows']), (string) $code));
        $check('spoof_sk2_youth_no_org1_codes', $org1Codes === [] || $leaked === [] || $org1 === $org2, json_encode(array_values($leaked)));
    }
    // sk1 spoofing another org stays on org1.
    $other = $orgsWithData !== [] ? max($orgsWithData) : $org2;
    $r1 = rp_req('GET', "$base/sk/reports/{$t}/csv?organization_id={$other}&orgId={$other}", json_encode(['organization_id' => $other]), $cookies['sk1'], ['X-Organization-Id: ' . $other]);
    $same("spoof_sk1_csv_{$t}_rows_are_org1", count(rp_csv($r1['body'])) - 1, $sqlReport($t, $org1)['totals']['total']);
}

foreach (['/sk/reports/unknown/csv', '/sk/reports/subscription/xlsx', '/sk/reports/youth/pdf', '/sk/reports/youth/json', '/sk/reports/../youth/csv'] as $path) {
    $r = rp_req('GET', $base . $path, null, $cookies['sk1']);
    $check("unknown_export_404 {$path}", $r['status'] === 404 && ($r['data']['success'] ?? null) === false && !isset($r['headers']['content-disposition']) && $noPath($r['body']), $r['status'] . ' ' . $r['body']);
}
$r = rp_req('GET', "$base/sk/reports?type=bogus", null, $cookies['sk1']);
$check('json_unknown_type_422', $r['status'] === 422, (string) $r['status']);
$r = rp_req('GET', "$base/sk/reports", null, $cookies['sk1']);
$check('json_missing_type_422', $r['status'] === 422, (string) $r['status']);
foreach ([
    'youth/csv?status=deleted', 'programs/xlsx?kind=meeting', 'assistance/csv?category=cash', 'applications/csv?status=bogus',
    'attendance/xlsx?status=late', 'youth/csv?from=2026-13-01', 'youth/xlsx?from=2026-10-10&to=2026-10-01',
] as $path) {
    $r = rp_req('GET', "$base/sk/reports/{$path}", null, $cookies['sk1']);
    $check("export_invalid_filter_422 {$path}", $r['status'] === 422 && ($r['data']['error']['code'] ?? '') === 'VALIDATION_ERROR'
        && !isset($r['headers']['content-disposition']) && str_starts_with($r['headers']['content-type'] ?? '', 'application/json'), $r['status'] . ' ' . $r['body']);
}

foreach (RP_TYPES as $t) {
    $r = rp_req('GET', "$base/sk/reports/{$t}?from=2099-01-01&to=2099-12-31", null, $cookies['sk1']);
    $same("empty_json_{$t}_total", rp_data($r)['totals']['total'] ?? null, 0);
    $c = rp_req('GET', "$base/sk/reports/{$t}/csv?from=2099-01-01&to=2099-12-31", null, $cookies['sk1']);
    $rows = rp_csv($c['body']);
    $check("empty_csv_{$t}_header_only", $c['status'] === 200 && count($rows) === 1 && $rows[0] === (rp_data($r)['export']['columns'] ?? null));
    $x = rp_req('GET', "$base/sk/reports/{$t}/xlsx?from=2099-01-01&to=2099-12-31", null, $cookies['sk1']);
    $book = rp_xlsx($x['body']);
    $check("empty_xlsx_{$t}_header_only", $x['status'] === 200 && $book['ok'] && count($book['rows']) === 4
        && array_values($book['rows'][3] ?? []) === (rp_data($r)['export']['columns'] ?? null));
}
$sk2Empty = array_sum(array_map(static fn ($t) => $sqlReport($t, $org2)['totals']['total'], RP_TYPES)) === 0;
if ($sk2Empty) {
    foreach (RP_TYPES as $t) {
        $c = rp_req('GET', "$base/sk/reports/{$t}/csv", null, $cookies['sk2']);
        $check("sk2_empty_org_csv_{$t}_header_only", count(rp_csv($c['body'])) === 1);
    }
}

// Plan: XLSX needs a plan with excel_export; CSV works on every plan.
if ($haveSk4) {
    $org4 = (int) ($me('sk4')['organization']['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT plan, sub_status FROM organizations WHERE id = :id');
    $stmt->execute(['id' => $org4]);
    $planRow = $stmt->fetch() ?: [];
    $excel = YouthSync\Org\PlanLimits::effective($planRow)['excel_export'];
    $x = rp_req('GET', "$base/sk/reports/youth/xlsx", null, $cookies['sk4']);
    $check('sk4_xlsx_plan_rule', $excel ? $x['status'] === 200 : ($x['status'] === 403 && ($x['data']['error']['code'] ?? '') === 'PLAN_FEATURE_UNAVAILABLE'), $x['status'] . ' ' . substr($x['body'], 0, 200));
    $c = rp_req('GET', "$base/sk/reports/youth/csv", null, $cookies['sk4']);
    $check('sk4_csv_allowed', $c['status'] === 200 && str_starts_with($c['body'], "\xEF\xBB\xBF"));
    $sub = rp_data(rp_req('GET', "$base/sk/subscription", null, $cookies['sk4']));
    $same('sk4_subscription_excel_flag', $sub['features']['excelExport'] ?? null, $excel);
} else {
    echo "SKIP sk4 plan checks (sk4@demo.test could not sign in)\n";
}
$sub1 = rp_data(rp_req('GET', "$base/sk/subscription", null, $cookies['sk1']));
$check('sk1_subscription_has_excel_flag', is_bool($sub1['features']['excelExport'] ?? null));

// ---- escaping + create → delete cycle -------------------------------------------

$created = ['youth' => [], 'programs' => []];
$base1 = ['youth' => rp_data(rp_req('GET', "$base/sk/reports/youth", null, $cookies['sk1'])),
    'programs' => rp_data(rp_req('GET', "$base/sk/reports/programs", null, $cookies['sk1']))];
$baseCsv = ['youth' => count(rp_csv(rp_req('GET', "$base/sk/reports/youth/csv", null, $cookies['sk1'])['body'])),
    'programs' => count(rp_csv(rp_req('GET', "$base/sk/reports/programs/csv", null, $cookies['sk1'])['body']))];

$escFirst = '=SUM(1,2) ' . RP_TAG;
$escMiddle = '@cmd';
$escLast = 'Quote "' . RP_TAG . '", Jr.';
$escAddress = "Purok 1, \"Riverside\"\nSecond line";
$escGuardian = '+63 Guardian -' . RP_TAG;
$r = rp_req('POST', "$base/sk/youth?organization_id={$org2}", json_encode([
    'firstName' => $escFirst,
    'middleName' => $escMiddle,
    'lastName' => $escLast,
    'birthDate' => date('Y-m-d', strtotime('-19 years -40 days')),
    'gender' => 'Female',
    'address' => $escAddress,
    'contact' => '0917' . random_int(1000000, 9999999),
    'interests' => ['Sports'],
    'employment' => 'Unemployed',
    'educationStatus' => 'Not Currently Studying',
    'education' => 'College',
    'guardianName' => $escGuardian,
    'organization_id' => $org2,
]), $cookies['sk1']);
$youthId = (int) (rp_data($r)['youth']['id'] ?? rp_data($r)['id'] ?? 0);
if ($youthId > 0) {
    $created['youth'][] = $youthId;
}
$check('esc_temp_youth_created', $r['status'] === 201 && $youthId > 0, substr($r['body'], 0, 300));
$check('esc_temp_youth_in_session_org', $one('SELECT organization_id FROM youth WHERE id = :id', ['id' => $youthId]) === $org1);
$stmt = $pdo->prepare('SELECT code, first_name, last_name, address FROM youth WHERE id = :id');
$stmt->execute(['id' => $youthId]);
$tempRow = $stmt->fetch() ?: [];
$same('esc_db_kept_newline_address', $tempRow['address'] ?? null, $escAddress);
$tempCode = (string) ($tempRow['code'] ?? '');

$afterYouth = rp_data(rp_req('GET', "$base/sk/reports/youth", null, $cookies['sk1']));
$same('cycle_youth_total_plus_1', $afterYouth['totals']['total'] ?? null, ($base1['youth']['totals']['total'] ?? 0) + 1);
$same('cycle_youth_rowCount_plus_1', $afterYouth['export']['rowCount'] ?? null, ($base1['youth']['export']['rowCount'] ?? 0) + 1);
$compare('cycle_youth_vs_sql', 'youth', $afterYouth, $sqlReport('youth', $org1));

$c = rp_req('GET', "$base/sk/reports/youth/csv", null, $cookies['sk1']);
$rows = rp_csv($c['body']);
$same('cycle_youth_csv_rows_plus_1', count($rows), $baseCsv['youth'] + 1);
$h = $rows[0] ?? [];
$tempCsv = null;
foreach (array_slice($rows, 1) as $row) {
    if (($row[array_search('Code', $h, true)] ?? null) === $tempCode) {
        $tempCsv = array_combine($h, $row);
    }
}
$check('esc_csv_row_found', is_array($tempCsv));
$same('esc_csv_first_name_guarded', $tempCsv['First name'] ?? null, "'" . $escFirst);
$same('esc_csv_middle_name_guarded', $tempCsv['Middle name'] ?? null, "'" . $escMiddle);
$same('esc_csv_last_name_quotes_commas', $tempCsv['Last name'] ?? null, $escLast);
$same('esc_csv_address_newline', $tempCsv['Address'] ?? null, $escAddress);
$same('esc_csv_guardian_guarded', $tempCsv['Guardian'] ?? null, "'" . $escGuardian);
$check('esc_csv_raw_quote_doubling', str_contains($c['body'], '"Quote ""' . RP_TAG . '"", Jr."'));
$check('esc_csv_raw_guarded_formula_quoted', str_contains($c['body'], "\"'=SUM(1,2) " . RP_TAG . '"'));
$check('esc_csv_raw_newline_inside_quotes', str_contains($c['body'], "\"Purok 1, \"\"Riverside\"\"\nSecond line\""));
$check('esc_csv_no_unguarded_formula_cell', !preg_match('/(^|,)=SUM/m', $c['body']));

$x = rp_req('GET', "$base/sk/reports/youth/xlsx", null, $cookies['sk1']);
$book = rp_xlsx($x['body']);
$tempX = null;
$tempXTypes = null;
foreach ($book['rows'] as $ri => $row) {
    if ($ri >= 4 && ($row[array_search('Code', $h, true)] ?? null) === $tempCode) {
        $tempX = $row;
        $tempXTypes = $book['cellTypes'][$ri];
    }
}
$check('esc_xlsx_row_found', is_array($tempX));
$same('esc_xlsx_first_name_plain_text', $tempX[array_search('First name', $h, true)] ?? null, $escFirst);
$same('esc_xlsx_first_name_is_shared_string', $tempXTypes[array_search('First name', $h, true)] ?? null, 's');
$same('esc_xlsx_last_name', $tempX[array_search('Last name', $h, true)] ?? null, $escLast);
$same('esc_xlsx_address_newline', $tempX[array_search('Address', $h, true)] ?? null, $escAddress);
$check('esc_xlsx_no_formula_elements', !$book['hasFormula']);
$same('cycle_youth_xlsx_rows_plus_1', count($book['rows']) - 4, $baseCsv['youth']);

$r = rp_req('POST', "$base/sk/programs", json_encode([
    'name' => RP_TAG . ' Program, "export" check',
    'category' => 'Sports',
    'status' => 'draft',
    'scheduledOn' => date('Y-m-d', strtotime('+5 days')),
    'location' => 'Hall A, 2nd floor',
    'organization_id' => $org2,
]), $cookies['sk1']);
$programId = (int) (rp_data($r)['id'] ?? 0);
if ($programId > 0) {
    $created['programs'][] = $programId;
}
$check('cycle_temp_program_created', $r['status'] === 201 && $programId > 0, substr($r['body'], 0, 300));
$afterProg = rp_data(rp_req('GET', "$base/sk/reports/programs", null, $cookies['sk1']));
$same('cycle_programs_total_plus_1', $afterProg['totals']['total'] ?? null, ($base1['programs']['totals']['total'] ?? 0) + 1);
$same('cycle_programs_kind_program_plus_1', $afterProg['totals']['programs'] ?? null, ($base1['programs']['totals']['programs'] ?? 0) + 1);
$same('cycle_programs_draft_plus_1', $afterProg['series']['byStatus']['draft'] ?? 0, ($base1['programs']['series']['byStatus']['draft'] ?? 0) + 1);
$draftOnly = rp_data(rp_req('GET', "$base/sk/reports/programs?status=draft", null, $cookies['sk1']));
$compare('cycle_programs_draft_filter_vs_sql', 'programs', $draftOnly, $sqlReport('programs', $org1, ['status' => 'draft']));
$pc = rp_csv(rp_req('GET', "$base/sk/reports/programs/csv", null, $cookies['sk1'])['body']);
$same('cycle_programs_csv_rows_plus_1', count($pc), $baseCsv['programs'] + 1);
$found = array_values(array_filter(array_slice($pc, 1), static fn ($row) => ($row[0] ?? '') === (string) $programId));
$same('cycle_programs_csv_has_new_row_name', $found[0][2] ?? null, RP_TAG . ' Program, "export" check');
$px = rp_xlsx(rp_req('GET', "$base/sk/reports/programs/xlsx?status=draft", null, $cookies['sk1'])['body']);
$same('cycle_programs_xlsx_draft_rows', count($px['rows']) - 4, $draftOnly['totals']['total'] ?? -1);

foreach ($created['programs'] as $id) {
    $r = rp_req('DELETE', "$base/sk/programs/{$id}", null, $cookies['sk1']);
    $check("cleanup_program_{$id}", $r['status'] === 200 && $one('SELECT COUNT(*) FROM programs WHERE id = :id', ['id' => $id]) === 0, (string) $r['status']);
}
foreach ($created['youth'] as $id) {
    $r = rp_req('DELETE', "$base/sk/youth/{$id}", null, $cookies['sk1']);
    $check("cleanup_youth_{$id}", $r['status'] === 200 && $one('SELECT COUNT(*) FROM youth WHERE id = :id', ['id' => $id]) === 0, (string) $r['status']);
}
$finalYouth = rp_data(rp_req('GET', "$base/sk/reports/youth", null, $cookies['sk1']));
$finalProg = rp_data(rp_req('GET', "$base/sk/reports/programs", null, $cookies['sk1']));
$same('cycle_youth_restored', $finalYouth['totals'] ?? null, $base1['youth']['totals'] ?? null);
$same('cycle_youth_series_restored', $finalYouth['series'] ?? null, $base1['youth']['series'] ?? null);
$same('cycle_programs_restored', $finalProg['totals'] ?? null, $base1['programs']['totals'] ?? null);
$same('cycle_youth_csv_rows_restored', count(rp_csv(rp_req('GET', "$base/sk/reports/youth/csv", null, $cookies['sk1'])['body'])), $baseCsv['youth']);
$same('cycle_programs_csv_rows_restored', count(rp_csv(rp_req('GET', "$base/sk/reports/programs/csv", null, $cookies['sk1'])['body'])), $baseCsv['programs']);

$same('no_applications_created', $one('SELECT COUNT(*) FROM applications WHERE id > :id', ['id' => $maxApplicationBefore]), 0);
$same('no_notifications_created', $one('SELECT COUNT(*) FROM notifications WHERE id > :id', ['id' => $maxNotificationBefore]), 0);
$same('no_sms_created', $one('SELECT COUNT(*) FROM sms_deliveries WHERE id > :id', ['id' => $maxSmsBefore]), 0);
$same('no_email_created', $one('SELECT COUNT(*) FROM email_deliveries WHERE id > :id', ['id' => $maxEmailBefore]), 0);
$activityIds = array_map('intval', $pdo->query("SELECT id FROM activity_logs WHERE id > {$maxActivityBefore} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

foreach ($cookies as $file) {
    @unlink($file);
}

echo "\nTemporary ids (deletion is checked by the cleanup_* lines above): youth " . json_encode($created['youth'])
    . ', programs ' . json_encode($created['programs']) . "\n";
echo 'activity_logs rows written by this run (audit trail, kept): '
    . ($activityIds === [] ? 'none' : min($activityIds) . '-' . max($activityIds) . ' (' . count($activityIds) . ')') . "\n";
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
