<?php

namespace App\Http\Controllers;

use App\Enums\ContractStatus;
use App\Enums\ReservationStatus;
use App\Http\Resources\ContractResource;
use App\Http\Resources\ReservationResource;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\UnitClassRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Reservation::query()
            ->with(['unit.site', 'unit.unitClass', 'contact', 'contract'])
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

            return Reservation::query()->create($reservationData);
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

        $reservation->update($validated);

        return $this->success(
            ReservationResource::make($reservation->fresh()->load(['unit.site', 'unit.unitClass', 'contact', 'contract'])),
            'Reservation updated successfully.'
        );
    }

    public function destroy(Reservation $reservation): JsonResponse
    {
        $reservation->delete();

        return $this->noContent('Reservation deleted successfully.');
    }

    /**
     * Convert a reservation to a contract (contract signing).
     * Creates a Contract with a unit item and an optional insurance item
     * from the reservation's unit/contact/deal data.
     */
    public function convert(Request $request, Reservation $reservation): JsonResponse
    {
        $validated = $request->validate([
            'start_date'       => ['required', 'date'],
            'end_date'         => ['nullable', 'date', 'after:start_date'],
            'signed_at'        => ['nullable', 'date'],
            'unit_rate'        => ['required', 'numeric', 'min:0'],
            'insurance_id'     => ['nullable', 'integer', 'exists:insurances,id'],
            'insurance_rate'   => ['nullable', 'required_with:insurance_id', 'numeric', 'min:0'],
        ]);

        $contract = DB::transaction(function () use ($reservation, $validated) {
            $contract = Contract::query()->create([
                'contact_id'     => $reservation->contact_id,
                'reservation_id' => $reservation->id,
                'deal_id'        => $reservation->deal_id,
                'start_date'     => $validated['start_date'],
                'end_date'       => $validated['end_date'] ?? null,
                'status'         => ContractStatus::Active->value,
                'signed_at'      => $validated['signed_at'] ?? now(),
            ]);

            $contract->items()->create([
                'item_type' => 'unit',
                'item_id'   => $reservation->unit_id,
                'rate'      => $validated['unit_rate'],
            ]);

            if (!empty($validated['insurance_id'])) {
                $contract->items()->create([
                    'item_type' => 'insurance',
                    'item_id'   => $validated['insurance_id'],
                    'rate'      => $validated['insurance_rate'],
                ]);
            }

            $reservation->update(['status' => ReservationStatus::Confirmed->value]);

            return $contract;
        });

        return $this->created(
            ContractResource::make($contract->load(['items.item', 'contact', 'reservation'])),
            'Reservation converted to contract successfully.'
        );
    }
}
