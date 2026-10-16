<?php
declare(strict_types=1);

namespace YouthSync\Infra;

use RuntimeException;

/**
 * Reads the local application error file for system administrators.
 * There is no error-log table and no external error service.
 */
final class ErrorLog
{
    public static function file(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
    }

    /**
     * @return list<array{at:string,message:string}>
     */
    public function latest(int $limit = 50, ?string $path = null): array
    {
        $limit = max(1, min(100, $limit));
        $path = $path ?? self::file();
        if ($path !== self::file()) {
            $root = realpath(dirname(self::file()));
            $real = realpath($path);
            if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('That error log was not found.');
            }
            $path = $real;
        }
        if (!is_file($path)) {
            return [];
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $size = filesize($path);
        $window = 262144;
        if (is_int($size) && $size > $window) {
            fseek($handle, -$window, SEEK_END);
            fgets($handle);
        }
        $lines = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = self::redact($line);
            }
        }
        fclose($handle);
        $lines = array_slice($lines, -$limit);
        $rows = [];
        foreach (array_reverse($lines) as $line) {
            $at = '';
            $message = $line;
            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $match) === 1) {
                $at = $match[1];
                $message = $match[2];
            }
            $rows[] = ['at' => $at, 'message' => $message];
        }
        return $rows;
    }

    public static function redact(string $line): string
    {
        $line = preg_replace('/(password|passwd|apikey|api_key|secret|authorization|token)\s*[:=]\s*\S+/i', '$1=[redacted]', $line) ?? $line;
        $line = preg_replace('/\b(sk|pk|whsk)_[A-Za-z0-9]+/', '$1_[redacted]', $line) ?? $line;
        $line = preg_replace('/\bre_[A-Za-z0-9]{8,}\b/', 're_[redacted]', $line) ?? $line;
        return $line;
    }
}
