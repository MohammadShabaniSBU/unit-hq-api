<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\ContractItem;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Models\UnitOccupancy;
use App\Support\Billing\BillingMath;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Point-in-time occupancy snapshot (unit / area / economic) for Insights.
 * Definitions: docs/report-definitions.md — Occupancy section.
 */
final class OccupancyMetrics
{
    /** Hold types that shrink the rentable denominator. */
    private const BLOCKING_HOLD_TYPES = [
        HoldType::Maintenance->value,
        HoldType::Damaged->value,
        HoldType::StaffUse->value,
        HoldType::Other->value,
    ];

    /**
     * Resolve as-of civil date from filters (site-local today when omitted).
     */
    public static function resolveAsOf(ReportFilters $filters): string
    {
        if ($filters->asOf !== null && $filters->asOf !== '') {
            return $filters->asOf;
        }

        if ($filters->siteIds !== null && $filters->siteIds !== []) {
            $site = Site::query()->find($filters->siteIds[0]);
            if ($site !== null) {
                return SiteClock::today($site)->format('Y-m-d');
            }
        }

        return CarbonImmutable::now()->toDateString();
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return array{
     *     as_of: string,
     *     occupied_units: int,
     *     rentable_units: int,
     *     occupied_area: string,
     *     rentable_area: string,
     *     unit_rate: float|null,
     *     area_rate: float|null,
     *     economic_numerator: string,
     *     economic_denominator: string,
     *     economic_currency: string,
     *     economic_rate: float|null,
     *     by_site_class: list<array{
     *         site_id: int,
     *         site_name: string,
     *         unit_class_id: int,
     *         class_code: string,
     *         class_label: string,
     *         occupied: int,
     *         rentable: int,
     *         occupied_area: string,
     *         rentable_area: string,
     *         unit_rate: float|null,
     *         area_rate: float|null,
     *         economic_numerator: string,
     *         economic_denominator: string,
     *         economic_rate: float|null
     *     }>
     * }
     */
    public static function snapshot(string $asOf, ?array $siteIds = null): array
    {
        $on = CarbonImmutable::parse($asOf)->toDateString();

        $unitsQuery = Unit::query()
            ->with(['site:id,name', 'unitClass:id,code,label,size'])
            ->where('enabled', true);

        if ($siteIds !== null) {
            $unitsQuery->whereIn('site_id', $siteIds);
        }

        /** @var list<Unit> $units */
        $units = $unitsQuery->orderBy('site_id')->orderBy('unit_class_id')->orderBy('id')->get()->all();

        if ($units === []) {
            return self::emptySnapshot($on);
        }

        $unitIds = array_map(static fn (Unit $u): int => $u->id, $units);

        $occupiedUnitIds = self::occupiedUnitIds($unitIds, $on);
        $blockedUnitIds = self::blockingHoldUnitIds($unitIds, $on);

        $catalogueByPair = self::catalogueAmountsBySiteClass($units);

        /** @var array<string, array{
         *     site_id: int,
         *     site_name: string,
         *     unit_class_id: int,
         *     class_code: string,
         *     class_label: string,
         *     occupied: int,
         *     rentable: int,
         *     occupied_area: string,
         *     rentable_area: string,
         *     economic_numerator: string,
         *     economic_denominator: string,
         *     currency: string
         * }> $buckets */
        $buckets = [];

        $occupiedUnits = 0;
        $rentableUnits = 0;
        $occupiedArea = '0.00';
        $rentableArea = '0.00';
        $economicNum = '0.00';
        $economicDen = '0.00';
        $currency = 'EUR';

        $inPlaceByUnit = self::inPlaceUnitRents($occupiedUnitIds, $on);

        foreach ($units as $unit) {
            $site = $unit->site;
            $class = $unit->unitClass;
            if ($site === null || $class === null) {
                continue;
            }

            $key = $site->id.'|'.$class->id;
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'site_id' => $site->id,
                    'site_name' => (string) $site->name,
                    'unit_class_id' => $class->id,
                    'class_code' => (string) $class->code,
                    'class_label' => (string) $class->label,
                    'occupied' => 0,
                    'rentable' => 0,
                    'occupied_area' => '0.00',
                    'rentable_area' => '0.00',
                    'economic_numerator' => '0.00',
                    'economic_denominator' => '0.00',
                    'currency' => strtoupper(trim((string) ($site->currency ?: 'EUR'))) ?: 'EUR',
                ];
            }

            $area = BillingMath::round2((string) ($class->size ?? '0'));
            $isBlocked = isset($blockedUnitIds[$unit->id]);
            $isOccupied = isset($occupiedUnitIds[$unit->id]);

            if (! $isBlocked) {
                $buckets[$key]['rentable']++;
                $buckets[$key]['rentable_area'] = bcadd($buckets[$key]['rentable_area'], $area, 2);
                $rentableUnits++;
                $rentableArea = bcadd($rentableArea, $area, 2);

                $pairKey = $site->id.'|'.$class->id;
                $cat = $catalogueByPair[$pairKey] ?? null;
                if ($cat !== null) {
                    $buckets[$key]['economic_denominator'] = bcadd(
                        $buckets[$key]['economic_denominator'],
                        $cat['amount'],
                        2,
                    );
                    $economicDen = bcadd($economicDen, $cat['amount'], 2);
                    $currency = $cat['currency'];
                    $buckets[$key]['currency'] = $cat['currency'];
                }
            }

            if ($isOccupied) {
                $buckets[$key]['occupied']++;
                $buckets[$key]['occupied_area'] = bcadd($buckets[$key]['occupied_area'], $area, 2);
                $occupiedUnits++;
                $occupiedArea = bcadd($occupiedArea, $area, 2);

                $rent = $inPlaceByUnit[$unit->id] ?? null;
                if ($rent !== null) {
                    $buckets[$key]['economic_numerator'] = bcadd(
                        $buckets[$key]['economic_numerator'],
                        $rent['amount'],
                        2,
                    );
                    $economicNum = bcadd($economicNum, $rent['amount'], 2);
                    $currency = $rent['currency'];
                }
            }
        }

