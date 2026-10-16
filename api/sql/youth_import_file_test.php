<?php
declare(strict_types=1);

/**
 * Parser-only check for youth CSV and Excel import files.
 * Does not insert youth records.
 */

if (PHP_SAPI !== 'cli') {
    exit("Forbidden\n");
}

require dirname(__DIR__) . '/config/config.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'YouthSync\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$failed = 0;
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

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ys-youth-import-' . bin2hex(random_bytes(4));
mkdir($dir);
$csv = $dir . DIRECTORY_SEPARATOR . 'youth.csv';
file_put_contents($csv, "title row\nfirst_name,last_name,birth_date,gender,address,contact_number\n\"Ana, Mae\",Reyes,2004-05-06,Female,\"12 Main St\",09171234567\n");
$rows = YouthSync\Youth\YouthImportFile::parsePath($csv, 'csv');
$check('csv_row', count($rows) === 1 && $rows[0]['firstName'] === 'Ana, Mae' && $rows[0]['contact'] === '09171234567' && $rows[0]['birthDate'] === '2004-05-06', json_encode($rows));

$xlsx = $dir . DIRECTORY_SEPARATOR . 'youth.xlsx';
$zip = new ZipArchive();
$zip->open($xlsx, ZipArchive::CREATE);
$zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>first_name</t></si><si><t>last_name</t></si><si><t>birth_date</t></si><si><t>gender</t></si><si><t>address</t></si><si><t>Luis</t></si><si><t>Santos</t></si><si><t>Male</t></si><si><t>Barangay Hall</t></si></sst>');
$zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
$zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
    . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c><c r="E1" t="s"><v>4</v></c></row>'
    . '<row r="2"><c r="A2" t="s"><v>5</v></c><c r="B2" t="s"><v>6</v></c><c r="C2"><v>44927</v></c><c r="D2" t="s"><v>7</v></c><c r="E2" t="s"><v>8</v></c></row>'
    . '</sheetData></worksheet>');
$zip->close();
$excel = YouthSync\Youth\YouthImportFile::parsePath($xlsx, 'xlsx');
$check('xlsx_row', count($excel) === 1 && $excel[0]['firstName'] === 'Luis' && $excel[0]['lastName'] === 'Santos' && $excel[0]['birthDate'] === '2023-01-01' && $excel[0]['address'] === 'Barangay Hall', json_encode($excel));

@unlink($csv);
@unlink($xlsx);
@rmdir($dir);

echo $failed === 0 ? "ALL PASSED\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
