<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Support\Reports\Charts\CategoryKind;
use App\Support\Reports\Charts\ChartFormat;
use App\Support\Reports\Charts\ChartSeries;
use App\Support\Reports\Charts\ChartSpec;
use App\Support\Reports\Charts\ChartType;
use App\Support\Reports\Charts\MonthSpine;

/**
 * Three occupancy definitions (unit / area / economic) with site×class
 * breakdown and a bounded monthly trend series.
 */
final class OccupancyReport extends AbstractReport
{
    public static function name(): string
    {
        return 'occupancy';
    }

    public function maxQueries(): int
    {
        // Snapshot ~5 queries × ≤24 month-ends + one as-of breakdown.
        return 150;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $asOf = OccupancyMetrics::resolveAsOf($filters);
        $snapshot = OccupancyMetrics::snapshot($asOf, $filters->siteIds);

        $currency = $snapshot['economic_currency'] ?: 'EUR';

        $rows = [];
        foreach ($snapshot['by_site_class'] as $bucket) {
            $rows[] = [
                'site' => $bucket['site_name'],
                'class' => $bucket['class_code'],
                'class_label' => $bucket['class_label'],
                'rentable' => $bucket['rentable'],
                'occupied' => $bucket['occupied'],
                'unit_rate' => $bucket['unit_rate'],
                'area_rate' => $bucket['area_rate'],
                'economic_rate' => $bucket['economic_rate'],
                'economic_numerator' => $bucket['economic_numerator'],
                'economic_denominator' => $bucket['economic_denominator'],
            ];
        }

        $series = OccupancyMetrics::monthlySeries(
            $asOf,
            $filters->siteIds,
            $filters->from,
            $filters->to ?? $asOf,
        );

        return new ReportResult(
            columns: [
                ReportColumn::string('site', 'Site'),
                ReportColumn::string('class', 'Class'),
                ReportColumn::string('class_label', 'Class label'),
                ReportColumn::int('rentable', 'Rentable'),
                ReportColumn::int('occupied', 'Occupied'),
                ReportColumn::percent('unit_rate', 'Unit occupancy %'),
                ReportColumn::percent('area_rate', 'Area occupancy %'),
                ReportColumn::percent('economic_rate', 'Economic occupancy %'),
                ReportColumn::money('economic_numerator', 'In-place rent', $currency),
                ReportColumn::money('economic_denominator', 'Gross potential', $currency),
            ],
            rows: $rows,
            meta: [
                'as_of' => $asOf,
                'headlines' => [
                    'unit' => [
                        'occupied' => $snapshot['occupied_units'],
                        'rentable' => $snapshot['rentable_units'],
                        'rate' => $snapshot['unit_rate'],
                        'formula' => 'occupied units ÷ rentable units',
                    ],
                    'area' => [
                        'occupied' => $snapshot['occupied_area'],
                        'rentable' => $snapshot['rentable_area'],
                        'rate' => $snapshot['area_rate'],
                        'formula' => 'occupied m² ÷ rentable m²',
                    ],
                    'economic' => [
                        'numerator' => $snapshot['economic_numerator'],
                        'denominator' => $snapshot['economic_denominator'],
                        'currency' => $currency,
                        'rate' => $snapshot['economic_rate'],
                        'formula' => 'actual in-place rent ÷ gross potential rent',
                    ],
                ],
                'series' => $series,
                'notes' => [
                    'Definitions: docs/report-definitions.md — Occupancy / Ocupación.',
                ],
            ],
            charts: self::charts($series, $rows),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $series
     * @param  list<array<string, mixed>>  $rows
     * @return list<ChartSpec>
     */
    private static function charts(array $series, array $rows): array
    {
        $categories = [];
        $unit = [];
        $area = [];
        $economic = [];
        foreach ($series as $point) {
            $categories[] = MonthSpine::of((string) $point['month_end']);
            $unit[] = $point['unit_rate'];
            $area[] = $point['area_rate'];
            $economic[] = $point['economic_rate'];
        }

        $charts = [
            new ChartSpec(
                key: 'occupancy_trend',
                type: ChartType::Line,
                titleKey: 'insights.charts.occupancy_trend.title',
                descriptionKey: 'insights.charts.occupancy_trend.description',
                categories: $categories,
                categoryKind: CategoryKind::Month,
                series: [
                    ChartSeries::keyed('insights.charts.series.unit_rate', $unit),
                    ChartSeries::keyed('insights.charts.series.area_rate', $area),
                    ChartSeries::keyed('insights.charts.series.economic_rate', $economic),
                ],
                format: ChartFormat::Percent,
                targets: [
                    ['y' => 90, 'label_key' => 'insights.charts.targets.occupancy_goal'],
                ],
            ),
        ];

        /** @var array<string, array<string, float|null>> $bySite */
        $bySite = [];
        $classCodes = [];
        foreach ($rows as $row) {
            $site = (string) $row['site'];
            $class = (string) $row['class'];
            $bySite[$site][$class] = $row['unit_rate'];
            $classCodes[$class] = true;
        }

        if ($bySite !== []) {
            $classes = array_keys($classCodes);
            sort($classes);
            $heatmap = [];
            foreach ($bySite as $site => $rates) {
                $data = [];
                foreach ($classes as $class) {
                    $data[] = $rates[$class] ?? null;
                }
                $heatmap[] = ChartSeries::named($site, $data);
            }
            $charts[] = new ChartSpec(
                key: 'occupancy_heatmap',
                type: ChartType::Heatmap,
                titleKey: 'insights.charts.occupancy_heatmap.title',
                descriptionKey: 'insights.charts.occupancy_heatmap.description',
                categories: $classes,
                categoryKind: CategoryKind::Text,
                series: $heatmap,
                format: ChartFormat::Percent,
            );
        }

        return $charts;
    }
}
