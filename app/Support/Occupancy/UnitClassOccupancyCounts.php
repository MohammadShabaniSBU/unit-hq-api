<?php

declare(strict_types=1);

namespace App\Support\Occupancy;

use App\Enums\HoldType;
use App\Models\Site;
use App\Models\Unit;
use App\Support\Time\SiteClock;
use Illuminate\Support\Collection;

/**
 * Site × unit-class occupancy counts for the facility matrix.
 *
 * Denominator is rentable units (docs/report-definitions.md): enabled, and not
 * under a blocking hold. Same occupied / rentable split as OccupancyMetrics.
 * As-of date is each site's today (invariant 32 / 36). Not an analytics read.
 */
final class UnitClassOccupancyCounts
{
    /** @var list<string> */
    private const BLOCKING_HOLD_TYPES = [
        HoldType::Maintenance->value,
        HoldType::Damaged->value,
        HoldType::StaffUse->value,
        HoldType::Other->value,
    ];

    /** @var list<string> */
    private const RESERVATION_HOLD_TYPES = [
        HoldType::Reservation->value,
        HoldType::ContractSignature->value,
    ];

    /**
     * @param  Collection<int, Site>  $sites
     * @return Collection<string, array{total: int, occupied: int, held_blocking: int, rentable: int, free: int}>
     */
    public static function forSites(Collection $sites): Collection
    {
        /** @var Collection<string, array{total: int, occupied: int, held_blocking: int, rentable: int, free: int}> $out */
        $out = collect();

        if ($sites->isEmpty()) {
            return $out;
        }

        /** @var Collection<string, Collection<int, Site>> $byTimezone */
        $byTimezone = $sites->groupBy(fn (Site $site): string => $site->timezone);

        foreach ($byTimezone as $group) {
            $site = $group->first();
            if ($site === null) {
                continue;
            }

            $on = SiteClock::today($site)->format('Y-m-d');
            $siteIds = $group->pluck('id')->all();

            $rows = Unit::query()
                ->where('units.enabled', true)
                ->whereIn('units.site_id', $siteIds)
                ->select('units.site_id', 'units.unit_class_id')
                ->selectRaw('COUNT(*) as total')
                ->selectRaw(
                    'SUM(CASE WHEN '.self::occupancyExists().' THEN 1 ELSE 0 END) as occupied',
                    [$on, $on],
                )
                ->selectRaw(
                    'SUM(CASE WHEN '.self::holdExists(count(self::BLOCKING_HOLD_TYPES)).' THEN 1 ELSE 0 END) as held_blocking',
                    [...self::BLOCKING_HOLD_TYPES, $on, $on],
                )
                ->selectRaw(
                    'SUM(CASE WHEN NOT '.self::occupancyExists()
                    .' AND NOT '.self::holdExists(count(self::BLOCKING_HOLD_TYPES))
                    .' AND NOT '.self::holdExists(count(self::RESERVATION_HOLD_TYPES))
                    .' THEN 1 ELSE 0 END) as free',
                    [
                        $on,
                        $on,
                        ...self::BLOCKING_HOLD_TYPES,
                        $on,
                        $on,
                        ...self::RESERVATION_HOLD_TYPES,
                        $on,
                        $on,
                    ],
                )
                ->groupBy('units.site_id', 'units.unit_class_id')
                ->get();

            foreach ($rows as $row) {
                $total = (int) $row->getAttribute('total');
                $heldBlocking = (int) $row->getAttribute('held_blocking');
                $key = $row->getAttribute('site_id').'|'.$row->getAttribute('unit_class_id');

                $out->put($key, [
                    'total' => $total,
                    'occupied' => (int) $row->getAttribute('occupied'),
                    'held_blocking' => $heldBlocking,
                    'rentable' => $total - $heldBlocking,
                    'free' => (int) $row->getAttribute('free'),
                ]);
            }
        }

        return $out;
    }

    private static function occupancyExists(): string
    {
        return 'EXISTS (
            SELECT 1 FROM unit_occupancies AS o
            WHERE o.unit_id = units.id
              AND o.started_on <= ?
              AND (o.ended_on IS NULL OR o.ended_on > ?)
        )';
    }

    private static function holdExists(int $typeCount): string
    {
        $placeholders = implode(', ', array_fill(0, $typeCount, '?'));

        return "EXISTS (
            SELECT 1 FROM unit_holds AS h
            WHERE h.unit_id = units.id
              AND h.released_at IS NULL
              AND h.hold_type IN ({$placeholders})
              AND h.starts_on <= ?
              AND (h.ends_on IS NULL OR h.ends_on > ?)
        )";
    }
}
