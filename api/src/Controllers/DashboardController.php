<?php
declare(strict_types=1);

namespace YouthSync\Controllers;

use YouthSync\Dashboard\DashboardService;
use YouthSync\Http\Json;
use YouthSync\Http\SkGuard;
use YouthSync\Org\PlanLimits;
use YouthSync\Reports\CsvWriter;
use YouthSync\Reports\ReportDownload;
use YouthSync\Reports\XlsxWriter;

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

    /**
     * GET /sk/reports/{type}/csv and /sk/reports/{type}/xlsx: file download of
     * the same report scope as the JSON route (same guard, organization and
     * filters). Errors stay JSON; a successful response is the file only.
     */
    public function reportExport(string $type, string $format): void
    {
        $ctx = $this->guard->requireSkOfficial();
        if (!DashboardService::isReportType($type)) {
            Json::error('REPORT_NOT_FOUND', 'Report type was not found.', 404);
        }
        if ($format !== 'csv' && $format !== 'xlsx') {
            Json::error('EXPORT_FORMAT_NOT_FOUND', 'Export format was not found.', 404);
        }
        if ($format === 'xlsx' && !PlanLimits::effective($ctx['membership'])['excel_export']) {
            Json::error(
                'PLAN_FEATURE_UNAVAILABLE',
                'Excel export is included in the Basic and Premium plans. CSV export is available on every plan.',
                403
            );
        }

        $query = $_GET;
        $query['type'] = $type;
        $export = $this->dashboard->reportExport($ctx['organization_id'], $query);

        if ($format === 'csv') {
            $bytes = CsvWriter::build($export['columns'], $export['rows']);
            ReportDownload::send($bytes, ReportDownload::CSV_TYPE, ReportDownload::filename($type, 'csv'));
        }

        $title = $export['title'] . ($export['organization'] !== '' ? ' - ' . $export['organization'] : '');
        $preamble = ['Generated ' . ReportDownload::manilaNow()->format('Y-m-d H:i') . ' (Asia/Manila)'];
        $applied = [];
        foreach (['from' => 'From', 'to' => 'To', 'status' => 'Status', 'kind' => 'Kind', 'category' => 'Category'] as $key => $label) {
            if (($export['filters'][$key] ?? null) !== null) {
                $applied[] = $label . ': ' . $export['filters'][$key];
            }
        }
        $preamble[] = 'Filters: ' . ($applied === [] ? 'none' : implode(', ', $applied))
            . ' | Rows: ' . count($export['rows']);
        $bytes = XlsxWriter::build($export['sheetName'], $title, $preamble, $export['columns'], $export['rows']);
        ReportDownload::send($bytes, ReportDownload::XLSX_TYPE, ReportDownload::filename($type, 'xlsx'));
    }

    public function activity(array $params = []): void
    {
        $ctx = $this->guard->requireSkOfficial();
        Json::success($this->dashboard->activity($ctx['organization_id'], $_GET));
    }

}
