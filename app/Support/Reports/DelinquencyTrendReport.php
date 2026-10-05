<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Support\Billing\BillingMath;
use App\Support\Delinquency\DelinquencyState;
use App\Support\Reports\Charts\CategoryKind;
use App\Support\Reports\Charts\ChartFormat;
use App\Support\Reports\Charts\ChartScope;
use App\Support\Reports\Charts\ChartSeries;
use App\Support\Reports\Charts\ChartSeriesKind;
use App\Support\Reports\Charts\ChartSpec;
use App\Support\Reports\Charts\ChartType;
use App\Support\Reports\Charts\MonthSpine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Point-in-time overdue balance at each month-end, rebuilt from the ledger.
 * Definitions: docs/report-definitions.md — Delinquency trend / Evolución de morosidad.
 */
final class DelinquencyTrendReport extends AbstractReport
{
    public const DELINQUENT_DAYS = 30;

    /** @var array<string, string> */
    private const BUCKET_SERIES = [
        '1-7' => 'insights.charts.series.bucket_1_7',
        '8-14' => 'insights.charts.series.bucket_8_14',
        '15-30' => 'insights.charts.series.bucket_15_30',
        '31-60' => 'insights.charts.series.bucket_31_60',
        '60+' => 'insights.charts.series.bucket_60_plus',
    ];

    public static function name(): string
    {
        return 'delinquency-trend';
    }

