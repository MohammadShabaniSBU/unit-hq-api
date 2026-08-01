<?php

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Enums\ContractStatus;
use App\Http\Controllers\Concerns\GeneratesFirstPeriodCharges;
use App\Http\Controllers\Concerns\SearchesWithFilters;
use App\Http\Controllers\Concerns\TransfersContracts;
use App\Http\Controllers\Concerns\VacatesContracts;
use App\Http\Controllers\Concerns\WritesUnitOccupancies;
use App\Http\Resources\ContractResource;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Support\Billing\ContractBilling;
use App\Support\Billing\CurrencyGuard;
use App\Support\Billing\RecurringBilling;
use App\Support\Billing\ResolvesContractItemPrice;
use App\Support\Fiscal\InvoiceIssuer;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Carbon\Carbon;
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
    use TransfersContracts;
    use VacatesContracts;
    use WritesUnitOccupancies;

    public function index(Request $request): JsonResponse
    {
        $query = Contract::query()
            ->with([
                'items' => fn ($q) => $q->whereNull('effective_to')->with(['item', 'price']),
                'contact',
                'reservation',
            ])
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

            $status = $moveIn->toDateString() > $today->toDateString()
                ? ContractStatus::Pending
                : ContractStatus::Active;

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

            $this->writeUnitOccupancies($contract, $contractItems, $moveIn, $endedOn, $request->user()?->id);

            $plan = ContractBilling::planFirstPeriod(
                $moveIn,
                $billing->billingAnchorModel,
                $billing->defaultBillingInterval,
                $billing->defaultBillingIntervalCount,
                $billing->billingAnchorDay,
            );

            $this->generateFirstPeriodCharges($contract, $contractItems, $plan, $billing->prorationMethod, $moveIn);

            $contract->load(['contact', 'unitItem.item.site.country', 'unitItem.item.site.legalEntity']);
            $charges = Charge::query()->where('contract_id', $contract->id)->get();
            InvoiceIssuer::issue($contract, $charges, null, $request->user()?->id);

            $signedProps = ['reservation_id' => $contract->reservation_id];
            RecordsActivity::core('contract.signed', $contract, $signedProps);
            $contract->loadMissing('contact');
            if ($contract->contact !== null) {
                RecordsActivity::core('contract.signed', $contract->contact, $signedProps);
            }

            return $contract;
        });

        return $this->created(
            ContractResource::make($contract->load(['items.price', 'items.item', 'contact', 'reservation', 'occupancies'])),
            'Contract created successfully.'
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
