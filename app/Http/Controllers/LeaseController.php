<?php

namespace App\Http\Controllers;

use App\Enums\LeaseStatus;
use App\Http\Resources\LeaseResource;
use App\Models\Lease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Lease::query()
            ->with(['unit.site', 'unit.unitClass', 'contact', 'reservation'])
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
            $query->paginate($this->perPage())->through(fn (Lease $lease) => LeaseResource::make($lease)),
            'Leases retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit_id'          => ['required', 'integer', 'exists:units,id'],
            'contact_id'       => ['required', 'integer', 'exists:contacts,id'],
            'reservation_id'   => ['nullable', 'integer', 'exists:reservations,id'],
            'deal_id'          => ['nullable', 'integer', 'exists:deals,id'],
            'start_date'       => ['required', 'date'],
            'end_date'         => ['nullable', 'date', 'after:start_date'],
            'actual_rate'      => ['required', 'numeric', 'min:0'],
            'actual_insurance' => ['nullable', 'numeric', 'min:0'],
            'status'           => ['nullable', Rule::enum(LeaseStatus::class)],
            'signed_at'        => ['nullable', 'date'],
        ]);

        $validated['status'] ??= LeaseStatus::Active->value;
        $validated['signed_at'] ??= now();

        $lease = Lease::query()->create($validated);

        return $this->created(
            LeaseResource::make($lease->load(['unit.site', 'unit.unitClass', 'contact', 'reservation'])),
            'Lease created successfully.'
        );
    }

    public function show(Lease $lease): JsonResponse
    {
        $lease->load(['unit.site', 'unit.unitClass', 'contact', 'reservation', 'deal']);

        return $this->success(
            LeaseResource::make($lease),
            'Lease retrieved successfully.'
        );
    }

    public function update(Request $request, Lease $lease): JsonResponse
    {
        $validated = $request->validate([
            'start_date'       => ['sometimes', 'required', 'date'],
            'end_date'         => ['sometimes', 'nullable', 'date'],
            'actual_rate'      => ['sometimes', 'required', 'numeric', 'min:0'],
            'actual_insurance' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status'           => ['sometimes', 'required', Rule::enum(LeaseStatus::class)],
            'signed_at'        => ['sometimes', 'required', 'date'],
        ]);

        $lease->update($validated);

        return $this->success(
            LeaseResource::make($lease->fresh()->load(['unit.site', 'unit.unitClass', 'contact', 'reservation'])),
            'Lease updated successfully.'
        );
    }

    public function destroy(Lease $lease): JsonResponse
    {
        $lease->delete();

        return $this->noContent('Lease deleted successfully.');
    }
}
