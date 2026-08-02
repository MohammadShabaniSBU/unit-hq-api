<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\AutopayAttemptStatus;
use App\Enums\ChargeType;
use App\Enums\DelinquencyStepAction;
use App\Models\Allocation;
use App\Models\AutopayAttempt;
use App\Models\CallWrapup;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Delinquency;
use App\Models\Payment;
use App\Models\Unit;
use App\Support\Billing\BillingMath;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Period collections rates + graded machinery (promise-kept, autopay recovery).
 * Definitions: docs/report-definitions.md — Collections section.
 */
final class CollectionsReport extends AbstractReport
{
    public static function name(): string
    {
        return 'collections';
    }

    public function maxQueries(): int
    {
        return 40;
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $to = $filters->to ?? OccupancyMetrics::resolveAsOf($filters);
        $from = $filters->from ?? CarbonImmutable::parse($to)->subDays(90)->toDateString();
        $fromDt = CarbonImmutable::parse($from)->startOfDay();
        $toDt = CarbonImmutable::parse($to)->endOfDay();
        $promiseWindow = AgeingBuckets::PROMISE_WINDOW_DAYS;

        $siteContractIds = $this->contractIdsForSites($filters->siteIds);

        $chargesQuery = Charge::query()
            ->whereBetween('created_at', [$fromDt->toDateTimeString(), $toDt->toDateTimeString()])
            ->when($siteContractIds !== null, static fn (Builder $q) => $q->whereIn('contract_id', $siteContractIds));

        /** @var list<Charge> $periodCharges */
        $periodCharges = $chargesQuery->with('allocations')->get()->all();

        $currency = 'EUR';
        if ($periodCharges !== []) {
            $currency = strtoupper(trim((string) ($periodCharges[0]->currency ?: 'EUR'))) ?: 'EUR';
        } elseif ($siteContractIds !== null && $siteContractIds !== []) {
            $c = Contract::query()->whereIn('id', $siteContractIds)->value('currency');
            $currency = strtoupper(trim((string) ($c ?: 'EUR'))) ?: 'EUR';
        }

        /** @var array<string, array{charge_type: string, charged: string, allocated: string}> $byType */
        $byType = [];
        foreach ($periodCharges as $charge) {
            $type = $charge->charge_type instanceof ChargeType
                ? $charge->charge_type->value
                : (string) $charge->charge_type;
            if (! isset($byType[$type])) {
                $byType[$type] = [
                    'charge_type' => $type,
                    'charged' => '0.00',
                    'allocated' => '0.00',
                ];
            }
            $byType[$type]['charged'] = BillingMath::round2(
                bcadd($byType[$type]['charged'], (string) $charge->amount, 2),
            );

            $allocatedToCharge = '0.00';
            foreach ($charge->allocations as $allocation) {
                $allocatedToCharge = bcadd($allocatedToCharge, (string) $allocation->amount, 2);
            }
            $byType[$type]['allocated'] = BillingMath::round2(
                bcadd($byType[$type]['allocated'], $allocatedToCharge, 2),
            );
        }

        ksort($byType);
        $rows = [];
        foreach ($byType as $row) {
            $rate = null;
            if (bccomp($row['charged'], '0.00', 2) > 0) {
                $rate = (float) BillingMath::round2(
                    bcmul(bcdiv($row['allocated'], $row['charged'], 6), '100', 6),
                );
            }
            $rows[] = [
                'charge_type' => $row['charge_type'],
                'currency' => $currency,
                'charged' => $row['charged'],
                'allocated' => $row['allocated'],
                'rate' => $rate,
            ];
        }

        $promises = $this->promiseStats($fromDt, $toDt, $promiseWindow, $siteContractIds);
        $autopay = $this->autopayStats($fromDt, $toDt, $siteContractIds);
        $cure = $this->cureStats($fromDt, $toDt, $siteContractIds);
        $overlock = $this->overlockCorrelation($fromDt, $toDt, $siteContractIds);

        return new ReportResult(
            columns: [
                ReportColumn::string('charge_type', 'Charge type'),
                ReportColumn::string('currency', 'Currency'),
                ReportColumn::money('charged', 'Charged', $currency),
                ReportColumn::money('allocated', 'Allocated', $currency),
                ReportColumn::percent('rate', 'Collections rate'),
            ],
            rows: $rows,
            meta: [
                'from' => $from,
                'to' => $to,
                'promise_window_days' => $promiseWindow,
                'promise_kept' => $promises,
                'autopay' => $autopay,
                'days_to_cure' => $cure,
                'overlock_correlation' => $overlock,
                'notes' => [
                    'Collections rate = allocated-to-period-charges ÷ charged, by charge type.',
                    'Promise-kept: payment_promised wrap-up followed by any allocation within '.$promiseWindow.' days.',
                    'Overlock figures are correlation, not causation — cases that received an overlock vs not.',
                ],
            ],
        );
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return list<int>|null
     */
    private function contractIdsForSites(?array $siteIds): ?array
    {
        if ($siteIds === null) {
            return null;
        }

        return Contract::query()
            ->whereHas('unitItem', function (Builder $item) use ($siteIds): void {
                $item->where('item_type', 'unit')
                    ->whereIn('item_id', Unit::query()->whereIn('site_id', $siteIds)->select('id'));
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>|null  $siteContractIds
     * @return array{
     *     promised: int,
     *     kept: int,
     *     broken: int,
     *     kept_rate: float|null,
     *     broken_contracts: list<array{contract_id: int, wrapup_at: string}>
     * }
     */
    private function promiseStats(
        CarbonImmutable $fromDt,
        CarbonImmutable $toDt,
        int $promiseWindow,
        ?array $siteContractIds,
    ): array {
        $wrapups = CallWrapup::query()
            ->where('disposition', 'payment_promised')
            ->whereBetween('created_at', [$fromDt->toDateTimeString(), $toDt->toDateTimeString()])
            ->with(['message.thread'])
            ->get();

        $promised = 0;
        $kept = 0;
        $broken = 0;
        /** @var list<array{contract_id: int, wrapup_at: string}> $brokenContracts */
        $brokenContracts = [];

        foreach ($wrapups as $wrapup) {
            $contactId = $wrapup->message?->thread?->contact_id;
            if ($contactId === null) {
                continue;
            }

            $contracts = Contract::query()
                ->where('contact_id', $contactId)
                ->when($siteContractIds !== null, static fn (Builder $q) => $q->whereIn('id', $siteContractIds))
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($contracts === []) {
                continue;
            }

            $promised++;
            $wrapAt = CarbonImmutable::parse($wrapup->created_at->toDateTimeString());
            $deadline = $wrapAt->addDays($promiseWindow)->endOfDay();

            $hasAllocation = Allocation::query()
                ->whereHas('payment', static fn (Builder $q) => $q->whereIn('contract_id', $contracts))
                ->where('created_at', '>=', $wrapAt->toDateTimeString())
                ->where('created_at', '<=', $deadline->toDateTimeString())
                ->exists();

            if ($hasAllocation) {
                $kept++;
            } else {
                $broken++;
                $brokenContracts[] = [
                    'contract_id' => $contracts[0],
                    'wrapup_at' => $wrapAt->toDateString(),
                ];
            }
        }

        $keptRate = $promised > 0
            ? (float) BillingMath::round2(bcmul(bcdiv((string) $kept, (string) $promised, 6), '100', 6))
            : null;

        return [
            'promised' => $promised,
            'kept' => $kept,
            'broken' => $broken,
            'kept_rate' => $keptRate,
            'broken_contracts' => $brokenContracts,
        ];
    }

    /**
     * @param  list<int>|null  $siteContractIds
     * @return array{
     *     attempts: int,
     *     first_success: int,
     *     first_success_rate: float|null,
     *     failed: int,
     *     recovered: int,
     *     recovery_rate: float|null
     * }
     */
    private function autopayStats(
        CarbonImmutable $fromDt,
        CarbonImmutable $toDt,
        ?array $siteContractIds,
    ): array {
        $attempts = AutopayAttempt::query()
            ->whereBetween('attempted_at', [$fromDt->toDateTimeString(), $toDt->toDateTimeString()])
            ->when($siteContractIds !== null, static fn (Builder $q) => $q->whereIn('contract_id', $siteContractIds))
            ->orderBy('attempted_at')
            ->orderBy('id')
            ->get();

        $total = $attempts->count();
        $firstSuccess = 0;
        $failed = 0;
        $recovered = 0;

        $byContract = $attempts->groupBy('contract_id');
        foreach ($byContract as $contractId => $contractAttempts) {
            $first = $contractAttempts->first();
            if ($first === null) {
                continue;
            }
            $status = $first->status instanceof AutopayAttemptStatus
                ? $first->status
                : AutopayAttemptStatus::from((string) $first->status);

            if ($status === AutopayAttemptStatus::Succeeded) {
                $firstSuccess++;
            }

            foreach ($contractAttempts as $attempt) {
                $st = $attempt->status instanceof AutopayAttemptStatus
                    ? $attempt->status
                    : AutopayAttemptStatus::from((string) $attempt->status);
                if ($st !== AutopayAttemptStatus::Failed) {
                    continue;
                }
                $failed++;
                $failedAt = CarbonImmutable::parse($attempt->attempted_at->toDateTimeString());
                $laterPayment = Payment::query()
                    ->where('contract_id', (int) $contractId)
                    ->where('created_at', '>', $failedAt->toDateTimeString())
                    ->where(static function (Builder $q): void {
                        $q->whereNull('reversal_of_payment_id')
                            ->where('amount', '>', 0);
                    })
                    ->exists();
                if ($laterPayment) {
                    $recovered++;
                }
            }
        }

        return [
            'attempts' => $total,
            'first_success' => $firstSuccess,
            'first_success_rate' => $byContract->count() > 0
                ? (float) BillingMath::round2(bcmul(bcdiv((string) $firstSuccess, (string) $byContract->count(), 6), '100', 6))
                : null,
            'failed' => $failed,
            'recovered' => $recovered,
            'recovery_rate' => $failed > 0
                ? (float) BillingMath::round2(bcmul(bcdiv((string) $recovered, (string) $failed, 6), '100', 6))
                : null,
        ];
    }

    /**
     * @param  list<int>|null  $siteContractIds
     * @return array{cured_count: int, average_days: float|null}
     */
    private function cureStats(
        CarbonImmutable $fromDt,
        CarbonImmutable $toDt,
        ?array $siteContractIds,
    ): array {
        $cases = Delinquency::query()
            ->whereNotNull('cured_on')
            ->whereBetween('cured_on', [$fromDt->toDateString(), $toDt->toDateString()])
            ->when($siteContractIds !== null, static fn (Builder $q) => $q->whereIn('contract_id', $siteContractIds))
            ->get(['opened_on', 'cured_on']);

        if ($cases->isEmpty()) {
            return ['cured_count' => 0, 'average_days' => null];
        }

        $sum = 0;
        foreach ($cases as $case) {
            $sum += BillingMath::daysBetween(
                CarbonImmutable::parse($case->opened_on->toDateString()),
                CarbonImmutable::parse($case->cured_on->toDateString()),
            );
        }

        return [
            'cured_count' => $cases->count(),
            'average_days' => round($sum / $cases->count(), 2),
        ];
    }

    /**
     * @param  list<int>|null  $siteContractIds
     * @return array{
     *     cases: int,
     *     with_overlock: int,
     *     without_overlock: int,
     *     cured_with_overlock: int,
     *     cured_without_overlock: int,
     *     caveat: string
     * }
     */
    private function overlockCorrelation(
        CarbonImmutable $fromDt,
        CarbonImmutable $toDt,
        ?array $siteContractIds,
    ): array {
        $cases = Delinquency::query()
            ->where(function (Builder $q) use ($fromDt, $toDt): void {
                $q->whereBetween('opened_on', [$fromDt->toDateString(), $toDt->toDateString()])
                    ->orWhereBetween('cured_on', [$fromDt->toDateString(), $toDt->toDateString()]);
            })
            ->when($siteContractIds !== null, static fn (Builder $q) => $q->whereIn('contract_id', $siteContractIds))
            ->with(['steps'])
            ->get();

        $with = 0;
        $without = 0;
        $curedWith = 0;
        $curedWithout = 0;

        foreach ($cases as $case) {
            $hasOverlock = $case->steps->contains(
                static function ($step): bool {
                    $action = $step->action instanceof DelinquencyStepAction
                        ? $step->action
                        : DelinquencyStepAction::from((string) $step->action);

                    return $action === DelinquencyStepAction::PlaceOverlock;
                },
            );
            if ($hasOverlock) {
                $with++;
                if ($case->cured_on !== null) {
                    $curedWith++;
                }
            } else {
                $without++;
                if ($case->cured_on !== null) {
                    $curedWithout++;
                }
            }
        }

        return [
            'cases' => $cases->count(),
            'with_overlock' => $with,
            'without_overlock' => $without,
            'cured_with_overlock' => $curedWith,
            'cured_without_overlock' => $curedWithout,
            'caveat' => 'Correlation labelled as such — not causation. Small print matters when a number implies overlock works.',
        ];
    }
}
