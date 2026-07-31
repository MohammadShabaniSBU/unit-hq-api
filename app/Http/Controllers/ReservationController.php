<?php

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Enums\ContractStatus;
use App\Enums\ProrationMethod;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Concerns\GeneratesFirstPeriodCharges;
use App\Http\Controllers\Concerns\SearchesWithFilters;
use App\Http\Controllers\Concerns\WritesReservationHolds;
use App\Http\Controllers\Concerns\WritesUnitOccupancies;
use App\Http\Resources\ContractResource;
use App\Http\Resources\DiscountResource;
use App\Http\Resources\ReservationCardResource;
use App\Http\Resources\ReservationResource;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Discount;
use App\Models\Insurance;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Site;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Support\Billing\BillingMath;
use App\Support\Billing\ContractBilling;
use App\Support\Billing\CurrencyGuard;
use App\Support\Billing\FirstPeriodPlan;
use App\Support\Billing\ResolvesContractItemPrice;
use App\Support\Billing\ResolvesItemCurrency;
use App\Support\Billing\TaxBreakdown;
use App\Support\Fiscal\InvoiceIssuer;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    use GeneratesFirstPeriodCharges;
    use SearchesWithFilters;
    use WritesReservationHolds;
    use WritesUnitOccupancies;

    public function index(Request $request): JsonResponse
    {
        $query = Reservation::query()
            ->with(['unit.site', 'unit.unitClass', 'contact', 'contract', 'price'])
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
            $query->where('unit_id', $request->integer('unit_id'));
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(fn (Reservation $r) => ReservationResource::make($r)),
            'Reservations retrieved successfully.'
        );
    }

    public function filterSchema(): JsonResponse
    {
        return $this->respondFilterSchema(AttributeEntityType::Reservation);
    }

    public function search(Request $request): JsonResponse
    {
        return $this->searchWithFilters(
            $request,
            AttributeEntityType::Reservation,
            Reservation::query()->with(['unit.site', 'unit.unitClass', 'contact', 'contract', 'price']),
            fn (Reservation $r) => ReservationResource::make($r),
            'Reservations retrieved successfully.',
            function ($query, Request $request): void {
                if ($request->filled('contact_id')) {
                    $query->where('contact_id', $request->integer('contact_id'));
                }
                if ($request->filled('deal_id')) {
                    $query->where('deal_id', $request->integer('deal_id'));
                }
                if ($request->filled('unit_id')) {
                    $query->where('unit_id', $request->integer('unit_id'));
                }
            },
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id'         => ['required', 'integer', 'exists:sites,id'],
            'unit_class_id'   => ['required', 'integer', 'exists:unit_classes,id'],
            'unit_id'         => ['nullable', 'integer', 'exists:units,id'],
            'contact_id'      => ['required', 'integer', 'exists:contacts,id'],
            'deal_id'         => ['nullable', 'integer', 'exists:deals,id'],
            'offer_option_id' => ['nullable', 'integer', 'exists:offer_options,id'],
            'status'          => ['nullable', Rule::enum(ReservationStatus::class)],
            'expires_at'      => ['required', 'date'],
        ]);

        $reservation = DB::transaction(function () use ($validated): Reservation {
            if (! empty($validated['deal_id'])) {
                $deal = Deal::query()->findOrFail($validated['deal_id']);

                if ($deal->site_id === null) {
                    throw ValidationException::withMessages([
                        'deal_id' => ['Selected deal is missing a site and cannot create a reservation.'],
                    ]);
                }

                if ($deal->site_id !== $validated['site_id']) {
                    throw ValidationException::withMessages([
                        'site_id' => ['Selected site must match the deal site.'],
                    ]);
                }
            }

            $latestRate = UnitClassRate::query()
                ->with('price')
                ->where('site_id', $validated['site_id'])
                ->where('unit_class_id', $validated['unit_class_id'])
                ->first();

            if ($latestRate === null || $latestRate->price === null) {
                throw ValidationException::withMessages([
                    'unit_class_id' => ['No active price configured for this unit class at the selected site.'],
                ]);
            }

            // Explicit unit_id: lock without availability scope so occupied/held
            // units surface OccupancyGuard / HoldGuard 422s. Auto-pick uses
            // Availability + site-local today (D8).
            if (! empty($validated['unit_id'])) {
                $selectedUnit = Unit::query()
                    ->where('site_id', $validated['site_id'])
                    ->where('unit_class_id', $validated['unit_class_id'])
                    ->where('enabled', true)
                    ->whereKey($validated['unit_id'])
                    ->lockForUpdate()
                    ->first();
            } else {
                $site = Site::query()->findOrFail($validated['site_id']);
                $selectedUnit = Unit::query()
                    ->where('site_id', $validated['site_id'])
                    ->where('unit_class_id', $validated['unit_class_id'])
                    ->where('enabled', true)
                    ->availableOn(SiteClock::today($site))
                    ->lockForUpdate()
                    ->inRandomOrder()
                    ->first();
            }

            if (! $selectedUnit) {
                throw ValidationException::withMessages([
                    'unit_id' => ['No available unit found for the selected site and unit class.'],
                ]);
            }

            $selectedUnit->load('site');

            $reservationData = $validated;
            unset($reservationData['site_id'], $reservationData['unit_class_id'], $reservationData['unit_id']);

            $reservationData['unit_id'] = $selectedUnit->id;
            $reservationData['price_id'] = $latestRate->price->id;

            $reservation = Reservation::query()->create($reservationData);

            $this->writeReservationHold($reservation, $selectedUnit);

            RecordsActivity::core('reservation.created', $reservation, [
                'unit_id' => $reservation->unit_id,
                'hold_expires_at' => $reservation->expires_at?->toIso8601String(),
            ]);

            return $reservation;
        });

        return $this->created(
            ReservationResource::make($reservation->load(['unit.site', 'unit.unitClass', 'contact', 'deal'])),
            'Reservation created successfully.'
        );
    }

    public function show(Reservation $reservation): JsonResponse
    {
        $reservation->load(['unit.site', 'unit.unitClass', 'contact', 'contract', 'notes']);

        return $this->success(
            ReservationResource::make($reservation),
            'Reservation retrieved successfully.'
        );
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        $validated = $request->validate([
            'unit_id'    => ['sometimes', 'required', 'integer', 'exists:units,id'],
            'status'     => ['sometimes', 'required', Rule::enum(ReservationStatus::class)],
            'expires_at' => ['sometimes', 'required', 'date'],
        ]);

        $previousStatus = $reservation->status;
        $reservation->update($validated);
        $reservation = $reservation->fresh()->load(['unit.site', 'unit.unitClass', 'contact', 'contract']);

        if (array_key_exists('status', $validated)) {
            $newStatus = $reservation->status;
            $becameCancelled = $newStatus === ReservationStatus::Cancelled
                || $newStatus === ReservationStatus::Cancelled->value;
            $wasCancelled = $previousStatus === ReservationStatus::Cancelled
                || $previousStatus === ReservationStatus::Cancelled->value;

            if ($becameCancelled && ! $wasCancelled) {
                RecordsActivity::core('reservation.cancelled', $reservation, [
                    'unit_id' => $reservation->unit_id,
                    'hold_expires_at' => $reservation->expires_at?->toIso8601String(),
                ]);
            }
        }

        return $this->success(
            ReservationResource::make($reservation),
            'Reservation updated successfully.'
        );
    }

    public function updateStatus(Request $request, Reservation $reservation): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(ReservationStatus::class)],
        ]);

        $previousStatus = $reservation->status;
        $reservation->update(['status' => $validated['status']]);
        $reservation = $reservation->fresh()->load(['contact', 'unit.site']);

        $newStatus = $reservation->status;
        $becameCancelled = $newStatus === ReservationStatus::Cancelled
            || $newStatus === ReservationStatus::Cancelled->value;
        $wasCancelled = $previousStatus === ReservationStatus::Cancelled
            || $previousStatus === ReservationStatus::Cancelled->value;

        if ($becameCancelled && ! $wasCancelled) {
            RecordsActivity::core('reservation.cancelled', $reservation, [
                'unit_id' => $reservation->unit_id,
                'hold_expires_at' => $reservation->expires_at?->toIso8601String(),
            ]);
        }

        return $this->success(
            ReservationCardResource::make($reservation),
            'Reservation status updated successfully.'
        );
    }

    public function destroy(Reservation $reservation): JsonResponse
    {
        $reservation->delete();

        return $this->noContent('Reservation deleted successfully.');
    }

    /**
     * Preview move-in totals before converting a reservation to a contract.
     * Read-only — does not create invoices or charges.
     */
    public function convertPreview(Request $request, Reservation $reservation): JsonResponse
    {
        $this->assertConvertible($reservation);

        $validated = $request->validate([
            'start_date'     => ['nullable', 'date'],
            'move_in_date'   => ['nullable', 'date'],
            'unit_rate'      => ['nullable', 'numeric', 'min:0'],
            'insurance_id'   => ['nullable', 'integer', 'exists:insurances,id'],
            'insurance_rate' => ['nullable', 'required_with:insurance_id', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $reservation->load([
            'unit.site',
            'unit.unitClass',
            'contact',
            'price',
            'offerOption.discount',
            'offerOption.unitClassRate.price',
        ]);

        $billing = Setting::billing();
        $startDate = Carbon::parse($validated['start_date'] ?? now()->toDateString())->startOfDay();
        $moveIn = CarbonImmutable::parse($validated['move_in_date'] ?? $startDate->toDateString())->startOfDay();
        $pricing = $this->resolveConvertPricing($reservation, $startDate);

        $suggestedUnitRate = $pricing['suggested_unit_rate'];
        $unitRate = array_key_exists('unit_rate', $validated) && $validated['unit_rate'] !== null
            ? round((float) $validated['unit_rate'], 2)
            : $suggestedUnitRate;

        $insuranceRate = null;
        if (! empty($validated['insurance_id'])) {
            $insuranceRate = round((float) ($validated['insurance_rate'] ?? 0), 2);
        }

        $currency = $reservation->price?->currency
            ?? $reservation->offerOption?->unitClassRate?->price?->currency
            ?? null;

        $depositAmount = $validated['deposit_amount'] ?? $billing->defaultDepositAmount;

        $unitTaxRate = ContractBilling::resolveTaxRate($reservation->unit->unitClass?->tax_rate_code, $moveIn);
        $insuranceTaxRate = ! empty($validated['insurance_id'])
            ? ContractBilling::resolveTaxRate(Insurance::query()->find($validated['insurance_id'])?->tax_rate_code, $moveIn)
            : null;

        $plan = ContractBilling::planFirstPeriod(
            $moveIn,
            $billing->billingAnchorModel,
            $billing->defaultBillingInterval,
            $billing->defaultBillingIntervalCount,
            $billing->billingAnchorDay,
        );

        $firstPeriod = $this->buildFirstPeriodPreview(
            $plan,
            $billing->prorationMethod,
            (string) $unitRate,
            $unitTaxRate,
            $insuranceRate !== null ? (string) $insuranceRate : null,
            $insuranceTaxRate,
        );

        $rateOverridden = $pricing['discount'] !== null
            && abs($unitRate - $suggestedUnitRate) > 0.001;

        $invoicePreview = InvoiceIssuer::previewForContact(
            $reservation->contact,
            (string) $firstPeriod['total_gross'],
            (string) $firstPeriod['total_net'],
            (string) $firstPeriod['total_tax'],
        );

        if ($invoicePreview['invoice_blocker'] === 'simplified_limit_exceeded') {
            throw ValidationException::withMessages([
                'invoice' => [__('errors.invoices.simplified_limit_exceeded', [
                    'limit' => (string) config('fiscal.simplified_gross_limit', '400.00'),
                    'gross' => (string) $firstPeriod['total_gross'],
                ])],
            ]);
        }

        return $this->success([
            'contact' => [
                'id'   => $reservation->contact->id,
                'name' => trim($reservation->contact->first_name.' '.$reservation->contact->last_name),
            ],
            'unit' => [
                'id'          => $reservation->unit->id,
                'unit_number' => $reservation->unit->unit_number,
                'site'        => $reservation->unit->site ? [
                    'id'   => $reservation->unit->site->id,
                    'name' => $reservation->unit->site->name,
                ] : null,
                'unit_class'  => $reservation->unit->unitClass ? [
                    'id'    => $reservation->unit->unitClass->id,
                    'label' => $reservation->unit->unitClass->label,
                    'code'  => $reservation->unit->unitClass->code_slug,
                ] : null,
            ],
            'billing_interval'       => $billing->defaultBillingInterval,
            'billing_interval_count' => $billing->defaultBillingIntervalCount,
            'billing_anchor_model'   => $billing->billingAnchorModel,
            'currency'            => $currency,
            'base_rate'           => $this->formatMoney($pricing['base_rate']),
            'suggested_unit_rate' => $this->formatMoney($suggestedUnitRate),
            'unit_rate'           => $this->formatMoney($unitRate),
            'unit_tax_rate'       => $this->formatTaxRate($unitTaxRate),
            'insurance_id'        => $validated['insurance_id'] ?? null,
            'insurance_rate'      => $insuranceRate !== null ? $this->formatMoney($insuranceRate) : null,
            'insurance_tax_rate'  => $this->formatTaxRate($insuranceTaxRate),
            'deposit_amount'      => $this->formatMoney((float) $depositAmount),
            'move_in_date'        => $moveIn->toDateString(),
            'discount'            => $pricing['discount'] !== null
                ? DiscountResource::make($pricing['discount'])->resolve()
                : null,
            'discount_ends_at'    => $pricing['discount_ends_at'],
            'rate_overridden'     => $rateOverridden,
            'first_period'        => $firstPeriod,
            ...$invoicePreview,
        ], 'Convert preview retrieved successfully.');
    }

    /**
     * Convert a reservation to a contract (contract signing).
     * Creates a Contract with a unit item and an optional insurance item
     * from the reservation's unit/contact/deal data.
     */
    public function convert(Request $request, Reservation $reservation): JsonResponse
    {
        $this->assertConvertible($reservation);

        $validated = $request->validate([
            'start_date'            => ['required', 'date'],
            'end_date'              => ['nullable', 'date', 'after:start_date'],
            'move_in_date'          => ['nullable', 'date'],
            'signed_at'             => ['nullable', 'date'],
            'unit_rate'             => ['required', 'numeric', 'min:0'],
            'unit_tax_rate_id'      => ['nullable', 'integer', 'exists:tax_rates,id'],
            'insurance_id'          => ['nullable', 'integer', 'exists:insurances,id'],
            'insurance_rate'        => ['nullable', 'required_with:insurance_id', 'numeric', 'min:0'],
            'insurance_tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
            'deposit_amount'        => ['nullable', 'numeric', 'min:0'],
        ]);

        $contract = DB::transaction(function () use ($reservation, $validated, $request) {
            $reservation->load([
                'unit.unitClass',
                'offerOption.discount',
                'offerOption.unitClassRate.price',
            ]);

            $billing = Setting::billing();
            $leasing = Setting::leasing();
            $startDate = Carbon::parse($validated['start_date'])->startOfDay();
            $moveIn = CarbonImmutable::parse($validated['move_in_date'] ?? $validated['start_date'])->startOfDay();
            $endedOn = isset($validated['end_date'])
                ? CarbonImmutable::parse($validated['end_date'])->startOfDay()
                : null;
            $pricing = $this->resolveConvertPricing($reservation, $startDate);
            $unitRate = round((float) $validated['unit_rate'], 2);

            $unitPrice = $reservation->price
                ?? $reservation->offerOption?->unitClassRate?->price;
            $unitCurrency = $unitPrice?->currency
                ?? ResolvesItemCurrency::forItem('unit', $reservation->unit_id, $reservation->price_id, $reservation->unit?->site_id);

            $site = $reservation->unit?->site;
            $today = $site !== null
                ? SiteClock::today($site)
                : CarbonImmutable::today()->startOfDay();
            $status = $moveIn->toDateString() > $today->toDateString()
                ? ContractStatus::Pending
                : ContractStatus::Active;

            $contract = Contract::query()->create([
                'contact_id'             => $reservation->contact_id,
                'reservation_id'         => $reservation->id,
                'deal_id'                => $reservation->deal_id,
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
                'currency'               => $unitCurrency,
            ]);

            $unitTaxRate = $this->resolveContractItemTaxRate(
                'unit',
                $reservation->unit_id,
                $validated['unit_tax_rate_id'] ?? null,
                $moveIn,
            );

            $resolvedUnitPrice = ResolvesContractItemPrice::forSigning(
                'unit',
                $reservation->unit_id,
                (string) $unitRate,
                $reservation->unit?->site_id,
                $request->user()?->id,
                $unitPrice,
            );

            $unitItemData = [
                'item_type'         => 'unit',
                'item_id'           => $reservation->unit_id,
                'price_id'          => $resolvedUnitPrice->id,
                'effective_from'    => $moveIn->toDateString(),
                'effective_to'      => null,
                'change_reason'     => null,
                'tax_rate_id'       => $unitTaxRate?->id,
                'tax_rate_snapshot' => $unitTaxRate?->rate,
            ];

            if ($pricing['discount'] !== null) {
                $unitItemData['base_rate'] = $pricing['base_rate'];
                $unitItemData['discount_id'] = $pricing['discount']->id;
                $unitItemData['discount_ends_at'] = $pricing['discount_ends_at'];
            }

            $contractItems = collect([$contract->items()->create($unitItemData)]);

            if (! empty($validated['insurance_id'])) {
                $insuranceTaxRate = $this->resolveContractItemTaxRate(
                    'insurance',
                    $validated['insurance_id'],
                    $validated['insurance_tax_rate_id'] ?? null,
                    $moveIn,
                );

                $insuranceAmount = round((float) $validated['insurance_rate'], 2);
                $resolvedInsurancePrice = ResolvesContractItemPrice::forSigning(
                    'insurance',
                    (int) $validated['insurance_id'],
                    (string) $insuranceAmount,
                    $reservation->unit?->site_id,
                    $request->user()?->id,
                );

                $contractItems->push($contract->items()->create([
                    'item_type'         => 'insurance',
                    'item_id'           => $validated['insurance_id'],
                    'price_id'          => $resolvedInsurancePrice->id,
                    'effective_from'    => $moveIn->toDateString(),
                    'effective_to'      => null,
                    'change_reason'     => null,
                    'tax_rate_id'       => $insuranceTaxRate?->id,
                    'tax_rate_snapshot' => $insuranceTaxRate?->rate,
                ]));
            }

            $contractItems->each->load('price');
            $agreedCurrency = CurrencyGuard::assertItemsAgree($contractItems);
            $contract->forceFill(['currency' => $agreedCurrency])->save();

            // Release reservation hold before opening occupancy — same TX; half-open
            // ranges allow occupancy to start on the release day.
            $this->releaseReservationHold($reservation);

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

            $reservation->update(['status' => ReservationStatus::Confirmed->value]);

            $signedProps = ['reservation_id' => $reservation->id];
            RecordsActivity::core('contract.signed', $contract, $signedProps);
            $contract->loadMissing('contact');
            if ($contract->contact !== null) {
                RecordsActivity::core('contract.signed', $contract->contact, $signedProps);
            }

            return $contract;
        });

        return $this->created(
            ContractResource::make($contract->load(['items.price', 'items.item', 'contact', 'reservation', 'occupancies'])),
            'Reservation converted to contract successfully.'
        );
    }

    private function assertConvertible(Reservation $reservation): void
    {
        if ($reservation->contract()->exists()) {
            throw ValidationException::withMessages([
                'reservation' => ['This reservation already has a contract.'],
            ]);
        }

        if (in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::Expired], true)) {
            throw ValidationException::withMessages([
                'reservation' => ['This reservation cannot be converted.'],
            ]);
        }
    }

    /**
     * @return array{
     *     base_rate: float,
     *     suggested_unit_rate: float,
     *     discount: Discount|null,
     *     discount_ends_at: string|null
     * }
     */
    private function resolveConvertPricing(Reservation $reservation, Carbon $startDate): array
    {
        $offerOption = $reservation->offerOption;
        $discount = $offerOption?->discount;
        $offerPrice = $offerOption?->unitClassRate?->price;

        $baseRate = $offerPrice !== null
            ? (float) $offerPrice->amount
            : (float) ($reservation->price?->amount ?? 0);

        $suggestedUnitRate = $discount !== null && $baseRate > 0
            ? $discount->applyTo($baseRate)
            : $baseRate;

        $discountEndsAt = null;
        if ($discount !== null && $discount->duration_months !== null) {
            $discountEndsAt = $startDate->copy()->addMonths($discount->duration_months)->toDateString();
        }

        return [
            'base_rate'           => round($baseRate, 2),
            'suggested_unit_rate' => round($suggestedUnitRate, 2),
            'discount'            => $discount,
            'discount_ends_at'    => $discountEndsAt,
        ];
    }

    /**
     * The real first-charge preview — same BillingMath::firstChargeWindow +
     * prorate + applyTax path convert() uses to write charges, so this never
     * diverges from what gets billed.
     *
     * @return array{
     *     start_date: string,
     *     end_date: string,
     *     has_stub: bool,
     *     skipped: bool,
     *     days_occupied: int|null,
     *     days_in_period: int|null,
     *     unit: array{net: string, tax: string, gross: string}|null,
     *     insurance: array{net: string, tax: string, gross: string}|null,
     *     total_net: string,
     *     total_tax: string,
     *     total_gross: string
     * }
     */
    private function buildFirstPeriodPreview(
        FirstPeriodPlan $plan,
        ProrationMethod|string $prorationMethod,
        string $unitAmount,
        ?TaxRate $unitTaxRate,
        ?string $insuranceAmount,
        ?TaxRate $insuranceTaxRate,
    ): array {
        $method = $prorationMethod instanceof ProrationMethod ? $prorationMethod : ProrationMethod::from($prorationMethod);
        $skipped = $plan->hasStub && $method === ProrationMethod::None;

        $unitLine = null;
        $insuranceLine = null;

        if (! $skipped) {
            $unitNet = ContractBilling::firstPeriodNetForItem($plan, $unitAmount, $method);
            $unitLine = $this->formatChargeLine(
                BillingMath::applyTax($unitNet, $unitTaxRate !== null ? (string) $unitTaxRate->rate : null)
            );

            if ($insuranceAmount !== null) {
                $insuranceNet = ContractBilling::firstPeriodNetForItem($plan, $insuranceAmount, $method);
                $insuranceLine = $this->formatChargeLine(
                    BillingMath::applyTax($insuranceNet, $insuranceTaxRate !== null ? (string) $insuranceTaxRate->rate : null)
                );
            }
        }

        return [
            'start_date'     => $plan->windowStart->toDateString(),
            'end_date'       => $plan->windowEnd->toDateString(),
            'has_stub'       => $plan->hasStub,
            'skipped'        => $skipped,
            'days_occupied'  => $plan->daysOccupied,
            'days_in_period' => $plan->daysInPeriod,
            'unit'           => $unitLine,
            'insurance'      => $insuranceLine,
            'total_net'      => bcadd($unitLine['net'] ?? '0.00', $insuranceLine['net'] ?? '0.00', 2),
            'total_tax'      => bcadd($unitLine['tax'] ?? '0.00', $insuranceLine['tax'] ?? '0.00', 2),
            'total_gross'    => bcadd($unitLine['gross'] ?? '0.00', $insuranceLine['gross'] ?? '0.00', 2),
        ];
    }

    /** @return array{net: string, tax: string, gross: string} */
    private function formatChargeLine(TaxBreakdown $breakdown): array
    {
        return [
            'net'   => $breakdown->net,
            'tax'   => $breakdown->tax,
            'gross' => $breakdown->gross,
        ];
    }

    /** @return array{id: int, name: string, code: string, rate: string}|null */
    private function formatTaxRate(?TaxRate $taxRate): ?array
    {
        if ($taxRate === null) {
            return null;
        }

        return [
            'id'   => $taxRate->id,
            'name' => $taxRate->name,
            'code' => $taxRate->code,
            'rate' => (string) $taxRate->rate,
        ];
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
