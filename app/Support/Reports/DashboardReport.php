<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\AutopayAttemptStatus;
use App\Enums\DepositPayoutStatus;
use App\Enums\EsignEnvelopeStatus;
use App\Enums\InsightReportSource;
use App\Models\AutopayAttempt;
use App\Models\CommsTriage;
use App\Models\Contract;
use App\Models\Delinquency;
use App\Models\DepositSettlement;
use App\Models\EsignEnvelope;
use App\Models\InsightReport;
use App\Models\Unit;
use App\Support\Billing\BillingMath;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Insights daily-glance: KPI cards + trends + attention row.
 * Card figures reuse the same query classes as the full reports.
 */
final class DashboardReport extends AbstractReport
{
    public static function name(): string
    {
        return 'dashboard';
    }

    public function maxQueries(): int
    {
        return 300;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $asOf = OccupancyMetrics::resolveAsOf($filters);
        $asOfDate = CarbonImmutable::parse($asOf)->startOfDay();
        $priorAsOf = $asOfDate->subMonthNoOverflow()->toDateString();
        $monthStart = $asOfDate->startOfMonth()->toDateString();
        $priorMonthStart = $asOfDate->subMonthNoOverflow()->startOfMonth()->toDateString();
        $priorMonthEnd = $asOfDate->subMonthNoOverflow()->endOfMonth()->toDateString();

        $siteIds = $filters->siteIds;

        $currentOcc = OccupancyMetrics::snapshot($asOf, $siteIds);
        $priorOcc = OccupancyMetrics::snapshot($priorAsOf, $siteIds);

        $rentCurrent = (new RentRollReport)->run(new ReportFilters(
            siteIds: $siteIds,
            asOf: $asOf,
        ));
        $rentPrior = (new RentRollReport)->run(new ReportFilters(
            siteIds: $siteIds,
            asOf: $priorAsOf,
        ));

        $ageingCurrent = (new AgeingReport)->run(new ReportFilters(
            siteIds: $siteIds,
            asOf: $asOf,
        ));
        $ageingPrior = (new AgeingReport)->run(new ReportFilters(
            siteIds: $siteIds,
            asOf: $priorAsOf,
        ));

        $movementCurrent = (new MovementReport)->run(new ReportFilters(
            siteIds: $siteIds,
            from: $monthStart,
            to: $asOf,
        ));
        $movementPrior = (new MovementReport)->run(new ReportFilters(
            siteIds: $siteIds,
            from: $priorMonthStart,
            to: $priorMonthEnd,
        ));

        $openCases = $this->openDelinquencyCount($asOf, $siteIds);
        $openCasesPrior = $this->openDelinquencyCount($priorAsOf, $siteIds);

        $rentFooter = $rentCurrent->meta['footer'] ?? [];
        $rentPriorFooter = $rentPrior->meta['footer'] ?? [];
        $rentCurrency = (string) ($rentFooter['currency'] ?? 'EUR');
        $monthlyRent = (string) ($rentFooter['monthly_rent'] ?? '0.00');
        $monthlyRentPrior = (string) ($rentPriorFooter['monthly_rent'] ?? '0.00');

        $overdue = $this->overdueCard($ageingCurrent, $ageingPrior);

        $moveIns = (int) ($movementCurrent->meta['identity']['move_ins'] ?? 0);
        $moveOuts = (int) ($movementCurrent->meta['identity']['move_outs'] ?? 0);
        $netUnits = $moveIns - $moveOuts;
        $priorNet = (int) ($movementPrior->meta['identity']['move_ins'] ?? 0)
            - (int) ($movementPrior->meta['identity']['move_outs'] ?? 0);

        $siteQuery = $siteIds !== null && $siteIds !== []
            ? ['site_ids' => $siteIds]
            : [];

        $cards = [
            'occupancy' => [
                'value' => $currentOcc['unit_rate'],
                'secondary' => [
                    'economic_rate' => $currentOcc['economic_rate'],
                ],
                'delta' => $this->nullableFloatDelta($currentOcc['unit_rate'], $priorOcc['unit_rate']),
                'currency' => null,
                'to' => '/insights/occupancy',
                'filters' => array_merge($siteQuery, ['as_of' => $asOf]),
            ],
            'monthly_rent' => [
                'value' => $monthlyRent,
                'secondary' => null,
                'delta' => BillingMath::round2(bcsub($monthlyRent, $monthlyRentPrior, 2)),
                'currency' => $rentCurrency,
                'to' => '/insights/rent-roll',
                'filters' => array_merge($siteQuery, ['as_of' => $asOf]),
            ],
            'overdue' => $overdue + [
                'to' => '/insights/ageing',
                'filters' => array_merge($siteQuery, ['as_of' => $asOf]),
            ],
            'open_delinquency_cases' => [
                'value' => $openCases,
                'secondary' => null,
                'delta' => $openCases - $openCasesPrior,
                'currency' => null,
                'to' => '/billing/delinquency',
                'filters' => $siteIds !== null && count($siteIds) === 1
                    ? ['site_id' => $siteIds[0]]
                    : [],
            ],
            'movement_net' => [
                'value' => $netUnits,
                'secondary' => [
                    'move_ins' => $moveIns,
                    'move_outs' => $moveOuts,
                ],
                'delta' => $netUnits - $priorNet,
                'currency' => null,
                'to' => '/insights/movement',
                'filters' => array_merge($siteQuery, [
                    'from' => $monthStart,
                    'to' => $asOf,
                ]),
            ],
        ];

        $occSeries = OccupancyMetrics::monthlySeries($asOf, $siteIds, null, $asOf);
        $collectionsSeries = CollectionsReport::monthlySeries($siteIds, $asOf);

        $trends = [
            'occupancy' => [
                'series' => $occSeries,
                'axis' => 'labelled',
                'note' => 'Occupancy axis may zoom; scale is labelled.',
            ],
            'collections' => [
                'series' => $collectionsSeries,
                'axis' => 'zero_based',
                'note' => 'Collected-vs-charged bars use a zero-based axis.',
            ],
        ];
        $attention = $this->attentionRow($siteIds);

        $this->omitArchivedNatives($cards, $trends, $attention);

        return new ReportResult(
            columns: [],
            rows: [],
            meta: [
                'as_of' => $asOf,
                'prior_as_of' => $priorAsOf,
                'cards' => $cards,
                'trends' => $trends,
                'attention' => $attention,
                'definitions' => 'docs/report-definitions.md',
                'notes' => [
                    'Dashboard KPIs are card-zoom of the full report query classes — one computation, two zoom levels.',
                ],
            ],
        );
    }

