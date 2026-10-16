<?php
declare(strict_types=1);

namespace YouthSync\Youth;

use YouthSync\Http\Json;
use ZipArchive;

/**
 * Reads a youth CSV or XLSX upload into the row shape YouthService::import already accepts.
 * The first sheet is used. A title row above the headers is skipped.
 */
final class YouthImportFile
{
    private const MAX_BYTES = 2097152;
    private const MAX_ROWS = 2000;

    /** @var array<string, string> */
    private const COLUMNS = [
        'first_name' => 'firstName',
        'middle_name' => 'middleName',
        'last_name' => 'lastName',
        'birth_date' => 'birthDate',
        'birthdate' => 'birthDate',
        'gender' => 'gender',
        'address' => 'address',
        'contact' => 'contact',
        'contact_number' => 'contact',
        'mobile' => 'contact',
        'phone' => 'contact',
        'email' => 'email',
        'gmail' => 'email',
        'education' => 'education',
        'school' => 'school',
        'family_income' => 'familyIncome',
        'family_members' => 'familyMembers',
        'interest' => 'interests',
        'interests' => 'interests',
    ];

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return list<array<string, mixed>>
     */
    public static function parse(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            Json::error('VALIDATION_ERROR', 'The import file could not be read.', 422);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Json::error('VALIDATION_ERROR', 'The import file could not be read.', 422);
        }
        if ($size < 1 || $size > self::MAX_BYTES) {
            Json::error('VALIDATION_ERROR', 'The import file must be a CSV or Excel file under 2 MB.', 422);
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return self::parsePath($tmp, $ext);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function parsePath(string $path, string $extension): array
    {
        $ext = strtolower($extension);
        if ($ext === 'csv' || $ext === 'txt') {
            $table = self::csv($path);
        } elseif ($ext === 'xlsx') {
            $table = self::xlsx($path);
        } else {
            Json::error('VALIDATION_ERROR', 'Upload a .csv or .xlsx file.', 422);
        }
        return self::rows($table);
    }

    /**
     * @param list<list<string>> $table
     * @return list<array<string, mixed>>
     */
    private static function rows(array $table): array
    {
        $headerIndex = null;
        $columns = [];
        $limit = min(count($table), 15);
        for ($i = 0; $i < $limit; $i++) {
            $mapped = [];
            foreach ($table[$i] as $index => $cell) {
                $key = self::headerKey($cell);
                if (isset(self::COLUMNS[$key])) {
                    $mapped[$index] = self::COLUMNS[$key];
                }
            }
            if (in_array('firstName', $mapped, true) && in_array('lastName', $mapped, true)) {
                $headerIndex = $i;
                $columns = $mapped;
                break;
            }
        }
        if ($headerIndex === null) {
            Json::error(
                'VALIDATION_ERROR',
                'The file needs a header row with first_name and last_name.',
                422
            );
        }

        $rows = [];
        for ($i = $headerIndex + 1; $i < count($table); $i++) {
            $line = $table[$i];
            if (self::blank($line)) {
                continue;
            }
            $row = [];
            foreach ($columns as $index => $field) {
                $value = trim((string) ($line[$index] ?? ''));
                if ($field === 'birthDate') {
                    $value = self::date($value);
                }
                if ($field === 'interests') {
                    $row[$field] = $value === '' ? [] : [$value];
                    continue;
                }
                $row[$field] = $value;
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) {
                Json::error('VALIDATION_ERROR', 'An import file can contain at most 2000 youth rows.', 422);
            }
        }
        if ($rows === []) {
            Json::error('VALIDATION_ERROR', 'The file has a header but no youth rows.', 422);
        }
        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    private static function csv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            Json::error('VALIDATION_ERROR', 'The CSV file could not be read.', 422);
        }
        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);
            Json::error('VALIDATION_ERROR', 'The CSV file is empty.', 422);
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($handle);
        $table = [];
        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $table[] = array_map(static fn ($cell) => trim((string) $cell), $cells);
        }
        fclose($handle);
        if ($table !== []) {
            $table[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $table[0][0]) ?? $table[0][0];
        }
        return $table;
    }

    /**
     * @return list<list<string>>
     */
    private static function xlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            Json::error('VALIDATION_ERROR', 'Excel import is not available on this server. Upload a CSV file instead.', 422);
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            Json::error('VALIDATION_ERROR', 'The Excel file could not be opened.', 422);
        }
        $shared = self::sharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: '');
        $sheetName = 'xl/worksheets/sheet1.xml';
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels') ?: '';
        if (preg_match('/Target="([^"]*worksheets\/[^"]+\.xml)"/', $rels, $match) === 1) {
            $target = str_replace('\\', '/', $match[1]);
            $sheetName = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . ltrim($target, '/');
        }
        $xml = $zip->getFromName($sheetName) ?: '';
        $zip->close();
        if ($xml === '') {
            Json::error('VALIDATION_ERROR', 'The Excel file has no worksheet.', 422);
        }
        $sheet = self::xml($xml);
        if ($sheet === null || !isset($sheet->sheetData)) {
            Json::error('VALIDATION_ERROR', 'The Excel worksheet could not be read.', 422);
        }
        $table = [];
        foreach ($sheet->sheetData->row as $row) {
            $line = [];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                $col = self::columnIndex($ref);
                $type = (string) $cell['t'];
                $value = '';
                if ($type === 's') {
                    $value = $shared[(int) $cell->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                } else {
                    $value = (string) $cell->v;
                }
                $line[$col] = $value;
            }
            if ($line !== []) {
                $max = max(array_keys($line));
                $filled = [];
                for ($i = 0; $i <= $max; $i++) {
                    $filled[] = $line[$i] ?? '';
                }
                $table[] = $filled;
            }
        }
        return $table;
    }

    private static function xml(string $xml): ?\SimpleXMLElement
    {
        $xml = preg_replace('/\sxmlns(:[A-Za-z0-9]+)?="[^"]*"/', '', $xml) ?? $xml;
        $doc = simplexml_load_string($xml);
        return $doc instanceof \SimpleXMLElement ? $doc : null;
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        $doc = self::xml($xml);
        if ($doc === null) {
            return [];
        }
        $strings = [];
        foreach ($doc->si as $item) {
            $text = '';
            if (isset($item->t)) {
                $text = (string) $item->t;
            }
            foreach ($item->r as $run) {
                $text .= (string) ($run->t ?? '');
            }
            $strings[] = $text;
        }
        return $strings;
    }

    private static function columnIndex(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?? '';
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }
        return max(0, $index - 1);
    }

    private static function headerKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        return preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
    }

    private static function date(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }
        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial > 20000 && $serial < 80000) {
                $days = (int) floor($serial);
                $base = new \DateTimeImmutable('1899-12-30');
                return $base->modify('+' . $days . ' days')->format('Y-m-d');
            }
        }
        $parsed = strtotime($value);
        return $parsed === false ? $value : date('Y-m-d', $parsed);
    }

    /**
     * @param list<string> $line
     */
    private static function blank(array $line): bool
    {
        foreach ($line as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
