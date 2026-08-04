<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AutopayAttemptStatus;
use App\Models\AutopayAttempt;
use App\Models\Contract;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Overdue contracts list + failed-autopay attention count (S06-04 stand-in for S07 dunning).
 */
class BillingOverdueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::InvoiceView->value);

        $validated = $request->validate([
            'failed_autopay' => ['sometimes', 'boolean'],
        ]);

        $failedOnly = (bool) ($validated['failed_autopay'] ?? false);
        $today = Carbon::today()->toDateString();

        $overdueContractIds = $this->overdueContractIds($today);
        $failedAutopayIds = $this->failedAutopayContractIds();
        $failedAutopayCount = count(array_intersect($overdueContractIds, $failedAutopayIds));

        $ids = $failedOnly
            ? array_values(array_intersect($overdueContractIds, $failedAutopayIds))
            : $overdueContractIds;

        if ($ids === []) {
            return response()->json([
                'message' => 'Overdue contracts retrieved successfully.',
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $this->perPage(),
                    'total' => 0,
                    'failed_autopay_count' => $failedAutopayCount,
                ],
            ]);
        }

        $paginator = Contract::query()
            ->whereIn('id', $ids)
            ->with([
                'contact',
                'paymentMethod',
                'unitItem' => function ($query): void {
                    $query->with(['item' => function (MorphTo $morphTo): void {
                        $morphTo->morphWith([
                            Unit::class => [],
                        ]);
                    }]);
                },
                'autopayAttempts' => fn ($q) => $q->latest('id')->limit(1),
            ])
            ->orderBy('id')
            ->paginate($this->perPage())
            ->through(function (Contract $contract) use ($failedAutopayIds): array {
                $unit = $contract->unitItem?->item;
                $unitNumber = is_object($unit) && isset($unit->unit_number)
                    ? (string) $unit->unit_number
                    : null;

                /** @var AutopayAttempt|null $last */
                $last = $contract->autopayAttempts->first();

                return [
                    'id' => $contract->id,
                    'contact_id' => $contract->contact_id,
                    'contact_name' => $contract->contact !== null
                        ? trim($contract->contact->first_name.' '.$contract->contact->last_name)
                        : null,
                    'unit_number' => $unitNumber,
                    'currency' => $contract->currency,
                    'overdue_amount' => $contract->overdueAmount(),
                    'autopay_enabled' => (bool) $contract->autopay_enabled,
                    'failed_autopay' => in_array($contract->id, $failedAutopayIds, true),
                    'last_autopay_attempt' => $last !== null ? [
                        'id' => $last->id,
                        'status' => $last->status instanceof \BackedEnum
                            ? $last->status->value
                            : (string) $last->status,
                        'failure_code' => $last->failure_code,
                        'decline_code' => $last->decline_code,
                        'failure_message' => $last->failure_message,
                        'attempted_at' => $last->attempted_at?->toIso8601String(),
                    ] : null,
                ];
            });

        return response()->json([
            'message' => 'Overdue contracts retrieved successfully.',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'failed_autopay_count' => $failedAutopayCount,
            ],
        ]);
    }

    /**
     * @return list<int>
     */
    private function overdueContractIds(string $today): array
    {
        // Charges past due with open amount > 0 — same definition as Contract::overdueAmount.
        $rows = DB::table('charges')
            ->leftJoin('allocations', 'allocations.charge_id', '=', 'charges.id')
            ->where('charges.due_date', '<', $today)
            ->groupBy('charges.id', 'charges.contract_id', 'charges.amount')
            ->havingRaw('(CAST(charges.amount AS DECIMAL(10,2)) - COALESCE(SUM(allocations.amount), 0)) > 0')
            ->select('charges.contract_id')
            ->distinct()
            ->pluck('contract_id');

        return $rows->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Contracts whose latest autopay attempt is failed.
     *
     * @return list<int>
     */
    private function failedAutopayContractIds(): array
    {
        $latestIds = AutopayAttempt::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('contract_id')
            ->pluck('id');

        if ($latestIds->isEmpty()) {
            return [];
        }

        return AutopayAttempt::query()
            ->whereIn('id', $latestIds)
            ->where('status', AutopayAttemptStatus::Failed)
            ->pluck('contract_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
