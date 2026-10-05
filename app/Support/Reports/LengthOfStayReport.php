<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\ContractEndedReason;
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
 * Tenure of a contract, not of an occupancy row. A transfer is not an exit.
 * Definitions: docs/report-definitions.md — Length of stay / Duración de estancia.
 */
final class LengthOfStayReport extends AbstractReport
{
    /** @var list<string> */
    public const TENURE_BANDS = [
        'lt_3',
        'm3_5',
        'm6_11',
        'm12_23',
        'm24_35',
        'm36_plus',
    ];

    public static function name(): string
    {
        return 'length-of-stay';
    }

    public function maxQueries(): int
    {
        return 4;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $asOf = $filters->to ?? OccupancyMetrics::resolveAsOf($filters);
        $from = $filters->from ?? CarbonImmutable::parse($asOf)->subMonthsNoOverflow(12)->toDateString();

        $occupancies = DB::table('unit_occupancies')
            ->join('contracts', 'contracts.id', '=', 'unit_occupancies.contract_id')
            ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
            ->where('unit_occupancies.started_on', '<=', $asOf)
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
                'unit_occupancies.started_on',
                'unit_occupancies.ended_on',
                'unit_occupancies.ended_reason',
            ]);

        /** @var array<int, array{start: string, end: ?string, reason: ?string, open: bool}> $tenancies */
        $tenancies = [];
        foreach ($occupancies as $occupancy) {
            $contractId = (int) $occupancy->contract_id;
            $started = substr((string) $occupancy->started_on, 0, 10);
            $ended = $occupancy->ended_on !== null ? substr((string) $occupancy->ended_on, 0, 10) : null;
            $open = $ended === null;

            if (! isset($tenancies[$contractId])) {
                $tenancies[$contractId] = [
                    'start' => $started,
                    'end' => $open ? null : $ended,
                    'reason' => $open ? null : ($occupancy->ended_reason !== null ? (string) $occupancy->ended_reason : null),
                    'open' => $open,
                ];

                continue;
            }

            if ($started < $tenancies[$contractId]['start']) {
                $tenancies[$contractId]['start'] = $started;
            }
            if ($open) {
                $tenancies[$contractId]['open'] = true;
                $tenancies[$contractId]['end'] = null;
                $tenancies[$contractId]['reason'] = null;

                continue;
            }
            if ($tenancies[$contractId]['open']) {
                continue;
            }
            if ($tenancies[$contractId]['end'] === null || $ended > $tenancies[$contractId]['end']) {
                $tenancies[$contractId]['end'] = $ended;
                $tenancies[$contractId]['reason'] = $occupancy->ended_reason !== null
                    ? (string) $occupancy->ended_reason
                    : null;
            }
        }

        /** @var array<string, int> $currentBands */
        $currentBands = array_fill_keys(self::TENURE_BANDS, 0);
        /** @var array<string, array{voluntary: int, involuntary: int}> $leaverBands */
        $leaverBands = [];
        foreach (self::TENURE_BANDS as $band) {
            $leaverBands[$band] = ['voluntary' => 0, 'involuntary' => 0];
        }

        $currentMonths = [];
        $leaverMonths = [];
        /** @var array<string, list<array{start: string, end: ?string}>> $cohorts */
        $cohorts = [];

        foreach ($tenancies as $tenancy) {
            $activeNow = self::activeAt($tenancy['start'], $tenancy['end'], $asOf);
            if ($activeNow) {
                $months = max(0, MonthSpine::monthsBetween($tenancy['start'], $asOf));
                $currentMonths[] = $months;
                $currentBands[self::tenureBand($months)]++;
            }

            $end = $tenancy['end'];
            $reason = $tenancy['reason'];
            if (
                $end !== null
                && $end >= $from
                && $end <= $asOf
                && ($reason === ContractEndedReason::Vacated->value || $reason === ContractEndedReason::NonPayment->value)
            ) {
                $stay = max(0, MonthSpine::monthsBetween($tenancy['start'], $end));
                $leaverMonths[] = $stay;
                $band = self::tenureBand($stay);
                if ($reason === ContractEndedReason::Vacated->value) {
                    $leaverBands[$band]['voluntary']++;
                } else {
                    $leaverBands[$band]['involuntary']++;
                }
            }

            $moveInMonth = MonthSpine::of($tenancy['start']);
            $cohorts[$moveInMonth][] = [
                'start' => $tenancy['start'],
                'end' => $tenancy['end'],
            ];
        }

        $cohortMonths = array_reverse(MonthSpine::months($asOf, null, 12));
        $heatmapCategories = [];
        for ($k = 0; $k <= 12; $k++) {
            $heatmapCategories[] = 'M'.$k;
        }
        $heatmapSeries = [];
        foreach ($cohortMonths as $month) {
            $members = $cohorts[$month] ?? [];
            $count = count($members);
            $cells = [];
            for ($k = 0; $k <= 12; $k++) {
                $day = CarbonImmutable::parse($month.'-01')->addMonthsNoOverflow($k)->endOfMonth()->toDateString();
                if ($day > $asOf || $count === 0) {
                    $cells[] = null;

                    continue;
                }
                $active = 0;
                foreach ($members as $member) {
                    if (self::activeAt($member['start'], $member['end'], $day)) {
                        $active++;
                    }
                }
                $cells[] = ChartScope::pct($active, $count);
            }
            $heatmapSeries[] = ChartSeries::named($month.' · '.$count, $cells);
        }

        $bandKeys = array_map(
            static fn (string $band): string => 'insights.charts.categories.tenure.'.$band,
            self::TENURE_BANDS,
        );
        $voluntary = [];
        $involuntary = [];
        $current = [];
        $rows = [];
        foreach (self::TENURE_BANDS as $band) {
            $current[] = $currentBands[$band];
            $voluntary[] = $leaverBands[$band]['voluntary'];
            $involuntary[] = $leaverBands[$band]['involuntary'];
            $rows[] = [
                'band' => $band,
                'current' => $currentBands[$band],
                'voluntary' => $leaverBands[$band]['voluntary'],
                'involuntary' => $leaverBands[$band]['involuntary'],
            ];
        }

        $charts = [
            new ChartSpec(
                key: 'tenure_current',
                type: ChartType::Column,
                titleKey: 'insights.charts.tenure_current.title',
                descriptionKey: 'insights.charts.tenure_current.description',
                categories: $bandKeys,
                categoryKind: CategoryKind::LabelKey,
                series: [ChartSeries::keyed('insights.charts.series.current_tenants', $current)],
                format: ChartFormat::Int,
                width: ChartWidth::Half,
            ),
            new ChartSpec(
                key: 'tenure_leavers',
                type: ChartType::StackedColumn,
                titleKey: 'insights.charts.tenure_leavers.title',
                descriptionKey: 'insights.charts.tenure_leavers.description',
                categories: $bandKeys,
                categoryKind: CategoryKind::LabelKey,
                series: [
                    ChartSeries::keyed('insights.charts.series.voluntary', $voluntary),
                    ChartSeries::keyed('insights.charts.series.involuntary', $involuntary),
                ],
                format: ChartFormat::Int,
                width: ChartWidth::Half,
            ),
            new ChartSpec(
                key: 'cohort_retention',
                type: ChartType::Heatmap,
                titleKey: 'insights.charts.cohort_retention.title',
                descriptionKey: 'insights.charts.cohort_retention.description',
                categories: $heatmapCategories,
                categoryKind: CategoryKind::Text,
                series: $heatmapSeries,
                format: ChartFormat::Percent,
            ),
        ];

        return new ReportResult(
            columns: [
                ReportColumn::string('band', 'Tenure band'),
                ReportColumn::int('current', 'Current tenants'),
                ReportColumn::int('voluntary', 'Voluntary leavers'),
                ReportColumn::int('involuntary', 'Involuntary leavers'),
            ],
            rows: $rows,
            meta: [
                'as_of' => $asOf,
                'from' => $from,
                'to' => $asOf,
                'headlines' => [
                    'current_tenants' => count($currentMonths),
                    'avg_tenure_months' => self::average($currentMonths),
                    'leavers' => count($leaverMonths),
                    'median_stay_of_leavers_months' => self::median($leaverMonths),
                ],
                'notes' => [
                    'Definitions: docs/report-definitions.md — Length of stay / Duración de estancia.',
                    'A tenancy is a contract. Transfers are not exits.',
                ],
            ],
            charts: $charts,
        );
    }

    /**
     * @param  array{start: string, end: ?string, reason?: ?string, open?: bool}|array{start: string, end: ?string}  $tenancy
     */
    private static function activeAt(string $start, ?string $end, string $on): bool
    {
        return $start <= $on && ($end === null || $end > $on);
    }

    private static function tenureBand(int $months): string
    {
        return match (true) {
            $months < 3 => 'lt_3',
            $months <= 5 => 'm3_5',
            $months <= 11 => 'm6_11',
            $months <= 23 => 'm12_23',
            $months <= 35 => 'm24_35',
            default => 'm36_plus',
        };
    }

    /**
     * @param  list<int>  $values
     */
    private static function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 1);
    }

    /**
     * @param  list<int>  $values
     */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float) $values[$mid];
        }

        return round(($values[$mid - 1] + $values[$mid]) / 2, 1);
    }
}
