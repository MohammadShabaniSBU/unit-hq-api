<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Enums\HoldType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UnitHoldResource;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Support\Occupancy\HoldGuard;
use App\Support\Occupancy\OccupancyGuard;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UnitHoldController extends Controller
{
    public function index(Request $request, Unit $unit): JsonResponse
    {
        $request->validate([
            'active' => ['nullable', 'boolean'],
        ]);

        $query = $unit->holds()->latest('id');

        if ($request->boolean('active')) {
            $unit->loadMissing('site');
            $today = SiteClock::today($unit->site);

            $query
                ->whereNull('released_at')
                ->where('hold_type', '<>', HoldType::Overlock->value)
                ->where('starts_on', '<=', $today->format('Y-m-d'))
                ->where(function ($q) use ($today): void {
                    $q->whereNull('ends_on')
                        ->orWhere('ends_on', '>', $today->format('Y-m-d'));
                });
        }

        return $this->success(
            UnitHoldResource::collection($query->get()),
            'Unit holds retrieved successfully.'
        );
    }

    public function store(Request $request, Unit $unit): JsonResponse
    {
        $validated = $request->validate([
            'hold_type' => ['required', Rule::enum(HoldType::class)],
            'starts_on' => ['nullable', 'date'],
            'ends_on'   => ['nullable', 'date'],
            'reason'    => ['nullable', 'string'],
        ]);

        $holdType = HoldType::from($validated['hold_type']);

        if (! $holdType->isManuallyManageable()) {
            $message = $holdType === HoldType::Overlock
                ? __('errors.holds.overlock_not_manageable')
                : __('errors.holds.reservation_not_manageable');

            throw ValidationException::withMessages([
                'hold_type' => [$message],
            ]);
        }

        if ($holdType->requiresReason() && blank($validated['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reason' => [__('errors.holds.reason_required')],
            ]);
        }

        $unit->loadMissing('site');
        $startsOn = isset($validated['starts_on'])
            ? CarbonImmutable::parse($validated['starts_on'])->startOfDay()
            : SiteClock::today($unit->site);
        $endsOn = isset($validated['ends_on'])
            ? CarbonImmutable::parse($validated['ends_on'])->startOfDay()
            : null;

        // ends_on after starts_on when starts_on was defaulted — re-check.
        if ($endsOn !== null && ! $endsOn->gt($startsOn)) {
            throw ValidationException::withMessages([
                'ends_on' => ['The ends on date must be after starts on.'],
            ]);
        }

        if ($holdType->blocksAvailability()) {
            OccupancyGuard::assertVacant($unit->id, $startsOn, $endsOn);
            HoldGuard::assertUnheld($unit->id, $startsOn, $endsOn);
        }

        $hold = UnitHold::query()->create([
            'unit_id'        => $unit->id,
            'hold_type'      => $holdType,
            'reservation_id' => null,
            'starts_on'      => $startsOn->format('Y-m-d'),
            'ends_on'        => $endsOn?->format('Y-m-d'),
            'released_at'    => null,
            'reason'         => $validated['reason'] ?? null,
            'created_by'     => $request->user()?->id,
        ]);

        return $this->created(
            UnitHoldResource::make($hold),
            'Unit hold created successfully.'
        );
    }

    public function destroy(Unit $unit, UnitHold $hold): JsonResponse
    {
        if ($hold->unit_id !== $unit->id) {
            return $this->notFound('Unit hold not found.');
        }

        if ($hold->hold_type === HoldType::Reservation) {
            throw ValidationException::withMessages([
                'hold' => [__('errors.holds.reservation_not_manageable')],
            ]);
        }

        if ($hold->hold_type === HoldType::Overlock) {
            throw ValidationException::withMessages([
                'hold' => [__('errors.holds.overlock_not_manageable')],
            ]);
        }

        if ($hold->released_at === null) {
            $hold->forceFill(['released_at' => now()])->save();
        }

        return $this->success(
            UnitHoldResource::make($hold->fresh()),
            'Unit hold released successfully.'
        );
    }
}
