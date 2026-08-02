<?php

declare(strict_types=1);

namespace App\Support\Reports;

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
        );
    }

}
