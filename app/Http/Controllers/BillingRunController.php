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
use App\Support\Billing\FailedBillingRetry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Billing-run list/detail + manual trigger. Auth: any authenticated Employee
 * (S17 RBAC stopgap — see docs/10-open-decisions.md).
 */
class BillingRunController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::BillingRunExecute->value);

        $paginator = BillingRun::query()
            ->with(['createdBy', 'items'])
            ->latest('started_at')
            ->latest('id')
            ->paginate($this->perPage())
            ->through(fn (BillingRun $run) => BillingRunResource::make($run));

        $response = $this->paginated($paginator, 'Billing runs retrieved successfully.');
        $payload = $response->getData(true);
        $payload['meta']['failed_contracts'] = count(FailedBillingRetry::contractIds());

        return response()->json($payload, $response->status());
    }

    public function show(Request $request, BillingRun $billingRun): JsonResponse
    {
        Gate::authorize(Permission::BillingRunExecute->value, $billingRun);

        $validated = $request->validate([
            'outcome' => ['nullable', Rule::enum(BillingRunItemOutcome::class)],
        ]);

        $billingRun->load(['createdBy']);

        $itemsQuery = $billingRun->items()
            ->with([
                'contract.contact',
                'contract.autopayAttempts' => fn ($q) => $q
                    ->where('billing_run_id', $billingRun->id)
                    ->latest('id'),
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
        Gate::authorize(Permission::BillingRunExecute->value);

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

    public function retryFailed(Request $request): JsonResponse
    {
        Gate::authorize(Permission::BillingRunExecute->value);

        $validated = $request->validate([
            'run_id' => ['sometimes', 'nullable', 'integer', 'exists:billing_runs,id'],
        ]);

        $ids = FailedBillingRetry::contractIds(
            isset($validated['run_id']) ? (int) $validated['run_id'] : null,
        );

        if ($ids === []) {
            throw ValidationException::withMessages([
                'run_id' => [__('errors.deployment.no_failed_items')],
            ]);
        }

        /** @var Employee $employee */
        $employee = $request->user();

        $lock = Cache::lock('billing-retry', 30);
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'run_id' => [__('errors.deployment.no_failed_items')],
            ]);
        }

        try {
            $result = (new BillingRunEngine)->run(
                trigger: BillingRunTrigger::Retry,
                createdBy: $employee->id,
                onlyContractIds: $ids,
            );
        } finally {
            $lock->release();
        }

        /** @var BillingRun $result */
        $result->load(['createdBy', 'items']);

        return $this->created(
            BillingRunResource::make($result),
            'Billing retry completed successfully.',
        );
    }
}
