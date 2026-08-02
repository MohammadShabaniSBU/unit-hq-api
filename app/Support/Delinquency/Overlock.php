<?php

declare(strict_types=1);

namespace App\Support\Delinquency;

use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Enums\HoldType;
use App\Models\Delinquency;
use App\Models\Employee;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Access\AccessSync;
use Illuminate\Support\Collection;

/**
 * Place / release overlock holds for a delinquency case.
 * Overlock is case-linked (reason = delinquency:{id}); never via the holds API.
 */
final class Overlock
{
    public static function reasonFor(Delinquency $case): string
    {
        return 'delinquency:'.$case->id;
    }

    public static function delinquencyIdFromReason(?string $reason): ?int
    {
        if ($reason === null || ! str_starts_with($reason, 'delinquency:')) {
            return null;
        }

        $id = (int) substr($reason, strlen('delinquency:'));

        return $id > 0 ? $id : null;
    }

    /**
     * Overlock each unit currently occupied by the contract (or a single unit when $unitId set).
     * Idempotent: an unreleased overlock for that unit+case is returned as-is.
     *
     * @return UnitHold|list<UnitHold>
     */
    public static function place(Delinquency $case, ?Employee $by = null, ?int $unitId = null): UnitHold|array
    {
        $contract = $case->contract;
        $today = DelinquencyState::siteToday($contract)->toDateString();
        $reason = self::reasonFor($case);

        $unitIds = UnitOccupancy::query()
            ->where('contract_id', $contract->id)
            ->whereNull('ended_on')
            ->orderBy('id')
            ->pluck('unit_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($unitIds === []) {
            // Fall back to the contract's current unit item when occupancy is absent in tests.
            $contract->loadMissing(['unitItem']);
            $fallbackId = $contract->unitItem?->item_id;
            if ($fallbackId !== null) {
                $unitIds = [(int) $fallbackId];
            }
        }

        if ($unitId !== null) {
            if (! in_array($unitId, $unitIds, true)) {
                throw new \InvalidArgumentException("Unit {$unitId} is not occupied by contract {$contract->id}.");
            }
            $unitIds = [$unitId];
        }

        $holds = [];
        foreach ($unitIds as $unitId) {
            $existing = UnitHold::query()
                ->where('unit_id', $unitId)
                ->where('hold_type', HoldType::Overlock)
                ->whereNull('released_at')
                ->where('reason', $reason)
                ->first();

            if ($existing !== null) {
                $holds[] = $existing;

                continue;
            }

            // Another live overlock on the unit (different case) — return it; don't duplicate.
            $anyLive = UnitHold::query()
                ->where('unit_id', $unitId)
                ->where('hold_type', HoldType::Overlock)
                ->whereNull('released_at')
                ->first();

            if ($anyLive !== null) {
                $holds[] = $anyLive;

                continue;
            }

            $holds[] = UnitHold::query()->create([
                'unit_id' => $unitId,
                'hold_type' => HoldType::Overlock,
                'reservation_id' => null,
                'starts_on' => $today,
                'ends_on' => null,
                'released_at' => null,
                'reason' => $reason,
                'created_by' => $by?->id,
            ]);
        }

        if ($holds === []) {
            throw new \RuntimeException("No units to overlock for delinquency {$case->id}.");
        }

        AccessSync::nudge((int) $contract->id);

        return count($holds) === 1 ? $holds[0] : $holds;
    }

    /**
     * Release unreleased overlock holds for this case. Never deletes (S01).
     * Appends a release_overlock timeline step when any holds were released.
     *
     * @return Collection<int, UnitHold>
     */
    public static function release(Delinquency $case, string $reason, ?Employee $by = null, ?int $unitId = null): Collection
    {
        $caseReason = self::reasonFor($case);

        $query = UnitHold::query()
            ->where('hold_type', HoldType::Overlock)
            ->whereNull('released_at')
            ->where('reason', $caseReason)
            ->orderBy('id');

        if ($unitId !== null) {
            $query->where('unit_id', $unitId);
        }

        $holds = $query->get();

        if ($holds->isEmpty()) {
            return $holds;
        }

        foreach ($holds as $hold) {
            $hold->forceFill([
                'released_at' => now(),
            ])->save();
        }

        /** @var UnitHold $primary */
        $primary = $holds->first();
        $today = DelinquencyState::siteToday($case->contract)->toDateString();
        $trigger = $reason === 'cure'
            ? DelinquencyStepTrigger::Cure
            : DelinquencyStepTrigger::Manual;

        DelinquencyLifecycle::recordStep(
            delinquency: $case,
            action: DelinquencyStepAction::ReleaseOverlock,
            trigger: $trigger,
            executedOn: $today,
            unitHold: $primary,
            detail: [
                'unit_hold_ids' => $holds->map(fn (UnitHold $h): int => (int) $h->id)->values()->all(),
                'release_reason' => $reason,
            ],
            createdBy: $by,
        );

        AccessSync::nudge((int) $case->contract_id);

        return $holds;
    }

    /**
     * Live overlock holds linked to this case (reason = delinquency:{id}).
     *
     * @return Collection<int, UnitHold>
     */
    public static function liveHolds(Delinquency $case): Collection
    {
        return UnitHold::query()
            ->where('hold_type', HoldType::Overlock)
            ->whereNull('released_at')
            ->where('reason', self::reasonFor($case))
            ->orderBy('id')
            ->get();
    }
}
