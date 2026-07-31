<?php

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Enums\ContractStatus;
use App\Http\Controllers\Concerns\GeneratesFirstPeriodCharges;
use App\Http\Controllers\Concerns\SearchesWithFilters;
use App\Http\Controllers\Concerns\WritesUnitOccupancies;
use App\Http\Resources\ContractCardResource;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Models\Setting;
use App\Models\Unit;
use App\Support\Billing\ContractBilling;
use App\Support\Billing\CurrencyGuard;
use App\Support\Billing\ResolvesItemCurrency;
use App\Support\RecordsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContractController extends Controller
{
    use GeneratesFirstPeriodCharges;
    use SearchesWithFilters;
    use WritesUnitOccupancies;

    public function index(Request $request): JsonResponse
    {
        $query = Contract::query()
            ->with(['items.item', 'contact', 'reservation'])
            ->latest();

        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->integer('contact_id'));
        }

        if ($request->filled('deal_id')) {
            $query->where('deal_id', $request->integer('deal_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('unit_id')) {
            $query->whereHas('items', function ($q) use ($request): void {
                $q->where('item_type', 'unit')
                  ->where('item_id', $request->integer('unit_id'));
            });
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (Contract $contract) => ContractResource::make($contract)),
            'Contracts retrieved successfully.'
        );
    }

    public function filterSchema(): JsonResponse
    {
        return $this->respondFilterSchema(AttributeEntityType::Contract);
    }

    public function search(Request $request): JsonResponse
    {
        return $this->searchWithFilters(
            $request,
            AttributeEntityType::Contract,
            Contract::query()->with(['items.item', 'contact', 'reservation']),
            fn (Contract $contract) => ContractResource::make($contract),
            'Contracts retrieved successfully.',
            function ($query, Request $request): void {
                if ($request->filled('contact_id')) {
                    $query->where('contact_id', $request->integer('contact_id'));
                }
                if ($request->filled('deal_id')) {
                    $query->where('deal_id', $request->integer('deal_id'));
                }
                if ($request->filled('unit_id')) {
                    $query->whereHas('items', function ($q) use ($request): void {
                        $q->where('item_type', 'unit')
                            ->where('item_id', $request->integer('unit_id'));
                    });
                }
            },
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_id'     => ['required', 'integer', 'exists:contacts,id'],
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'deal_id'        => ['nullable', 'integer', 'exists:deals,id'],
            'start_date'     => ['required', 'date'],
            'end_date'       => ['nullable', 'date', 'after:start_date'],
            'move_in_date'   => ['nullable', 'date'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'status'         => ['nullable', Rule::enum(ContractStatus::class)],
            'signed_at'      => ['nullable', 'date'],
            'items'          => ['required', 'array', 'min:1'],
            'items.*.item_type'             => ['required', 'string', Rule::in(['unit', 'insurance'])],
            'items.*.item_id'               => ['required', 'integer'],
            'items.*.amount'                => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate_id'           => ['nullable', 'integer', 'exists:tax_rates,id'],
            'items.*.declared_goods_value'  => ['nullable', 'numeric', 'min:0'],
            'items.*.description'           => ['nullable', 'string'],
        ]);

        $contract = DB::transaction(function () use ($validated, $request) {
            $billing = Setting::billing();
            $moveIn = CarbonImmutable::parse($validated['move_in_date'] ?? $validated['start_date'])->startOfDay();
            $endedOn = isset($validated['end_date'])
                ? CarbonImmutable::parse($validated['end_date'])->startOfDay()
                : null;

            $contract = Contract::query()->create([
                'contact_id'             => $validated['contact_id'],
                'reservation_id'         => $validated['reservation_id'] ?? null,
                'deal_id'                => $validated['deal_id'] ?? null,
                'start_date'             => $validated['start_date'],
                'end_date'               => $validated['end_date'] ?? null,
                'status'                 => $validated['status'] ?? ContractStatus::Active->value,
                'signed_at'              => $validated['signed_at'] ?? now(),
                'billing_interval'       => $billing->defaultBillingInterval,
                'billing_interval_count' => $billing->defaultBillingIntervalCount,
                'billing_anchor_model'   => $billing->billingAnchorModel,
                'proration_method'       => $billing->prorationMethod,
                'move_in_date'           => $moveIn->toDateString(),
                'deposit_amount'         => $validated['deposit_amount'] ?? $billing->defaultDepositAmount,
                // Placeholder until items agree — overwritten before charges.
                'currency'               => $billing->defaultCurrency ?: 'EUR',
            ]);

            $siteId = null;
            foreach ($validated['items'] as $itemData) {
                if ($itemData['item_type'] === 'unit') {
                    $siteId = Unit::query()->whereKey($itemData['item_id'])->value('site_id');
                    break;
                }
            }

            $contractItems = collect($validated['items'])->map(function (array $itemData) use ($contract, $moveIn, $siteId) {
                $taxRate = $this->resolveContractItemTaxRate(
                    $itemData['item_type'],
                    $itemData['item_id'],
                    $itemData['tax_rate_id'] ?? null,
                    $moveIn,
                );

                $itemSiteId = $itemData['item_type'] === 'unit'
                    ? Unit::query()->whereKey($itemData['item_id'])->value('site_id')
                    : $siteId;

                $currency = ResolvesItemCurrency::forItem(
                    $itemData['item_type'],
                    $itemData['item_id'],
                    null,
                    $itemSiteId !== null ? (int) $itemSiteId : null,
                );

                return $contract->items()->create([
                    'item_type'             => $itemData['item_type'],
                    'item_id'               => $itemData['item_id'],
                    'amount'                => $itemData['amount'],
                    'currency'              => $currency,
                    'tax_rate_id'           => $taxRate?->id,
                    'tax_rate_snapshot'     => $taxRate?->rate,
                    'declared_goods_value'  => $itemData['declared_goods_value'] ?? null,
                    'description'           => $itemData['description'] ?? null,
                ]);
            });

            $agreedCurrency = CurrencyGuard::assertItemsAgree($contractItems);
            $contract->forceFill(['currency' => $agreedCurrency])->save();

            $this->writeUnitOccupancies($contract, $contractItems, $moveIn, $endedOn, $request->user()?->id);

            $plan = ContractBilling::planFirstPeriod(
                $moveIn,
                $billing->billingAnchorModel,
                $billing->defaultBillingInterval,
                $billing->defaultBillingIntervalCount,
                $billing->billingAnchorDay,
            );

            $this->generateFirstPeriodCharges($contract, $contractItems, $plan, $billing->prorationMethod, $moveIn);

            $signedProps = ['reservation_id' => $contract->reservation_id];
            RecordsActivity::core('contract.signed', $contract, $signedProps);
            $contract->loadMissing('contact');
            if ($contract->contact !== null) {
                RecordsActivity::core('contract.signed', $contract->contact, $signedProps);
            }

            return $contract;
        });

        return $this->created(
            ContractResource::make($contract->load(['items.item', 'contact', 'reservation', 'occupancies'])),
            'Contract created successfully.'
        );
    }

    public function show(Contract $contract): JsonResponse
    {
        $this->loadDetailRelations($contract);

        return $this->success(
            ContractResource::make($contract),
            'Contract retrieved successfully.'
        );
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date'   => ['sometimes', 'nullable', 'date'],
            'status'     => ['sometimes', 'required', Rule::enum(ContractStatus::class)],
            'signed_at'  => ['sometimes', 'required', 'date'],
        ]);

        $previousStatus = $contract->status;
        $contract->update($validated);
        $contract->refresh();

        if (array_key_exists('status', $validated)) {
            $this->logEndedIfNeeded($contract, $previousStatus);
        }

        $this->loadDetailRelations($contract);

        return $this->success(
            ContractResource::make($contract),
            'Contract updated successfully.'
        );
    }

    public function updateStatus(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(ContractStatus::class)],
        ]);

        $previousStatus = $contract->status;
        $contract->update(['status' => $validated['status']]);
        $contract = $contract->fresh()->load([
            'contact',
            'unitItem.item' => function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    Unit::class => ['site'],
                ]);
            },
        ]);

        $this->logEndedIfNeeded($contract, $previousStatus);

        return $this->success(
            ContractCardResource::make($contract),
            'Contract status updated successfully.'
        );
    }

    public function destroy(Contract $contract): JsonResponse
    {
        $contract->delete();

        return $this->noContent('Contract deleted successfully.');
    }

    private function logEndedIfNeeded(Contract $contract, mixed $previousStatus): void
    {
        $endedStatuses = [
            ContractStatus::MovedOut,
            ContractStatus::Terminated,
            ContractStatus::Expired,
            ContractStatus::MovedOut->value,
            ContractStatus::Terminated->value,
            ContractStatus::Expired->value,
        ];
        $wasEnded = in_array($previousStatus, $endedStatuses, true);
        $isEnded = in_array($contract->status, $endedStatuses, true);

        if ($isEnded && ! $wasEnded) {
            RecordsActivity::core('contract.ended', $contract, [
                'reservation_id' => $contract->reservation_id,
                'status' => $contract->status instanceof ContractStatus
                    ? $contract->status->value
                    : $contract->status,
            ]);
        }
    }

    private function loadDetailRelations(Contract $contract): void
    {
        $contract->load([
            'contact',
            'reservation',
            'deal',
            'notes.employee',
            'items.discount',
            'items.taxRate',
            'occupancies',
            'billingPeriods' => fn ($query) => $query->orderByDesc('billing_period_start')->with('charges'),
            'payments' => fn ($query) => $query->orderByDesc('created_at')->with('allocations'),
            'charges'  => fn ($query) => $query->orderByDesc('due_date'),
            'items.item' => function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    Unit::class => ['site', 'unitClass'],
                ]);
            },
        ]);
    }
}
