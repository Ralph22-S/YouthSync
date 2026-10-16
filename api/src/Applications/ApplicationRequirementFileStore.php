<?php
declare(strict_types=1);

namespace YouthSync\Applications;

use YouthSync\Http\Json;

/**
 * Stores one requirement document per application submission.
 * The database keeps the generated storage name. The original filename is metadata only.
 */
final class ApplicationRequirementFileStore
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    private const DOCUMENT_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function isStoredName(string $storageName): bool
    {
        return preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $storageName) === 1;
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array{storageName: string, name: string, type: string, size: int}
     */
    public function store(?array $file, string $accepts): array
    {
        $upload = $this->requireUpload($file);
        $allowed = $this->allowedMimes($accepts);
        $mime = $this->detectMime($upload['tmp_name'], $allowed);
        $extension = $allowed[$mime];
        $storageName = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $this->destination($storageName);
        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
            Json::error('UPLOAD_FAILED', 'The file could not be stored.', 500);
        }

        return [
            'storageName' => $storageName,
            'name' => $this->displayName((string) $upload['name'], $extension),
            'type' => $mime,
            'size' => (int) $upload['size'],
        ];
    }

    public function discard(string $storageName): void
    {
        $path = $this->readablePath($storageName);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    public function send(string $storageName, string $originalName, string $mime, bool $attachment): never
    {
        $path = $this->readablePath($storageName);
        if ($path === null) {
            Json::error('NOT_FOUND', 'This document is not stored.', 404);
        }
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $download = $this->displayName($originalName, is_string($extension) ? $extension : 'pdf');
        $safeMime = isset(self::DOCUMENT_MIME[$mime]) ? $mime : 'application/octet-stream';

        http_response_code(200);
        header('Content-Type: ' . $safeMime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . '; filename="' . $download . '"');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
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
            Json::error('VALIDATION_ERROR', 'That file is larger than 8 MB.', 422);
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
            Json::error('VALIDATION_ERROR', 'That file is larger than 8 MB.', 422);
        }
        return [
            'tmp_name' => $tmp,
            'name' => (string) ($file['name'] ?? ''),
            'size' => $size,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function allowedMimes(string $accepts): array
    {
        $rules = array_values(array_filter(array_map('trim', explode(',', $accepts))));
        $allowed = [];
        foreach (self::DOCUMENT_MIME as $mime => $extension) {
            foreach ($rules as $rule) {
                if ($rule === $mime || ($rule === 'image/*' && str_starts_with($mime, 'image/'))) {
                    $allowed[$mime] = $extension;
                }
            }
        }
        return $allowed === [] ? self::DOCUMENT_MIME : $allowed;
    }

    /**
     * @param array<string, string> $allowed
     */
    private function detectMime(string $tmp, array $allowed): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $mime = is_string($mime) ? strtolower($mime) : '';
        if (!isset($allowed[$mime])) {
            Json::error('VALIDATION_ERROR', 'That file type is not accepted. Use JPG, PNG, WebP, or PDF.', 422);
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
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'application_requirements';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            Json::error('UPLOAD_FAILED', 'The file could not be stored.', 500);
        }
        return $dir;
    }

    private function destination(string $storageName): string
    {
        if (!$this->isStoredName($storageName)) {
            Json::error('UPLOAD_FAILED', 'The file could not be stored.', 500);
        }
        return $this->directory() . DIRECTORY_SEPARATOR . $storageName;
    }

    private function readablePath(string $storageName): ?string
    {
        if (!$this->isStoredName($storageName)) {
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
