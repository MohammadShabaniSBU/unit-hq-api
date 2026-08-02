<?php

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Enums\ContractStatus;
use App\Http\Controllers\Concerns\GeneratesFirstPeriodCharges;
use App\Http\Controllers\Concerns\SearchesWithFilters;
use App\Http\Controllers\Concerns\TransfersContracts;
use App\Http\Controllers\Concerns\VacatesContracts;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Support\Billing\CurrencyGuard;
use App\Support\Billing\RecurringBilling;
use App\Support\Billing\ResolvesContractItemPrice;
use App\Support\Contracts\ContractSigning;
use App\Support\Filtering\FilterBuilder;
use App\Support\Filtering\FilterTreeValidator;
use App\Support\Time\SiteClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContractController extends Controller
{
    use GeneratesFirstPeriodCharges;
    use SearchesWithFilters;
    use TransfersContracts;
    use VacatesContracts;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attention' => ['nullable', Rule::in(['declined', 'post_cancellation'])],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'deal_id' => ['nullable', 'integer', 'exists:deals,id'],
            'status' => ['nullable', 'string'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        $chips = Contract::attentionCounts();

        $query = Contract::query()
            ->with([
                'items' => fn ($q) => $q->whereNull('effective_to')->with(['item', 'price']),
                'contact',
                'reservation',
            ])
            ->latest();

        if (isset($validated['contact_id'])) {
            $query->where('contact_id', $validated['contact_id']);
        }

        if (isset($validated['deal_id'])) {
            $query->where('deal_id', $validated['deal_id']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['unit_id'])) {
            $query->whereHas('items', function ($q) use ($validated): void {
                $q->where('item_type', 'unit')
                    ->where('item_id', $validated['unit_id']);
            });
        }

        $this->applyAttentionFilter($query, $validated['attention'] ?? null);

        $paginator = $query->paginate($this->perPage())
            ->through(fn (Contract $contract) => ContractResource::make($contract));

        return response()->json([
            'message' => 'Contracts retrieved successfully.',
            'data' => $paginator->items(),
            'meta' => array_merge([
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ], $chips),
        ]);
    }

    public function filterSchema(): JsonResponse
    {
        return $this->respondFilterSchema(AttributeEntityType::Contract);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filter' => ['nullable', 'array'],
            'sort' => ['nullable', 'array'],
            'sort.*.field' => ['required_with:sort', 'string'],
            'sort.*.dir' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'attention' => ['nullable', Rule::in(['declined', 'post_cancellation'])],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'deal_id' => ['nullable', 'integer', 'exists:deals,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        $chips = Contract::attentionCounts();

        $query = Contract::query()->with(['items.item', 'contact', 'reservation']);

        if (isset($validated['contact_id'])) {
            $query->where('contact_id', $validated['contact_id']);
        }
        if (isset($validated['deal_id'])) {
            $query->where('deal_id', $validated['deal_id']);
        }
        if (isset($validated['unit_id'])) {
            $query->whereHas('items', function ($q) use ($validated): void {
                $q->where('item_type', 'unit')
                    ->where('item_id', $validated['unit_id']);
            });
        }

        if ($request->filled('search') && method_exists($query->getModel(), 'scopeSearch')) {
            $query->search($request->string('search')->trim()->value());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        $this->applyAttentionFilter($query, $validated['attention'] ?? null);

        $filter = (new FilterTreeValidator(AttributeEntityType::Contract))
            ->validate($validated['filter'] ?? null);

        if ($filter !== null) {
            FilterBuilder::for(AttributeEntityType::Contract)->apply($query, $filter);
        }

        FilterBuilder::for(AttributeEntityType::Contract)
            ->applySort($query, $validated['sort'] ?? []);

        $perPage = min(max((int) ($validated['per_page'] ?? $this->perPage()), 1), 100);
        $paginator = $query->paginate($perPage)
            ->through(fn (Contract $contract) => ContractResource::make($contract));

        return response()->json([
            'message' => 'Contracts retrieved successfully.',
            'data' => $paginator->items(),
            'meta' => array_merge([
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ], $chips),
        ]);
    }

    private function applyAttentionFilter(Builder $query, ?string $attention): void
    {
        if ($attention === 'declined') {
            $query->attentionDeclined();
        } elseif ($attention === 'post_cancellation') {
            $query->attentionPostCancellation();
        }
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
            'signed_at'      => ['nullable', 'date'],
            'signature_mode' => ['nullable', Rule::in(['immediate', 'remote'])],
            'items'          => ['required', 'array', 'min:1'],
            'items.*.item_type'             => ['required', 'string', Rule::in(['unit', 'insurance'])],
            'items.*.item_id'               => ['required', 'integer'],
            'items.*.amount'                => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate_id'           => ['nullable', 'integer', 'exists:tax_rates,id'],
            'items.*.declared_goods_value'  => ['nullable', 'numeric', 'min:0'],
            'items.*.description'           => ['nullable', 'string'],
        ]);

        $signatureMode = $validated['signature_mode'] ?? 'immediate';

        $contract = DB::transaction(function () use ($validated, $request, $signatureMode) {
            $billing = Setting::billing();
            $leasing = Setting::leasing();
            $moveIn = CarbonImmutable::parse($validated['move_in_date'] ?? $validated['start_date'])->startOfDay();
            $endedOn = isset($validated['end_date'])
                ? CarbonImmutable::parse($validated['end_date'])->startOfDay()
                : null;

            $siteId = null;
            foreach ($validated['items'] as $itemData) {
                if ($itemData['item_type'] === 'unit') {
                    $siteId = Unit::query()->whereKey($itemData['item_id'])->value('site_id');
                    break;
                }
            }

            $today = CarbonImmutable::today()->startOfDay();
            if ($siteId !== null) {
                $site = Site::query()->find($siteId);
                if ($site !== null) {
                    $today = SiteClock::today($site);
                }
            }

            $remote = $signatureMode === 'remote';
            $status = $remote
                ? ContractStatus::AwaitingSignature
                : ($moveIn->toDateString() > $today->toDateString()
                    ? ContractStatus::Pending
                    : ContractStatus::Active);

            $contract = Contract::query()->create([
                'contact_id'             => $validated['contact_id'],
                'reservation_id'         => $validated['reservation_id'] ?? null,
                'deal_id'                => $validated['deal_id'] ?? null,
                'start_date'             => $validated['start_date'],
                'end_date'               => $validated['end_date'] ?? null,
                'status'                 => $status->value,
                'notice_period_days'     => $leasing->defaultNoticePeriodDays,
                'move_out_settlement'    => $billing->moveOutSettlement,
                'transfer_billing'       => $billing->transferBilling,
                'signed_at'              => null,
                'billing_interval'       => $billing->defaultBillingInterval,
                'billing_interval_count' => $billing->defaultBillingIntervalCount,
                'billing_anchor_model'   => $billing->billingAnchorModel,
                'proration_method'       => $billing->prorationMethod,
                'move_in_date'           => $moveIn->toDateString(),
                'deposit_amount'         => $validated['deposit_amount'] ?? $billing->defaultDepositAmount,
                // Placeholder until items agree — overwritten before charges.
                'currency'               => $billing->defaultCurrency ?: 'EUR',
            ]);

            $createdBy = $request->user()?->id;
            $resolvedSite = $siteId !== null
                ? Site::query()->with('country')->find($siteId)
                : null;

            $contractItems = collect($validated['items'])->map(function (array $itemData) use ($contract, $moveIn, $siteId, $createdBy, $resolvedSite) {
                $taxRate = $this->resolveContractItemTaxRate(
                    $itemData['item_type'],
                    $itemData['item_id'],
                    $itemData['tax_rate_id'] ?? null,
                    $moveIn,
                    $resolvedSite,
                );

                $itemSiteId = $itemData['item_type'] === 'unit'
                    ? Unit::query()->whereKey($itemData['item_id'])->value('site_id')
                    : $siteId;

                $price = ResolvesContractItemPrice::forSigning(
                    $itemData['item_type'],
                    (int) $itemData['item_id'],
                    (string) $itemData['amount'],
                    $itemSiteId !== null ? (int) $itemSiteId : null,
                    $createdBy,
                );

                return $contract->items()->create([
                    'item_type'             => $itemData['item_type'],
                    'item_id'               => $itemData['item_id'],
                    'price_id'              => $price->id,
                    'effective_from'        => $moveIn->toDateString(),
                    'effective_to'          => null,
                    'change_reason'         => null,
                    'tax_rate_id'           => $taxRate?->id,
                    'tax_rate_snapshot'     => $taxRate?->rate,
                    'declared_goods_value'  => $itemData['declared_goods_value'] ?? null,
                    'description'           => $itemData['description'] ?? null,
                ]);
            });

            $contractItems->each->load('price');

            $agreedCurrency = CurrencyGuard::assertItemsAgree($contractItems);
            $contract->forceFill(['currency' => $agreedCurrency])->save();

            if ($remote) {
                ContractSigning::writeSignatureHolds($contract, $contractItems, $createdBy);
            } else {
                ContractSigning::complete(
                    $contract,
                    $endedOn,
                    $createdBy,
                    $validated['signed_at'] ?? now(),
                );
            }

            return $contract;
        });

        return $this->created(
            ContractResource::make($contract->load(['items.price', 'items.item', 'contact', 'reservation', 'occupancies'])),
            'Contract created successfully.'
        );
    }

    public function cancel(Contract $contract): JsonResponse
    {
        DB::transaction(function () use ($contract): void {
            ContractSigning::cancel($contract);
        });

        $contract->refresh();
        $this->loadDetailRelations($contract);

        return $this->success(
            ContractResource::make($contract),
            'Contract cancelled successfully.'
        );
    }

    public function show(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['nullable', 'date'],
        ]);

        $asOf = Carbon::parse($validated['as_of'] ?? Carbon::today()->toDateString())->startOfDay();

        $this->loadDetailRelations($contract, $asOf);

        return $this->success(
            ContractResource::make($contract),
            'Contract retrieved successfully.'
        );
    }

    public function nextBill(Contract $contract): JsonResponse
    {
        return $this->success(
            RecurringBilling::nextBillEstimate($contract),
            'Next bill estimate retrieved successfully.',
        );
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date'   => ['sometimes', 'nullable', 'date'],
            'signed_at'  => ['sometimes', 'required', 'date'],
        ]);

        $contract->update($validated);
        $contract->refresh();

        $this->loadDetailRelations($contract);

        return $this->success(
            ContractResource::make($contract),
            'Contract updated successfully.'
        );
    }

    public function destroy(Contract $contract): JsonResponse
    {
        $contract->delete();

        return $this->noContent('Contract deleted successfully.');
    }

    private function loadDetailRelations(Contract $contract, ?Carbon $asOf = null): void
    {
        $asOf ??= Carbon::today();

        $contract->load([
            'contact',
            'reservation',
            'deal',
            'notes.employee',
            'occupancies.unit',
            'depositSettlement.lines',
            'paymentMethod',
            'autopayAttempts' => fn ($query) => $query->latest('id')->limit(1),
            'billingPeriods' => fn ($query) => $query->orderByDesc('billing_period_start')->with('charges'),
            'payments' => fn ($query) => $query->orderByDesc('created_at')->with('allocations'),
            'charges' => fn ($query) => $query->orderBy('due_date')->orderBy('id')->with('allocations'),
        ]);

        $allItems = $contract->items()
            ->with([
                'price',
                'discount',
                'taxRate',
                'item' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([
                        Unit::class => ['site', 'unitClass'],
                    ]);
                },
            ])
            ->orderBy('id')
            ->get();

        $current = $allItems->filter(fn ($item) => $item->effective_from->lte($asOf)
            && ($item->effective_to === null || $item->effective_to->gt($asOf)))->values();

        $history = $allItems->filter(fn ($item) => ! $current->contains('id', $item->id))->values();

        $contract->setRelation('items', $current);
        $contract->setRelation('itemHistory', $history);
    }
}
