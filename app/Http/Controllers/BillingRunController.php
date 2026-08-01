<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BillingRunItemOutcome;
use App\Enums\BillingRunTrigger;
use App\Http\Resources\BillingRunResource;
use App\Models\BillingRun;
use App\Models\Employee;
use App\Models\Unit;
use App\Support\Billing\BillingRunEngine;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Billing-run list/detail + manual trigger. Auth: any authenticated Employee
 * (S17 RBAC stopgap — see docs/10-open-decisions.md).
 */
class BillingRunController extends Controller
{
    public function index(): JsonResponse
    {
        $paginator = BillingRun::query()
            ->with(['createdBy', 'items'])
            ->latest('started_at')
            ->latest('id')
            ->paginate($this->perPage())
            ->through(fn (BillingRun $run) => BillingRunResource::make($run));

        return $this->paginated($paginator, 'Billing runs retrieved successfully.');
    }

    public function show(Request $request, BillingRun $billingRun): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => ['nullable', Rule::enum(BillingRunItemOutcome::class)],
        ]);

        $billingRun->load(['createdBy']);

        $itemsQuery = $billingRun->items()
            ->with([
                'contract.contact',
                'contract.unitItem' => function ($query): void {
                    $query->with(['item' => function (MorphTo $morphTo): void {
                        $morphTo->morphWith([
                            Unit::class => [],
                        ]);
                    }]);
                },
            ])
            ->orderBy('id');

        if (isset($validated['outcome'])) {
            $itemsQuery->where('outcome', $validated['outcome']);
        }

        $billingRun->setRelation('items', $itemsQuery->get());

        return $this->success(
            BillingRunResource::make($billingRun),
            'Billing run retrieved successfully.',
        );
    }

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
        $result->load(['createdBy', 'items']);

        return $this->created(
            BillingRunResource::make($result),
            'Billing run completed successfully.',
        );
    }
}
