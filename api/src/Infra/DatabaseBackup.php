<?php
declare(strict_types=1);

namespace YouthSync\Infra;

use RuntimeException;

/**
 * Stores a full logical backup of the YouthSync database with mysqldump.
 * Files stay under api/storage/backups and are not served as static files.
 */
final class DatabaseBackup
{
    private const KEEP = 14;

    public function __construct(
        private string $directory,
        private ?string $mysqldumpBinary = null,
    ) {
    }

    public static function storage(): self
    {
        return new self(dirname(__DIR__, 2) . '/storage/backups');
    }

    /**
     * @return array{file:string,bytes:int,createdAt:string}
     */
    public function create(): array
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('The backup folder could not be created.');
        }
        $binary = $this->mysqldump();
        $name = 'youthsync-' . date('Ymd-His') . '.sql';
        $target = $this->directory . DIRECTORY_SEPARATOR . $name;
        $cnf = tempnam(sys_get_temp_dir(), 'ysb');
        if ($cnf === false) {
            throw new RuntimeException('A temporary MySQL defaults file could not be created.');
        }
        $host = (string) youthsync_env('DB_HOST', '127.0.0.1');
        $port = (string) youthsync_env('DB_PORT', '3306');
        $user = (string) youthsync_env('DB_USER', 'root');
        $pass = (string) youthsync_env('DB_PASSWORD', '');
        $db = (string) youthsync_env('DB_NAME', 'youthsync');
        if (str_contains($pass, "\n") || str_contains($pass, "\r")) {
            throw new RuntimeException('The database password cannot be used by the backup command.');
        }
        file_put_contents($cnf, "[client]\nhost={$host}\nport={$port}\nuser={$user}\npassword={$pass}\n");
        $command = [
            $binary,
            '--defaults-extra-file=' . $cnf,
            '--single-transaction',
            '--skip-lock-tables',
            '--default-character-set=utf8mb4',
            '--result-file=' . $target,
            $db,
        ];
        $error = '';
        $code = 1;
        try {
            $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open($command, $spec, $pipes, $this->directory);
            if (!is_resource($process)) {
                throw new RuntimeException('mysqldump could not be started.');
            }
            fclose($pipes[0]);
            fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[2]);
            $code = proc_close($process);
        } finally {
            @unlink($cnf);
        }
        if ($code !== 0 || !is_file($target) || filesize($target) < 1) {
            if (is_file($target)) {
                @unlink($target);
            }
            $detail = trim($error);
            if ($detail === '') {
                $detail = 'mysqldump exited with status ' . $code;
            }
            $logDir = dirname($this->directory, 2) . '/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0750, true);
            }
            @file_put_contents($logDir . '/backup.err.log', '[' . date('c') . '] ' . $detail . "\n", FILE_APPEND);
            throw new RuntimeException('Database backup failed.');
        }
        $this->prune();
        return [
            'file' => $name,
            'bytes' => (int) filesize($target),
            'createdAt' => date('c', (int) filemtime($target)),
        ];
    }

    /**
     * @return list<array{file:string,bytes:int,createdAt:string}>
     */
    public function list(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $rows = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . 'youthsync-*.sql') ?: [] as $path) {
            $base = basename($path);
            if (!$this->validName($base)) {
                continue;
            }
            $rows[] = [
                'file' => $base,
                'bytes' => (int) filesize($path),
                'createdAt' => date('c', (int) filemtime($path)),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($b['createdAt'], $a['createdAt']));
        return $rows;
    }

    public function path(string $file): string
    {
        if (!$this->validName($file)) {
            throw new RuntimeException('That backup file was not found.');
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            throw new RuntimeException('That backup file was not found.');
        }
        $real = realpath($path);
        $root = realpath($this->directory);
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('That backup file was not found.');
        }
        return $real;
    }

    private function prune(): void
    {
        $files = $this->list();
        foreach (array_slice($files, self::KEEP) as $old) {
            $path = $this->directory . DIRECTORY_SEPARATOR . $old['file'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function validName(string $file): bool
    {
        return preg_match('/^youthsync-\d{8}-\d{6}\.sql$/', $file) === 1;
    }

    private function mysqldump(): string
    {
        if (is_string($this->mysqldumpBinary) && $this->mysqldumpBinary !== '') {
            if (!is_file($this->mysqldumpBinary)) {
                throw new RuntimeException('mysqldump was not found.');
            }
            return $this->mysqldumpBinary;
        }
        $configured = trim((string) youthsync_env('MYSQLDUMP_PATH', ''));
        $candidates = array_filter([
            $configured,
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'mysqldump',
        ]);
        foreach ($candidates as $candidate) {
            if ($candidate === 'mysqldump' || is_file($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('mysqldump was not found.');
    }
}
