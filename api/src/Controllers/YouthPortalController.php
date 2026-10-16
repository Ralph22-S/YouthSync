<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Auth\AuthService;
use YouthSync\Http\Json;
use YouthSync\Http\YouthGuard;
use YouthSync\Youth\YouthPortalService;

final class YouthPortalController
{
    public function __construct(
        private YouthGuard $guard,
        private AuthService $auth,
        private YouthPortalService $portal,
    ) {
    }

    public function profile(array $params = []): void
    {
        $this->guard->requireYouth();
        Json::success($this->portal->withPhoto($this->auth->currentYouthSnapshot()));
    }

    public function updateProfile(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        $this->portal->updateProfile($ctx['youth_id'], Json::readBody(), $ctx['user']);
        Json::success($this->portal->withPhoto($this->auth->currentYouthSnapshot()));
    }

    public function uploadPhoto(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        $file = $_FILES['file'] ?? null;
        Json::success($this->portal->storePhoto($ctx['youth_id'], is_array($file) ? $file : null, $ctx['user']));
    }

    public function photo(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        $this->portal->sendPhoto($ctx['youth_id']);
    }

    public function deletePhoto(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        $this->portal->removePhoto($ctx['youth_id'], $ctx['user']);
        Json::success(['deleted' => true]);
    }

    public function programs(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->programs($ctx['youth_id'], $_GET));
    }

    public function program(array $params): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->program($ctx['youth_id'], (int) $params['id']));
    }

    public function assistance(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->assistance($ctx['youth_id']));
    }

    public function respondProgramInvitation(array $params): void
    {
        $ctx = $this->guard->requireYouth();
        $body = Json::readBody();
        Json::success($this->portal->respondToProgramInvitation(
            $ctx['youth_id'],
            (int) $params['id'],
            (string) ($body['decision'] ?? '')
        ));
    }

    public function respondInvitation(array $params): void
    {
        $ctx = $this->guard->requireYouth();
        $body = Json::readBody();
        Json::success($this->portal->respondToInvitation(
            $ctx['youth_id'],
            (int) $params['id'],
            (string) ($body['decision'] ?? '')
        ));
    }

    public function assistanceItem(array $params): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->assistanceItem($ctx['youth_id'], (int) $params['id']));
    }

    public function attendance(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->attendance($ctx['youth_id'], $_GET));
    }

    public function checkIn(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->checkIn($ctx['youth_id'], Json::readBody(), $ctx['user']));
    }

    public function registerByCode(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->registerByCode($ctx['youth_id'], Json::readBody(), $ctx['user']), 201);
    }

    public function applications(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->applications($ctx['youth_id'], $_GET));
    }

    public function application(array $params): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->application($ctx['youth_id'], (int) $params['id']));
    }

    public function createApplication(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->createApplication($ctx['youth_id'], Json::readBody(), $ctx['user']), 201);
    }

    public function resubmitApplication(array $params): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->resubmitApplication($ctx['youth_id'], (int) $params['id'], $ctx['user']));
    }

    public function uploadRequirementFile(array $params): void
    {
        $ctx = $this->guard->requireYouth();
        $file = $_FILES['file'] ?? null;
        Json::success($this->portal->storeRequirementFile(
            $ctx['youth_id'],
            (int) $params['id'],
            (int) $params['requirementId'],
            is_array($file) ? $file : null,
            $ctx['user']
        ));
    }

    public function requirementFile(array $params): void
    {
        $ctx = $this->guard->requireYouth();
        $this->portal->sendRequirementFile(
            $ctx['youth_id'],
            (int) $params['id'],
            (int) $params['requirementId'],
            isset($_GET['download']) && (string) $_GET['download'] !== '' && (string) $_GET['download'] !== '0'
        );
    }

    public function deleteRequirementFile(array $params): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->removeRequirementFile(
            $ctx['youth_id'],
            (int) $params['id'],
            (int) $params['requirementId'],
            $ctx['user']
        ));
    }

    public function notifications(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->notifications($ctx['youth_id'], $_GET));
    }

    public function markNotificationRead(array $params): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->markNotificationRead($ctx['youth_id'], (int) $params['id']));
    }

    public function markAllNotificationsRead(array $params = []): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->markAllNotificationsRead($ctx['youth_id']));
    }

    public function deleteNotification(array $params): void
    {
        $ctx = $this->guard->requireYouth();
        Json::success($this->portal->deleteNotification($ctx['youth_id'], (int) $params['id']));
    }
}