        $bySiteClass = [];
        foreach ($buckets as $bucket) {
            $bySiteClass[] = [
                'site_id' => $bucket['site_id'],
                'site_name' => $bucket['site_name'],
                'unit_class_id' => $bucket['unit_class_id'],
                'class_code' => $bucket['class_code'],
                'class_label' => $bucket['class_label'],
                'occupied' => $bucket['occupied'],
                'rentable' => $bucket['rentable'],
                'occupied_area' => $bucket['occupied_area'],
                'rentable_area' => $bucket['rentable_area'],
                'unit_rate' => self::rate($bucket['occupied'], $bucket['rentable']),
                'area_rate' => self::areaRate($bucket['occupied_area'], $bucket['rentable_area']),
                'economic_numerator' => $bucket['economic_numerator'],
                'economic_denominator' => $bucket['economic_denominator'],
                'economic_rate' => self::moneyRate(
                    $bucket['economic_numerator'],
                    $bucket['economic_denominator'],
                ),
            ];
        }

        usort($bySiteClass, static function (array $a, array $b): int {
            return [$a['site_name'], $a['class_code']] <=> [$b['site_name'], $b['class_code']];
        });

        return [
            'as_of' => $on,
            'occupied_units' => $occupiedUnits,
            'rentable_units' => $rentableUnits,
            'occupied_area' => $occupiedArea,
            'rentable_area' => $rentableArea,
            'unit_rate' => self::rate($occupiedUnits, $rentableUnits),
            'area_rate' => self::areaRate($occupiedArea, $rentableArea),
            'economic_numerator' => BillingMath::round2($economicNum),
            'economic_denominator' => BillingMath::round2($economicDen),
            'economic_currency' => $currency,
            'economic_rate' => self::moneyRate($economicNum, $economicDen),
            'by_site_class' => $bySiteClass,
        ];
    }

    /**
     * Month-end occupancy points for trend charts (≤24 points).
     *
     * @param  list<int>|null  $siteIds
     * @return list<array{
     *     month_end: string,
     *     occupied_units: int,
     *     rentable_units: int,
     *     unit_rate: float|null,
     *     area_rate: float|null,
     *     economic_rate: float|null,
     *     economic_numerator: string,
     *     economic_denominator: string
     * }>
     */
    public static function monthlySeries(
        string $asOf,
        ?array $siteIds = null,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $end = CarbonImmutable::parse($to ?? $asOf)->endOfMonth()->startOfDay();
        $start = $from !== null
            ? CarbonImmutable::parse($from)->endOfMonth()->startOfDay()
            : $end->subMonthsNoOverflow(11)->endOfMonth()->startOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        $points = [];
        $cursor = $start;
        while ($cursor->lessThanOrEqualTo($end) && count($points) < 24) {
            $monthEnd = $cursor->endOfMonth()->toDateString();
            $snap = self::snapshot($monthEnd, $siteIds);
            $points[] = [
                'month_end' => $monthEnd,
                'occupied_units' => $snap['occupied_units'],
                'rentable_units' => $snap['rentable_units'],
                'unit_rate' => $snap['unit_rate'],
                'area_rate' => $snap['area_rate'],
                'economic_rate' => $snap['economic_rate'],
                'economic_numerator' => $snap['economic_numerator'],
                'economic_denominator' => $snap['economic_denominator'],
            ];
            $cursor = $cursor->addMonthNoOverflow()->endOfMonth()->startOfDay();
        }

        return $points;
    }

    /**
     * @return array{
     *     as_of: string,
     *     occupied_units: int,
     *     rentable_units: int,
     *     occupied_area: string,
     *     rentable_area: string,
     *     unit_rate: float|null,
     *     area_rate: float|null,
     *     economic_numerator: string,
     *     economic_denominator: string,
     *     economic_currency: string,
     *     economic_rate: float|null,
     *     by_site_class: list<array<string, mixed>>
     * }
     */
    private static function emptySnapshot(string $on): array
    {
        return [
            'as_of' => $on,
            'occupied_units' => 0,
            'rentable_units' => 0,
            'occupied_area' => '0.00',
            'rentable_area' => '0.00',
            'unit_rate' => null,
            'area_rate' => null,
            'economic_numerator' => '0.00',
            'economic_denominator' => '0.00',
            'economic_currency' => 'EUR',
            'economic_rate' => null,
            'by_site_class' => [],
        ];
    }

    /**
     * @param  list<int>  $unitIds
     * @return array<int, true>
     */
    private static function occupiedUnitIds(array $unitIds, string $on): array
    {
        if ($unitIds === []) {
            return [];
        }

        $ids = UnitOccupancy::query()
            ->whereIn('unit_id', $unitIds)
            ->where('started_on', '<=', $on)
            ->where(function (Builder $q) use ($on): void {
                $q->whereNull('ended_on')
                    ->orWhere('ended_on', '>', $on);
            })
            ->distinct()
            ->pluck('unit_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $set = [];
        foreach ($ids as $id) {
            $set[$id] = true;
        }

        return $set;
    }

    /**
     * @param  list<int>  $unitIds
     * @return array<int, true>
     */
    private static function blockingHoldUnitIds(array $unitIds, string $on): array
    {
        if ($unitIds === []) {
            return [];
        }

        $ids = DB::table('unit_holds')
            ->whereIn('unit_id', $unitIds)
            ->whereNull('released_at')
            ->whereIn('hold_type', self::BLOCKING_HOLD_TYPES)
            ->where('starts_on', '<=', $on)
            ->where(function ($q) use ($on): void {
                $q->whereNull('ends_on')
                    ->orWhere('ends_on', '>', $on);
            })
            ->distinct()
            ->pluck('unit_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $set = [];
        foreach ($ids as $id) {
            $set[$id] = true;
        }

        return $set;
    }

    /**
     * @param  list<Unit>  $units
     * @return array<string, array{amount: string, currency: string}>
     */
    private static function catalogueAmountsBySiteClass(array $units): array
    {
        $pairs = [];
        foreach ($units as $unit) {
            $pairs[$unit->site_id.'|'.$unit->unit_class_id] = [
                'site_id' => (int) $unit->site_id,
                'unit_class_id' => (int) $unit->unit_class_id,
            ];
        }

        if ($pairs === []) {
            return [];
        }

        $siteIds = array_values(array_unique(array_map(
            static fn (array $p): int => $p['site_id'],
            $pairs,
        )));
        $classIds = array_values(array_unique(array_map(
            static fn (array $p): int => $p['unit_class_id'],
            $pairs,
        )));

        $rates = UnitClassRate::query()
            ->with(['price'])
            ->whereIn('site_id', $siteIds)
            ->whereIn('unit_class_id', $classIds)
            ->get();

        $out = [];
        foreach ($rates as $rate) {
            $price = $rate->price;
            if ($price === null) {
                continue;
            }
            $out[$rate->site_id.'|'.$rate->unit_class_id] = [
                'amount' => BillingMath::round2((string) $price->amount),
                'currency' => strtoupper(trim((string) $price->currency)) ?: 'EUR',
            ];
        }

        return $out;
    }

    /**
     * In-place unit rent per occupied unit (itemsOn unit lines).
     *
     * @param  array<int, true>  $occupiedUnitIds
     * @return array<int, array{amount: string, currency: string}>
     */
    private static function inPlaceUnitRents(array $occupiedUnitIds, string $on): array
    {
        if ($occupiedUnitIds === []) {
            return [];
        }

        $unitIds = array_keys($occupiedUnitIds);

        $occupancies = UnitOccupancy::query()
            ->with(['contract:id,status,currency'])
            ->whereIn('unit_id', $unitIds)
            ->where('started_on', '<=', $on)
            ->where(function (Builder $q) use ($on): void {
                $q->whereNull('ended_on')
                    ->orWhere('ended_on', '>', $on);
            })
            ->get();

        $excluded = [
            ContractStatus::AwaitingSignature->value,
            ContractStatus::Cancelled->value,
        ];

        /** @var array<int, int> $unitToContract */
        $unitToContract = [];
        $contractIds = [];
        foreach ($occupancies as $occ) {
            $contract = $occ->contract;
            if ($contract === null) {
                continue;
            }
            $status = $contract->status instanceof ContractStatus
                ? $contract->status->value
                : (string) $contract->status;
            if (in_array($status, $excluded, true)) {
                continue;
            }
            $unitToContract[(int) $occ->unit_id] = (int) $contract->id;
            $contractIds[(int) $contract->id] = true;
        }

        if ($contractIds === []) {
            return [];
        }

        $items = ContractItem::query()
            ->with('price')
            ->whereIn('contract_id', array_keys($contractIds))
            ->where('item_type', 'unit')
            ->effectiveOn(CarbonImmutable::parse($on))
            ->get();

        /** @var array<int, array{amount: string, currency: string}> $byContractUnit */
        $byContractUnit = [];
        foreach ($items as $item) {
            $price = $item->price;
            if ($price === null) {
                continue;
            }
            $key = $item->contract_id.'|'.$item->item_id;
            $byContractUnit[$key] = [
                'amount' => BillingMath::round2((string) $price->amount),
                'currency' => strtoupper(trim((string) $price->currency)) ?: 'EUR',
            ];
        }

        $out = [];
        foreach ($unitToContract as $unitId => $contractId) {
            $key = $contractId.'|'.$unitId;
            if (isset($byContractUnit[$key])) {
                $out[$unitId] = $byContractUnit[$key];
            }
        }

        return $out;
    }

    private static function rate(int $num, int $den): ?float
    {
        if ($den <= 0) {
            return null;
        }

        return round(($num / $den) * 100, 1);
    }

    private static function areaRate(string $num, string $den): ?float
    {
        if (bccomp($den, '0', 2) <= 0) {
            return null;
        }

        $pct = (float) bcmul(bcdiv($num, $den, 8), '100', 8);

        return round($pct, 1);
    }

    private static function moneyRate(string $num, string $den): ?float
    {
        if (bccomp($den, '0', 2) <= 0) {
            return null;
        }

        $pct = (float) bcmul(bcdiv($num, $den, 8), '100', 8);

        return round($pct, 1);
    }
}