    public function maxQueries(): int
    {
        return 6;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $asOf = OccupancyMetrics::resolveAsOf($filters);
        $end = $filters->to !== null && $filters->to < $asOf ? $filters->to : $asOf;
        $months = MonthSpine::months($end, null, 12);
        $endMonth = MonthSpine::of($end);

        /** @var list<string> $checkpoints */
        $checkpoints = [];
        foreach ($months as $month) {
            $checkpoints[] = $month === $endMonth ? $end : MonthSpine::lastDay($month);
        }

        $first = $checkpoints[0];
        $last = $checkpoints[array_key_last($checkpoints)];
        $firstCutoff = $first.' 23:59:59';
        $lastCutoff = $last.' 23:59:59';

        $triggerTypes = array_map(
            static fn ($type): string => $type->value,
            DelinquencyState::TRIGGER_TYPES,
        );

        $charges = DB::table('charges')
            ->whereIn('charge_type', $triggerTypes)
            ->where('amount', '>', 0)
            ->where('due_date', '<', $last)
            ->whereRaw(
                'charges.amount > (SELECT COALESCE(SUM(a.amount), 0) FROM allocations a WHERE a.charge_id = charges.id AND a.created_at <= ?)',
                [$firstCutoff],
            );
        ChartScope::withoutReversedCharges($charges);
        ChartScope::whereContractInSites($charges, 'charges.contract_id', $filters->siteIds);
        $chargeRows = $charges->get([
            'charges.id',
            'charges.contract_id',
            'charges.amount',
            'charges.due_date',
            'charges.currency',
        ]);

        /** @var array<int, list<array{amount: string, at: string}>> $allocationsByCharge */
        $allocationsByCharge = [];
        $chargeIds = [];
        foreach ($chargeRows as $charge) {
            $chargeIds[] = (int) $charge->id;
        }
        foreach (array_chunk($chargeIds, 1000) as $chunk) {
            $allocationRows = DB::table('allocations')
                ->whereIn('charge_id', $chunk)
                ->where('created_at', '<=', $lastCutoff)
                ->get(['charge_id', 'amount', 'created_at']);
            foreach ($allocationRows as $allocation) {
                $allocationsByCharge[(int) $allocation->charge_id][] = [
                    'amount' => (string) $allocation->amount,
                    'at' => CarbonImmutable::parse((string) $allocation->created_at)->utc()->format('Y-m-d H:i:s'),
                ];
            }
        }

        $occupancyRows = DB::table('unit_occupancies')
            ->join('contracts', 'contracts.id', '=', 'unit_occupancies.contract_id')
            ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
            ->where('unit_occupancies.started_on', '<=', $last)
            ->where(function ($query) use ($first): void {
                $query->whereNull('unit_occupancies.ended_on')
                    ->orWhere('unit_occupancies.ended_on', '>', $first);
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
                'unit_occupancies.started_on',
                'unit_occupancies.ended_on',
            ]);

        /** @var array<int, list<array{start: string, end: ?string}>> $spansByContract */
        $spansByContract = [];
        foreach ($occupancyRows as $occupancy) {
            $spansByContract[(int) $occupancy->contract_id][] = [
                'start' => substr((string) $occupancy->started_on, 0, 10),
                'end' => $occupancy->ended_on !== null ? substr((string) $occupancy->ended_on, 0, 10) : null,
            ];
        }

        /** @var array<string, array<string, array<string, string>>> $bucketSums currency => month => bucket => amount */
        $bucketSums = [];
        /** @var list<int> $delinquentCounts */
        $delinquentCounts = [];
        /** @var list<int> $occupiedCounts */
        $occupiedCounts = [];
        /** @var list<float|null> $delinquentShares */
        $delinquentShares = [];

        foreach ($checkpoints as $index => $checkpoint) {
            $month = $months[$index];
            $cutoff = $checkpoint.' 23:59:59';
            /** @var array<int, true> $delinquentContracts */
            $delinquentContracts = [];

            foreach ($chargeRows as $charge) {
                $due = substr((string) $charge->due_date, 0, 10);
                if ($due >= $checkpoint) {
                    continue;
                }
                $allocated = '0.00';
                foreach ($allocationsByCharge[(int) $charge->id] ?? [] as $allocation) {
                    if ($allocation['at'] <= $cutoff) {
                        $allocated = BillingMath::round2(bcadd($allocated, $allocation['amount'], 6));
                    }
                }
                $open = BillingMath::round2(bcsub((string) $charge->amount, $allocated, 6));
                if (bccomp($open, '0', 2) <= 0) {
                    continue;
                }

                $days = (int) CarbonImmutable::parse($due)->startOfDay()
                    ->diffInDays(CarbonImmutable::parse($checkpoint)->startOfDay());
                $bucket = AgeingBuckets::fromDays(max(1, $days));
                $currency = ChartScope::currency($charge->currency);
                $bucketSums[$currency][$month][$bucket] = BillingMath::round2(bcadd(
                    $bucketSums[$currency][$month][$bucket] ?? '0.00',
                    $open,
                    6,
                ));
                if ($days > self::DELINQUENT_DAYS) {
                    $delinquentContracts[(int) $charge->contract_id] = true;
                }
            }

            $occupied = 0;
            foreach ($spansByContract as $spans) {
                foreach ($spans as $span) {
                    if ($span['start'] <= $checkpoint && ($span['end'] === null || $span['end'] > $checkpoint)) {
                        $occupied++;

                        break;
                    }
                }
            }

            $delinquent = count($delinquentContracts);
            $delinquentCounts[] = $delinquent;
            $occupiedCounts[] = $occupied;
            $delinquentShares[] = ChartScope::pct($delinquent, $occupied);
        }

        $currencies = array_keys($bucketSums);
        sort($currencies);

        $rows = [];
        $charts = [];
        foreach ($currencies as $currency) {
            /** @var array<string, list<string>> $series */
            $series = [];
            foreach (array_keys(self::BUCKET_SERIES) as $bucket) {
                $series[$bucket] = [];
            }
            foreach ($months as $month) {
                $total = '0.00';
                $line = ['month' => $month, 'currency' => $currency];
                foreach (array_keys(self::BUCKET_SERIES) as $bucket) {
                    $amount = $bucketSums[$currency][$month][$bucket] ?? '0.00';
                    $line[$bucket] = $amount;
                    $series[$bucket][] = $amount;
                    $total = BillingMath::round2(bcadd($total, $amount, 6));
                }
                $line['total'] = $total;
                $rows[] = $line;
            }

            $chartSeries = [];
            foreach (self::BUCKET_SERIES as $bucket => $nameKey) {
                $chartSeries[] = ChartSeries::keyed($nameKey, $series[$bucket]);
            }
            $charts[] = new ChartSpec(
                key: 'overdue_by_bucket:'.$currency,
                type: ChartType::StackedArea,
                titleKey: 'insights.charts.overdue_by_bucket.title',
                descriptionKey: 'insights.charts.overdue_by_bucket.description',
                categories: $months,
                categoryKind: CategoryKind::Month,
                series: $chartSeries,
                format: ChartFormat::Money,
                currency: $currency,
            );
        }

        if ($months !== []) {
            $charts[] = new ChartSpec(
                key: 'delinquent_tenants',
                type: ChartType::Combo,
                titleKey: 'insights.charts.delinquent_tenants.title',
                descriptionKey: 'insights.charts.delinquent_tenants.description',
                categories: $months,
                categoryKind: CategoryKind::Month,
                series: [
                    ChartSeries::keyed(
                        'insights.charts.series.delinquent_contracts',
                        $delinquentCounts,
                        ChartSeriesKind::Column,
                        0,
                    ),
                    ChartSeries::keyed(
                        'insights.charts.series.delinquent_share',
                        $delinquentShares,
                        ChartSeriesKind::Line,
                        1,
                    ),
                ],
                format: ChartFormat::Int,
                secondaryFormat: ChartFormat::Percent,
            );
        }

        return new ReportResult(
            columns: self::columns(count($currencies) === 1 ? ($currencies[0] ?? null) : null),
            rows: $rows,
            meta: [
                'as_of' => $asOf,
                'to' => $end,
                'checkpoints' => $checkpoints,
                'occupied_contracts' => $occupiedCounts,
                'delinquent_contracts' => $delinquentCounts,
                'notes' => [
                    'Definitions: docs/report-definitions.md — Delinquency trend / Evolución de morosidad.',
                    'Allocation timestamps are compared at end of day UTC, not site-local.',
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

        $columns = [ReportColumn::string('month', 'Month')];
        if ($currency === null) {
            $columns[] = ReportColumn::string('currency', 'Currency');
        }

        return [
            ...$columns,
            $money('1-7', '1–7 days'),
            $money('8-14', '8–14 days'),
            $money('15-30', '15–30 days'),
            $money('31-60', '31–60 days'),
            $money('60+', '60+ days'),
            $money('total', 'Total overdue'),
        ];
    }
}
