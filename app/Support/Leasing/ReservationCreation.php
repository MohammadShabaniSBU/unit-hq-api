<?php

declare(strict_types=1);

namespace App\Support\Leasing;

use App\Enums\AttributeEntityType;
use App\Enums\PipelineSource;
use App\Enums\ReservationStatus;
use App\Models\Deal;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Support\Attributes\AppliesCreateAttributes;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReservationCreation
{
    /**
     * @param  list<array{definition_id: int|string, value: mixed}>  $customAttributes
     */
    public static function create(
        int $siteId,
        int $unitClassId,
        int $contactId,
        ?int $dealId,
        ?int $unitId,
        ?Carbon $expiresAt,
        ?int $offerOptionId,
        ?ReservationStatus $status,
        array $customAttributes,
        LeasingActor $actor,
    ): Reservation {
        return DB::transaction(function () use (
            $siteId,
            $unitClassId,
            $contactId,
            $dealId,
            $unitId,
            $expiresAt,
            $offerOptionId,
            $status,
            $customAttributes,
            $actor,
        ): Reservation {
            if (! empty($dealId)) {
                $deal = Deal::query()->findOrFail($dealId);

                if ($deal->site_id === null) {
                    throw ValidationException::withMessages([
                        'deal_id' => ['Selected deal is missing a site and cannot create a reservation.'],
                    ]);
                }

                if ($deal->site_id !== $siteId) {
                    throw ValidationException::withMessages([
                        'site_id' => ['Selected site must match the deal site.'],
                    ]);
                }
            }

            $latestRate = UnitClassRate::query()
                ->with('price')
                ->where('site_id', $siteId)
                ->where('unit_class_id', $unitClassId)
                ->first();

            if ($latestRate === null || $latestRate->price === null) {
                throw ValidationException::withMessages([
                    'unit_class_id' => ['No active price configured for this unit class at the selected site.'],
                ]);
            }

            // Explicit unit_id: lock without availability scope so occupied/held
            // units surface OccupancyGuard / HoldGuard 422s. Auto-pick uses
            // Availability + site-local today (D8).
            if (! empty($unitId)) {
                $selectedUnit = Unit::query()
                    ->where('site_id', $siteId)
                    ->where('unit_class_id', $unitClassId)
                    ->where('enabled', true)
                    ->whereKey($unitId)
                    ->lockForUpdate()
                    ->first();
            } else {
                $site = Site::query()->findOrFail($siteId);
                $selectedUnit = Unit::query()
                    ->where('site_id', $siteId)
                    ->where('unit_class_id', $unitClassId)
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

            if ($actor->pipelineSource() === PipelineSource::AiAgent) {
                $alreadyHeld = Reservation::query()
                    ->where('source', PipelineSource::AiAgent)
                    ->where('contact_id', $contactId)
                    ->where('status', ReservationStatus::Pending)
                    ->whereHas('unit', function (Builder $query) use ($siteId, $unitClassId): void {
                        $query->where('site_id', $siteId)->where('unit_class_id', $unitClassId);
                    })
                    ->exists();

                if ($alreadyHeld) {
                    throw ValidationException::withMessages([
                        'unit_class_id' => ['An agent already holds a unit in this class for this contact at this site.'],
                    ]);
                }
            }

            $selectedUnit->load('site');

            $reservationData = [
                'unit_id' => $selectedUnit->id,
                'contact_id' => $contactId,
                'deal_id' => $dealId,
                'offer_option_id' => $offerOptionId,
                'price_id' => $latestRate->price->id,
                'expires_at' => $expiresAt ?? self::defaultExpiry(),
            ];

            // Omit rather than pass null — the column has a DB-level default
            // ('pending') that only applies when the key is absent from the insert.
            if ($status !== null) {
                $reservationData['status'] = $status;
            }

            $reservation = self::persistFromAcceptance($reservationData, $selectedUnit, $actor);

            AppliesCreateAttributes::apply(
                AttributeEntityType::Reservation,
                $reservation,
                $customAttributes,
                $actor->employee,
            );

            RecordsActivity::core('reservation.created', $reservation, [
                'unit_id' => $reservation->unit_id,
                'hold_expires_at' => $reservation->expires_at?->toIso8601String(),
            ], $actor->causer());

            return $reservation;
        });
    }

    public static function defaultExpiry(): Carbon
    {
        $settings = Setting::leasing();
        $value = $settings->defaultReservationExpirationValue;
        $unit = $settings->defaultReservationExpirationUnit;

        return match ($unit) {
            'minutes' => now()->addMinutes($value),
            'hours' => now()->addHours($value),
            'weeks' => now()->addWeeks($value),
            default => now()->addDays($value),
        };
    }

    /**
     * Insert an already-resolved reservation and its hold. Price and unit are
     * caller-resolved — offer acceptance pins price_id from the option's
     * unitClassRate.price, not latestRate for site+class.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function persistFromAcceptance(
        array $attributes,
        Unit $unit,
        LeasingActor $actor,
    ): Reservation {
        $reservation = self::persist($attributes, $actor);
        ReservationHolds::write($reservation, $unit, $actor->employeeId());

        return $reservation;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function persist(array $attributes, LeasingActor $actor): Reservation
    {
        $attributes['source'] = $actor->pipelineSource();
        $attributes['ai_agent_id'] = $actor->aiAgentId();

        return Reservation::query()->create($attributes);
    }
}