    /**
     * Drop dashboard surfaces whose native report is archived.
     * A missing seeder row is not archived — the card stays (I-Archive-1).
     *
     * @param  array<string, mixed>  $cards
     * @param  array<string, mixed>  $trends
     * @param  list<array{key: string, count: int, to: string, filters: array<string, mixed>}>  $attention
     */
    private function omitArchivedNatives(array &$cards, array &$trends, array &$attention): void
    {
        $archived = InsightReport::query()
            ->where('source', InsightReportSource::Native)
            ->whereNotNull('archived_at')
            ->whereNotNull('native_key')
            ->pluck('native_key')
            ->all();

        if ($archived === []) {
            return;
        }

        $archivedSet = array_fill_keys($archived, true);

        $cardMap = [
            'occupancy' => 'occupancy',
            'monthly_rent' => 'rent-roll',
            'overdue' => 'ageing',
            'movement_net' => 'movement',
        ];
        foreach ($cardMap as $cardKey => $nativeKey) {
            if (isset($archivedSet[$nativeKey])) {
                unset($cards[$cardKey]);
            }
        }

        $trendMap = [
            'occupancy' => 'occupancy',
            'collections' => 'collections',
        ];
        foreach ($trendMap as $trendKey => $nativeKey) {
            if (isset($archivedSet[$nativeKey])) {
                unset($trends[$trendKey]);
            }
        }

        $attention = array_values(array_filter(
            $attention,
            static function (array $chip) use ($archivedSet): bool {
                if ($chip['key'] === 'pending_deposit_payouts') {
                    return ! isset($archivedSet['deposit-liability']);
                }

                return true;
            },
        ));
    }

    /**
     * @return array{
     *     value: string,
     *     secondary: array{contract_count: int},
     *     delta: string,
     *     currency: string,
     *     totals_by_currency: list<array{currency: string, amount: string}>
     * }
     */
    private function overdueCard(ReportResult $current, ReportResult $prior): array
    {
        /** @var list<array{currency: string, amount: string}> $totals */
        $totals = $current->meta['totals_by_currency'] ?? [];
        /** @var list<array{currency: string, amount: string}> $priorTotals */
        $priorTotals = $prior->meta['totals_by_currency'] ?? [];

        $currency = $totals[0]['currency'] ?? 'EUR';
        $amount = $totals[0]['amount'] ?? '0.00';
        $priorAmount = '0.00';
        foreach ($priorTotals as $row) {
            if (($row['currency'] ?? '') === $currency) {
                $priorAmount = (string) $row['amount'];
                break;
            }
        }

        return [
            'value' => $amount,
            'secondary' => [
                'contract_count' => count($current->rows),
            ],
            'delta' => BillingMath::round2(bcsub($amount, $priorAmount, 2)),
            'currency' => $currency,
            'totals_by_currency' => $totals,
        ];
    }

