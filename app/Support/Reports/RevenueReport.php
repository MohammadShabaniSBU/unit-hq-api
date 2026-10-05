<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Support\Billing\BillingMath;
use App\Support\Reports\Charts\CategoryKind;
use App\Support\Reports\Charts\ChartFormat;
use App\Support\Reports\Charts\ChartScope;
use App\Support\Reports\Charts\ChartSeries;
use App\Support\Reports\Charts\ChartSpec;
use App\Support\Reports\Charts\ChartType;
use App\Support\Reports\Charts\ChartWidth;
use App\Support\Reports\Charts\MonthSpine;
use Illuminate\Support\Facades\DB;

/**
 * Revenue by stream and RevPAM, one chart set per currency.
 * Definitions: docs/report-definitions.md — Revenue / Ingresos.
 */
final class RevenueReport extends AbstractReport
{
    /** @var list<string> */
    private const STREAMS = ['rent', 'insurance', 'fees', 'other'];

    /** @var array<string, string> */
    private const STREAM_OF_TYPE = [
        'rent' => 'rent',
        'insurance' => 'insurance',
        'late_fee' => 'fees',
        'lien_fee' => 'fees',
        'other' => 'other',
        'adjustment' => 'other',
    ];

    /** @var array<string, string> */
    private const STREAM_LABEL = [
        'rent' => 'insights.charts.series.rent',
        'insurance' => 'insights.charts.series.insurance',
        'fees' => 'insights.charts.series.fees',
        'other' => 'insights.charts.series.other',
    ];

    public static function name(): string
    {
        return 'revenue';
    }

