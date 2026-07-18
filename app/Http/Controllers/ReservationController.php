<?php

namespace App\Http\Controllers;

use App\Enums\ContractStatus;
use App\Enums\ReservationStatus;
use App\Http\Resources\ContractResource;
use App\Http\Resources\DiscountResource;
use App\Http\Resources\ReservationResource;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Discount;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
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
                ->where('site_id', $validated['site_id'])
                ->where('unit_class_id', $validated['unit_class_id'])
                ->latest('id')
                ->first();

            if (! $latestRate) {
                throw ValidationException::withMessages([
                    'unit_class_id' => ['No active price configured for this unit class at the selected site.'],
                ]);
            }

            $unitQuery = Unit::query()
                ->where('site_id', $validated['site_id'])
                ->where('unit_class_id', $validated['unit_class_id'])
                ->where('enabled', true)
                ->reservable()
                ->lockForUpdate();

            $selectedUnit = ! empty($validated['unit_id'])
                ? $unitQuery->whereKey($validated['unit_id'])->first()
                : $unitQuery->inRandomOrder()->first();

            if (! $selectedUnit) {
                throw ValidationException::withMessages([
                    'unit_id' => ['No available unit found for the selected site and unit class.'],
                ]);
            }

            $reservationData = $validated;
            unset($reservationData['site_id'], $reservationData['unit_class_id'], $reservationData['unit_id']);

            $reservationData['unit_id'] = $selectedUnit->id;
            $reservationData['price_id'] = $latestRate->price_id;

            $reservation = Reservation::query()->create($reservationData);

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
            'unit_rate'      => ['nullable', 'numeric', 'min:0'],
            'insurance_id'   => ['nullable', 'integer', 'exists:insurances,id'],
            'insurance_rate' => ['nullable', 'required_with:insurance_id', 'numeric', 'min:0'],
        ]);

        $reservation->load([
            'unit.site',
            'unit.unitClass',
            'contact',
            'price',
            'offerOption.discount',
            'offerOption.unitClassRate.price',
        ]);

        $startDate = Carbon::parse($validated['start_date'] ?? now()->toDateString())->startOfDay();
        $pricing = $this->resolveConvertPricing($reservation, $startDate);

        $suggestedUnitRate = $pricing['suggested_unit_rate'];
        $unitRate = array_key_exists('unit_rate', $validated) && $validated['unit_rate'] !== null
            ? round((float) $validated['unit_rate'], 2)
            : $suggestedUnitRate;

        $insuranceRate = null;
        if (! empty($validated['insurance_id'])) {
            $insuranceRate = round((float) ($validated['insurance_rate'] ?? 0), 2);
        }

        $billingPeriod = $reservation->price?->billing_period
            ?? $reservation->offerOption?->unitClassRate?->price?->billing_period
            ?? 'monthly';

        $currency = $reservation->price?->currency
            ?? $reservation->offerOption?->unitClassRate?->price?->currency
            ?? null;

        $firstPeriod = $this->buildFirstPeriodEstimate(
            $startDate,
            $billingPeriod,
            $unitRate,
            $insuranceRate,
        );

        $rateOverridden = $pricing['discount'] !== null
            && abs($unitRate - $suggestedUnitRate) > 0.001;

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
            'billing_period'      => $billingPeriod,
            'currency'            => $currency,
            'base_rate'           => $this->formatMoney($pricing['base_rate']),
            'suggested_unit_rate' => $this->formatMoney($suggestedUnitRate),
            'unit_rate'           => $this->formatMoney($unitRate),
            'insurance_id'        => $validated['insurance_id'] ?? null,
            'insurance_rate'      => $insuranceRate !== null ? $this->formatMoney($insuranceRate) : null,
            'discount'            => $pricing['discount'] !== null
                ? DiscountResource::make($pricing['discount'])->resolve()
                : null,
            'discount_ends_at'    => $pricing['discount_ends_at'],
            'rate_overridden'     => $rateOverridden,
            'first_period'        => $firstPeriod,
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
            'start_date'     => ['required', 'date'],
            'end_date'       => ['nullable', 'date', 'after:start_date'],
            'signed_at'      => ['nullable', 'date'],
            'unit_rate'      => ['required', 'numeric', 'min:0'],
            'insurance_id'   => ['nullable', 'integer', 'exists:insurances,id'],
            'insurance_rate' => ['nullable', 'required_with:insurance_id', 'numeric', 'min:0'],
        ]);

        $contract = DB::transaction(function () use ($reservation, $validated) {
            $reservation->load([
                'offerOption.discount',
                'offerOption.unitClassRate.price',
            ]);

            $startDate = Carbon::parse($validated['start_date'])->startOfDay();
            $pricing = $this->resolveConvertPricing($reservation, $startDate);
            $unitRate = round((float) $validated['unit_rate'], 2);

            $contract = Contract::query()->create([
                'contact_id'     => $reservation->contact_id,
                'reservation_id' => $reservation->id,
                'deal_id'        => $reservation->deal_id,
                'start_date'     => $validated['start_date'],
                'end_date'       => $validated['end_date'] ?? null,
                'status'         => ContractStatus::Active->value,
                'signed_at'      => $validated['signed_at'] ?? now(),
            ]);

            $unitItemData = [
                'item_type' => 'unit',
                'item_id'   => $reservation->unit_id,
                'rate'      => $unitRate,
            ];

            if ($pricing['discount'] !== null) {
                $unitItemData['base_rate'] = $pricing['base_rate'];
                $unitItemData['discount_id'] = $pricing['discount']->id;
                $unitItemData['discount_ends_at'] = $pricing['discount_ends_at'];
            }

            $contract->items()->create($unitItemData);

            if (! empty($validated['insurance_id'])) {
                $contract->items()->create([
                    'item_type' => 'insurance',
                    'item_id'   => $validated['insurance_id'],
                    'rate'      => round((float) $validated['insurance_rate'], 2),
                ]);
            }

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
            ContractResource::make($contract->load(['items.item', 'contact', 'reservation'])),
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
     * Anniversary first period from start date (matches billing seed convention).
     *
     * @return array{
     *     start_date: string,
     *     end_date: string,
     *     days: int,
     *     unit_amount: string,
     *     insurance_amount: string|null,
     *     total: string
     * }
     */
    private function buildFirstPeriodEstimate(
        Carbon $startDate,
        string $billingPeriod,
        float $unitRate,
        ?float $insuranceRate,
    ): array {
        $periodStart = $startDate->copy()->startOfDay();
        $periodEnd = match ($billingPeriod) {
            'weekly' => $periodStart->copy()->addWeek()->subDay(),
            'annual' => $periodStart->copy()->addYear()->subDay(),
            default  => $periodStart->copy()->addMonth()->subDay(),
        };

        $days = $periodStart->diffInDays($periodEnd) + 1;
        $insuranceAmount = $insuranceRate !== null ? round($insuranceRate, 2) : null;
        $total = round($unitRate + ($insuranceAmount ?? 0.0), 2);

        return [
            'start_date'       => $periodStart->toDateString(),
            'end_date'         => $periodEnd->toDateString(),
            'days'             => $days,
            'unit_amount'      => $this->formatMoney($unitRate),
            'insurance_amount' => $insuranceAmount !== null ? $this->formatMoney($insuranceAmount) : null,
            'total'            => $this->formatMoney($total),
        ];
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
