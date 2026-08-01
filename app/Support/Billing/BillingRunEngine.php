<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\AutopayAttemptTrigger;
use App\Enums\BillingRunItemOutcome;
use App\Enums\BillingRunTrigger;
use App\Enums\ContractStatus;
use App\Enums\LogChannel;
use App\Models\BillingRun;
use App\Models\BillingRunItem;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Support\Billing\Exceptions\BillingRunFailure;
use App\Support\Billing\Exceptions\CatchUpCapExceeded;
use App\Support\Payments\AutopayCollector;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recurring billing run shell: eligibility, per-contract locking, failure
 * isolation, and audit trail. Charge/invoice writes live in RecurringBilling
 * (S05-02); this class only orchestrates the trustworthy money-job envelope.
 *
 * Idempotency is the row-locked read-and-advance of contracts.billed_through —
 * no secondary dedup state (invariant: cursor-serialised billing).
 */
final class BillingRunEngine
{
    /**
     * @param  (Closure(Contract, array{start: CarbonImmutable, end: CarbonImmutable}): PeriodResult)|null  $generator
     * @param  (Closure(int): void)|null  $beforeLock  test seam: runs after eligibility, before FOR UPDATE
     */
    public function __construct(
        private readonly ?Closure $generator = null,
        private readonly ?Closure $beforeLock = null,
    ) {}

    /**
     * @return BillingRun|list<array{
     *     contract_id: int,
     *     periods: int,
     *     window_start: string|null,
     *     window_end: string|null,
     *     est_amount: string
     * }>
     */
    public function run(
        BillingRunTrigger $trigger = BillingRunTrigger::Manual,
        ?int $contractId = null,
        bool $dryRun = false,
        ?int $createdBy = null,
    ): BillingRun|array {
        $horizonDays = max(0, Setting::billing()->billingHorizonDays);
        $catchUpCap = max(1, (int) config('billing.catch_up_cap', 12));
        $horizonDate = CarbonImmutable::today()->addDays($horizonDays)->startOfDay();

        $eligibleIds = $this->eligibleContractIds($horizonDate, $contractId);

        if ($dryRun) {
            return $this->dryRunPreview($eligibleIds, $horizonDays, $catchUpCap);
        }

        $run = BillingRun::query()->create([
            'started_at' => now(),
            'finished_at' => null,
            'trigger' => $trigger,
            'horizon_date' => $horizonDate->toDateString(),
            'contracts_considered' => count($eligibleIds),
            'contracts_billed' => 0,
            'contracts_skipped' => 0,
            'contracts_failed' => 0,
            'created_by' => $createdBy,
            'created_at' => now(),
        ]);

        $billed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($eligibleIds as $id) {
            if ($this->beforeLock !== null) {
                ($this->beforeLock)($id);
            }

            $outcome = $this->processContract($run, $id, $horizonDays, $catchUpCap);

            match ($outcome) {
                BillingRunItemOutcome::Billed => $billed++,
                BillingRunItemOutcome::Skipped => $skipped++,
                BillingRunItemOutcome::Failed => $failed++,
            };
        }

        $run->forceFill([
            'finished_at' => now(),
            'contracts_billed' => $billed,
            'contracts_skipped' => $skipped,
            'contracts_failed' => $failed,
        ])->save();

        $causer = $createdBy !== null
            ? Employee::query()->find($createdBy)
            : null;

        RecordsActivity::log(
            LogChannel::Billing,
            'billing.run.completed',
            $run,
            [
                'trigger' => $trigger->value,
                'horizon_date' => $horizonDate->toDateString(),
                'contracts_considered' => count($eligibleIds),
                'contracts_billed' => $billed,
                'contracts_skipped' => $skipped,
                'contracts_failed' => $failed,
            ],
            $causer,
        );

        $this->collectAutopayForRun($run);

        return $run->refresh();
    }

