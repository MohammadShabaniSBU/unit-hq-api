<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\ContractEndedReason;
use App\Enums\DepositSettlementOutcome;
use App\Models\ContractTransfer;
use App\Models\DepositSettlement;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitOccupancy;
use App\Support\Billing\BillingMath;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Period tenancy movement: move-ins, move-outs, transfers (neither churn nor acquisition).
 * Definitions: docs/report-definitions.md — Movement section.
 */
final class MovementReport extends AbstractReport
{
    public static function name(): string
    {
        return 'movement';
    }

    public function maxQueries(): int
    {
        return 40;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $to = $filters->to ?? OccupancyMetrics::resolveAsOf($filters);
        $from = $filters->from ?? CarbonImmutable::parse($to)->subDays(90)->toDateString();
        $fromDate = CarbonImmutable::parse($from)->toDateString();
        $toDate = CarbonImmutable::parse($to)->toDateString();

        $sites = Site::query()
            ->when($filters->siteIds !== null, static fn (Builder $q) => $q->whereIn('id', $filters->siteIds))
            ->orderBy('name')
            ->get(['id', 'name', 'currency']);

        /** @var array<int, list<int>> $unitIdsBySite */
        $unitIdsBySite = [];
        $allUnitIds = [];
        if ($sites->isNotEmpty()) {
            $units = Unit::query()
                ->whereIn('site_id', $sites->pluck('id')->all())
                ->with('unitClass:id,size')
                ->get(['id', 'site_id', 'unit_class_id']);
            foreach ($units as $unit) {
                $siteId = (int) $unit->site_id;
                $unitIdsBySite[$siteId] ??= [];
                $unitIdsBySite[$siteId][] = (int) $unit->id;
                $allUnitIds[] = (int) $unit->id;
            }
        }

        $transferDestKeys = $this->transferDestinationKeys($fromDate, $toDate, $allUnitIds);

        $moveIns = UnitOccupancy::query()
            ->with(['unit.unitClass:id,size', 'unit:id,site_id,unit_class_id', 'contractItem.price'])
            ->whereBetween('started_on', [$fromDate, $toDate])
            ->when($allUnitIds !== [], static fn (Builder $q) => $q->whereIn('unit_id', $allUnitIds))
            ->when($allUnitIds === [], static fn (Builder $q) => $q->whereRaw('0 = 1'))
            ->get()
            ->filter(function (UnitOccupancy $occ) use ($transferDestKeys): bool {
                $key = (int) $occ->unit_id.'|'.$occ->started_on->toDateString();

                return ! isset($transferDestKeys[$key]);
            });

        $moveOuts = UnitOccupancy::query()
            ->with(['unit.unitClass:id,size', 'unit:id,site_id,unit_class_id', 'contract.depositSettlement', 'contractItem.price'])
            ->whereBetween('ended_on', [$fromDate, $toDate])
            ->whereIn('ended_reason', [
                ContractEndedReason::Vacated->value,
                ContractEndedReason::NonPayment->value,
            ])
            ->when($allUnitIds !== [], static fn (Builder $q) => $q->whereIn('unit_id', $allUnitIds))
            ->when($allUnitIds === [], static fn (Builder $q) => $q->whereRaw('0 = 1'))
            ->get();

        $transfers = ContractTransfer::query()
            ->with([
                'fromUnit.unitClass:id,size',
                'fromUnit:id,site_id,unit_class_id',
                'toUnit.unitClass:id,size',
                'toUnit:id,site_id,unit_class_id',
                'fromContractItem.price',
                'toContractItem.price',
            ])
            ->whereBetween('transfer_date', [$fromDate, $toDate])
            ->when($allUnitIds !== [], static function (Builder $q) use ($allUnitIds): void {
                $q->where(function (Builder $inner) use ($allUnitIds): void {
                    $inner->whereIn('from_unit_id', $allUnitIds)
                        ->orWhereIn('to_unit_id', $allUnitIds);
                });
            })
            ->when($allUnitIds === [], static fn (Builder $q) => $q->whereRaw('0 = 1'))
            ->get();

        $occupiedStartBySite = $this->occupiedCountBySite($unitIdsBySite, $fromDate);
        $occupiedEndBySite = $this->occupiedCountBySite($unitIdsBySite, $toDate);
        $occupiedAreaStartBySite = $this->occupiedAreaBySite($unitIdsBySite, $fromDate);
        $occupiedAreaEndBySite = $this->occupiedAreaBySite($unitIdsBySite, $toDate);

        /** @var array<string, string> $rateDeltaByCurrency */
        $rateDeltaByCurrency = [];
        /** @var array<string, int> $endedReasonCounts */
        $endedReasonCounts = [
            ContractEndedReason::Vacated->value => 0,
            ContractEndedReason::NonPayment->value => 0,
        ];
        $tenureDays = [];
        /** @var array<string, int> $depositOutcomes */
        $depositOutcomes = [
            'full_refund' => 0,
            'deductions' => 0,
            'none' => 0,
        ];

        $rows = [];
        $currencyForColumns = 'EUR';

        foreach ($sites as $site) {
            $siteId = (int) $site->id;
            $siteUnitIds = $unitIdsBySite[$siteId] ?? [];
            $siteUnitSet = array_fill_keys($siteUnitIds, true);

            $siteMoveIns = $moveIns->filter(
                static fn (UnitOccupancy $o): bool => isset($siteUnitSet[(int) $o->unit_id]),
            );
            $siteMoveOuts = $moveOuts->filter(
                static fn (UnitOccupancy $o): bool => isset($siteUnitSet[(int) $o->unit_id]),
            );
            $siteTransfers = $transfers->filter(
                static fn (ContractTransfer $t): bool => isset($siteUnitSet[(int) $t->from_unit_id])
                    || isset($siteUnitSet[(int) $t->to_unit_id]),
            );

            $vacated = 0;
            $nonPayment = 0;
            foreach ($siteMoveOuts as $out) {
                $reason = (string) $out->ended_reason;
                if ($reason === ContractEndedReason::NonPayment->value) {
                    $nonPayment++;
                } else {
                    $vacated++;
                }
                $endedReasonCounts[$reason] = ($endedReasonCounts[$reason] ?? 0) + 1;

                $start = CarbonImmutable::parse($out->started_on->toDateString());
                $end = CarbonImmutable::parse($out->ended_on->toDateString());
                $tenureDays[] = $start->diffInDays($end);

                $settlement = $out->contract?->depositSettlement;
                if ($settlement instanceof DepositSettlement) {
                    if ($settlement->outcome === DepositSettlementOutcome::Released
                        && BillingMath::cmp((string) $settlement->refunded_amount, (string) $settlement->deposit_amount) === 0) {
                        $depositOutcomes['full_refund']++;
                    } else {
                        $depositOutcomes['deductions']++;
                    }
                } else {
                    $depositOutcomes['none']++;
                }
            }

            $transfersUp = 0;
            $transfersDown = 0;
            $transfersSame = 0;
            $siteRateDelta = '0.00';
            $siteCurrency = strtoupper(trim((string) ($site->currency ?: 'EUR'))) ?: 'EUR';
            if ($siteTransfers->isNotEmpty()) {
                $first = $siteTransfers->first();
                $priceCurrency = strtoupper(trim((string) ($first?->toContractItem?->price?->currency
                    ?? $first?->fromContractItem?->price?->currency
                    ?? $siteCurrency))) ?: 'EUR';
                $siteCurrency = $priceCurrency;
            }
            $currencyForColumns = $siteCurrency;

            foreach ($siteTransfers as $transfer) {
                $fromSize = (float) ($transfer->fromUnit?->unitClass?->size ?? 0);
                $toSize = (float) ($transfer->toUnit?->unitClass?->size ?? 0);
                if ($toSize > $fromSize) {
                    $transfersUp++;
                } elseif ($toSize < $fromSize) {
                    $transfersDown++;
                } else {
                    $transfersSame++;
                }

                $fromAmount = BillingMath::round2((string) ($transfer->fromContractItem?->price?->amount ?? '0'));
                $toAmount = BillingMath::round2((string) ($transfer->toContractItem?->price?->amount ?? '0'));
                $delta = BillingMath::round2(bcsub($toAmount, $fromAmount, 2));
                $currency = strtoupper(trim((string) ($transfer->toContractItem?->price?->currency
                    ?? $transfer->fromContractItem?->price?->currency
                    ?? $siteCurrency))) ?: 'EUR';
                $siteRateDelta = BillingMath::round2(bcadd($siteRateDelta, $delta, 2));
                $rateDeltaByCurrency[$currency] = BillingMath::round2(
                    bcadd($rateDeltaByCurrency[$currency] ?? '0.00', $delta, 2),
                );
            }

            $ins = $siteMoveIns->count();
            $outs = $vacated + $nonPayment;
            $occupiedStart = $occupiedStartBySite[$siteId] ?? 0;
            $occupiedEnd = $occupiedEndBySite[$siteId] ?? 0;
            $deltaOccupied = $occupiedEnd - $occupiedStart;

            $areaStart = $occupiedAreaStartBySite[$siteId] ?? '0.00';
            $areaEnd = $occupiedAreaEndBySite[$siteId] ?? '0.00';
            $netM2 = BillingMath::round2(bcsub($areaEnd, $areaStart, 2));

            $rows[] = [
                'site' => (string) $site->name,
                'site_id' => $siteId,
                'move_ins' => $ins,
                'move_outs_vacated' => $vacated,
                'move_outs_non_payment' => $nonPayment,
                'transfers' => $siteTransfers->count(),
                'transfers_up' => $transfersUp,
                'transfers_down' => $transfersDown,
                'transfers_same' => $transfersSame,
                'net_units' => $ins - $outs,
                'net_m2' => $netM2,
                'rate_delta' => $siteRateDelta,
                'currency' => $siteCurrency,
                'occupied_start' => $occupiedStart,
                'occupied_end' => $occupiedEnd,
                'delta_occupied' => $deltaOccupied,
            ];
        }

        $avgTenure = $tenureDays === []
            ? null
            : round(array_sum($tenureDays) / count($tenureDays), 2);

        $columns = [
            ReportColumn::string('site', 'Site'),
            ReportColumn::int('move_ins', 'Move-ins'),
            ReportColumn::int('move_outs_vacated', 'Move-outs (vacated)'),
            ReportColumn::int('move_outs_non_payment', 'Move-outs (non-payment)'),
            ReportColumn::int('transfers', 'Transfers'),
            ReportColumn::int('transfers_up', 'Transfers up'),
            ReportColumn::int('transfers_down', 'Transfers down'),
            ReportColumn::int('transfers_same', 'Transfers same'),
            ReportColumn::int('net_units', 'Net units'),
            ReportColumn::string('net_m2', 'Net m²'),
            ReportColumn::money('rate_delta', 'Rate delta', $currencyForColumns),
        ];

        $rateDeltaList = [];
        foreach ($rateDeltaByCurrency as $currency => $amount) {
            $rateDeltaList[] = ['currency' => $currency, 'amount' => $amount];
        }
        usort($rateDeltaList, static fn (array $a, array $b): int => strcmp($a['currency'], $b['currency']));

        return new ReportResult($columns, $rows, [
            'from' => $fromDate,
            'to' => $toDate,
            'avg_tenure_days' => $avgTenure,
            'ended_reason_counts' => $endedReasonCounts,
            'deposit_outcomes' => $depositOutcomes,
            'rate_delta_by_currency' => $rateDeltaList,
            'identity' => [
                'note' => 'ins − outs = Δoccupied; transfers cancel',
                'occupied_start' => array_sum($occupiedStartBySite),
                'occupied_end' => array_sum($occupiedEndBySite),
                'delta_occupied' => array_sum($occupiedEndBySite) - array_sum($occupiedStartBySite),
                'move_ins' => $moveIns->count(),
                'move_outs' => $moveOuts->count(),
                'transfers' => $transfers->count(),
            ],
            'definitions' => 'docs/report-definitions.md#movement--movimiento',
        ]);
    }

