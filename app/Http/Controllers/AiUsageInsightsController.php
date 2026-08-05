<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\Ai\AiUsageReport;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AiUsageInsightsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ReportView->value);

        return $this->report($request);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        return $this->report($request, $employee->id);
    }

    private function report(Request $request, ?int $employeeId = null): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'group_by' => ['sometimes', Rule::in(['employee', 'model', 'purpose', 'day'])],
        ]);

        $result = AiUsageReport::build(
            from: Carbon::parse($validated['from']),
            to: Carbon::parse($validated['to']),
            groupBy: $validated['group_by'] ?? 'employee',
            employeeId: $employeeId,
        );

        return response()->json([
            'message' => 'AI usage retrieved successfully.',
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }
}