    /**
     * @param  list<int>|null  $siteIds
     */
    private function openDelinquencyCount(string $asOf, ?array $siteIds): int
    {
        return (int) Delinquency::query()
            ->where('opened_on', '<=', $asOf)
            ->where(function (Builder $q) use ($asOf): void {
                $q->whereNull('cured_on')
                    ->orWhere('cured_on', '>', $asOf);
            })
            ->when($siteIds !== null, function (Builder $q) use ($siteIds): void {
                $q->whereExists(function ($sub) use ($siteIds): void {
                    $sub->selectRaw('1')
                        ->from('contract_items')
                        ->join('units', function ($join): void {
                            $join->on('units.id', '=', 'contract_items.item_id')
                                ->where('contract_items.item_type', '=', 'unit');
                        })
                        ->whereColumn('contract_items.contract_id', 'delinquencies.contract_id')
                        ->whereNull('contract_items.effective_to')
                        ->whereIn('units.site_id', $siteIds);
                });
            })
            ->count();
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return list<array{key: string, count: int, to: string, filters: array<string, mixed>}>
     */
    private function attentionRow(?array $siteIds): array
    {
        $contractChips = Contract::attentionCounts();
        $siteQuery = $siteIds !== null && $siteIds !== []
            ? ['site_ids' => $siteIds]
            : [];

        return [
            [
                'key' => 'failed_autopay',
                'count' => $this->failedAutopayCount($siteIds),
                'to' => '/billing/delinquency',
                'filters' => $siteIds !== null && count($siteIds) === 1
                    ? ['site_id' => $siteIds[0]]
                    : [],
            ],
            [
                'key' => 'drift_denied_but_granted',
                'count' => (int) ($contractChips['drift_denied_but_granted_count'] ?? 0),
                'to' => '/leasing/contracts',
                'filters' => ['attention' => 'drift_denied_but_granted'],
            ],
            [
                'key' => 'signed_after_cancellation',
                'count' => (int) ($contractChips['post_cancellation_count'] ?? 0),
                'to' => '/leasing/contracts',
                'filters' => ['attention' => 'post_cancellation'],
            ],
            [
                'key' => 'triage',
                'count' => (int) CommsTriage::query()->where('status', 'pending')->count(),
                'to' => '/inbox',
                'filters' => ['tab' => 'triage'],
            ],
            [
                'key' => 'expiring_signatures',
                'count' => $this->expiringSignaturesCount(),
                'to' => '/leasing/contracts',
                'filters' => ['status' => 'awaiting_signature'],
            ],
            [
                'key' => 'pending_deposit_payouts',
                'count' => $this->pendingDepositPayoutCount($siteIds),
                'to' => '/insights/deposit-liability',
                'filters' => $siteQuery,
            ],
        ];
    }

    /**
     * @param  list<int>|null  $siteIds
     */
    private function failedAutopayCount(?array $siteIds): int
    {
        $latestIds = AutopayAttempt::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('contract_id')
            ->pluck('id');

        if ($latestIds->isEmpty()) {
            return 0;
        }

        $failedContractIds = AutopayAttempt::query()
            ->whereIn('id', $latestIds)
            ->where('status', AutopayAttemptStatus::Failed)
            ->pluck('contract_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($failedContractIds === []) {
            return 0;
        }

        if ($siteIds === null) {
            return count($failedContractIds);
        }

        return (int) Contract::query()
            ->whereIn('id', $failedContractIds)
            ->whereHas('unitItem', function (Builder $item) use ($siteIds): void {
                $item->where('item_type', 'unit')
                    ->whereIn('item_id', Unit::query()->whereIn('site_id', $siteIds)->select('id'));
            })
            ->count();
    }

    private function expiringSignaturesCount(): int
    {
        $deadline = CarbonImmutable::now()->addDays(3);

        return (int) EsignEnvelope::query()
            ->whereIn('status', [
                EsignEnvelopeStatus::Sent->value,
                EsignEnvelopeStatus::Viewed->value,
            ])
            ->where('expires_at', '<=', $deadline->toDateTimeString())
            ->count();
    }

    /**
     * @param  list<int>|null  $siteIds
     */
    private function pendingDepositPayoutCount(?array $siteIds): int
    {
        return (int) DepositSettlement::query()
            ->where('payout_status', DepositPayoutStatus::Pending->value)
            ->when($siteIds !== null, function (Builder $q) use ($siteIds): void {
                $q->whereHas('contract.unitItem', function (Builder $item) use ($siteIds): void {
                    $item->where('item_type', 'unit')
                        ->whereIn('item_id', Unit::query()->whereIn('site_id', $siteIds)->select('id'));
                });
            })
            ->count();
    }

    private function nullableFloatDelta(?float $current, ?float $prior): ?float
    {
        if ($current === null && $prior === null) {
            return null;
        }

        return round(($current ?? 0.0) - ($prior ?? 0.0), 1);
    }
}
