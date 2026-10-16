<?php
declare(strict_types=1);

namespace YouthSync\Youth;

use PDO;
use YouthSync\Http\Json;

/**
 * Stores one profile photo and one supporting document per youth record.
 * The database keeps metadata and a generated file id. The original filename
 * is never used as a path.
 */
final class YouthFileStore
{
    public const MAX_BYTES = 2097152;

    private const PHOTO_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const DOCUMENT_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed>|null $file $_FILES entry
     * @return array{name: string, type: string, size: int}
     */
    public function store(int $organizationId, int $youthId, string $kind, ?array $file): array
    {
        $this->assertKind($kind);
        $upload = $this->requireUpload($file);
        $mime = $this->detectMime($upload['tmp_name'], $kind);
        $extension = ($kind === 'photo' ? self::PHOTO_MIME : self::DOCUMENT_MIME)[$mime];
        $storageName = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $this->destination($storageName);

        $existing = $this->row($youthId, $kind);
        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
            Json::error('UPLOAD_FAILED', 'The file could not be stored.', 500);
        }

        $original = $this->displayName((string) ($upload['name'] ?? ''), $extension);
        try {
            if ($existing === null) {
                $this->pdo->prepare(
                    'INSERT INTO youth_files
                        (organization_id, youth_id, kind, storage_name, original_name, mime, size_bytes)
                     VALUES
                        (:organization_id, :youth_id, :kind, :storage_name, :original_name, :mime, :size_bytes)'
                )->execute([
                    'organization_id' => $organizationId,
                    'youth_id' => $youthId,
                    'kind' => $kind,
                    'storage_name' => $storageName,
                    'original_name' => $original,
                    'mime' => $mime,
                    'size_bytes' => (int) $upload['size'],
                ]);
            } else {
                $this->pdo->prepare(
                    'UPDATE youth_files
                     SET organization_id = :organization_id,
                         storage_name = :storage_name,
                         original_name = :original_name,
                         mime = :mime,
                         size_bytes = :size_bytes
                     WHERE id = :id AND youth_id = :youth_id AND kind = :kind'
                )->execute([
                    'organization_id' => $organizationId,
                    'storage_name' => $storageName,
                    'original_name' => $original,
                    'mime' => $mime,
                    'size_bytes' => (int) $upload['size'],
                    'id' => (int) $existing['id'],
                    'youth_id' => $youthId,
                    'kind' => $kind,
                ]);
            }
        } catch (\Throwable $e) {
            $this->unlinkStored($storageName);
            throw $e;
        }

        if ($existing !== null) {
            $this->unlinkStored((string) $existing['storage_name']);
        }

