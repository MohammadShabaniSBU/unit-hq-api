<?php

declare(strict_types=1);

namespace App\Support\Occupancy;

use App\Enums\HoldType;
use App\Enums\UnitState;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Canonical availability read path. Callers always pass an explicit civil date —
 * this helper never defaults to "today". Cross-site list filters must resolve
 * today per site via SiteClock (D8 / invariant 32).
 */
final class Availability
{
    public static function isAvailable(int $unitId, CarbonInterface $on): bool
    {
        $onDay = CarbonImmutable::instance($on)->startOfDay()->format('Y-m-d');

        $occupied = UnitOccupancy::query()
            ->where('unit_id', $unitId)
            ->where('started_on', '<=', $onDay)
            ->where(function (Builder $q) use ($onDay): void {
                $q->whereNull('ended_on')
                    ->orWhere('ended_on', '>', $onDay);
            })
            ->exists();

        if ($occupied) {
            return false;
        }

        return ! UnitHold::query()
            ->where('unit_id', $unitId)
            ->whereNull('released_at')
            ->where('hold_type', '<>', HoldType::Overlock->value)
            ->where('starts_on', '<=', $onDay)
            ->where(function (Builder $q) use ($onDay): void {
                $q->whereNull('ends_on')
                    ->orWhere('ends_on', '>', $onDay);
            })
            ->exists();
    }

    /**
     * @param  Builder<Unit>  $q
     * @return Builder<Unit>
     */
    public static function scopeAvailableOn(Builder $q, CarbonInterface $on): Builder
    {
        $onDay = CarbonImmutable::instance($on)->startOfDay()->format('Y-m-d');

        return $q
            ->whereNotExists(function ($sub) use ($onDay): void {
                $sub->selectRaw('1')
                    ->from('unit_occupancies as o')
                    ->whereColumn('o.unit_id', 'units.id')
                    ->where('o.started_on', '<=', $onDay)
                    ->where(function ($inner) use ($onDay): void {
                        $inner->whereNull('o.ended_on')
                            ->orWhere('o.ended_on', '>', $onDay);
                    });
            })
            ->whereNotExists(function ($sub) use ($onDay): void {
                $sub->selectRaw('1')
                    ->from('unit_holds as h')
                    ->whereColumn('h.unit_id', 'units.id')
                    ->whereNull('h.released_at')
                    ->where('h.hold_type', '<>', HoldType::Overlock->value)
                    ->where('h.starts_on', '<=', $onDay)
                    ->where(function ($inner) use ($onDay): void {
                        $inner->whereNull('h.ends_on')
                            ->orWhere('h.ends_on', '>', $onDay);
                    });
            });
    }

    /**
     * Unit is free for the whole half-open range [from, to).
     * NULL $to means open-ended (no occupancy/hold overlapping from onward).
     *
     * @param  Builder<Unit>  $q
     * @return Builder<Unit>
     */
    public static function scopeAvailableBetween(
        Builder $q,
        CarbonInterface $from,
        ?CarbonInterface $to,
    ): Builder {
        $fromDay = CarbonImmutable::instance($from)->startOfDay()->format('Y-m-d');
        $toDay = $to !== null
            ? CarbonImmutable::instance($to)->startOfDay()->format('Y-m-d')
            : null;

        return $q
            ->whereNotExists(function ($sub) use ($fromDay, $toDay): void {
                $sub->selectRaw('1')
                    ->from('unit_occupancies as o')
                    ->whereColumn('o.unit_id', 'units.id')
                    ->where(function ($inner) use ($fromDay): void {
                        $inner->whereNull('o.ended_on')
                            ->orWhere('o.ended_on', '>', $fromDay);
                    })
                    ->when(
                        $toDay !== null,
                        fn ($inner) => $inner->where('o.started_on', '<', $toDay),
                    );
            })
            ->whereNotExists(function ($sub) use ($fromDay, $toDay): void {
                $sub->selectRaw('1')
                    ->from('unit_holds as h')
                    ->whereColumn('h.unit_id', 'units.id')
                    ->whereNull('h.released_at')
                    ->where('h.hold_type', '<>', HoldType::Overlock->value)
                    ->where(function ($inner) use ($fromDay): void {
                        $inner->whereNull('h.ends_on')
                            ->orWhere('h.ends_on', '>', $fromDay);
                    })
                    ->when(
                        $toDay !== null,
                        fn ($inner) => $inner->where('h.starts_on', '<', $toDay),
                    );
            });
    }

