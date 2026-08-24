<?php

namespace App\Http\Controllers;

use App\Enums\AttributeEntityType;
use App\Enums\ContractStatus;
use App\Enums\ProrationMethod;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Concerns\AppliesPortalSiteFilter;
use App\Http\Controllers\Concerns\GeneratesFirstPeriodCharges;
use App\Http\Controllers\Concerns\SearchesWithFilters;
use App\Http\Resources\ContractResource;
use App\Http\Resources\DiscountResource;
use App\Http\Resources\ReservationCardResource;
use App\Http\Resources\ReservationResource;
use App\Models\Contract;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\Insurance;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Support\Attributes\AppliesCreateAttributes;
use App\Support\Auth\Permission;
use App\Support\Billing\BillingMath;
use App\Support\Billing\ContractBilling;
use App\Support\Billing\CurrencyGuard;
use App\Support\Billing\FirstPeriodPlan;
use App\Support\Billing\ResolvesContractItemPrice;
use App\Support\Billing\ResolvesItemCurrency;
use App\Support\Billing\TaxBreakdown;
use App\Support\Contracts\ContractSigning;
use App\Support\Discounts\AttachesDiscount;
use App\Support\Discounts\CommitmentWeeks;
use App\Support\Discounts\VersionPlan;
use App\Support\Fiscal\InvoiceIssuer;
use App\Support\Fiscal\TaxResolver;
use App\Support\Leasing\LeasingActor;
use App\Support\Leasing\ReservationCreation;
use App\Support\Leasing\ReservationHolds;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    use AppliesPortalSiteFilter;
    use GeneratesFirstPeriodCharges;
    use SearchesWithFilters;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ReservationManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $query = Reservation::query()
            ->visibleTo($employee, Permission::ReservationManage)
            ->with(['unit.site', 'unit.unitClass', 'contact', 'contract', 'price'])
            ->latest();
        $this->applyPortalSiteFilter($query, $request, Reservation::class, Permission::ReservationManage);

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
        Gate::authorize(Permission::ReservationManage->value);

        return $this->respondFilterSchema(AttributeEntityType::Reservation);
    }

    public function search(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ReservationManage->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $query = Reservation::query()
            ->visibleTo($employee, Permission::ReservationManage)
            ->with(['unit.site', 'unit.unitClass', 'contact', 'contract', 'price']);
        $this->applyPortalSiteFilter($query, $request, Reservation::class, Permission::ReservationManage);

        return $this->searchWithFilters(
            $request,
            AttributeEntityType::Reservation,
            $query,
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
        Gate::authorize(Permission::ReservationManage->value);

        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'unit_class_id' => ['required', 'integer', 'exists:unit_classes,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'deal_id' => ['nullable', 'integer', 'exists:deals,id'],
            'offer_option_id' => ['nullable', 'integer', 'exists:offer_options,id'],
            'status' => ['nullable', Rule::enum(ReservationStatus::class)],
            'expires_at' => ['required', 'date'],
            ...AppliesCreateAttributes::validationRules(),
        ]);

        $attributes = $validated['attributes'] ?? [];
        unset($validated['attributes']);

        /** @var Employee $employee */
        $employee = $request->user();
        assert($employee instanceof Employee);

        $rawStatus = $validated['status'] ?? null;
        $status = $rawStatus instanceof ReservationStatus
            ? $rawStatus
            : ($rawStatus !== null ? ReservationStatus::from((string) $rawStatus) : null);

        $reservation = ReservationCreation::create(
            (int) $validated['site_id'],
            (int) $validated['unit_class_id'],
            (int) $validated['contact_id'],
            isset($validated['deal_id']) ? (int) $validated['deal_id'] : null,
            isset($validated['unit_id']) ? (int) $validated['unit_id'] : null,
            Carbon::parse($validated['expires_at']),
            isset($validated['offer_option_id']) ? (int) $validated['offer_option_id'] : null,
            $status,
            $attributes,
            LeasingActor::employee($employee),
        );

        return $this->created(
            ReservationResource::make($reservation->load(['unit.site', 'unit.unitClass', 'contact', 'deal'])),
            'Reservation created successfully.'
        );
    }

    public function show(Reservation $reservation): JsonResponse
    {
        Gate::authorize(Permission::ReservationManage->value, $reservation);

        $reservation->load(['unit.site', 'unit.unitClass', 'contact', 'contract', 'notes']);

        return $this->success(
            ReservationResource::make($reservation),
            'Reservation retrieved successfully.'
        );
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        Gate::authorize(Permission::ReservationManage->value, $reservation);

        $validated = $request->validate([
            'unit_id' => ['sometimes', 'required', 'integer', 'exists:units,id'],
            'status' => ['sometimes', 'required', Rule::enum(ReservationStatus::class)],
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
        Gate::authorize(Permission::ReservationManage->value, $reservation);

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
        Gate::authorize(Permission::ReservationManage->value, $reservation);

        $reservation->delete();

        return $this->noContent('Reservation deleted successfully.');
    }

    /**
     * Preview move-in totals before converting a reservation to a contract.
     * Read-only — does not create invoices or charges.
     */
    public function convertPreview(Request $request, Reservation $reservation): JsonResponse
    {
        Gate::authorize(Permission::ContractSign->value, $reservation);

        $this->assertConvertible($reservation);

        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'move_in_date' => ['nullable', 'date'],
            'unit_rate' => ['nullable', 'numeric', 'min:0'],
            'insurance_id' => ['nullable', 'integer', 'exists:insurances,id'],
            'insurance_rate' => ['nullable', 'required_with:insurance_id', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'commitment_weeks' => ['nullable', 'integer', 'min:1'],
        ]);

        $reservation->load([
            'unit.site.country',
            'unit.unitClass',
            'contact',
            'price',
            'deal',
            'offerOption.discount',
            'offerOption.unitClassRate.price',
        ]);

        $billing = Setting::billing();
        $startDate = Carbon::parse($validated['start_date'] ?? now()->toDateString())->startOfDay();
        $moveIn = CarbonImmutable::parse($validated['move_in_date'] ?? $startDate->toDateString())->startOfDay();
        $pricing = $this->resolveConvertPricing($reservation, $validated['commitment_weeks'] ?? null);

        $listRate = $pricing['base_rate'];
        $unitRate = array_key_exists('unit_rate', $validated) && $validated['unit_rate'] !== null
            ? round((float) $validated['unit_rate'], 2)
            : $listRate;

        $currency = $reservation->price?->currency
            ?? $reservation->offerOption?->unitClassRate?->price?->currency
            ?? null;

        $versionPlan = null;
        if ($pricing['discount'] !== null) {
            $versionPlan = AttachesDiscount::previewPlan(
                $pricing['discount'],
                BillingMath::round2((string) $unitRate),
                (string) ($currency ?? 'EUR'),
                (string) $billing->defaultBillingInterval,
                (int) $billing->defaultBillingIntervalCount,
                $moveIn->toDateString(),
                $pricing['commitment_weeks'],
            );
        }
        $periodAmount = $versionPlan?->firstAmount() ?? BillingMath::round2((string) $unitRate);

        $insuranceRate = null;
        if (! empty($validated['insurance_id'])) {
            $insuranceRate = round((float) ($validated['insurance_rate'] ?? 0), 2);
        }

        $depositAmount = $validated['deposit_amount'] ?? $billing->defaultDepositAmount;

        $site = $reservation->unit?->site;
        if ($site === null) {
            throw ValidationException::withMessages([
                'tax_rate' => [__('errors.invoices.missing_site')],
            ]);
        }

        $unitTaxRate = TaxResolver::resolve(
            null,
            $reservation->unit->unitClass?->tax_rate_code,
            $site,
            $moveIn,
        );
        $insuranceTaxRate = ! empty($validated['insurance_id'])
            ? TaxResolver::resolve(
                null,
                Insurance::query()->find($validated['insurance_id'])?->tax_rate_code,
                $site,
                $moveIn,
            )
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
            $periodAmount,
            $unitTaxRate,
            $insuranceRate !== null ? (string) $insuranceRate : null,
            $insuranceTaxRate,
        );

        $rateOverridden = $pricing['discount'] !== null
            && abs($unitRate - $listRate) > 0.001;

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

        $discountEndsAt = null;
        if ($versionPlan !== null && ! $versionPlan->noop) {
            foreach ($versionPlan->segments as $segment) {
                if ($segment['to'] === null) {
                    $discountEndsAt = count($versionPlan->segments) > 1 ? $segment['from'] : null;
                    break;
                }
            }
        }

        return $this->success([
            'contact' => [
                'id' => $reservation->contact->id,
                'name' => trim($reservation->contact->first_name.' '.$reservation->contact->last_name),
            ],
            'unit' => [
                'id' => $reservation->unit->id,
                'unit_number' => $reservation->unit->unit_number,
                'site' => $reservation->unit->site ? [
                    'id' => $reservation->unit->site->id,
                    'name' => $reservation->unit->site->name,
                ] : null,
                'unit_class' => $reservation->unit->unitClass ? [
                    'id' => $reservation->unit->unitClass->id,
                    'label' => $reservation->unit->unitClass->label,
                    'code' => $reservation->unit->unitClass->code_slug,
                ] : null,
            ],
            'billing_interval' => $billing->defaultBillingInterval,
            'billing_interval_count' => $billing->defaultBillingIntervalCount,
            'billing_anchor_model' => $billing->billingAnchorModel,
            'currency' => $currency,
            'base_rate' => $this->formatMoney($pricing['base_rate']),
            'suggested_unit_rate' => $this->formatMoney((float) $periodAmount),
            'unit_rate' => $this->formatMoney($unitRate),
            'unit_tax_rate' => $this->formatTaxRate($unitTaxRate),
            'insurance_id' => $validated['insurance_id'] ?? null,
            'insurance_rate' => $insuranceRate !== null ? $this->formatMoney($insuranceRate) : null,
            'insurance_tax_rate' => $this->formatTaxRate($insuranceTaxRate),
            'deposit_amount' => $this->formatMoney((float) $depositAmount),
            'move_in_date' => $moveIn->toDateString(),
            'discount' => $pricing['discount'] !== null
                ? DiscountResource::make($pricing['discount'])->resolve()
                : null,
            'discount_ends_at' => $discountEndsAt,
            'discount_schedule' => $versionPlan?->toArray(),
            'commitment_weeks' => $pricing['commitment_weeks'],
            'rate_overridden' => $rateOverridden,
            'first_period' => $firstPeriod,
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
        Gate::authorize(Permission::ContractSign->value, $reservation);

        $this->assertConvertible($reservation);

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'move_in_date' => ['nullable', 'date'],
            'signed_at' => ['nullable', 'date'],
            'signature_mode' => ['nullable', Rule::in(['immediate', 'remote'])],
            'unit_rate' => ['required', 'numeric', 'min:0'],
            'unit_tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
            'insurance_id' => ['nullable', 'integer', 'exists:insurances,id'],
            'insurance_rate' => ['nullable', 'required_with:insurance_id', 'numeric', 'min:0'],
            'insurance_tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'commitment_weeks' => ['nullable', 'integer', 'min:1'],
        ]);

        $signatureMode = $validated['signature_mode'] ?? 'immediate';

        $contract = DB::transaction(function () use ($reservation, $validated, $request, $signatureMode) {
            $reservation->load([
                'unit.unitClass',
                'deal',
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
            $pricing = $this->resolveConvertPricing($reservation, $validated['commitment_weeks'] ?? null);
            // Confirmed list price — compiler materializes the schedule from this.
            $listRate = round((float) $validated['unit_rate'], 2);

            $unitPrice = $reservation->price
                ?? $reservation->offerOption?->unitClassRate?->price;
            $unitCurrency = $unitPrice?->currency
                ?? ResolvesItemCurrency::forItem('unit', $reservation->unit_id, $reservation->price_id, $reservation->unit?->site_id);

            $site = $reservation->unit?->site;
            $today = $site !== null
                ? SiteClock::today($site)
                : CarbonImmutable::today()->startOfDay();
            $remote = $signatureMode === 'remote';
            $status = $remote
                ? ContractStatus::AwaitingSignature
                : ($moveIn->toDateString() > $today->toDateString()
                    ? ContractStatus::Pending
                    : ContractStatus::Active);

            $contract = Contract::query()->create([
                'contact_id' => $reservation->contact_id,
                'reservation_id' => $reservation->id,
                'deal_id' => $reservation->deal_id,
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
                'currency' => $unitCurrency,
            ]);

            $site = $reservation->unit?->site;
            if ($site === null) {
                throw ValidationException::withMessages([
                    'tax_rate' => [__('errors.invoices.missing_site')],
                ]);
            }

            $unitTaxRate = $this->resolveContractItemTaxRate(
                'unit',
                $reservation->unit_id,
                $validated['unit_tax_rate_id'] ?? null,
                $moveIn,
                $site,
            );

            $resolvedUnitPrice = ResolvesContractItemPrice::forSigning(
                'unit',
                $reservation->unit_id,
                (string) $listRate,
                $reservation->unit?->site_id,
                $request->user()?->id,
                $unitPrice,
            );

            $contractItems = collect([$contract->items()->create([
                'item_type' => 'unit',
                'item_id' => $reservation->unit_id,
                'price_id' => $resolvedUnitPrice->id,
                'effective_from' => $moveIn->toDateString(),
                'effective_to' => null,
                'change_reason' => null,
                'tax_rate_id' => $unitTaxRate?->id,
                'tax_rate_snapshot' => $unitTaxRate?->rate,
            ])]);

            $createdBy = $request->user()?->id;

            if ($pricing['discount'] !== null) {
                AttachesDiscount::compileAndApply(
                    $contract,
                    $pricing['discount'],
                    (string) $listRate,
                    (string) $unitCurrency,
                    $moveIn->toDateString(),
                    $pricing['commitment_weeks'],
                    $createdBy,
                );
            }

            if (! empty($validated['insurance_id'])) {
                $insuranceTaxRate = $this->resolveContractItemTaxRate(
                    'insurance',
                    $validated['insurance_id'],
                    $validated['insurance_tax_rate_id'] ?? null,
                    $moveIn,
                    $site,
                );

                $insuranceAmount = round((float) $validated['insurance_rate'], 2);
                $resolvedInsurancePrice = ResolvesContractItemPrice::forSigning(
                    'insurance',
                    (int) $validated['insurance_id'],
                    (string) $insuranceAmount,
                    $reservation->unit?->site_id,
                    $request->user()?->id,
                );

                $contract->items()->create([
                    'item_type' => 'insurance',
                    'item_id' => $validated['insurance_id'],
                    'price_id' => $resolvedInsurancePrice->id,
                    'effective_from' => $moveIn->toDateString(),
                    'effective_to' => null,
                    'change_reason' => null,
                    'tax_rate_id' => $insuranceTaxRate?->id,
                    'tax_rate_snapshot' => $insuranceTaxRate?->rate,
                ]);
            }

            $contractItems = $contract->items()->whereNull('effective_to')->with('price')->get();
            $agreedCurrency = CurrencyGuard::assertItemsAgree($contractItems);
            $contract->forceFill(['currency' => $agreedCurrency])->save();

            // Release reservation hold before opening occupancy / signature hold —
            // same TX; half-open ranges allow occupancy to start on the release day.
            ReservationHolds::release($reservation);

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

            $reservation->update(['status' => ReservationStatus::Confirmed->value]);

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
     *     discount: Discount|null,
     *     commitment_weeks: int|null,
     *     version_plan: VersionPlan|null
     * }
     */
    private function resolveConvertPricing(Reservation $reservation, ?int $commitmentWeeksOverride = null): array
    {
        $offerOption = $reservation->offerOption;
        $discount = $offerOption?->discount;
        $offerPrice = $offerOption?->unitClassRate?->price;

        $baseRate = $offerPrice !== null
            ? (float) $offerPrice->amount
            : (float) ($reservation->price?->amount ?? 0);

        $commitmentWeeks = $commitmentWeeksOverride ?? CommitmentWeeks::fromDeal($reservation->deal);
        $versionPlan = null;

        if ($discount !== null && $baseRate > 0) {
            $billing = Setting::billing();
            $currency = (string) ($offerPrice?->currency
                ?? $reservation->price?->currency
                ?? 'EUR');
            $versionPlan = AttachesDiscount::previewPlan(
                $discount,
                BillingMath::round2((string) $baseRate),
                $currency,
                (string) $billing->defaultBillingInterval,
                (int) $billing->defaultBillingIntervalCount,
                now()->toDateString(),
                $commitmentWeeks,
            );
        }

        return [
            'base_rate' => round($baseRate, 2),
            'discount' => $discount,
            'commitment_weeks' => $commitmentWeeks,
            'version_plan' => $versionPlan,
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
            'start_date' => $plan->windowStart->toDateString(),
            'end_date' => $plan->windowEnd->toDateString(),
            'has_stub' => $plan->hasStub,
            'skipped' => $skipped,
            'days_occupied' => $plan->daysOccupied,
            'days_in_period' => $plan->daysInPeriod,
            'unit' => $unitLine,
            'insurance' => $insuranceLine,
            'total_net' => bcadd($unitLine['net'] ?? '0.00', $insuranceLine['net'] ?? '0.00', 2),
            'total_tax' => bcadd($unitLine['tax'] ?? '0.00', $insuranceLine['tax'] ?? '0.00', 2),
            'total_gross' => bcadd($unitLine['gross'] ?? '0.00', $insuranceLine['gross'] ?? '0.00', 2),
        ];
    }

    /** @return array{net: string, tax: string, gross: string} */
    private function formatChargeLine(TaxBreakdown $breakdown): array
    {
        return [
            'net' => $breakdown->net,
            'tax' => $breakdown->tax,
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
            'id' => $taxRate->id,
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
