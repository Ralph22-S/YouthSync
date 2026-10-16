<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;
use YouthSync\Youth\YouthService;
use YouthSync\Youth\YouthImportFile;

final class YouthController
{
    public function __construct(
        private SkGuard $guard,
        private YouthService $youth,
    ) {
    }

    public function index(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->youth->list($ctx['organization_id'], $_GET, $ctx['membership']));
    }

    public function show(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->youth->get($ctx['organization_id'], (int) $params['id']));
    }

    public function store(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->youth->create($ctx['organization_id'], Json::readBody(), $ctx['membership'], $ctx['user']), 201);
    }

    public function update(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->youth->update($ctx['organization_id'], (int) $params['id'], Json::readBody(), $ctx['user']));
    }

    public function archive(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->youth->archive($ctx['organization_id'], (int) $params['id'], $ctx['membership'], true, $ctx['user']));
    }

    public function restore(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->youth->archive($ctx['organization_id'], (int) $params['id'], $ctx['membership'], false, $ctx['user']));
    }

    public function destroy(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->youth->delete($ctx['organization_id'], (int) $params['id'], $ctx['user']);
        Json::success(['deleted' => true]);
    }

    public function uploadPhoto(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $file = $_FILES['file'] ?? null;
        Json::success($this->youth->storeFile(
            $ctx['organization_id'],
            (int) $params['id'],
            'photo',
            is_array($file) ? $file : null,
            $ctx['user']
        ));
    }

    public function photo(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->youth->sendFile($ctx['organization_id'], (int) $params['id'], 'photo');
    }

    public function deletePhoto(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->youth->removeFile($ctx['organization_id'], (int) $params['id'], 'photo', $ctx['user']);
        Json::success(['deleted' => true]);
    }

    public function uploadDocument(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $file = $_FILES['file'] ?? null;
        Json::success($this->youth->storeFile(
            $ctx['organization_id'],
            (int) $params['id'],
            'document',
            is_array($file) ? $file : null,
            $ctx['user']
        ));
    }

    public function document(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->youth->sendFile($ctx['organization_id'], (int) $params['id'], 'document');
    }

    public function deleteDocument(array $params): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $this->youth->removeFile($ctx['organization_id'], (int) $params['id'], 'document', $ctx['user']);
        Json::success(['deleted' => true]);
    }

    public function importPreview(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $file = $_FILES['file'] ?? null;
        Json::success($this->youth->importPreview(
            $ctx['membership'],
            is_array($file) ? $file : []
        ));
    }

    public function import(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $file = $_FILES['file'] ?? null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $rows = YouthImportFile::parse($file);
        } else {
            $body = Json::readBody();
            $rows = [];
            if (array_is_list($body)) {
                $rows = $body;
            } elseif (isset($body['rows']) && is_array($body['rows'])) {
                $rows = $body['rows'];
            }
        }
        Json::success($this->youth->import($ctx['organization_id'], $rows, $ctx['membership'], $ctx['user']), 201);
    }
}