        return [
            'name' => $original,
            'type' => $mime,
            'size' => (int) $upload['size'],
        ];
    }

    public function remove(int $organizationId, int $youthId, string $kind): void
    {
        $this->assertKind($kind);
        $existing = $this->row($youthId, $kind);
        if ($existing === null || (int) $existing['organization_id'] !== $organizationId) {
            Json::error('NOT_FOUND', 'This file is not stored for this youth record.', 404);
        }
        $this->pdo->prepare(
            'DELETE FROM youth_files WHERE id = :id AND youth_id = :youth_id AND organization_id = :org AND kind = :kind'
        )->execute([
            'id' => (int) $existing['id'],
            'youth_id' => $youthId,
            'org' => $organizationId,
            'kind' => $kind,
        ]);
        $this->unlinkStored((string) $existing['storage_name']);
    }

    public function send(int $organizationId, int $youthId, string $kind): never
    {
        $this->assertKind($kind);
        $existing = $this->row($youthId, $kind);
        if ($existing === null || (int) $existing['organization_id'] !== $organizationId) {
            Json::error('NOT_FOUND', 'This file is not stored for this youth record.', 404);
        }
        $path = $this->readablePath((string) $existing['storage_name']);
        if ($path === null) {
            Json::error('NOT_FOUND', 'This file is not stored for this youth record.', 404);
        }

        $download = $this->displayName((string) $existing['original_name'], pathinfo($path, PATHINFO_EXTENSION));
        http_response_code(200);
        header('Content-Type: ' . $existing['mime']);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="' . $download . '"');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    /**
     * @return array{name: string, type: string, size: int}|null
     */
    public function meta(int $youthId, string $kind): ?array
    {
        $existing = $this->row($youthId, $kind);
        if ($existing === null) {
            return null;
        }
        return $this->publicMeta($existing);
    }

    /**
     * @param list<int> $youthIds
     * @return array<int, array<string, array{name: string, type: string, size: int}>>
     */
    public function grouped(array $youthIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $youthIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT youth_id, kind, original_name, mime, size_bytes
             FROM youth_files WHERE youth_id IN ({$placeholders})"
        );
        $stmt->execute($ids);
        $grouped = [];
        foreach ($ids as $id) {
            $grouped[$id] = [];
        }
        foreach ($stmt->fetchAll() as $row) {
            $youthId = (int) $row['youth_id'];
            $kind = (string) $row['kind'];
            if ($kind !== 'photo' && $kind !== 'document') {
                continue;
            }
            $grouped[$youthId][$kind] = $this->publicMeta($row);
        }
        return $grouped;
    }

    /**
     * @return list<string>
     */
    public function storageNames(int $youthId): array
    {
        $stmt = $this->pdo->prepare('SELECT storage_name FROM youth_files WHERE youth_id = :id');
        $stmt->execute(['id' => $youthId]);
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * @param list<string> $storageNames
     */
    public function unlinkNames(array $storageNames): void
    {
        foreach ($storageNames as $name) {
            $this->unlinkStored((string) $name);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{name: string, type: string, size: int}
     */
    private function publicMeta(array $row): array
    {
        return [
            'name' => (string) $row['original_name'],
            'type' => (string) $row['mime'],
            'size' => (int) $row['size_bytes'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(int $youthId, string $kind): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, organization_id, youth_id, kind, storage_name, original_name, mime, size_bytes
             FROM youth_files WHERE youth_id = :youth_id AND kind = :kind LIMIT 1'
        );
        $stmt->execute(['youth_id' => $youthId, 'kind' => $kind]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function assertKind(string $kind): void
    {
        if ($kind !== 'photo' && $kind !== 'document') {
            Json::error('VALIDATION_ERROR', 'This file kind is not accepted.', 422);
        }
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array{tmp_name: string, name: string, size: int}
     */
    private function requireUpload(?array $file): array
    {
        if ($file === null || !isset($file['error'])) {
            Json::error('VALIDATION_ERROR', 'Choose a file to upload.', 422);
        }
        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_NO_FILE) {
            Json::error('VALIDATION_ERROR', 'Choose a file to upload.', 422);
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            Json::error('VALIDATION_ERROR', 'That file is larger than 2 MB.', 422);
        }
        if ($error !== UPLOAD_ERR_OK) {
            Json::error('VALIDATION_ERROR', 'The file could not be uploaded.', 422);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Json::error('VALIDATION_ERROR', 'The file could not be uploaded.', 422);
        }
        if ($size < 1) {
            Json::error('VALIDATION_ERROR', 'The file is empty.', 422);
        }
        if ($size > self::MAX_BYTES) {
            Json::error('VALIDATION_ERROR', 'That file is larger than 2 MB.', 422);
        }
        return [
            'tmp_name' => $tmp,
            'name' => (string) ($file['name'] ?? ''),
            'size' => $size,
        ];
    }

    private function detectMime(string $tmp, string $kind): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $mime = is_string($mime) ? strtolower($mime) : '';
        $allowed = $kind === 'photo' ? self::PHOTO_MIME : self::DOCUMENT_MIME;
        if (!isset($allowed[$mime])) {
            $label = $kind === 'photo' ? 'JPG, PNG, or WebP' : 'JPG, PNG, WebP, or PDF';
            Json::error('VALIDATION_ERROR', "That file type is not accepted. Use {$label}.", 422);
        }
        return $mime;
    }

    private function displayName(string $original, string $extension): string
    {
        $base = basename(str_replace('\\', '/', $original));
        $base = preg_replace('/[^A-Za-z0-9._ -]/', '', $base) ?? '';
        $base = trim($base, ". \t");
        if ($base === '' || $base === '.' || $base === '..') {
            $base = ($extension === 'pdf' ? 'document' : 'photo') . '.' . $extension;
        }
        if (!str_ends_with(strtolower($base), '.' . $extension)) {
            $base .= '.' . $extension;
        }
        return mb_substr($base, 0, 160);
    }

    private function directory(): string
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'youth_files';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            Json::error('UPLOAD_FAILED', 'The file could not be stored.', 500);
        }
        return $dir;
    }

    private function destination(string $storageName): string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $storageName)) {
            Json::error('UPLOAD_FAILED', 'The file could not be stored.', 500);
        }
        return $this->directory() . DIRECTORY_SEPARATOR . $storageName;
    }

    private function readablePath(string $storageName): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $storageName)) {
            return null;
        }
        $path = $this->directory() . DIRECTORY_SEPARATOR . $storageName;
        if (!is_file($path)) {
            return null;
        }
        $real = realpath($path);
        if ($real === false || !$this->insideRoot($real)) {
            return null;
        }
        return $real;
    }

    private function unlinkStored(string $storageName): void
    {
        $path = $this->readablePath($storageName);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private function insideRoot(string $path): bool
    {
        $root = realpath($this->directory());
        if ($root === false) {
            return false;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $full = str_replace('\\', '/', $path);
        return str_starts_with(strtolower($full), strtolower($root) . '/');
    }
}
