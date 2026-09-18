<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Dashboard\DashboardService;
use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;

final class DashboardController
{
    public function __construct(
        private SkGuard $guard,
        private DashboardService $dashboard,
    ) {
    }

    public function dashboard(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->dashboard->dashboard(
            $ctx['organization_id'],
            (int) $ctx['user']['id'],
            $_GET
        ));
    }

    public function reports(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->dashboard->report($ctx['organization_id'], $_GET));
    }

    public function reportYouth(array $params = []): void
    {
        $this->reportTyped('youth');
    }

    public function reportPrograms(array $params = []): void
    {
        $this->reportTyped('programs');
    }

    public function reportAssistance(array $params = []): void
    {
        $this->reportTyped('assistance');
    }

    public function reportApplications(array $params = []): void
    {
        $this->reportTyped('applications');
    }

    public function reportAttendance(array $params = []): void
    {
        $this->reportTyped('attendance');
    }

    private function reportTyped(string $type): void
    {
        $ctx = $this->guard->requireSkOfficial();
        $query = $_GET;
        $query['type'] = $type;
        Json::success($this->dashboard->report($ctx['organization_id'], $query));
    }
}
