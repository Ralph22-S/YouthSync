<?php
declare(strict_types=1);

namespace YouthSync\Reports;

/**
 * RFC 4180 CSV for report exports.
 *
 * - UTF-8 with a byte order mark so Excel detects the encoding.
 * - CRLF row endings; fields containing a comma, quote, CR or LF are quoted
 *   and embedded quotes are doubled.
 * - Formula-injection guard: a text cell that starts with = + - @ (or a tab /
 *   carriage return) is prefixed with a single quote so spreadsheet apps treat
 *   it as text. Numeric columns are written as plain numbers and never prefixed.
 */
final class CsvWriter
{
    public const BOM = "\xEF\xBB\xBF";

    /**
     * @param list<array{key:string,label:string,type:string}> $columns
     * @param list<array<string, mixed>> $rows
     */
    public static function build(array $columns, array $rows): string
    {
        $out = self::BOM;
        $out .= self::line(array_map(static fn (array $c): string => self::text($c['label']), $columns));
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = self::cell($row[$column['key']] ?? null, $column['type']);
            }
            $out .= self::line($cells);
        }
        return $out;
    }

    /**
     * @param list<string> $cells
     */
    private static function line(array $cells): string
    {
        return implode(',', $cells) . "\r\n";
    }

    private static function cell(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (($type === 'int' || $type === 'number') && (is_int($value) || is_float($value) || is_numeric($value))) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        return self::text((string) $value);
    }

    public static function text(string $value): string
    {
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            $value = "'" . $value;
        }
        if (strpbrk($value, ",\"\r\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
