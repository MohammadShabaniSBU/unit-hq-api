<?php

namespace App\Http\Controllers;

use App\Enums\AccessSuspensionLiftReason;
use App\Enums\AccessSuspensionReason;
use App\Enums\AttributeEntityType;
use App\Enums\ContractStatus;
use App\Http\Controllers\Concerns\AppliesPortalSiteFilter;
use App\Http\Controllers\Concerns\GeneratesFirstPeriodCharges;
use App\Http\Controllers\Concerns\SearchesWithFilters;
use App\Http\Controllers\Concerns\TransfersContracts;
use App\Http\Controllers\Concerns\VacatesContracts;
use App\Http\Resources\ContractResource;
use App\Models\AccessSuspension;
use App\Models\Contract;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Support\Attributes\AppliesCreateAttributes;
use App\Support\Auth\Permission;
use App\Support\Billing\BillingMath;
use App\Support\Billing\CurrencyGuard;
use App\Support\Billing\RecurringBilling;
use App\Support\Billing\ResolvesContractItemPrice;
use App\Support\Contracts\ContractSigning;
use App\Support\Discounts\AttachesDiscount;
use App\Support\Discounts\RemovesDiscount;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContractController extends Controller
{
    use AppliesPortalSiteFilter;
    use GeneratesFirstPeriodCharges;
    use SearchesWithFilters;
    use TransfersContracts;
    use VacatesContracts;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContractView->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'attention' => ['nullable', Rule::in(['declined', 'post_cancellation', 'failed_grants', 'drift_denied_but_granted'])],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'deal_id' => ['nullable', 'integer', 'exists:deals,id'],
            'status' => ['nullable', 'string'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        $base = Contract::query()->visibleTo($employee, Permission::ContractView);
        $this->applyPortalSiteFilter($base, $request, Contract::class, Permission::ContractView);
        $chips = Contract::attentionCounts(clone $base);

        $query = (clone $base)
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
        Gate::authorize(Permission::ContractView->value);

        return $this->respondFilterSchema(AttributeEntityType::Contract);
    }

    public function search(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContractView->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'filter' => ['nullable', 'array'],
            'sort' => ['nullable', 'array'],
            'sort.*.field' => ['required_with:sort', 'string'],
            'sort.*.dir' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'attention' => ['nullable', Rule::in(['declined', 'post_cancellation', 'failed_grants', 'drift_denied_but_granted'])],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'deal_id' => ['nullable', 'integer', 'exists:deals,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        $base = Contract::query()->visibleTo($employee, Permission::ContractView);
        $this->applyPortalSiteFilter($base, $request, Contract::class, Permission::ContractView);
        $chips = Contract::attentionCounts(clone $base);

        $query = (clone $base)->with(['items.item', 'contact', 'reservation']);

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
        } elseif ($attention === 'failed_grants') {
            $query->attentionFailedGrants();
        } elseif ($attention === 'drift_denied_but_granted') {
            $query->attentionDriftDeniedButGranted();
        }
    }

    public function store(Request $request): JsonResponse
    {
        // Authorize against the unit (site carrier) before validation/transaction.
        $unitId = null;
        foreach ((array) $request->input('items', []) as $item) {
            if (($item['item_type'] ?? null) === 'unit' && isset($item['item_id'])) {
                $unitId = (int) $item['item_id'];
                break;
            }
        }
        $unit = $unitId !== null ? Unit::query()->find($unitId) : null;
        if ($unit instanceof Unit) {
            Gate::authorize(Permission::ContractSign->value, $unit);
        } else {
            Gate::authorize(Permission::ContractSign->value);
        }

        $validated = $request->validate([
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'deal_id' => ['nullable', 'integer', 'exists:deals,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'move_in_date' => ['nullable', 'date'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'signed_at' => ['nullable', 'date'],
            'signature_mode' => ['nullable', Rule::in(['immediate', 'remote'])],
            'discount_id' => ['nullable', 'integer', 'exists:discounts,id'],
            'commitment_weeks' => ['nullable', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_type' => ['required', 'string', Rule::in(['unit', 'insurance'])],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
            'items.*.declared_goods_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string'],
            ...AppliesCreateAttributes::validationRules(),
        ]);

        $signatureMode = $validated['signature_mode'] ?? 'immediate';
        $attributes = $validated['attributes'] ?? [];
        unset($validated['attributes']);

        $contract = DB::transaction(function () use ($validated, $request, $signatureMode, $attributes) {
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
                'contact_id' => $validated['contact_id'],
                'reservation_id' => $validated['reservation_id'] ?? null,
                'deal_id' => $validated['deal_id'] ?? null,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'] ?? null,
                'status' => $status->value,
                'notice_period_days' => $leasing->defaultNoticePeriodDays,
                'move_out_settlement' => $billing->moveOutSettlement,
                'transfer_billing' => $billing->transferBilling,
                'signed_at' => null,
                'billing_interval' => $billing->defaultBillingInterval,
                'billing_interval_count' => $billing->defaultBillingIntervalCount,
                'billing_anchor_model' => $billing->billingAnchorModel,
                'proration_method' => $billing->prorationMethod,
                'move_in_date' => $moveIn->toDateString(),
                'deposit_amount' => $validated['deposit_amount'] ?? $billing->defaultDepositAmount,
                // Placeholder until items agree — overwritten before charges.
                'currency' => $billing->defaultCurrency ?: 'EUR',
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
                    'item_type' => $itemData['item_type'],
                    'item_id' => $itemData['item_id'],
                    'price_id' => $price->id,
                    'effective_from' => $moveIn->toDateString(),
                    'effective_to' => null,
                    'change_reason' => null,
                    'tax_rate_id' => $taxRate?->id,
                    'tax_rate_snapshot' => $taxRate?->rate,
                    'declared_goods_value' => $itemData['declared_goods_value'] ?? null,
                    'description' => $itemData['description'] ?? null,
                ]);
            });

            $contractItems->each->load('price');

            if (! empty($validated['discount_id'])) {
                $unitCount = $contractItems->where('item_type', 'unit')->count();
                if ($unitCount !== 1) {
                    throw ValidationException::withMessages([
                        'discount_id' => ['A unit item is required to attach a discount.'],
                    ]);
                }

                $unitItemPayload = collect($validated['items'])->firstWhere('item_type', 'unit');
                /** @var Discount $discount */
                $discount = Discount::query()->findOrFail($validated['discount_id']);
                $unitItem = $contractItems->firstWhere('item_type', 'unit');
                $listAmount = BillingMath::round2((string) ($unitItemPayload['amount'] ?? $unitItem->price->amount));

                AttachesDiscount::compileAndApply(
                    $contract,
                    $discount,
                    $listAmount,
                    (string) $unitItem->price->currency,
                    $moveIn->toDateString(),
                    isset($validated['commitment_weeks']) ? (int) $validated['commitment_weeks'] : null,
                    $createdBy,
                );

                $contractItems = $contract->items()->whereNull('effective_to')->with('price')->get();
            }

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

            /** @var Employee|null $actor */
            $actor = $request->user();

            AppliesCreateAttributes::apply(
                AttributeEntityType::Contract,
                $contract,
                $attributes,
                $actor,
            );

            return $contract;
        });

        return $this->created(
            ContractResource::make($contract->load(['items.price', 'items.item', 'contact', 'reservation', 'occupancies'])),
            'Contract created successfully.'
        );
    }

    public function cancel(Contract $contract): JsonResponse
    {
        Gate::authorize(Permission::ContractSign->value, $contract);

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

    public function destroyDiscount(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize(Permission::ContractSign->value, $contract);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $employee = $user instanceof Employee ? $user : null;

        $result = RemovesDiscount::run(
            $contract,
            (string) $validated['reason'],
            $employee,
        );

        $contract->refresh();
        $this->loadDetailRelations($contract);

        return $this->success([
            'contract' => ContractResource::make($contract),
            'item' => [
                'id' => $result['item']->id,
                'amount' => $result['item']->price?->amount,
                'effective_from' => $result['item']->effective_from?->toDateString(),
                'change_reason' => $result['item']->change_reason?->value,
            ],
            'previous_item_id' => $result['previous']->id,
            'boundary' => $result['boundary'],
        ], 'Discount removed successfully.');
    }

    public function show(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize(Permission::ContractView->value, $contract);

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
        Gate::authorize(Permission::ContractView->value, $contract);

        return $this->success(
            RecurringBilling::nextBillEstimate($contract),
            'Next bill estimate retrieved successfully.',
        );
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize(Permission::ContractSign->value, $contract);

        $validated = $request->validate([
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'signed_at' => ['sometimes', 'required', 'date'],
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
        Gate::authorize(Permission::ContractSign->value, $contract);

        $contract->delete();

        return $this->noContent('Contract deleted successfully.');
    }

    public function suspendAccess(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize(Permission::AccessManage->value, $contract);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();

        AccessSuspension::suspend(
            $contract,
            AccessSuspensionReason::Manual,
            null,
            $employee,
        );

        $contract->refresh();
        $this->loadDetailRelations($contract);

        return $this->success(
            ContractResource::make($contract),
            'Access suspended successfully.'
        );
    }

    public function restoreAccess(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize(Permission::AccessManage->value, $contract);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();

        AccessSuspension::lift(
            $contract,
            AccessSuspensionLiftReason::Manual,
            $employee,
        );

        $contract->refresh();
        $this->loadDetailRelations($contract);

        return $this->success(
            ContractResource::make($contract),
            'Access restored successfully.'
        );
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
