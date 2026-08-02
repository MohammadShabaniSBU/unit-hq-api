<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\ContractStatus;
use App\Enums\DepositPayoutStatus;
use App\Models\Contract;
use App\Models\DepositSettlement;
use App\Support\Billing\BillingMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Accountant deposit liability: holdings on in-force contracts + pending payouts.
 */
final class DepositLiabilityReport extends AbstractReport
{
    public static function name(): string
    {
        return 'deposit-liability';
    }

    public function maxQueries(): int
    {
        return 20;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $asOf = OccupancyMetrics::resolveAsOf($filters);

        $heldRows = Contract::query()
            ->select([
                'sites.id as site_id',
                'sites.name as site_name',
                'contracts.currency',
                DB::raw('SUM(contracts.deposit_amount) as deposits_held'),
                DB::raw('COUNT(*) as contract_count'),
            ])
            ->join('contract_items', function ($join): void {
                $join->on('contract_items.contract_id', '=', 'contracts.id')
                    ->where('contract_items.item_type', '=', 'unit')
                    ->whereNull('contract_items.effective_to');
            })
            ->join('units', 'units.id', '=', 'contract_items.item_id')
            ->join('sites', 'sites.id', '=', 'units.site_id')
            ->whereIn('contracts.status', [
                ContractStatus::Active->value,
                ContractStatus::NoticeGiven->value,
            ])
            ->whereDoesntHave('depositSettlement')
            ->when($filters->siteIds !== null, static fn (Builder $q) => $q->whereIn('sites.id', $filters->siteIds))
            ->groupBy('sites.id', 'sites.name', 'contracts.currency')
            ->orderBy('sites.name')
            ->orderBy('contracts.currency')
            ->get();

        $pendingRows = DepositSettlement::query()
            ->select([
                'sites.id as site_id',
                'sites.name as site_name',
                'deposit_settlements.currency',
                DB::raw('SUM(deposit_settlements.refunded_amount) as pending_payouts'),
                DB::raw('COUNT(*) as pending_count'),
            ])
            ->join('contracts', 'contracts.id', '=', 'deposit_settlements.contract_id')
            ->join('contract_items', function ($join): void {
                $join->on('contract_items.contract_id', '=', 'contracts.id')
                    ->where('contract_items.item_type', '=', 'unit')
                    ->whereNull('contract_items.effective_to');
            })
            ->join('units', 'units.id', '=', 'contract_items.item_id')
            ->join('sites', 'sites.id', '=', 'units.site_id')
            ->where('deposit_settlements.payout_status', DepositPayoutStatus::Pending->value)
            ->when($filters->siteIds !== null, static fn (Builder $q) => $q->whereIn('sites.id', $filters->siteIds))
            ->groupBy('sites.id', 'sites.name', 'deposit_settlements.currency')
            ->orderBy('sites.name')
            ->orderBy('deposit_settlements.currency')
            ->get();

        /** @var array<string, array{
         *     site_id: int,
         *     site: string,
         *     currency: string,
         *     deposits_held: string,
         *     contract_count: int,
         *     pending_payouts: string,
         *     pending_count: int
         * }> $merged */
        $merged = [];

        foreach ($heldRows as $row) {
            $currency = strtoupper(trim((string) $row->currency)) ?: 'EUR';
            $key = ((int) $row->site_id).'|'.$currency;
            $merged[$key] = [
                'site_id' => (int) $row->site_id,
                'site' => (string) $row->site_name,
                'currency' => $currency,
                'deposits_held' => BillingMath::round2((string) $row->deposits_held),
                'contract_count' => (int) $row->contract_count,
                'pending_payouts' => '0.00',
                'pending_count' => 0,
            ];
        }

        foreach ($pendingRows as $row) {
            $currency = strtoupper(trim((string) $row->currency)) ?: 'EUR';
            $key = ((int) $row->site_id).'|'.$currency;
            if (! isset($merged[$key])) {
                $merged[$key] = [
                    'site_id' => (int) $row->site_id,
                    'site' => (string) $row->site_name,
                    'currency' => $currency,
                    'deposits_held' => '0.00',
                    'contract_count' => 0,
                    'pending_payouts' => '0.00',
                    'pending_count' => 0,
                ];
            }
            $merged[$key]['pending_payouts'] = BillingMath::round2((string) $row->pending_payouts);
            $merged[$key]['pending_count'] = (int) $row->pending_count;
        }

        $rows = array_values($merged);
        usort($rows, static fn (array $a, array $b): int => [$a['site'], $a['currency']] <=> [$b['site'], $b['currency']]);

        $currency = $rows[0]['currency'] ?? 'EUR';
        if ($filters->siteIds !== null && $filters->siteIds !== [] && $rows === []) {
            $site = \App\Models\Site::query()->find($filters->siteIds[0]);
            $currency = strtoupper(trim((string) ($site?->currency ?: 'EUR'))) ?: 'EUR';
        }

        /** @var array<string, string> $totalsHeld */
        $totalsHeld = [];
        /** @var array<string, string> $totalsPending */
        $totalsPending = [];
        foreach ($rows as $row) {
            $cur = $row['currency'];
            $totalsHeld[$cur] = BillingMath::round2(bcadd($totalsHeld[$cur] ?? '0.00', $row['deposits_held'], 2));
            $totalsPending[$cur] = BillingMath::round2(bcadd($totalsPending[$cur] ?? '0.00', $row['pending_payouts'], 2));
        }

        $totalsByCurrency = [];
        foreach (array_unique(array_merge(array_keys($totalsHeld), array_keys($totalsPending))) as $cur) {
            $totalsByCurrency[] = [
                'currency' => $cur,
                'deposits_held' => $totalsHeld[$cur] ?? '0.00',
                'pending_payouts' => $totalsPending[$cur] ?? '0.00',
            ];
        }

        return new ReportResult(
            columns: [
                ReportColumn::string('site', 'Site'),
                ReportColumn::string('currency', 'Currency'),
                ReportColumn::money('deposits_held', 'Deposits held', $currency),
                ReportColumn::int('contract_count', 'Contracts'),
                ReportColumn::money('pending_payouts', 'Pending payouts', $currency),
                ReportColumn::int('pending_count', 'Pending settlements'),
            ],
            rows: array_map(static fn (array $r): array => [
                'site' => $r['site'],
                'currency' => $r['currency'],
                'deposits_held' => $r['deposits_held'],
                'contract_count' => $r['contract_count'],
                'pending_payouts' => $r['pending_payouts'],
                'pending_count' => $r['pending_count'],
            ], $rows),
            meta: [
                'as_of' => $asOf,
                'totals_by_currency' => $totalsByCurrency,
                'notes' => [
                    'Deposits held are current contract snapshot amounts for active/notice contracts without a settlement.',
                    'Pending payouts are deposit_settlements with payout_status = pending (refunded_amount).',
                    'Figures are current-state snapshots, not reconstructed as of a historical date.',
                ],
            ],
        );
    }
}