    public function maxQueries(): int
    {
        return 6;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $to = $filters->to ?? OccupancyMetrics::resolveAsOf($filters);
        $months = MonthSpine::months($to, $filters->from, 12);
        $windowStart = MonthSpine::firstDay($months[0]);
        $windowEnd = MonthSpine::lastDay($months[array_key_last($months)]);

        $charges = DB::table('charges')
            ->whereIn('charge_type', array_keys(self::STREAM_OF_TYPE))
            ->where(function ($query) use ($windowStart, $windowEnd): void {
                $query->where(function ($inner) use ($windowStart, $windowEnd): void {
                    $inner->whereNotNull('period_start')
                        ->whereBetween('period_start', [$windowStart, $windowEnd]);
                })->orWhere(function ($inner) use ($windowStart, $windowEnd): void {
                    $inner->whereNull('period_start')
                        ->whereBetween('due_date', [$windowStart, $windowEnd]);
                });
            });
        ChartScope::withoutReversedCharges($charges);
        ChartScope::whereContractInSites($charges, 'charges.contract_id', $filters->siteIds);
        $chargeRows = $charges->get([
            'charge_type',
            'net_amount',
            'amount',
            'currency',
            'period_start',
            'due_date',
        ]);

        /** @var array<string, array<string, array<string, string>>> $buckets currency => month => stream => amount */
        $buckets = [];
        foreach ($chargeRows as $charge) {
            $type = (string) $charge->charge_type;
            $stream = self::STREAM_OF_TYPE[$type] ?? null;
            if ($stream === null) {
                continue;
            }
            $recognizedOn = $charge->period_start !== null && $charge->period_start !== ''
                ? (string) $charge->period_start
                : (string) $charge->due_date;
            $month = MonthSpine::of($recognizedOn);
            if (! in_array($month, $months, true)) {
                continue;
            }
            $currency = ChartScope::currency($charge->currency);
            $measure = $charge->net_amount !== null && $charge->net_amount !== ''
                ? (string) $charge->net_amount
                : (string) $charge->amount;
            $buckets[$currency][$month][$stream] = self::add(
                $buckets[$currency][$month][$stream] ?? '0.00',
                $measure,
            );
        }

        /** @var array<string, string> $areaByCurrency */
        $areaByCurrency = [];
        foreach (ChartScope::units($filters->siteIds)->get() as $unit) {
            $currency = ChartScope::currency($unit->currency);
            $area = $unit->area !== null && $unit->area !== '' ? (string) $unit->area : '0';
            $areaByCurrency[$currency] = self::add($areaByCurrency[$currency] ?? '0.00', $area);
        }

        $currencies = array_values(array_unique(array_merge(
            array_keys($buckets),
            array_keys($areaByCurrency),
        )));
        sort($currencies);

        $rows = [];
        $charts = [];
        /** @var array<string, array<string, mixed>> $headlines */
        $headlines = [];

        foreach ($currencies as $currency) {
            $area = $areaByCurrency[$currency] ?? '0.00';
            /** @var array<string, string> $totals */
            $totals = array_fill_keys(self::STREAMS, '0.00');
            /** @var array<string, list<string>> $seriesData */
            $seriesData = [];
            foreach (self::STREAMS as $stream) {
                $seriesData[$stream] = [];
            }
            $revpamSeries = [];

            foreach ($months as $month) {
                $line = [];
                $monthTotal = '0.00';
                foreach (self::STREAMS as $stream) {
                    $amount = $buckets[$currency][$month][$stream] ?? '0.00';
                    $line[$stream] = $amount;
                    $seriesData[$stream][] = $amount;
                    $totals[$stream] = self::add($totals[$stream], $amount);
                    $monthTotal = self::add($monthTotal, $amount);
                }
                $revpam = self::perArea($line['rent'], $area);
                $revpamSeries[] = $revpam;
                $rows[] = [
                    'month' => $month,
                    'currency' => $currency,
                    'rent' => $line['rent'],
                    'insurance' => $line['insurance'],
                    'fees' => $line['fees'],
                    'other' => $line['other'],
                    'total' => $monthTotal,
                    'revpam' => $revpam,
                ];
            }

            $periodTotal = '0.00';
            foreach (self::STREAMS as $stream) {
                $periodTotal = self::add($periodTotal, $totals[$stream]);
            }
            $ancillary = self::sub($periodTotal, $totals['rent']);

            $headlines[$currency] = [
                'total' => $periodTotal,
                'rent' => $totals['rent'],
                'ancillary' => $ancillary,
                'ancillary_share' => ChartScope::pct($ancillary, $periodTotal),
                'rentable_m2' => $area,
            ];

            $streamSeries = [];
            foreach (self::STREAMS as $stream) {
                $streamSeries[] = ChartSeries::keyed(self::STREAM_LABEL[$stream], $seriesData[$stream]);
            }

            $charts[] = new ChartSpec(
                key: 'revenue_by_stream:'.$currency,
                type: ChartType::StackedColumn,
                titleKey: 'insights.charts.revenue_by_stream.title',
                descriptionKey: 'insights.charts.revenue_by_stream.description',
                categories: $months,
                categoryKind: CategoryKind::Month,
                series: $streamSeries,
                format: ChartFormat::Money,
                currency: $currency,
            );
            $charts[] = new ChartSpec(
                key: 'revpam:'.$currency,
                type: ChartType::Area,
                titleKey: 'insights.charts.revpam.title',
                descriptionKey: 'insights.charts.revpam.description',
                categories: $months,
                categoryKind: CategoryKind::Month,
                series: [ChartSeries::keyed('insights.charts.series.revpam', $revpamSeries)],
                format: ChartFormat::Money,
                currency: $currency,
                width: ChartWidth::Half,
            );
            $charts[] = new ChartSpec(
                key: 'revenue_mix:'.$currency,
                type: ChartType::Donut,
                titleKey: 'insights.charts.revenue_mix.title',
                descriptionKey: 'insights.charts.revenue_mix.description',
                categories: array_values(self::STREAM_LABEL),
                categoryKind: CategoryKind::LabelKey,
                series: [ChartSeries::keyed('insights.charts.series.total', array_values($totals))],
                format: ChartFormat::Money,
                currency: $currency,
                width: ChartWidth::Half,
            );
        }

        return new ReportResult(
            columns: self::columns(count($currencies) === 1 ? ($currencies[0] ?? null) : null),
            rows: $rows,
            meta: [
                'from' => $windowStart,
                'to' => $to,
                'headlines_by_currency' => $headlines,
                'notes' => [
                    'Definitions: docs/report-definitions.md — Revenue / Ingresos.',
                    'RevPAM uses current rentable m², not a reconstructed monthly denominator.',
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
        $amount = function (string $key, string $label) use ($currency): ReportColumn {
            return $currency !== null
                ? ReportColumn::money($key, $label, $currency)
                : ReportColumn::string($key, $label);
        };

        $columns = [ReportColumn::string('month', 'Month')];
        if ($currency === null) {
            $columns[] = ReportColumn::string('currency', 'Currency');
        }

        return [
            ...$columns,
            $amount('rent', 'Rent'),
            $amount('insurance', 'Insurance'),
            $amount('fees', 'Fees'),
            $amount('other', 'Other'),
            $amount('total', 'Total'),
            $amount('revpam', 'RevPAM'),
        ];
    }

    private static function add(string $left, string $right): string
    {
        return BillingMath::round2(bcadd($left, $right, 6));
    }

    private static function sub(string $left, string $right): string
    {
        return BillingMath::round2(bcsub($left, $right, 6));
    }

    private static function perArea(string $rent, string $area): ?string
    {
        if (bccomp($area, '0', 2) <= 0) {
            return null;
        }

        return BillingMath::round2(bcdiv($rent, $area, 8));
    }
}
