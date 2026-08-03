<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\Contract;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;

/**
 * World-scale occupancy / hold invariant sweeps (SeederIntegrity + demo verification).
 */
trait AssertsOccupancyIntegrity
{
    private function assertNoOverlappingOccupancies(): void
    {
        $occupancies = UnitOccupancy::query()
            ->orderBy('unit_id')
            ->orderBy('started_on')
            ->get()
            ->groupBy('unit_id');

        foreach ($occupancies as $unitId => $rows) {
            $sorted = $rows->values();
            for ($i = 0; $i < $sorted->count(); $i++) {
                for ($j = $i + 1; $j < $sorted->count(); $j++) {
                    $a = $sorted[$i];
                    $b = $sorted[$j];
                    $this->assertFalse(
                        $this->rangesOverlap(
                            $a->started_on->format('Y-m-d'),
                            $a->ended_on?->format('Y-m-d'),
                            $b->started_on->format('Y-m-d'),
                            $b->ended_on?->format('Y-m-d'),
                        ),
                        "Overlapping occupancies on unit {$unitId}",
                    );
                }
            }
        }
    }

    private function assertNoOverlappingBlockingHolds(): void
    {
        $holds = UnitHold::query()
            ->whereNull('released_at')
            ->where('hold_type', '<>', HoldType::Overlock->value)
            ->orderBy('unit_id')
            ->orderBy('starts_on')
            ->get()
            ->groupBy('unit_id');

        foreach ($holds as $unitId => $rows) {
            $sorted = $rows->values();
            for ($i = 0; $i < $sorted->count(); $i++) {
                for ($j = $i + 1; $j < $sorted->count(); $j++) {
                    $a = $sorted[$i];
                    $b = $sorted[$j];
                    $this->assertFalse(
                        $this->rangesOverlap(
                            $a->starts_on->format('Y-m-d'),
                            $a->ends_on?->format('Y-m-d'),
                            $b->starts_on->format('Y-m-d'),
                            $b->ends_on?->format('Y-m-d'),
                        ),
                        "Overlapping blocking holds on unit {$unitId}",
                    );
                }
            }
        }
    }

    /**
     * SEED-04: every non-awaiting, non-cancelled contract has occupancy;
     * awaiting_signature and cancelled must not.
     */
    private function assertEveryNonAwaitingContractHasOccupancy(): void
    {
        $excluded = [
            ContractStatus::Cancelled->value,
            ContractStatus::AwaitingSignature->value,
        ];

        $requiredIds = Contract::query()
            ->whereNotIn('status', $excluded)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $occupancyContractIds = UnitOccupancy::query()
            ->distinct()
            ->pluck('contract_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $missingOccupancy = $requiredIds->diff($occupancyContractIds)->values();
        $this->assertTrue(
            $missingOccupancy->isEmpty(),
            'Non-awaiting/non-cancelled contracts without occupancy: '.$missingOccupancy->implode(', '),
        );

        $unexpected = Contract::query()
            ->whereIn('status', $excluded)
            ->whereIn('id', $occupancyContractIds->all())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $this->assertTrue(
            $unexpected->isEmpty(),
            'Awaiting/cancelled contracts must not have occupancy: '.$unexpected->implode(', '),
        );

        $this->assertGreaterThan(0, $requiredIds->count());
    }

    private function rangesOverlap(
        string $aStart,
        ?string $aEnd,
        string $bStart,
        ?string $bEnd,
    ): bool {
        $aBeforeBEnd = $bEnd === null || $aStart < $bEnd;
        $bBeforeAEnd = $aEnd === null || $bStart < $aEnd;

        return $aBeforeBEnd && $bBeforeAEnd;
    }
}
