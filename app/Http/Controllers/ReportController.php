<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\InsightReport;
use App\Support\Auth\Permission;
use App\Support\Reports\CsvExporter;
use App\Support\Reports\ReportFilters;
use App\Support\Reports\ReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Insights reports. Financial names require ReportFinancialView.
 */
class ReportController extends Controller
{
    /** @var list<string> */
    private const FINANCIAL_REPORTS = [
        'rent-roll',
        'ageing',
        'collections',
        'deposit-liability',
        'daily-close',
    ];

    public function show(Request $request, string $name): JsonResponse|Response
    {
        $permission = in_array($name, self::FINANCIAL_REPORTS, true)
            ? Permission::ReportFinancialView
            : Permission::ReportView;

        Gate::authorize($permission->value);

        if (! ReportRegistry::has($name)) {
            return $this->notFound('Report not found.');
        }

        $nativeRow = InsightReport::query()
            ->where('native_key', $name)
            ->first();

        if ($nativeRow !== null && $nativeRow->isArchived()) {
            return $this->notFound('Report not found.');
        }

        /** @var Employee $employee */
        $employee = $request->user();

        $filters = ReportFilters::fromValidated($request->validate(ReportFilters::rules()))
            ->constrainToGranted($employee->siteIdsFor($permission));
        $report = ReportRegistry::make($name);
        $result = $report->run($filters);

        $format = $request->query('format', 'json');
        if ($format === 'csv') {
            $csv = CsvExporter::export($result, $filters->locale);
            $filename = CsvExporter::filename($name, $filters);

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        return $this->success($result->toArray(), 'Report retrieved successfully.');
    }
}
