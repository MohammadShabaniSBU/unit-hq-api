<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\ContractItem;
use App\Models\Unit;
use App\Support\Billing\BillingMath;
use App\Support\Reports\Charts\CategoryKind;
use App\Support\Reports\Charts\ChartFormat;
use App\Support\Reports\Charts\ChartScope;
use App\Support\Reports\Charts\ChartSeries;
use App\Support\Reports\Charts\ChartSpec;
use App\Support\Reports\Charts\ChartType;
use App\Support\Reports\Charts\ChartWidth;
use App\Support\Reports\Charts\MonthSpine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * In-place rent against street rate, for an existing-customer rate increase.
 * Definitions: docs/report-definitions.md — Rate management / Gestión de tarifas.
 */
final class RateManagementReport extends AbstractReport
{
    public const ECRI_MIN_MONTHS = 12;

    /** @var list<string> */
    public const VARIANCE_BANDS = [
        'lt_minus_20',
        'minus_20_10',
        'minus_10_0',
        'zero_10',
        'gt_10',
    ];

    /** @var list<string> */
    public const RENT_AGE_BANDS = [
        'm0_5',
        'm6_11',
        'm12_17',
        'm18_23',
        'm24_plus',
    ];

    public static function name(): string
    {
        return 'rate-management';
    }

