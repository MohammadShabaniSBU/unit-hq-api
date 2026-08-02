<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Enums\AccessPointType;
use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\AccessPoint;
use App\Models\AccessSuspension;
use App\Models\Contract;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Time\SiteClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pure desired-state computation: facts in, DesiredGrant triples out.
 * Never reads the grants cache; never talks to a provider (S15-00).
 */
final class DesiredAccess
{
    /**
     * @return Collection<int, DesiredGrant>
     */
    public static function forSite(Site $site): Collection
    {
        $today = SiteClock::today($site)->format('Y-m-d');

        $points = AccessPoint::query()
            ->active()
            ->where('site_id', $site->id)
            ->get();

        if ($points->isEmpty()) {
            return collect();
        }

        $unitIds = Unit::query()
            ->where('site_id', $site->id)
            ->pluck('id');

        if ($unitIds->isEmpty()) {
            return collect();
        }

        $occupancies = UnitOccupancy::query()
            ->with('contract')
            ->whereIn('unit_id', $unitIds)
            ->where('started_on', '<=', $today)
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('ended_on')
                    ->orWhere('ended_on', '>', $today);
            })
            ->get();

        $eligible = $occupancies->filter(function (UnitOccupancy $occupancy): bool {
            $contract = $occupancy->contract;
            if ($contract === null) {
                return false;
            }

            $status = $contract->status instanceof ContractStatus
                ? $contract->status
                : ContractStatus::from((string) $contract->status);

            return in_array($status, [ContractStatus::Active, ContractStatus::NoticeGiven], true);
        });

        if ($eligible->isEmpty()) {
            return collect();
        }

        $contractIds = $eligible->pluck('contract_id')->unique()->values();

        $suspendedContractIds = AccessSuspension::query()
            ->active()
            ->whereIn('contract_id', $contractIds)
            ->pluck('contract_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $suspendedLookup = array_fill_keys($suspendedContractIds, true);

        $overlockedUnitIds = UnitHold::query()
            ->whereIn('unit_id', $unitIds)
            ->whereNull('released_at')
            ->where('hold_type', HoldType::Overlock->value)
            ->where('starts_on', '<=', $today)
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('ends_on')
                    ->orWhere('ends_on', '>', $today);
            })
            ->pluck('unit_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $overlockLookup = array_fill_keys($overlockedUnitIds, true);

        /** @var Collection<int, DesiredGrant> $grants */
        $grants = collect();

        $siteLevelPoints = $points->filter(
            fn (AccessPoint $p): bool => $p->point_type->isSiteLevel(),
        );
        $doorPointsByUnit = $points
            ->filter(fn (AccessPoint $p): bool => $p->point_type === AccessPointType::UnitDoor)
            ->keyBy(fn (AccessPoint $p): int => (int) $p->unit_id);

        foreach ($eligible as $occupancy) {
            /** @var Contract $contract */
            $contract = $occupancy->contract;
            $contractId = (int) $contract->id;

            if (isset($suspendedLookup[$contractId])) {
                continue;
            }

            $contactId = (int) $contract->contact_id;
            $unitId = (int) $occupancy->unit_id;

            foreach ($siteLevelPoints as $point) {
                $grants->push(new DesiredGrant(
                    contactId: $contactId,
                    contractId: $contractId,
                    accessPointId: (int) $point->id,
                ));
            }

            if (isset($overlockLookup[$unitId])) {
                continue;
            }

            $door = $doorPointsByUnit->get($unitId);
            if ($door instanceof AccessPoint) {
                $grants->push(new DesiredGrant(
                    contactId: $contactId,
                    contractId: $contractId,
                    accessPointId: (int) $door->id,
                ));
            }
        }

        return $grants->unique(
            fn (DesiredGrant $g): string => $g->accessPointId.'|'.$g->contactId.'|'.$g->contractId,
        )->values();
    }
}