    /**
     * Post-run autopay for contracts this run billed. Errors isolate inside
     * AutopayCollector — a Stripe blip must not undo billing.
     */
    private function collectAutopayForRun(BillingRun $run): void
    {
        $billedIds = $run->items()
            ->where('outcome', BillingRunItemOutcome::Billed)
            ->pluck('contract_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($billedIds === []) {
            return;
        }

        try {
            app(AutopayCollector::class)->collect(
                trigger: AutopayAttemptTrigger::BillingRun,
                contractIds: $billedIds,
                billingRunId: (int) $run->id,
            );
        } catch (Throwable $e) {
            SystemEvent::record('autopay.collect.failed', $run, [
                'billing_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function eligibleContractIds(CarbonImmutable $horizonDate, ?int $contractId): array
    {
        $query = Contract::query()
            ->whereIn('status', [ContractStatus::Active, ContractStatus::NoticeGiven])
            ->whereNotNull('billed_through')
            ->whereDate('billed_through', '<=', $horizonDate->toDateString())
            ->orderBy('id');

        if ($contractId !== null) {
            $query->where('id', $contractId);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $eligibleIds
     * @return list<array{
     *     contract_id: int,
     *     periods: int,
     *     window_start: string|null,
     *     window_end: string|null,
     *     est_amount: string
     * }>
     */
    private function dryRunPreview(array $eligibleIds, int $horizonDays, int $catchUpCap): array
    {
        $rows = [];

        foreach ($eligibleIds as $id) {
            $contract = Contract::query()
                ->with(['unitItem.item.site'])
                ->find($id);

            if ($contract === null) {
                continue;
            }

            $site = $this->resolveSite($contract);
            if ($site === null || $contract->billed_through === null) {
                continue;
            }

            $siteHorizon = $this->civilDate(
                SiteClock::today($site)->addDays($horizonDays)->toDateString()
            );
            $cursor = $this->civilDate($contract->billedThrough());

            if ($this->isPastStopLine($contract, $cursor)) {
                continue;
            }

            try {
                $windows = BillingMath::periodsBetween($contract, $cursor, $siteHorizon, $catchUpCap);
            } catch (CatchUpCapExceeded) {
                $rows[] = [
                    'contract_id' => $id,
                    'periods' => 0,
                    'window_start' => null,
                    'window_end' => null,
                    'est_amount' => '0.00',
                ];

                continue;
            } catch (Throwable) {
                continue;
            }

            $stop = $contract->status === ContractStatus::NoticeGiven
                ? RecurringBilling::stopDate($contract)
                : null;

            $billable = [];
            foreach ($windows as $window) {
                if ($stop !== null && $window['start']->gte($stop)) {
                    break;
                }
                $billable[] = $window;
            }

            if ($billable === []) {
                continue;
            }

            $est = '0.00';
            foreach ($billable as $window) {
                $est = bcadd($est, RecurringBilling::estimatePeriodGross($contract, $window), 2);
            }

            $rows[] = [
                'contract_id' => $id,
                'periods' => count($billable),
                'window_start' => $billable[0]['start']->toDateString(),
                'window_end' => $billable[array_key_last($billable)]['end']->toDateString(),
                'est_amount' => $est,
            ];
        }

        return $rows;
    }

    private function processContract(
        BillingRun $run,
        int $contractId,
        int $horizonDays,
        int $catchUpCap,
    ): BillingRunItemOutcome {
        try {
            /**
             * CatchUpCapExceeded / BillingRunFailure are returned as markers so the
             * per-contract transaction rolls back before the failure row is written.
             *
             * @var array{outcome: BillingRunItemOutcome}|array{fail: string, message: string} $result
             */
            $result = DB::transaction(function () use ($run, $contractId, $horizonDays, $catchUpCap) {
                /** @var Contract|null $contract */
                $contract = Contract::query()
                    ->with(['unitItem.item.site'])
                    ->lockForUpdate()
                    ->find($contractId);

                if ($contract === null) {
                    $this->writeItem($run, $contractId, BillingRunItemOutcome::Skipped, detail: 'ineligible_status');

                    return ['outcome' => BillingRunItemOutcome::Skipped];
                }

                if (! in_array($contract->status, [ContractStatus::Active, ContractStatus::NoticeGiven], true)) {
                    $this->writeItem($run, $contractId, BillingRunItemOutcome::Skipped, detail: 'ineligible_status');

                    return ['outcome' => BillingRunItemOutcome::Skipped];
                }

                $site = $this->resolveSite($contract);
                if ($site === null || $contract->billed_through === null) {
                    $this->writeItem($run, $contractId, BillingRunItemOutcome::Skipped, detail: 'ineligible_status');

                    return ['outcome' => BillingRunItemOutcome::Skipped];
                }

                // Civil date only — BillingMath compares midnights; do not pass a
                // site-TZ instant or inclusive-horizon equality breaks across offsets.
                $siteHorizon = $this->civilDate(
                    SiteClock::today($site)->addDays($horizonDays)->toDateString()
                );
                $cursor = $this->civilDate($contract->billedThrough());

                // 1b — stop-line pre-check: never call nextPeriod past stop; never write cursor.
                if ($this->isPastStopLine($contract, $cursor)) {
                    $this->writeItem($run, $contractId, BillingRunItemOutcome::Skipped, detail: 'stop_line');

                    return ['outcome' => BillingRunItemOutcome::Skipped];
                }

                if ($cursor->gt($siteHorizon)) {
                    $this->writeItem($run, $contractId, BillingRunItemOutcome::Skipped, detail: 'not_due');

                    return ['outcome' => BillingRunItemOutcome::Skipped];
                }

                try {
                    $windows = BillingMath::periodsBetween($contract, $cursor, $siteHorizon, $catchUpCap);
                } catch (CatchUpCapExceeded $e) {
                    return ['fail' => 'catch_up_cap', 'message' => $e->getMessage()];
                }

                if ($windows === []) {
                    $this->writeItem($run, $contractId, BillingRunItemOutcome::Skipped, detail: 'not_due');

                    return ['outcome' => BillingRunItemOutcome::Skipped];
                }

                $stop = $contract->status === ContractStatus::NoticeGiven
                    ? RecurringBilling::stopDate($contract)
                    : null;

                $periodsBilled = 0;
                $amountTotal = '0.00';
                $currency = $contract->currency;
                $invoiceIds = [];
                $lastBilledEnd = null;

                foreach ($windows as $window) {
                    if ($stop !== null && $window['start']->gte($stop)) {
                        if ($periodsBilled > 0) {
                            break;
                        }

                        $this->writeItem($run, $contractId, BillingRunItemOutcome::Skipped, detail: 'stop_line');

                        return ['outcome' => BillingRunItemOutcome::Skipped];
                    }

                    // BillingRunFailure must propagate so the transaction rolls back
                    // (charges without an invoice must not commit).
                    $periodResult = $this->generate($contract, $window);

                    if ($periodResult->isSkip()) {
                        if ($periodResult->skipDetail === 'stop_line') {
                            if ($periodsBilled > 0) {
                                break;
                            }

                            $this->writeItem($run, $contractId, BillingRunItemOutcome::Skipped, detail: 'stop_line');

                            return ['outcome' => BillingRunItemOutcome::Skipped];
                        }

                        $this->writeItem(
                            $run,
                            $contractId,
                            BillingRunItemOutcome::Skipped,
                            detail: $periodResult->skipDetail,
                        );

                        return ['outcome' => BillingRunItemOutcome::Skipped];
                    }

                    $periodsBilled += $periodResult->periodsBilled;
                    $amountTotal = bcadd($amountTotal, $periodResult->amountTotal, 2);
                    if ($periodResult->currency !== null) {
                        $currency = $periodResult->currency;
                    }
                    $invoiceIds = array_merge($invoiceIds, $periodResult->invoiceIds);
                    $lastBilledEnd = $window['end'];
                }

                if ($periodsBilled === 0 || $lastBilledEnd === null) {
                    $this->writeItem($run, $contractId, BillingRunItemOutcome::Skipped, detail: 'not_due');

                    return ['outcome' => BillingRunItemOutcome::Skipped];
                }

                $contract->forceFill([
                    'billed_through' => $lastBilledEnd->toDateString(),
                ])->save();

                $this->writeItem(
                    $run,
                    $contractId,
                    BillingRunItemOutcome::Billed,
                    periodsBilled: $periodsBilled,
                    amountTotal: $amountTotal,
                    currency: $currency,
                    invoiceIds: $invoiceIds === [] ? null : array_values(array_unique($invoiceIds)),
                );

                return ['outcome' => BillingRunItemOutcome::Billed];
            });
        } catch (BillingRunFailure $e) {
            $this->recordFailure(
                $run,
                $contractId,
                detail: $e->detail,
                message: $e->getMessage(),
            );

            return BillingRunItemOutcome::Failed;
        } catch (Throwable $e) {
            $this->recordFailure(
                $run,
                $contractId,
                detail: 'error',
                message: $e->getMessage(),
            );

            return BillingRunItemOutcome::Failed;
        }

        if (isset($result['fail'])) {
            $this->recordFailure(
                $run,
                $contractId,
                detail: $result['fail'],
                message: $result['message'],
            );

            return BillingRunItemOutcome::Failed;
        }

        return $result['outcome'];
    }

    private function civilDate(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::parse($ymd)->startOfDay();
    }

    private function isPastStopLine(Contract $contract, CarbonImmutable $cursor): bool
    {
        if ($contract->status !== ContractStatus::NoticeGiven) {
            return false;
        }

        $stop = RecurringBilling::stopDate($contract);

        return $stop !== null && $cursor->gte($stop);
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $window
     */
    private function generate(Contract $contract, array $window): PeriodResult
    {
        if ($this->generator !== null) {
            return ($this->generator)($contract, $window);
        }

        return RecurringBilling::generatePeriod($contract, $window);
    }

    private function resolveSite(Contract $contract): ?Site
    {
        $item = $contract->unitItem?->item;

        return $item instanceof \App\Models\Unit ? $item->site : null;
    }

    /**
     * @param  list<int>|null  $invoiceIds
     */
    private function writeItem(
        BillingRun $run,
        int $contractId,
        BillingRunItemOutcome $outcome,
        ?string $detail = null,
        ?string $errorMessage = null,
        int $periodsBilled = 0,
        ?string $amountTotal = null,
        ?string $currency = null,
        ?array $invoiceIds = null,
    ): void {
        BillingRunItem::query()->create([
            'billing_run_id' => $run->id,
            'contract_id' => $contractId,
            'outcome' => $outcome,
            'periods_billed' => $periodsBilled,
            'detail' => $detail,
            'error_message' => $errorMessage,
            'invoice_ids' => $invoiceIds,
            'amount_total' => $amountTotal,
            'currency' => $currency,
            'created_at' => now(),
        ]);
    }

    private function recordFailure(
        BillingRun $run,
        int $contractId,
        string $detail,
        string $message,
    ): void {
        // Written outside the rolled-back per-contract transaction (fresh work
        // on the ambient connection / outer test transaction).
        $this->writeItem(
            $run,
            $contractId,
            BillingRunItemOutcome::Failed,
            detail: $detail,
            errorMessage: $message,
        );

        $contract = Contract::query()->find($contractId);
        SystemEvent::record('billing.contract.failed', $contract, [
            'billing_run_id' => $run->id,
            'detail' => $detail,
            'error_message' => $message,
        ]);
    }
}
