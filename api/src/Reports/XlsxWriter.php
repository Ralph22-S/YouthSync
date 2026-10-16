<?php
declare(strict_types=1);

namespace YouthSync\Reports;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Minimal, dependency-free XLSX (Office Open XML SpreadsheetML) writer.
 *
 * Produces one worksheet: a bold title row, a "Generated" row, an optional
 * filter summary row, a bold header row (frozen, with an auto filter) and the
 * data rows. Text goes into the shared string table; numeric columns are real
 * numeric cells. Strings are never written as formulas, so a value beginning
 * with "=" stays plain text in the workbook.
 *
 * The ZIP container is written here (local headers, central directory, end
 * record, CRC-32). Entries are deflated with gzdeflate() when zlib is present
 * and stored otherwise, so neither ZipArchive nor Composer is required.
 * Compatible with PHP 8.2+.
 */
final class XlsxWriter
{
    /** @var array<string, int> */
    private array $stringIndex = [];
    /** @var list<string> */
    private array $strings = [];
    private int $stringRefs = 0;
    private ?string $filterRef = null;

    /**
     * @param list<array{key:string,label:string,type:string}> $columns
     * @param list<array<string, mixed>> $rows
     * @param list<string> $preamble extra lines under the title (e.g. generated time, filters)
     */
    public static function build(string $sheetName, string $title, array $preamble, array $columns, array $rows): string
    {
        $writer = new self();
        $sheet = $writer->sheetXml($title, $preamble, $columns, $rows);
        $name = self::sheetName($sheetName);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $files = [
            '[Content_Types].xml' => self::contentTypes(),
            '_rels/.rels' => self::rootRels(),
            'docProps/core.xml' => self::coreProps($title, $now),
            'docProps/app.xml' => self::appProps($name),
            'xl/workbook.xml' => self::workbook($name, $writer->filterRef),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/styles.xml' => self::styles(),
            'xl/sharedStrings.xml' => $writer->sharedStringsXml(),
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        return self::zip($files, new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')));
    }

    /**
     * Excel sheet names: max 31 chars, none of : \ / ? * [ ].
     */
    public static function sheetName(string $name): string
    {
        $clean = trim((string) preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', $name));
        if ($clean === '') {
            $clean = 'Report';
        }
        return mb_substr($clean, 0, 31);
    }

    /**
     * @param list<string> $preamble
     * @param list<array{key:string,label:string,type:string}> $columns
     * @param list<array<string, mixed>> $rows
     */
    private function sheetXml(string $title, array $preamble, array $columns, array $rows): string
    {
        $colCount = max(1, count($columns));
        $xmlRows = [];
        $r = 1;
        $xmlRows[] = '<row r="' . $r . '"><c r="A' . $r . '" t="s" s="1"><v>' . $this->sst($title) . '</v></c></row>';
        $r++;
        foreach ($preamble as $line) {
            $xmlRows[] = '<row r="' . $r . '"><c r="A' . $r . '" t="s" s="3"><v>' . $this->sst($line) . '</v></c></row>';
            $r++;
        }
        $headerRow = $r;
        $cells = '';
        foreach ($columns as $i => $column) {
            $cells .= '<c r="' . self::col($i) . $r . '" t="s" s="2"><v>' . $this->sst($column['label']) . '</v></c>';
        }
        $xmlRows[] = '<row r="' . $r . '">' . $cells . '</row>';
        $r++;

        $widths = array_map(static fn (array $c): int => max(8, min(60, mb_strlen($c['label']) + 2)), $columns);
        foreach ($rows as $row) {
            $cells = '';
            foreach ($columns as $i => $column) {
                $value = $row[$column['key']] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $ref = self::col($i) . $r;
                if (($column['type'] === 'int' || $column['type'] === 'number') && is_numeric($value)) {
                    $num = $column['type'] === 'int' ? (string) (int) $value : self::number($value);
                    $cells .= '<c r="' . $ref . '"><v>' . $num . '</v></c>';
                    $len = strlen($num);
                } else {
                    $text = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
                    $cells .= '<c r="' . $ref . '" t="s"><v>' . $this->sst($text) . '</v></c>';
                    $len = mb_strlen($text);
                }
                $widths[$i] = max($widths[$i], min(60, $len + 2));
            }
            $xmlRows[] = '<row r="' . $r . '">' . $cells . '</row>';
            $r++;
        }
        $lastRow = max($headerRow, $r - 1);
        $lastCol = self::col($colCount - 1);
        $this->filterRef = $columns !== [] ? '$A$' . $headerRow . ':$' . $lastCol . '$' . $lastRow : null;

        $cols = '';
        foreach ($widths as $i => $width) {
            $n = $i + 1;
            $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . $width . '" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="A1:' . $lastCol . $lastRow . '"/>'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . implode('', $xmlRows) . '</sheetData>'
            . ($columns !== [] ? '<autoFilter ref="A' . $headerRow . ':' . $lastCol . $lastRow . '"/>' : '')
            . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            . '</worksheet>';
    }

    private function sst(string $value): int
    {
        $this->stringRefs++;
        $value = self::cleanText($value);
        if (!isset($this->stringIndex[$value])) {
            $this->stringIndex[$value] = count($this->strings);
            $this->strings[] = $value;
        }
        return $this->stringIndex[$value];
    }

    private function sharedStringsXml(): string
    {
        $items = '';
        foreach ($this->strings as $s) {
            $preserve = ($s !== trim($s) || strpbrk($s, "\n\r\t") !== false) ? ' xml:space="preserve"' : '';
            $items .= '<si><t' . $preserve . '>' . self::esc($s) . '</t></si>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $this->stringRefs
            . '" uniqueCount="' . count($this->strings) . '">' . $items . '</sst>';
    }

    /** Remove characters XML 1.0 cannot carry (keeps tab, LF, CR). */
    private static function cleanText(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function number(mixed $value): string
    {
        $f = (float) $value;
        if (floor($f) === $f && abs($f) < 1e15) {
            return (string) (int) $f;
        }
        return rtrim(rtrim(sprintf('%.6F', $f), '0'), '.');
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    public static function col(int $index): string
    {
        $name = '';
        $n = $index + 1;
        while ($n > 0) {
            $mod = ($n - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $n = intdiv($n - 1, 26);
        }
        return $name;
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function coreProps(string $title, DateTimeImmutable $nowUtc): string
    {
        $stamp = $nowUtc->format('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::esc(self::cleanText($title)) . '</dc:title>'
            . '<dc:creator>YouthSync</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $stamp . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $stamp . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function appProps(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>YouthSync</Application>'
            . '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>' . self::esc($sheetName) . '</vt:lpstr></vt:vector></TitlesOfParts>'
            . '</Properties>';
    }

    private static function workbook(string $sheetName, ?string $filterRef): string
    {
        $defined = $filterRef === null ? '' : '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
            . self::esc("'" . str_replace("'", "''", $sheetName) . "'!" . $filterRef) . '</definedName></definedNames>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView/></bookViews>'
            . '<sheets><sheet name="' . self::esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . $defined
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
    }

    /** Style 0 normal, 1 title (bold 14), 2 header (bold, grey fill, bottom border), 3 muted note. */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF555555"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE7ECF2"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left/><right/><top/><bottom style="thin"><color rgb="FF8391A4"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * @param array<string, string> $files name => contents
     */
    private static function zip(array $files, DateTimeImmutable $localTime): string
    {
        $dosTime = (((int) $localTime->format('G') & 0x1F) << 11) | (((int) $localTime->format('i') & 0x3F) << 5)
            | ((int) floor((int) $localTime->format('s') / 2) & 0x1F);
        $dosDate = ((((int) $localTime->format('Y') - 1980) & 0x7F) << 9) | (((int) $localTime->format('n') & 0x0F) << 5)
            | ((int) $localTime->format('j') & 0x1F);
        $canDeflate = function_exists('gzdeflate');

        $body = '';
        $central = '';
        $count = 0;
        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $size = strlen($data);
            $method = 0;
            $payload = $data;
            if ($canDeflate) {
                $deflated = gzdeflate($data, 6);
                if ($deflated !== false && strlen($deflated) < $size) {
                    $method = 8;
                    $payload = $deflated;
                }
            }
            $compressed = strlen($payload);
            $offset = strlen($body);
            $nameLen = strlen($name);

            $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, $dosTime, $dosDate, $crc, $compressed, $size, $nameLen, 0)
                . $name . $payload;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, $dosTime, $dosDate, $crc, $compressed, $size,
                $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
            $count++;
        }

        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($body), 0);
        return $body . $central . $end;
    }
}
