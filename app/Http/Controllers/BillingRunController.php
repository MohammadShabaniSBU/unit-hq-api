<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BillingRunTrigger;
use App\Models\BillingRun;
use App\Models\Employee;
use App\Support\Billing\BillingRunEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manual billing-run trigger. Auth: any authenticated Employee (S17 RBAC stopgap —
 * see docs/10-open-decisions.md).
 */
class BillingRunController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();

        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $engine = new BillingRunEngine;

        $result = $engine->run(
            trigger: BillingRunTrigger::Manual,
            dryRun: $dryRun,
            createdBy: $employee->id,
        );

        if ($dryRun) {
            return $this->success(
                $result,
                'Billing dry-run preview generated successfully.',
            );
        }

        /** @var BillingRun $result */
        return $this->created(
            [
                'id' => $result->id,
                'trigger' => $result->trigger->value,
                'horizon_date' => $result->horizon_date->toDateString(),
                'contracts_considered' => $result->contracts_considered,
                'contracts_billed' => $result->contracts_billed,
                'contracts_skipped' => $result->contracts_skipped,
                'contracts_failed' => $result->contracts_failed,
            ],
            'Billing run completed successfully.',
        );
    }
}
