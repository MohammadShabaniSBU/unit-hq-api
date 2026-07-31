<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\UnitOccupancy;
use App\Support\Occupancy\OccupancyGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Shared by ContractController::store and ReservationController::convert —
 * occupancy rows join the same transaction as items, currency snapshot, and
 * first-period charges (invariant 20).
 */
trait WritesUnitOccupancies
{
    /**
     * @param  Collection<int, ContractItem>  $contractItems
     */
    protected function writeUnitOccupancies(
        Contract $contract,
        Collection $contractItems,
        CarbonImmutable $moveIn,
        ?CarbonImmutable $endedOn,
        ?int $createdBy,
    ): void {
        foreach ($contractItems as $item) {
            if ($item->item_type !== 'unit') {
                continue;
            }

            OccupancyGuard::assertVacant((int) $item->item_id, $moveIn, $endedOn);

            UnitOccupancy::query()->create([
                'unit_id'          => $item->item_id,
                'contract_id'      => $contract->id,
                'contract_item_id' => $item->id,
                // Civil dates from the signing TX — never server "today".
                'started_on'       => $moveIn->format('Y-m-d'),
                'ended_on'         => $endedOn?->format('Y-m-d'),
                'created_by'       => $createdBy,
            ]);
        }
    }
}
