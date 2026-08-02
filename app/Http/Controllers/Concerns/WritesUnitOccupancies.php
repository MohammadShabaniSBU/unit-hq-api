<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\UnitOccupancy;
use App\Support\Occupancy\HoldGuard;
use App\Support\Occupancy\OccupancyGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Occupancy open at signing. Prefer ContractSigning::complete() for the full
 * signing block (invariant 20); this trait remains for any legacy call sites.
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
            HoldGuard::assertUnheld((int) $item->item_id, $moveIn, $endedOn);

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
