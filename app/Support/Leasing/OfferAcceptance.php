<?php

declare(strict_types=1);

namespace App\Support\Leasing;

use App\Enums\ReservationStatus;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\SystemEvent;
use App\Models\Unit;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OfferAcceptance
{
    public static function accept(OfferOption $offerOption, LeasingActor $actor): Offer
    {
        $offerOption->load(['offer', 'unitClassRate']);
        $offer = $offerOption->offer;

        if ($offer->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'offer' => ['This offer has expired.'],
            ]);
        }

        if ($offer->status === 'accepted') {
            throw ValidationException::withMessages([
                'offer' => ['This offer has already been accepted.'],
            ]);
        }

        SystemEvent::record('offer.accept.started', $offer, [
            'offer_option_id' => $offerOption->id,
        ]);

        return DB::transaction(function () use ($offerOption, $actor): Offer {
            $offer = Offer::query()->whereKey($offerOption->offer_id)->lockForUpdate()->firstOrFail();

            if ($offer->expires_at->isPast()) {
                throw ValidationException::withMessages(['offer' => ['This offer has expired.']]);
            }

            if ($offer->status === 'accepted') {
                throw ValidationException::withMessages(['offer' => ['This offer has already been accepted.']]);
            }

            $unitId = $offerOption->unit_id;
            if ($unitId !== null) {
                $candidate = Unit::query()->with('site')->whereKey($unitId)->lockForUpdate()->first();
                if ($candidate === null || ! $candidate->isAvailableOn(SiteClock::today($candidate->site))) {
                    $unitId = null;
                }
            }

            $unitId ??= Unit::resolveUnitIdForRate($offerOption->unit_class_rate_id);

            if ($unitId === null) {
                throw ValidationException::withMessages(['unit' => ['No available unit found for the selected option.']]);
            }

            $now = now();
            $offerOption->update(['selected_at' => $now]);
            $offer->update([
                'status'      => 'accepted',
                'accepted_at' => $now,
            ]);

            $offerOption->loadMissing('unitClassRate.price');

            $cataloguePriceId = $offerOption->unitClassRate?->price?->id;
            if ($cataloguePriceId === null) {
                throw ValidationException::withMessages([
                    'unit_class_rate_id' => ['No current catalogue price for the selected option.'],
                ]);
            }

            $unit = Unit::query()->with('site')->findOrFail($unitId);
            ReservationCreation::persistFromAcceptance([
                'unit_id'         => $unitId,
                'contact_id'      => $offer->contact_id,
                'deal_id'         => $offer->deal_id,
                'price_id'        => $cataloguePriceId,
                'offer_option_id' => $offerOption->id,
                'status'          => ReservationStatus::Pending,
                'expires_at'      => ReservationCreation::defaultExpiry(),
            ], $unit, $actor);

            SystemEvent::record('offer.accept.committed', $offer, [
                'offer_option_id' => $offerOption->id,
                'unit_id' => $unitId,
            ]);

            $props = [
                'offer_option_id' => $offerOption->id,
                'unit_id' => $unitId,
            ];
            // Causer is auto-resolved by the activity logger; normalising it
            // is deliberately out of scope for this task.
            RecordsActivity::core('offer.accepted', $offer, $props);
            $offer->loadMissing('contact');
            if ($offer->contact !== null) {
                RecordsActivity::core('offer.accepted', $offer->contact, $props);
            }

            return $offer->fresh()->load([
                'contact',
                'deal',
                'options' => fn ($q) => $q->with(OfferOption::unitClassRateEagerLoads())->orderBy('display_order'),
            ]);
        });
    }
}
