<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Resources\OfferOptionResource;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfferOptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'offer_id'           => ['required', 'integer', 'exists:offers,id'],
            'unit_class_rate_id' => ['required', 'integer', 'exists:unit_class_rates,id'],
            'discount_id'        => ['nullable', 'integer', 'exists:discounts,id'],
            'label'          => ['required', 'string'],
            'description'    => ['nullable', 'string'],
            'display_order'  => ['required', 'integer', 'min:0'],
        ]);

        $validated['unit_id'] = Unit::resolveUnitIdForRate($validated['unit_class_rate_id']);

        $offerOption = OfferOption::query()->create($validated);

        $offerOption->load(OfferOption::unitClassRateEagerLoads());

        return $this->created(
            OfferOptionResource::make($offerOption),
            'Offer option created successfully.'
        );
    }

    public function update(Request $request, OfferOption $offerOption): JsonResponse
    {
        $validated = $request->validate([
            'unit_class_rate_id' => ['sometimes', 'required', 'integer', 'exists:unit_class_rates,id'],
            'discount_id'        => ['sometimes', 'nullable', 'integer', 'exists:discounts,id'],
            'label'          => ['sometimes', 'required', 'string'],
            'description'    => ['sometimes', 'nullable', 'string'],
            'display_order'  => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        if (array_key_exists('unit_class_rate_id', $validated)) {
            $validated['unit_id'] = Unit::resolveUnitIdForRate($validated['unit_class_rate_id']);
        }

        $offerOption->update($validated);

        $offerOption = $offerOption->fresh()->load(OfferOption::unitClassRateEagerLoads());

        return $this->success(
            OfferOptionResource::make($offerOption),
            'Offer option updated successfully.'
        );
    }

    public function destroy(OfferOption $offerOption): JsonResponse
    {
        $offerOption->delete();

        return $this->noContent('Offer option deleted successfully.');
    }

    public function select(OfferOption $offerOption): JsonResponse
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

        $offer = DB::transaction(function () use ($offerOption): Offer {
            $offer = Offer::query()->whereKey($offerOption->offer_id)->lockForUpdate()->firstOrFail();

            if ($offer->expires_at->isPast()) {
                throw ValidationException::withMessages(['offer' => ['This offer has expired.']]);
            }

            if ($offer->status === 'accepted') {
                throw ValidationException::withMessages(['offer' => ['This offer has already been accepted.']]);
            }

            $unitId = $offerOption->unit_id;
            if ($unitId !== null) {
                $unitAvailable = Unit::query()->reservable()->whereKey($unitId)->lockForUpdate()->exists();
                if (! $unitAvailable) {
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

            Reservation::query()->create([
                'unit_id'         => $unitId,
                'contact_id'      => $offer->contact_id,
                'deal_id'         => $offer->deal_id,
                'price_id'        => $offerOption->unitClassRate->price_id,
                'offer_option_id' => $offerOption->id,
                'status'          => ReservationStatus::Pending,
                'expires_at'      => $this->reservationExpiresAt(),
            ]);

            return $offer->fresh()->load([
                'contact',
                'deal',
                'options' => fn ($q) => $q->with(OfferOption::unitClassRateEagerLoads())->orderBy('display_order'),
            ]);
        });

        return $this->success(OfferResource::make($offer), 'Offer option selected successfully.');
    }

    private function reservationExpiresAt(): Carbon
    {
        $settings = Setting::leasing();
        $value    = $settings->defaultReservationExpirationValue;
        $unit     = $settings->defaultReservationExpirationUnit;

        return match ($unit) {
            'minutes' => now()->addMinutes($value),
            'hours'   => now()->addHours($value),
            'weeks'   => now()->addWeeks($value),
            default   => now()->addDays($value),
        };
    }
}