    /**
     * Keys "unit_id|date" for transfer destination legs in the period.
     *
     * @param  list<int>  $unitIds
     * @return array<string, true>
     */
    private function transferDestinationKeys(string $from, string $to, array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        $keys = [];
        $rows = ContractTransfer::query()
            ->whereBetween('transfer_date', [$from, $to])
            ->whereIn('to_unit_id', $unitIds)
            ->get(['to_unit_id', 'transfer_date']);

        foreach ($rows as $row) {
            $date = $row->transfer_date instanceof \DateTimeInterface
                ? $row->transfer_date->format('Y-m-d')
                : (string) $row->transfer_date;
            $keys[(int) $row->to_unit_id.'|'.$date] = true;
        }

        return $keys;
    }

    /**
     * @param  array<int, list<int>>  $unitIdsBySite
     * @return array<int, int>
     */
    private function occupiedCountBySite(array $unitIdsBySite, string $on): array
    {
        $result = [];
        foreach ($unitIdsBySite as $siteId => $unitIds) {
            if ($unitIds === []) {
                $result[$siteId] = 0;

                continue;
            }

            $result[$siteId] = (int) UnitOccupancy::query()
                ->whereIn('unit_id', $unitIds)
                ->where('started_on', '<=', $on)
                ->where(function (Builder $q) use ($on): void {
                    $q->whereNull('ended_on')
                        ->orWhere('ended_on', '>', $on);
                })
                ->distinct()
                ->count('unit_id');
        }

        return $result;
    }

    /**
     * @param  array<int, list<int>>  $unitIdsBySite
     * @return array<int, string>
     */
    private function occupiedAreaBySite(array $unitIdsBySite, string $on): array
    {
        $result = [];
        foreach ($unitIdsBySite as $siteId => $unitIds) {
            if ($unitIds === []) {
                $result[$siteId] = '0.00';

                continue;
            }

            $occupiedIds = UnitOccupancy::query()
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

            if ($occupiedIds === []) {
                $result[$siteId] = '0.00';

                continue;
            }

            $area = '0.00';
            $sizes = Unit::query()
                ->whereIn('id', $occupiedIds)
                ->with('unitClass:id,size')
                ->get();
            foreach ($sizes as $unit) {
                $size = BillingMath::round2((string) ($unit->unitClass?->size ?? '0'));
                $area = BillingMath::round2(bcadd($area, $size, 2));
            }
            $result[$siteId] = $area;
        }

        return $result;
    }
}
