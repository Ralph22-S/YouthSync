<?php
declare(strict_types=1);

namespace YouthSync\Reports;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Sends a fully built export file as an attachment. The bytes are generated
 * before any header is set, so a failure while building still returns the
 * normal JSON error; once headers are written nothing else is echoed.
 */
final class ReportDownload
{
    public const CSV_TYPE = 'text/csv; charset=utf-8';
    public const XLSX_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public static function manilaNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    }

    /** e.g. youth_report_2026-10-09.csv (Asia/Manila date). */
    public static function filename(string $type, string $extension): string
    {
        $safeType = preg_replace('/[^a-z]/', '', strtolower($type)) ?: 'report';
        return $safeType . '_report_' . self::manilaNow()->format('Y-m-d') . '.' . $extension;
    }

    public static function send(string $bytes, string $contentType, string $filename): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'report';
        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Expose-Headers: Content-Disposition');
        echo $bytes;
        exit;
    }
}