    public static function stateOn(int $unitId, CarbonInterface $on): UnitState
    {
        $onDay = CarbonImmutable::instance($on)->startOfDay()->format('Y-m-d');

        $occupied = UnitOccupancy::query()
            ->where('unit_id', $unitId)
            ->where('started_on', '<=', $onDay)
            ->where(function (Builder $q) use ($onDay): void {
                $q->whereNull('ended_on')
                    ->orWhere('ended_on', '>', $onDay);
            })
            ->exists();

        if ($occupied) {
            return UnitState::Occupied;
        }

        $hold = UnitHold::query()
            ->where('unit_id', $unitId)
            ->whereNull('released_at')
            ->where('hold_type', '<>', HoldType::Overlock->value)
            ->where('starts_on', '<=', $onDay)
            ->where(function (Builder $q) use ($onDay): void {
                $q->whereNull('ends_on')
                    ->orWhere('ends_on', '>', $onDay);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($hold === null) {
            return UnitState::Available;
        }

        return match ($hold->hold_type) {
            HoldType::Reservation => UnitState::Reserved,
            HoldType::Maintenance => UnitState::Maintenance,
            HoldType::Damaged => UnitState::Damaged,
            HoldType::StaffUse => UnitState::StaffUse,
            HoldType::Other => UnitState::Other,
            HoldType::Overlock => UnitState::Available,
        };
    }

    /**
     * Restrict a units query to those available on each site's civil today.
     *
     * Grouped by timezone because "All Sites" spans zones (Madrid vs London).
     * A single :on bind would silently mis-classify units near midnight UTC.
     * In practice there are one or two distinct zones, so this is a small IN
     * per group — not one query per site.
     *
     * @param  Builder<Unit>  $q
     * @return Builder<Unit>
     */
    public static function scopeAvailableTodayPerSite(Builder $q): Builder
    {
        return self::scopeStateTodayPerSite($q, UnitState::Available);
    }

    /**
     * Units whose derived state on $on equals $state (same precedence as stateOn).
     *
     * @param  Builder<Unit>  $q
     * @return Builder<Unit>
     */
    public static function scopeStateOn(Builder $q, UnitState $state, CarbonInterface $on): Builder
    {
        $onDay = CarbonImmutable::instance($on)->startOfDay()->format('Y-m-d');

        return match ($state) {
            UnitState::Available => self::scopeAvailableOn($q, $on),
            UnitState::Occupied => self::whereCoveringOccupancy($q, $onDay),
            UnitState::Reserved,
            UnitState::Maintenance,
            UnitState::Damaged,
            UnitState::StaffUse,
            UnitState::Other => self::whereWinningHoldType(
                $q,
                $onDay,
                [self::holdTypeForState($state)->value],
            ),
        };
    }

    /**
     * Units in a named state group on $on.
     * Currently only `out_of_service` (= maintenance|damaged|staff_use|other).
     *
     * @param  Builder<Unit>  $q
     * @return Builder<Unit>
     */
    public static function scopeStateGroupOn(Builder $q, string $group, CarbonInterface $on): Builder
    {
        if ($group !== 'out_of_service') {
            return $q->whereRaw('0 = 1');
        }

        $onDay = CarbonImmutable::instance($on)->startOfDay()->format('Y-m-d');

        return self::whereWinningHoldType($q, $onDay, [
            HoldType::Maintenance->value,
            HoldType::Damaged->value,
            HoldType::StaffUse->value,
            HoldType::Other->value,
        ]);
    }

    /**
     * @param  Builder<Unit>  $q
     * @return Builder<Unit>
     */
    public static function scopeStateTodayPerSite(Builder $q, UnitState $state): Builder
    {
        return self::applyPerSiteToday($q, function (Builder $inner, CarbonInterface $on) use ($state): void {
            self::scopeStateOn($inner, $state, $on);
        });
    }

    /**
     * @param  Builder<Unit>  $q
     * @return Builder<Unit>
     */
    public static function scopeStateGroupTodayPerSite(Builder $q, string $group): Builder
    {
        return self::applyPerSiteToday($q, function (Builder $inner, CarbonInterface $on) use ($group): void {
            self::scopeStateGroupOn($inner, $group, $on);
        });
    }

    /**
     * @param  Builder<Unit>  $q
     * @param  callable(Builder<Unit>, CarbonInterface): void  $apply
     * @return Builder<Unit>
     */
    private static function applyPerSiteToday(Builder $q, callable $apply): Builder
    {
        /** @var Collection<int, Site> $sites */
        $sites = Site::query()->get(['id', 'timezone']);

        if ($sites->isEmpty()) {
            return $q->whereRaw('0 = 1');
        }

        $byTimezone = $sites->groupBy(fn (Site $site): string => $site->timezone);

        return $q->where(function (Builder $outer) use ($byTimezone, $apply): void {
            $first = true;

            foreach ($byTimezone as $timezone => $group) {
                $siteIds = $group->pluck('id')->all();
                $on = SiteClock::today($group->first());

                $clause = function (Builder $inner) use ($siteIds, $on, $apply): void {
                    $inner->whereIn('units.site_id', $siteIds);
                    $apply($inner, $on);
                };

                if ($first) {
                    $outer->where($clause);
                    $first = false;
                } else {
                    $outer->orWhere($clause);
                }
            }
        });
    }

    /**
     * @param  Builder<Unit>  $q
     * @return Builder<Unit>
     */
    private static function whereCoveringOccupancy(Builder $q, string $onDay): Builder
    {
        return $q->whereExists(function ($sub) use ($onDay): void {
            $sub->selectRaw('1')
                ->from('unit_occupancies as o')
                ->whereColumn('o.unit_id', 'units.id')
                ->where('o.started_on', '<=', $onDay)
                ->where(function ($inner) use ($onDay): void {
                    $inner->whereNull('o.ended_on')
                        ->orWhere('o.ended_on', '>', $onDay);
                });
        });
    }

    /**
     * No covering occupancy, and the earliest-created covering blocking hold
     * has a hold_type in $holdTypes.
     *
     * @param  Builder<Unit>  $q
     * @param  list<string>  $holdTypes
     * @return Builder<Unit>
     */
    private static function whereWinningHoldType(Builder $q, string $onDay, array $holdTypes): Builder
    {
        return $q
            ->whereNotExists(function ($sub) use ($onDay): void {
                $sub->selectRaw('1')
                    ->from('unit_occupancies as o')
                    ->whereColumn('o.unit_id', 'units.id')
                    ->where('o.started_on', '<=', $onDay)
                    ->where(function ($inner) use ($onDay): void {
                        $inner->whereNull('o.ended_on')
                            ->orWhere('o.ended_on', '>', $onDay);
                    });
            })
            ->whereExists(function ($sub) use ($onDay, $holdTypes): void {
                $sub->selectRaw('1')
                    ->from('unit_holds as h')
                    ->whereColumn('h.unit_id', 'units.id')
                    ->whereNull('h.released_at')
                    ->where('h.hold_type', '<>', HoldType::Overlock->value)
                    ->where('h.starts_on', '<=', $onDay)
                    ->where(function ($inner) use ($onDay): void {
                        $inner->whereNull('h.ends_on')
                            ->orWhere('h.ends_on', '>', $onDay);
                    })
                    ->whereIn('h.hold_type', $holdTypes)
                    ->whereRaw(
                        'h.id = (
                            SELECT h2.id FROM unit_holds AS h2
                            WHERE h2.unit_id = units.id
                              AND h2.released_at IS NULL
                              AND h2.hold_type <> ?
                              AND h2.starts_on <= ?
                              AND (h2.ends_on IS NULL OR h2.ends_on > ?)
                            ORDER BY h2.created_at ASC, h2.id ASC
                            LIMIT 1
                        )',
                        [HoldType::Overlock->value, $onDay, $onDay],
                    );
            });
    }

    private static function holdTypeForState(UnitState $state): HoldType
    {
        return match ($state) {
            UnitState::Reserved => HoldType::Reservation,
            UnitState::Maintenance => HoldType::Maintenance,
            UnitState::Damaged => HoldType::Damaged,
            UnitState::StaffUse => HoldType::StaffUse,
            UnitState::Other => HoldType::Other,
            default => throw new \InvalidArgumentException("State {$state->value} is not hold-driven"),
        };
    }

    /**
     * Covering occupancy on $on, if any.
     */
    public static function coveringOccupancy(int $unitId, CarbonInterface $on): ?UnitOccupancy
    {
        $onDay = CarbonImmutable::instance($on)->startOfDay()->format('Y-m-d');

        return UnitOccupancy::query()
            ->where('unit_id', $unitId)
            ->where('started_on', '<=', $onDay)
            ->where(function (Builder $q) use ($onDay): void {
                $q->whereNull('ended_on')
                    ->orWhere('ended_on', '>', $onDay);
            })
            ->first();
    }

    /**
     * Earliest-created covering blocking hold on $on, if any.
     */
    public static function coveringHold(int $unitId, CarbonInterface $on): ?UnitHold
    {
        $onDay = CarbonImmutable::instance($on)->startOfDay()->format('Y-m-d');

        return UnitHold::query()
            ->where('unit_id', $unitId)
            ->whereNull('released_at')
            ->where('hold_type', '<>', HoldType::Overlock->value)
            ->where('starts_on', '<=', $onDay)
            ->where(function (Builder $q) use ($onDay): void {
                $q->whereNull('ends_on')
                    ->orWhere('ends_on', '>', $onDay);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Batch-attach covering occupancy/hold + derived state onto units.
     * When $on is null, resolves today per site timezone group (D8).
     *
     * Sets: coveringOccupancy relation, coveringHold relation, derived_state attribute.
     *
     * @param  Collection<int, Unit>  $units
     */
    public static function hydrateState(Collection $units, ?CarbonInterface $on = null): void
    {
        if ($units->isEmpty()) {
            return;
        }

        // Accept Support\Collection from paginator items — Eloquent\Collection has loadMissing.
        $eloquentUnits = $units instanceof EloquentCollection
            ? $units
            : new EloquentCollection($units->all());

        $eloquentUnits->loadMissing('site');

        /** @var Collection<string, Collection<int, Unit>> $groups */
        $groups = $on !== null
            ? collect(['explicit' => $eloquentUnits])
            : $eloquentUnits->groupBy(fn (Unit $unit): string => $unit->site->timezone);

        foreach ($groups as $key => $group) {
            $onDay = $on !== null
                ? CarbonImmutable::instance($on)->startOfDay()
                : SiteClock::today($group->first()->site);
            $onStr = $onDay->format('Y-m-d');
            $unitIds = $group->pluck('id')->all();

            $occupancies = UnitOccupancy::query()
                ->with(['contract.contact', 'contract.items'])
                ->whereIn('unit_id', $unitIds)
                ->where('started_on', '<=', $onStr)
                ->where(function (Builder $q) use ($onStr): void {
                    $q->whereNull('ended_on')
                        ->orWhere('ended_on', '>', $onStr);
                })
                ->get()
                ->keyBy('unit_id');

            $holds = UnitHold::query()
                ->whereIn('unit_id', $unitIds)
                ->whereNull('released_at')
                ->where('hold_type', '<>', HoldType::Overlock->value)
                ->where('starts_on', '<=', $onStr)
                ->where(function (Builder $q) use ($onStr): void {
                    $q->whereNull('ends_on')
                        ->orWhere('ends_on', '>', $onStr);
                })
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->groupBy('unit_id')
                ->map(fn (Collection $rows) => $rows->first());

            foreach ($group as $unit) {
                $occupancy = $occupancies->get($unit->id);
                $hold = $holds->get($unit->id);

                $unit->setRelation('coveringOccupancy', $occupancy);
                $unit->setRelation('coveringHold', $hold);

                if ($occupancy !== null) {
                    $unit->setAttribute('derived_state', UnitState::Occupied->value);
                } elseif ($hold !== null) {
                    $unit->setAttribute('derived_state', match ($hold->hold_type) {
                        HoldType::Reservation => UnitState::Reserved->value,
                        HoldType::Maintenance => UnitState::Maintenance->value,
                        HoldType::Damaged => UnitState::Damaged->value,
                        HoldType::StaffUse => UnitState::StaffUse->value,
                        HoldType::Other => UnitState::Other->value,
                        HoldType::Overlock => UnitState::Available->value,
                    });
                } else {
                    $unit->setAttribute('derived_state', UnitState::Available->value);
                }
            }
        }
    }
}