    public function maxQueries(): int
    {
        return 12;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $asOf = OccupancyMetrics::resolveAsOf($filters);

        $occupancies = DB::table('unit_occupancies')
            ->join('contracts', 'contracts.id', '=', 'unit_occupancies.contract_id')
            ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
            ->join('sites', 'sites.id', '=', 'units.site_id')
            ->join('unit_classes', 'unit_classes.id', '=', 'units.unit_class_id')
            ->where('units.enabled', true)
            ->where('unit_occupancies.started_on', '<=', $asOf)
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('unit_occupancies.ended_on')
                    ->orWhere('unit_occupancies.ended_on', '>', $asOf);
            })
            ->whereNotIn('contracts.status', ChartScope::EXCLUDED_CONTRACT_STATUSES)
            ->when(
                $filters->siteIds === [],
                static fn ($query) => $query->whereRaw('0 = 1'),
            )
            ->when(
                is_array($filters->siteIds) && $filters->siteIds !== [],
                static fn ($query) => $query->whereIn('units.site_id', $filters->siteIds),
            )
            ->get([
                'unit_occupancies.contract_id',
                'unit_occupancies.unit_id',
                'units.site_id',
                'units.unit_class_id',
                'sites.name as site_name',
                'sites.currency as site_currency',
                'unit_classes.code as class_code',
                'unit_classes.size as class_size',
            ]);

        $contractIds = [];
        $unitIds = [];
        foreach ($occupancies as $occupancy) {
            $contractIds[(int) $occupancy->contract_id] = true;
            $unitIds[(int) $occupancy->unit_id] = true;
        }

        /** @var array<string, ContractItem> $itemsByContractUnit */
        $itemsByContractUnit = [];
        if ($contractIds !== []) {
            $items = ContractItem::query()
                ->with('price')
                ->whereIn('contract_id', array_keys($contractIds))
                ->where('item_type', 'unit')
                ->effectiveOn(CarbonImmutable::parse($asOf))
                ->get();
            foreach ($items as $item) {
                $itemsByContractUnit[$item->contract_id.'|'.$item->item_id] = $item;
            }
        }

        $occupiedUnits = $unitIds === []
            ? []
            : Unit::query()->whereIn('id', array_keys($unitIds))->get()->all();
        $streetByPair = OccupancyMetrics::catalogueAmountsBySiteClass($occupiedUnits);

        /** @var array<string, array<string, mixed>> $groups currency => class code => accumulator */
        $groups = [];
        /** @var array<string, array<string, int>> $varianceCounts */
        $varianceCounts = [];
        /** @var array<string, array<string, array{below: int, above: int}>> $ageCounts */
        $ageCounts = [];
        /** @var array<string, array{tenants: int, below: int, gap: string, eligible: int, opportunity: string}> $headlines */
        $headlines = [];

        foreach ($occupancies as $occupancy) {
            $item = $itemsByContractUnit[$occupancy->contract_id.'|'.$occupancy->unit_id] ?? null;
            $price = $item?->price;
            if ($item === null || $price === null) {
                continue;
            }

            $pair = $occupancy->site_id.'|'.$occupancy->unit_class_id;
            $street = $streetByPair[$pair] ?? null;
            if ($street === null || bccomp($street['amount'], '0', 2) <= 0) {
                continue;
            }

            $inPlaceCurrency = ChartScope::currency($price->currency);
            $streetCurrency = ChartScope::currency($street['currency']);
            if ($inPlaceCurrency !== $streetCurrency) {
                continue;
            }

            $inPlace = BillingMath::round2((string) $price->amount);
            $streetAmount = BillingMath::round2($street['amount']);
            $currency = $streetCurrency;
            $classCode = (string) $occupancy->class_code;
            $below = BillingMath::cmp($inPlace, $streetAmount) < 0;
            $gap = $below ? self::sub($streetAmount, $inPlace) : '0.00';
            $age = MonthSpine::monthsBetween($item->effective_from->toDateString(), $asOf);
            if ($age < 0) {
                $age = 0;
            }
            $eligible = $below && $age >= self::ECRI_MIN_MONTHS;

            $headlines[$currency] ??= [
                'tenants' => 0,
                'below_street' => 0,
                'monthly_gap_to_street' => '0.00',
                'ecri_eligible' => 0,
                'ecri_monthly_opportunity' => '0.00',
            ];
            $headlines[$currency]['tenants']++;
            if ($below) {
                $headlines[$currency]['below_street']++;
                $headlines[$currency]['monthly_gap_to_street'] = self::add(
                    $headlines[$currency]['monthly_gap_to_street'],
                    $gap,
                );
            }
            if ($eligible) {
                $headlines[$currency]['ecri_eligible']++;
                $headlines[$currency]['ecri_monthly_opportunity'] = self::add(
                    $headlines[$currency]['ecri_monthly_opportunity'],
                    $gap,
                );
            }

            $varianceBand = self::varianceBand(self::variancePercent($inPlace, $streetAmount));
            $varianceCounts[$currency][$varianceBand] = ($varianceCounts[$currency][$varianceBand] ?? 0) + 1;

            $ageBand = self::rentAgeBand($age);
            $ageCounts[$currency][$ageBand] ??= ['below' => 0, 'above' => 0];
            if ($below) {
                $ageCounts[$currency][$ageBand]['below']++;
            } else {
                $ageCounts[$currency][$ageBand]['above']++;
            }

            $size = $occupancy->class_size !== null ? (float) $occupancy->class_size : 0.0;
            $groups[$currency][$classCode] ??= [
                'size' => $size,
                'site_rows' => [],
            ];
            if ($size < $groups[$currency][$classCode]['size']) {
                $groups[$currency][$classCode]['size'] = $size;
            }
            $siteKey = $occupancy->site_id.'|'.$classCode;
            $groups[$currency][$classCode]['site_rows'][$siteKey] ??= [
                'site' => (string) $occupancy->site_name,
                'class' => $classCode,
                'size' => $size,
                'tenants' => 0,
                'street' => $streetAmount,
                'in_place_sum' => '0.00',
                'below' => 0,
                'eligible' => 0,
                'gap' => '0.00',
                'street_sum' => '0.00',
            ];
            $row = &$groups[$currency][$classCode]['site_rows'][$siteKey];
            $row['tenants']++;
            $row['in_place_sum'] = self::add($row['in_place_sum'], $inPlace);
            $row['street_sum'] = self::add($row['street_sum'], $streetAmount);
            if ($below) {
                $row['below']++;
                $row['gap'] = self::add($row['gap'], $gap);
            }
            if ($eligible) {
                $row['eligible']++;
            }
            unset($row);
        }

        $currencies = array_keys($groups);
        sort($currencies);

        $rows = [];
        $charts = [];
        foreach ($currencies as $currency) {
            $classCodes = array_keys($groups[$currency]);
            usort($classCodes, static function (string $left, string $right) use ($groups, $currency): int {
                $size = $groups[$currency][$left]['size'] <=> $groups[$currency][$right]['size'];

                return $size !== 0 ? $size : $left <=> $right;
            });

            $avgStreet = [];
            $avgInPlace = [];
            foreach ($classCodes as $classCode) {
                $tenantCount = 0;
                $streetSum = '0.00';
                $inPlaceSum = '0.00';
                foreach ($groups[$currency][$classCode]['site_rows'] as $siteRow) {
                    $tenantCount += $siteRow['tenants'];
                    $streetSum = self::add($streetSum, $siteRow['street_sum']);
                    $inPlaceSum = self::add($inPlaceSum, $siteRow['in_place_sum']);
                    $avg = self::div($siteRow['in_place_sum'], $siteRow['tenants']);
                    $rows[] = [
                        'site' => $siteRow['site'],
                        'class' => $siteRow['class'],
                        'currency' => $currency,
                        'tenants' => $siteRow['tenants'],
                        'street' => $siteRow['street'],
                        'avg_in_place' => $avg,
                        'variance_pct' => self::variancePercent($avg, $siteRow['street']),
                        'below' => $siteRow['below'],
                        'eligible' => $siteRow['eligible'],
                        'monthly_gap' => $siteRow['gap'],
                    ];
                }
                $avgStreet[] = self::div($streetSum, $tenantCount);
                $avgInPlace[] = self::div($inPlaceSum, $tenantCount);
            }

            $charts[] = new ChartSpec(
                key: 'street_vs_in_place:'.$currency,
                type: ChartType::Column,
                titleKey: 'insights.charts.street_vs_in_place.title',
                descriptionKey: 'insights.charts.street_vs_in_place.description',
                categories: $classCodes,
                categoryKind: CategoryKind::Text,
                series: [
                    ChartSeries::keyed('insights.charts.series.street_rate', $avgStreet),
                    ChartSeries::keyed('insights.charts.series.in_place_rent', $avgInPlace),
                ],
                format: ChartFormat::Money,
                currency: $currency,
            );

            $varianceData = [];
            foreach (self::VARIANCE_BANDS as $band) {
                $varianceData[] = $varianceCounts[$currency][$band] ?? 0;
            }
            $charts[] = new ChartSpec(
                key: 'variance_to_street:'.$currency,
                type: ChartType::Column,
                titleKey: 'insights.charts.variance_to_street.title',
                descriptionKey: 'insights.charts.variance_to_street.description',
                categories: array_map(
                    static fn (string $band): string => 'insights.charts.categories.variance.'.$band,
                    self::VARIANCE_BANDS,
                ),
                categoryKind: CategoryKind::LabelKey,
                series: [ChartSeries::keyed('insights.charts.series.tenants', $varianceData)],
                format: ChartFormat::Int,
                width: ChartWidth::Half,
            );

            $belowData = [];
            $aboveData = [];
            foreach (self::RENT_AGE_BANDS as $band) {
                $belowData[] = $ageCounts[$currency][$band]['below'] ?? 0;
                $aboveData[] = $ageCounts[$currency][$band]['above'] ?? 0;
            }
            $charts[] = new ChartSpec(
                key: 'rent_age:'.$currency,
                type: ChartType::StackedColumn,
                titleKey: 'insights.charts.rent_age.title',
                descriptionKey: 'insights.charts.rent_age.description',
                categories: array_map(
                    static fn (string $band): string => 'insights.charts.categories.rent_age.'.$band,
                    self::RENT_AGE_BANDS,
                ),
                categoryKind: CategoryKind::LabelKey,
                series: [
                    ChartSeries::keyed('insights.charts.series.below_street', $belowData),
                    ChartSeries::keyed('insights.charts.series.at_or_above_street', $aboveData),
                ],
                format: ChartFormat::Int,
                width: ChartWidth::Half,
            );
        }

        usort($rows, static function (array $left, array $right): int {
            $site = $left['site'] <=> $right['site'];

            return $site !== 0 ? $site : $left['class'] <=> $right['class'];
        });

        return new ReportResult(
            columns: self::columns(count($currencies) === 1 ? ($currencies[0] ?? null) : null),
            rows: $rows,
            meta: [
                'as_of' => $asOf,
                'headlines_by_currency' => $headlines,
                'notes' => [
                    'Definitions: docs/report-definitions.md — Rate management / Gestión de tarifas.',
                    'ECRI monthly opportunity is a ceiling that ignores churn.',
                ],
            ],
            charts: $charts,
        );
    }

    /**
     * @return list<ReportColumn>
     */
    private static function columns(?string $currency): array
    {
        $money = function (string $key, string $label) use ($currency): ReportColumn {
            return $currency !== null
                ? ReportColumn::money($key, $label, $currency)
                : ReportColumn::string($key, $label);
        };

        $columns = [
            ReportColumn::string('site', 'Site'),
            ReportColumn::string('class', 'Class'),
        ];
        if ($currency === null) {
            $columns[] = ReportColumn::string('currency', 'Currency');
        }

        return [
            ...$columns,
            ReportColumn::int('tenants', 'Tenants'),
            $money('street', 'Street'),
            $money('avg_in_place', 'Avg in-place'),
            ReportColumn::percent('variance_pct', 'Variance %'),
            ReportColumn::int('below', 'Below street'),
            ReportColumn::int('eligible', 'ECRI eligible'),
            $money('monthly_gap', 'Monthly gap'),
        ];
    }

    private static function varianceBand(float $variance): string
    {
        return match (true) {
            $variance < -20 => 'lt_minus_20',
            $variance < -10 => 'minus_20_10',
            $variance < 0 => 'minus_10_0',
            $variance <= 10 => 'zero_10',
            default => 'gt_10',
        };
    }

    private static function rentAgeBand(int $months): string
    {
        return match (true) {
            $months <= 5 => 'm0_5',
            $months <= 11 => 'm6_11',
            $months <= 17 => 'm12_17',
            $months <= 23 => 'm18_23',
            default => 'm24_plus',
        };
    }

    private static function variancePercent(string $inPlace, string $street): float
    {
        $delta = bcsub($inPlace, $street, 6);
        $ratio = bcdiv(bcmul($delta, '100', 6), $street, 6);

        return (float) bcadd(bcadd($ratio, bccomp($ratio, '0', 6) >= 0 ? '0.05' : '-0.05', 6), '0', 1);
    }

    private static function add(string $left, string $right): string
    {
        return BillingMath::round2(bcadd($left, $right, 6));
    }

    private static function sub(string $left, string $right): string
    {
        return BillingMath::round2(bcsub($left, $right, 6));
    }

    private static function div(string $amount, int $count): string
    {
        if ($count <= 0) {
            return '0.00';
        }

        return BillingMath::round2(bcdiv($amount, (string) $count, 8));
    }
}
