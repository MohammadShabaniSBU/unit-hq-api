<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\BillingInterval;
use App\Enums\ChargeType;
use App\Enums\DepositPayoutStatus;
use App\Enums\DepositSettlementOutcome;
use App\Enums\MoveOutSettlement;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\TaxRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Pure vacate settlement planner. Preview and commit share this path so the
 * panel preview never diverges from the ledger rows vacate writes (inv 20).
 *
 * Money is always decimal strings. No writes.
 */
final class VacateSettlement
{
    /**
     * @param  list<array{amount: string|float|int, reason: string, tax_rate_id?: int|null}>  $deductions
     * @return array{
     *     final_billing_date: string,
     *     notice_derived_date: string,
     *     move_out_on: string,
     *     billed_through: string|null,
     *     move_out_settlement: string,
     *     item_lines: list<array<string, mixed>>,
     *     deposit: array<string, mixed>,
     *     resulting_balance: string,
     *     payout_amount: string,
     *     currency: string
     * }
     */
    public static function compute(
        Contract $contract,
        CarbonImmutable $moveOutOn,
        DepositSettlementOutcome $outcome,
        array $deductions = [],
        ?CarbonImmutable $noticeGivenOn = null,
    ): array {
        $contract->loadMissing(['items.price', 'charges']);

        $noticeOn = $noticeGivenOn
            ?? ($contract->notice_given_on !== null
                ? CarbonImmutable::parse($contract->notice_given_on)->startOfDay()
                : $moveOutOn);
        $noticeDays = (int) ($contract->notice_period_days ?? 0);
        $noticeDerived = $noticeOn->addDays($noticeDays);
        $finalBillingDate = $noticeDerived->gt($moveOutOn) ? $noticeDerived : $moveOutOn;

        $policy = $contract->move_out_settlement instanceof MoveOutSettlement
            ? $contract->move_out_settlement
            : MoveOutSettlement::tryFrom((string) ($contract->move_out_settlement ?? 'none')) ?? MoveOutSettlement::None;

        $billedThrough = $contract->billed_through !== null
            ? CarbonImmutable::parse($contract->billed_through)->startOfDay()
            : null;

        $openItems = $contract->items
            ->filter(fn (ContractItem $item) => $item->effective_to === null)
            ->values();

        $itemLines = [];
        if ($billedThrough !== null) {
            if ($billedThrough->gt($finalBillingDate) && $policy !== MoveOutSettlement::None) {
                $creditFrom = $policy === MoveOutSettlement::NoticeBased
                    ? $noticeDerived
                    : $finalBillingDate;

                if ($billedThrough->gt($creditFrom)) {
                    foreach ($openItems as $item) {
                        $line = self::planProratedLine(
                            $contract,
                            $item,
                            $creditFrom,
                            $billedThrough,
                            credit: true,
                        );
                        if ($line !== null) {
                            $itemLines[] = $line;
                        }
                    }
                }
            }

            if ($billedThrough->lt($finalBillingDate)) {
                foreach ($openItems as $item) {
                    $line = self::planProratedLine(
                        $contract,
                        $item,
                        $billedThrough,
                        $finalBillingDate,
                        credit: false,
                    );
                    if ($line !== null) {
                        $itemLines[] = $line;
                    }
                }
            }
        }

        $deposit = self::planDeposit($contract, $outcome, $deductions);

        $delta = '0.00';
        foreach ($itemLines as $line) {
            $delta = bcadd($delta, (string) $line['gross'], 2);
        }
        foreach ($deposit['lines'] as $line) {
            $delta = bcadd($delta, (string) $line['gross'], 2);
        }

        $currentBalance = $contract->balanceOwed();
        $resultingBalance = bcadd($currentBalance, $delta, 2);

        return [
            'final_billing_date' => $finalBillingDate->toDateString(),
            'notice_derived_date' => $noticeDerived->toDateString(),
            'move_out_on' => $moveOutOn->toDateString(),
            'billed_through' => $billedThrough?->toDateString(),
            'move_out_settlement' => $policy->value,
            'item_lines' => $itemLines,
            'deposit' => $deposit,
            'resulting_balance' => $resultingBalance,
            'payout_amount' => $deposit['refunded_amount'],
            'currency' => (string) $contract->currency,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function planProratedLine(
        Contract $contract,
        ContractItem $item,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        bool $credit,
    ): ?array {
        $item->loadMissing('price');
        $periodAmount = (string) ($item->price?->amount ?? '0.00');
        if (bccomp($periodAmount, '0', 2) === 0) {
            return null;
        }

        $daysInPeriod = self::daysInBillingPeriod($contract, $windowEnd);
        $days = BillingMath::daysBetween($windowStart, $windowEnd);
        if ($days <= 0) {
            return null;
        }

        $net = BillingMath::prorate($periodAmount, $days, $daysInPeriod);
        if ($credit) {
            $net = bcmul($net, '-1', 2);
        }

        $taxRate = $credit
            ? self::originalTaxSnapshot($contract, $item)
            : ($item->tax_rate_snapshot !== null ? (string) $item->tax_rate_snapshot : null);

        $breakdown = BillingMath::applyTax($net, $taxRate);
        $chargeType = $credit
            ? ChargeType::Adjustment
            : match ($item->item_type) {
                'unit' => ChargeType::Rent,
                'insurance' => ChargeType::Insurance,
                default => ChargeType::Other,
            };

        $adjustsChargeId = $credit ? self::coveringChargeId($contract, $item) : null;

        return [
            'contract_item_id' => $item->id,
            'item_type' => $item->item_type,
            'charge_type' => $chargeType->value,
            'period_start' => $windowStart->toDateString(),
            'period_end' => $windowEnd->toDateString(),
            'days' => $days,
            'days_in_period' => $daysInPeriod,
            'net' => $breakdown->net,
            'tax' => $breakdown->tax,
            'gross' => $breakdown->gross,
            'tax_rate_snapshot' => $taxRate,
            'adjusts_charge_id' => $adjustsChargeId,
            'description' => $credit
                ? 'vacate.credit'
                : 'vacate.gap',
        ];
    }

    private static function daysInBillingPeriod(Contract $contract, CarbonImmutable $periodEnd): int
    {
        $interval = $contract->billing_interval instanceof BillingInterval
            ? $contract->billing_interval
            : BillingInterval::from((string) $contract->billing_interval);
        $count = (int) $contract->billing_interval_count;

        $periodStart = match ($interval) {
            BillingInterval::Day => $periodEnd->subDays($count),
            BillingInterval::Week => $periodEnd->subWeeks($count),
            BillingInterval::Month => $periodEnd->subMonthsNoOverflow($count),
        };

        $days = BillingMath::daysBetween($periodStart, $periodEnd);

        return max(1, $days);
    }

    private static function originalTaxSnapshot(Contract $contract, ContractItem $item): ?string
    {
        $charge = self::coveringCharge($contract, $item);

        if ($charge === null) {
            return $item->tax_rate_snapshot !== null ? (string) $item->tax_rate_snapshot : null;
        }

        return $charge->tax_rate_snapshot !== null ? (string) $charge->tax_rate_snapshot : null;
    }

    private static function coveringChargeId(Contract $contract, ContractItem $item): ?int
    {
        return self::coveringCharge($contract, $item)?->id;
    }

    private static function coveringCharge(Contract $contract, ContractItem $item): ?Charge
    {
        /** @var Collection<int, Charge> $charges */
        $charges = $contract->charges;

        return $charges
            ->filter(fn (Charge $c) => (int) $c->contract_item_id === (int) $item->id)
            ->filter(fn (Charge $c) => in_array(
                $c->charge_type instanceof ChargeType ? $c->charge_type : ChargeType::tryFrom((string) $c->charge_type),
                [ChargeType::Rent, ChargeType::Insurance],
                true,
            ))
            ->sortByDesc(fn (Charge $c) => (string) $c->period_end)
            ->first();
    }

    /**
     * @param  list<array{amount: string|float|int, reason: string, tax_rate_id?: int|null}>  $deductions
     * @return array{
     *     outcome: string,
     *     deposit_amount: string,
     *     refunded_amount: string,
     *     payout_status: string,
     *     lines: list<array<string, mixed>>
     * }
     */
    private static function planDeposit(
        Contract $contract,
        DepositSettlementOutcome $outcome,
        array $deductions,
    ): array {
        $depositAmount = BillingMath::round2((string) $contract->deposit_amount);

        if (bccomp($depositAmount, '0', 2) === 0) {
            return [
                'outcome' => $outcome->value,
                'deposit_amount' => '0.00',
                'refunded_amount' => '0.00',
                'payout_status' => DepositPayoutStatus::NotApplicable->value,
                'lines' => [],
            ];
        }

        return match ($outcome) {
            DepositSettlementOutcome::Released => [
                'outcome' => $outcome->value,
                'deposit_amount' => $depositAmount,
                'refunded_amount' => $depositAmount,
                'payout_status' => DepositPayoutStatus::Pending->value,
                'lines' => [[
                    'kind' => 'refund',
                    'charge_type' => ChargeType::Refund->value,
                    'net' => bcmul($depositAmount, '-1', 2),
                    'tax' => '0.00',
                    'gross' => bcmul($depositAmount, '-1', 2),
                    'tax_rate_snapshot' => null,
                    'reason' => 'deposit.release',
                    'description' => 'vacate.deposit_refund',
                ]],
            ],
            DepositSettlementOutcome::Forfeited => self::planForfeit($depositAmount, $deductions),
            DepositSettlementOutcome::Deducted => self::planDeducted($depositAmount, $deductions),
        };
    }

    /**
     * @param  list<array{amount: string|float|int, reason: string, tax_rate_id?: int|null}>  $deductions
     * @return array<string, mixed>
     */
    private static function planForfeit(string $depositAmount, array $deductions): array
    {
        $reason = trim((string) ($deductions[0]['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages([
                'deposit.deductions' => [__('errors.contracts.deposit_reason_required')],
            ]);
        }

        $taxRate = self::resolveDeductionTaxRate($deductions[0]['tax_rate_id'] ?? null);
        $breakdown = BillingMath::applyTax($depositAmount, $taxRate);

        return [
            'outcome' => DepositSettlementOutcome::Forfeited->value,
            'deposit_amount' => $depositAmount,
            'refunded_amount' => '0.00',
            'payout_status' => DepositPayoutStatus::NotApplicable->value,
            'lines' => [[
                'kind' => 'deduction',
                'charge_type' => ChargeType::Adjustment->value,
                'net' => $breakdown->net,
                'tax' => $breakdown->tax,
                'gross' => $breakdown->gross,
                'tax_rate_snapshot' => $taxRate,
                'reason' => $reason,
                'description' => 'vacate.deposit_forfeit',
            ]],
        ];
    }

    /**
     * @param  list<array{amount: string|float|int, reason: string, tax_rate_id?: int|null}>  $deductions
     * @return array<string, mixed>
     */
    private static function planDeducted(string $depositAmount, array $deductions): array
    {
        if ($deductions === []) {
            throw ValidationException::withMessages([
                'deposit.deductions' => [__('errors.contracts.deposit_reason_required')],
            ]);
        }

        $lines = [];
        $deducted = '0.00';

        foreach ($deductions as $index => $deduction) {
            $amount = BillingMath::round2((string) $deduction['amount']);
            $reason = trim((string) ($deduction['reason'] ?? ''));

            if ($reason === '' || bccomp($amount, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    "deposit.deductions.{$index}" => [__('errors.contracts.deposit_reason_required')],
                ]);
            }

            $deducted = bcadd($deducted, $amount, 2);
            $taxRate = self::resolveDeductionTaxRate($deduction['tax_rate_id'] ?? null);
            $breakdown = BillingMath::applyTax($amount, $taxRate);

            $lines[] = [
                'kind' => 'deduction',
                'charge_type' => ChargeType::Adjustment->value,
                'net' => $breakdown->net,
                'tax' => $breakdown->tax,
                'gross' => $breakdown->gross,
                'tax_rate_snapshot' => $taxRate,
                'reason' => $reason,
                'description' => 'vacate.deposit_deduction',
            ];
        }

        if (bccomp($deducted, $depositAmount, 2) === 1) {
            throw ValidationException::withMessages([
                'deposit.deductions' => [__('errors.contracts.deposit_exceeds')],
            ]);
        }

        $remainder = bcsub($depositAmount, $deducted, 2);
        $payoutStatus = DepositPayoutStatus::NotApplicable;
        $refunded = '0.00';

        if (bccomp($remainder, '0', 2) === 1) {
            $refunded = $remainder;
            $payoutStatus = DepositPayoutStatus::Pending;
            $lines[] = [
                'kind' => 'refund',
                'charge_type' => ChargeType::Refund->value,
                'net' => bcmul($remainder, '-1', 2),
                'tax' => '0.00',
                'gross' => bcmul($remainder, '-1', 2),
                'tax_rate_snapshot' => null,
                'reason' => 'deposit.remainder',
                'description' => 'vacate.deposit_refund',
            ];
        }

        return [
            'outcome' => DepositSettlementOutcome::Deducted->value,
            'deposit_amount' => $depositAmount,
            'refunded_amount' => $refunded,
            'payout_status' => $payoutStatus->value,
            'lines' => $lines,
        ];
    }

    private static function resolveDeductionTaxRate(mixed $taxRateId): ?string
    {
        if ($taxRateId === null || $taxRateId === '') {
            return '0.00';
        }

        $rate = TaxRate::query()->find((int) $taxRateId);

        return $rate !== null ? (string) $rate->rate : '0.00';
    }
}
