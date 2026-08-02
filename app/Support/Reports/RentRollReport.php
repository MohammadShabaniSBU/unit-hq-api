<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\AccessSuspension;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\UnitOccupancy;
use App\Support\Billing\BillingMath;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rent roll as of a civil date: open occupancies with as-of rates and
 * current-state balances (honesty labels on balance columns).
 */
final class RentRollReport extends AbstractReport
{
    public static function name(): string
    {
        return 'rent-roll';
    }

    public function maxQueries(): int
    {
        return 20;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $asOf = OccupancyMetrics::resolveAsOf($filters);
        $on = CarbonImmutable::parse($asOf);

        $query = UnitOccupancy::query()
            ->with([
                'unit.site:id,name,timezone,currency',
                'unit.unitClass:id,code,label,size',
                'contract.contact:id,first_name,last_name',
                'contract.depositSettlement',
            ])
            ->where('started_on', '<=', $asOf)
            ->where(function (Builder $q) use ($asOf): void {
                $q->whereNull('ended_on')
                    ->orWhere('ended_on', '>', $asOf);
            })
            ->whereHas('unit', function (Builder $q) use ($filters): void {
                $q->where('enabled', true);
                if ($filters->siteIds !== null) {
                    $q->whereIn('site_id', $filters->siteIds);
                }
            })
            ->whereHas('contract', function (Builder $q): void {
                $q->whereNotIn('status', [
                    ContractStatus::AwaitingSignature->value,
                    ContractStatus::Cancelled->value,
                ]);
            });

        /** @var list<UnitOccupancy> $occupancies */
        $occupancies = $query
            ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
            ->join('sites', 'sites.id', '=', 'units.site_id')
            ->orderBy('sites.name')
            ->orderBy('units.unit_number')
            ->orderBy('unit_occupancies.id')
            ->select('unit_occupancies.*')
            ->get()
            ->all();

        $currency = 'EUR';
        if ($occupancies !== []) {
            $siteCurrency = $occupancies[0]->unit?->site?->currency;
            $currency = strtoupper(trim((string) ($siteCurrency ?: 'EUR'))) ?: 'EUR';
        } elseif ($filters->siteIds !== null && $filters->siteIds !== []) {
            $site = \App\Models\Site::query()->find($filters->siteIds[0]);
            if ($site !== null) {
                $currency = strtoupper(trim((string) ($site->currency ?: 'EUR'))) ?: 'EUR';
            }
        }

        if ($occupancies === []) {
            return new ReportResult(
                columns: $this->columns($currency),
                rows: [],
                meta: [
                    'as_of' => $asOf,
                    'notes' => [
                        'Balances and overdue are current-state figures, not reconstructed as of the report date.',
                    ],
                    'footer' => [
                        'units' => 0,
                        'area_m2' => '0.00',
                        'monthly_rent' => '0.00',
                        'deposits' => '0.00',
                        'overdue' => '0.00',
                        'currency' => $currency,
                    ],
                ],
            );
        }

        $contractIds = [];
        $unitIds = [];
        foreach ($occupancies as $occ) {
            $contractIds[(int) $occ->contract_id] = true;
            $unitIds[(int) $occ->unit_id] = true;
        }
        $contractIdList = array_keys($contractIds);
        $unitIdList = array_keys($unitIds);

        $rates = $this->itemRates($contractIdList, $asOf);
        $balances = $this->balancesByContract($contractIdList);
        $overdues = $this->overduesByContract($contractIdList);
        $delinquencyDays = $this->delinquencyDaysByContract($contractIdList, $occupancies);
        $overlockedUnits = $this->overlockedUnitIds($unitIdList);
        $suspendedContracts = $this->suspendedContractIds($contractIdList);

        $rows = [];
        $sumArea = '0.00';
        $sumRent = '0.00';
        $sumDeposit = '0.00';
        $sumOverdue = '0.00';

        foreach ($occupancies as $occ) {
            $unit = $occ->unit;
            $contract = $occ->contract;
            $site = $unit?->site;
            $class = $unit?->unitClass;
            $contact = $contract?->contact;

            if ($unit === null || $contract === null || $site === null || $class === null) {
                continue;
            }

            $contractId = (int) $contract->id;
            $unitId = (int) $unit->id;
            $area = BillingMath::round2((string) ($class->size ?? '0'));
            $monthly = $rates[$contractId]['unit'] ?? ['amount' => '0.00', 'currency' => $currency];
            $insurance = $rates[$contractId]['insurance'] ?? null;
            $deposit = $this->depositHeld($contract);
            $balance = $balances[$contractId] ?? '0.00';
            $overdue = $overdues[$contractId] ?? '0.00';
            $moveIn = $occ->started_on?->toDateString() ?? (string) $occ->started_on;
            $tenure = max(0, (int) CarbonImmutable::parse($moveIn)->diffInDays($on));
            $status = $contract->status instanceof ContractStatus
                ? $contract->status->value
                : (string) $contract->status;
            $endDate = $occ->ended_on?->toDateString();
            if ($status === ContractStatus::NoticeGiven->value && $contract->end_date !== null) {
                $endDate = $contract->end_date->toDateString();
            }

            $rowCurrency = strtoupper(trim((string) ($monthly['currency'] ?: $contract->currency ?: $currency))) ?: $currency;
            $currency = $rowCurrency;

            $rows[] = [
                'unit_number' => (string) $unit->unit_number,
                'class' => (string) $class->code,
                'site' => (string) $site->name,
                'area_m2' => $area,
                'tenant' => trim(($contact?->first_name ?? '').' '.($contact?->last_name ?? '')),
                'contract_id' => $contractId,
                'status' => $status,
                'move_in' => $moveIn,
                'end_date' => $endDate,
                'tenure_days' => $tenure,
                'monthly_rate' => $monthly['amount'],
                'insurance' => $insurance['amount'] ?? null,
                'deposit_held' => $deposit,
                'balance_owed' => $balance,
                'overdue' => $overdue,
                'autopay' => $contract->autopay_enabled ? 'yes' : 'no',
                'delinquency_days' => $delinquencyDays[$contractId] ?? null,
                'overlocked' => isset($overlockedUnits[$unitId]) ? 'yes' : 'no',
                'access_suspended' => isset($suspendedContracts[$contractId]) ? 'yes' : 'no',
            ];

            $sumArea = bcadd($sumArea, $area, 2);
            $sumRent = bcadd($sumRent, $monthly['amount'], 2);
            $sumDeposit = bcadd($sumDeposit, $deposit, 2);
            $sumOverdue = bcadd($sumOverdue, $overdue, 2);
        }

        return new ReportResult(
            columns: $this->columns($currency),
            rows: $rows,
            meta: [
                'as_of' => $asOf,
                'notes' => [
                    'Balances and overdue are current-state figures, not reconstructed as of the report date.',
                ],
                'footer' => [
                    'units' => count($rows),
                    'area_m2' => BillingMath::round2($sumArea),
                    'monthly_rent' => BillingMath::round2($sumRent),
                    'deposits' => BillingMath::round2($sumDeposit),
                    'overdue' => BillingMath::round2($sumOverdue),
                    'currency' => $currency,
                ],
            ],
        );
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(string $currency): array
    {
        return [
            ReportColumn::string('unit_number', 'Unit'),
            ReportColumn::string('class', 'Class'),
            ReportColumn::string('site', 'Site'),
            ReportColumn::string('area_m2', 'Area m²'),
            ReportColumn::string('tenant', 'Tenant'),
            ReportColumn::int('contract_id', 'Contract'),
            ReportColumn::string('status', 'Status'),
            ReportColumn::date('move_in', 'Move-in'),
            ReportColumn::date('end_date', 'End date'),
            ReportColumn::int('tenure_days', 'Tenure days'),
            ReportColumn::money('monthly_rate', 'Monthly rate', $currency),
            ReportColumn::money('insurance', 'Insurance', $currency),
            ReportColumn::money('deposit_held', 'Deposit held', $currency),
            ReportColumn::money('balance_owed', 'Balance owed (current)', $currency),
            ReportColumn::money('overdue', 'Overdue (current)', $currency),
            ReportColumn::string('autopay', 'Autopay'),
            ReportColumn::int('delinquency_days', 'Delinquency days'),
            ReportColumn::string('overlocked', 'Overlocked'),
            ReportColumn::string('access_suspended', 'Access suspended'),
        ];
    }

    /**
     * @param  list<int>  $contractIds
     * @return array<int, array{unit?: array{amount: string, currency: string}, insurance?: array{amount: string, currency: string}}>
     */
    private function itemRates(array $contractIds, string $asOf): array
    {
        if ($contractIds === []) {
            return [];
        }

        $items = \App\Models\ContractItem::query()
            ->with('price')
            ->whereIn('contract_id', $contractIds)
            ->whereIn('item_type', ['unit', 'insurance'])
            ->effectiveOn(CarbonImmutable::parse($asOf))
            ->orderBy('id')
            ->get();

        /** @var array<int, array{unit?: array{amount: string, currency: string}, insurance?: array{amount: string, currency: string}}> $out */
        $out = [];
        foreach ($items as $item) {
            $price = $item->price;
            if ($price === null) {
                continue;
            }
            $payload = [
                'amount' => BillingMath::round2((string) $price->amount),
                'currency' => strtoupper(trim((string) $price->currency)) ?: 'EUR',
            ];
            $type = (string) $item->item_type;
            if ($type === 'unit' && ! isset($out[$item->contract_id]['unit'])) {
                $out[(int) $item->contract_id]['unit'] = $payload;
            }
            if ($type === 'insurance' && ! isset($out[$item->contract_id]['insurance'])) {
                $out[(int) $item->contract_id]['insurance'] = $payload;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $contractIds
     * @return array<int, string>
     */
    private function balancesByContract(array $contractIds): array
    {
        if ($contractIds === []) {
            return [];
        }

        $charges = Charge::query()
            ->whereIn('contract_id', $contractIds)
            ->groupBy('contract_id')
            ->select('contract_id', DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->pluck('total', 'contract_id');

        $payments = Payment::query()
            ->whereIn('contract_id', $contractIds)
            ->groupBy('contract_id')
            ->select('contract_id', DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->pluck('total', 'contract_id');

        $out = [];
        foreach ($contractIds as $id) {
            $c = BillingMath::round2((string) ($charges[$id] ?? '0'));
            $p = BillingMath::round2((string) ($payments[$id] ?? '0'));
            $out[$id] = BillingMath::round2(bcsub($c, $p, 2));
        }

        return $out;
    }

    /**
     * @param  list<int>  $contractIds
     * @return array<int, string>
     */
    private function overduesByContract(array $contractIds): array
    {
        if ($contractIds === []) {
            return [];
        }

        $today = CarbonImmutable::now()->toDateString();

        $charges = Charge::query()
            ->with('allocations')
            ->whereIn('contract_id', $contractIds)
            ->where('due_date', '<', $today)
            ->get();

        $out = array_fill_keys($contractIds, '0.00');
        foreach ($charges as $charge) {
            $open = $charge->openAmount();
            if (bccomp($open, '0', 2) <= 0) {
                continue;
            }
            $cid = (int) $charge->contract_id;
            $out[$cid] = BillingMath::round2(bcadd($out[$cid], $open, 2));
        }

        return $out;
    }

    /**
     * @param  list<int>  $contractIds
     * @param  list<UnitOccupancy>  $occupancies
     * @return array<int, int>
     */
    private function delinquencyDaysByContract(array $contractIds, array $occupancies): array
    {
        if ($contractIds === []) {
            return [];
        }

        /** @var array<int, CarbonImmutable> $todayByContract */
        $todayByContract = [];
        foreach ($occupancies as $occ) {
            $site = $occ->unit?->site;
            if ($site === null) {
                continue;
            }
            $todayByContract[(int) $occ->contract_id] = SiteClock::today($site);
        }

        $charges = Charge::query()
            ->with('allocations')
            ->whereIn('contract_id', $contractIds)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->groupBy('contract_id');

        $out = [];
        foreach ($contractIds as $cid) {
            $today = $todayByContract[$cid] ?? CarbonImmutable::now()->startOfDay();
            $todayStr = $today->toDateString();
            $oldest = null;
            foreach ($charges->get($cid, collect()) as $charge) {
                $due = $charge->due_date?->toDateString() ?? (string) $charge->due_date;
                if ($due >= $todayStr) {
                    continue;
                }
                if (bccomp($charge->openAmount(), '0', 2) <= 0) {
                    continue;
                }
                $oldest = $due;
                break;
            }
            if ($oldest !== null) {
                $out[$cid] = BillingMath::daysBetween(
                    CarbonImmutable::parse($oldest),
                    $today,
                );
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $unitIds
     * @return array<int, true>
     */
    private function overlockedUnitIds(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        $ids = DB::table('unit_holds')
            ->whereIn('unit_id', $unitIds)
            ->whereNull('released_at')
            ->where('hold_type', HoldType::Overlock->value)
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
     * @param  list<int>  $contractIds
     * @return array<int, true>
     */
    private function suspendedContractIds(array $contractIds): array
    {
        if ($contractIds === []) {
            return [];
        }

        $ids = AccessSuspension::query()
            ->active()
            ->whereIn('contract_id', $contractIds)
            ->pluck('contract_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $set = [];
        foreach ($ids as $id) {
            $set[$id] = true;
        }

        return $set;
    }

    private function depositHeld(Contract $contract): string
    {
        $settlement = $contract->depositSettlement;
        if ($settlement !== null) {
            // Liability remaining after settlement intent: released → 0; else deposit snapshot.
            return match ((string) $settlement->outcome->value) {
                'released' => '0.00',
                default => BillingMath::round2((string) $settlement->deposit_amount),
            };
        }

        return BillingMath::round2((string) ($contract->deposit_amount ?? '0'));
    }
}
