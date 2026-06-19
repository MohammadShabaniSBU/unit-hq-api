<?php

namespace App\Http\Controllers;

use App\Enums\LeaseStatus;
use App\Enums\ReservationStatus;
use App\Http\Resources\LeaseResource;
use App\Http\Resources\ReservationResource;
use App\Models\Lease;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Reservation::query()
            ->with(['unit.site', 'unit.unitClass', 'contact', 'lease'])
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
            'unit_id'         => ['required', 'integer', 'exists:units,id'],
            'contact_id'      => ['required', 'integer', 'exists:contacts,id'],
            'deal_id'         => ['nullable', 'integer', 'exists:deals,id'],
            'offer_option_id' => ['nullable', 'integer', 'exists:offer_options,id'],
            'status'          => ['nullable', Rule::enum(ReservationStatus::class)],
            'expires_at'      => ['required', 'date'],
            'hold_notes'      => ['nullable', 'string'],
        ]);

        $reservation = Reservation::query()->create($validated);

        return $this->created(
            ReservationResource::make($reservation->load(['unit.site', 'unit.unitClass', 'contact'])),
            'Reservation created successfully.'
        );
    }

    public function show(Reservation $reservation): JsonResponse
    {
        $reservation->load(['unit.site', 'unit.unitClass', 'contact', 'lease']);

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
            'hold_notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $reservation->update($validated);

        return $this->success(
            ReservationResource::make($reservation->fresh()->load(['unit.site', 'unit.unitClass', 'contact', 'lease'])),
            'Reservation updated successfully.'
        );
    }

    public function destroy(Reservation $reservation): JsonResponse
    {
        $reservation->delete();

        return $this->noContent('Reservation deleted successfully.');
    }

    /**
     * Convert a reservation to a lease (contract signing).
     * Creates a Lease from the reservation's unit/contact/deal data.
     */
    public function convert(Request $request, Reservation $reservation): JsonResponse
    {
        $validated = $request->validate([
            'start_date'       => ['required', 'date'],
            'end_date'         => ['nullable', 'date', 'after:start_date'],
            'actual_rate'      => ['required', 'numeric', 'min:0'],
            'actual_insurance' => ['nullable', 'numeric', 'min:0'],
            'signed_at'        => ['nullable', 'date'],
        ]);

        $lease = DB::transaction(function () use ($reservation, $validated) {
            $lease = Lease::query()->create([
                'unit_id'          => $reservation->unit_id,
                'contact_id'       => $reservation->contact_id,
                'reservation_id'   => $reservation->id,
                'deal_id'          => $reservation->deal_id,
                'start_date'       => $validated['start_date'],
                'end_date'         => $validated['end_date'] ?? null,
                'actual_rate'      => $validated['actual_rate'],
                'actual_insurance' => $validated['actual_insurance'] ?? null,
                'status'           => LeaseStatus::Active->value,
                'signed_at'        => $validated['signed_at'] ?? now(),
            ]);

            $reservation->update(['status' => ReservationStatus::Confirmed->value]);

            return $lease;
        });

        return $this->created(
            LeaseResource::make($lease->load(['unit.site', 'unit.unitClass', 'contact', 'reservation'])),
            'Reservation converted to lease successfully.'
        );
    }
}
