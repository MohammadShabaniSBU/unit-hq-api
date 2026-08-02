<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Reports\CsvExporter;
use App\Support\Reports\ReportFilters;
use App\Support\Reports\ReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Insights reports. Auth: any authenticated Employee until S17 RBAC
 * (see docs/10-open-decisions.md — reports namespace stopgap).
 */
class ReportController extends Controller
{
    public function show(Request $request, string $name): JsonResponse|Response
    {
        if (! ReportRegistry::has($name)) {
            return $this->notFound('Report not found.');
        }

        $filters = ReportFilters::fromValidated($request->validate(ReportFilters::rules()));
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
