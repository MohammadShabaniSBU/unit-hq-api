<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\BillingInterval;
use App\Enums\ChargeType;
use App\Enums\TransferBilling;
use App\Enums\TransferPricingMode;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\UnitClassRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Pure transfer settlement planner. Preview and commit share this path so the
 * panel preview never diverges from the ledger rows transfer writes (inv 20).
 *
 * Money is always decimal strings. No writes.
 */
final class TransferSettlement
{
    /**
     * @return array{
     *     pricing_mode: string,
     *     transfer_billing: string,
     *     transfer_date: string,
     *     billed_through: string|null,
     *     origin_item: array{id: int, price_id: int, amount: string, tax_rate_snapshot: string|null},
     *     destination_item: array{
     *         price_id: int,
     *         amount: string,
     *         currency: string,
     *         tax_rate_id: int|null,
     *         tax_rate_snapshot: string|null,
     *         item_id: int
     *     },
     *     credit: array<string, mixed>|null,
     *     debit: array<string, mixed>|null,
     *     deposit: array{
     *         differential: string,
     *         surplus: string,
     *         new_deposit_amount: string,
     *         charge: array<string, mixed>|null
     *     },
     *     resulting_balance: string,
     *     currency: string
     * }
     */
    public static function compute(
        Contract $contract,
        Unit $destination,
        CarbonImmutable $transferDate,
        TransferPricingMode $mode,
        ContractItem $originItem,
    ): array {
        $contract->loadMissing(['items.price', 'charges']);
        $destination->loadMissing(['unitClass', 'site']);

        $originItem->loadMissing('price');

        $policy = $contract->transfer_billing instanceof TransferBilling
            ? $contract->transfer_billing
            : TransferBilling::tryFrom((string) ($contract->transfer_billing ?? 'prorate_immediately'))
                ?? TransferBilling::ProrateImmediately;

        $destinationItem = self::planDestinationItem($destination, $originItem, $mode, $transferDate);

        $billedThrough = $contract->billed_through !== null
            ? CarbonImmutable::parse($contract->billed_through)->startOfDay()
            : null;

        $credit = null;
        $debit = null;

        if (
            $policy === TransferBilling::ProrateImmediately
            && $billedThrough !== null
            && $billedThrough->gt($transferDate)
        ) {
            $credit = self::planCredit($contract, $originItem, $transferDate, $billedThrough);
            $debit = self::planDebit($contract, $destinationItem, $transferDate, $billedThrough);
        }

        $deposit = self::planDeposit($contract);

        $delta = '0.00';
        if ($credit !== null) {
            $delta = bcadd($delta, (string) $credit['gross'], 2);
        }
        if ($debit !== null) {
            $delta = bcadd($delta, (string) $debit['gross'], 2);
        }
        if ($deposit['charge'] !== null) {
            $delta = bcadd($delta, (string) $deposit['charge']['gross'], 2);
        }

        $resultingBalance = bcadd($contract->balanceOwed(), $delta, 2);

        return [
            'pricing_mode' => $mode->value,
            'transfer_billing' => $policy->value,
            'transfer_date' => $transferDate->toDateString(),
            'billed_through' => $billedThrough?->toDateString(),
            'origin_item' => [
                'id' => (int) $originItem->id,
                'price_id' => (int) $originItem->price_id,
                'amount' => BillingMath::round2((string) ($originItem->price?->amount ?? '0.00')),
                'tax_rate_snapshot' => $originItem->tax_rate_snapshot !== null
                    ? (string) $originItem->tax_rate_snapshot
                    : null,
            ],
            'destination_item' => $destinationItem,
            'credit' => $credit,
            'debit' => $debit,
            'deposit' => $deposit,
            'resulting_balance' => $resultingBalance,
            'currency' => (string) $contract->currency,
        ];
    }

    /**
     * @return array{
     *     price_id: int,
     *     amount: string,
     *     currency: string,
     *     tax_rate_id: int|null,
     *     tax_rate_snapshot: string|null,
     *     item_id: int
     * }
     */
    private static function planDestinationItem(
        Unit $destination,
        ContractItem $originItem,
        TransferPricingMode $mode,
        CarbonImmutable $transferDate,
    ): array {
        $price = match ($mode) {
            TransferPricingMode::RetainRate => $originItem->price,
            TransferPricingMode::DestinationRate => self::cataloguePriceForUnit($destination),
        };

        if ($price === null) {
            throw ValidationException::withMessages([
                'to_unit_id' => [__('errors.contracts.transfer_no_catalogue_price')],
            ]);
        }

        $taxRate = ContractBilling::resolveTaxRate(
            $destination->unitClass?->tax_rate_code,
            $transferDate,
        );

        return [
            'price_id' => (int) $price->id,
            'amount' => BillingMath::round2((string) $price->amount),
            'currency' => (string) $price->currency,
            'tax_rate_id' => $taxRate?->id,
            'tax_rate_snapshot' => $taxRate?->rate !== null ? (string) $taxRate->rate : null,
            'item_id' => (int) $destination->id,
        ];
    }

    private static function cataloguePriceForUnit(Unit $destination): ?\App\Models\Price
    {
        $rate = UnitClassRate::query()
            ->with('price')
            ->where('unit_class_id', $destination->unit_class_id)
            ->where('site_id', $destination->site_id)
            ->first();

        return $rate?->price;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function planCredit(
        Contract $contract,
        ContractItem $originItem,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): ?array {
        $originItem->loadMissing('price');
        $periodAmount = (string) ($originItem->price?->amount ?? '0.00');
        if (bccomp($periodAmount, '0', 2) === 0) {
            return null;
        }

        $daysInPeriod = self::daysInBillingPeriod($contract, $windowEnd);
        $days = BillingMath::daysBetween($windowStart, $windowEnd);
        if ($days <= 0) {
            return null;
        }

        $net = bcmul(BillingMath::prorate($periodAmount, $days, $daysInPeriod), '-1', 2);
        $taxRate = self::originalTaxSnapshot($contract, $originItem);
        $breakdown = BillingMath::applyTax($net, $taxRate);

        return [
            'contract_item_id' => $originItem->id,
            'charge_type' => ChargeType::Adjustment->value,
            'period_start' => $windowStart->toDateString(),
            'period_end' => $windowEnd->toDateString(),
            'days' => $days,
            'days_in_period' => $daysInPeriod,
            'net' => $breakdown->net,
            'tax' => $breakdown->tax,
            'gross' => $breakdown->gross,
            'tax_rate_snapshot' => $taxRate,
            'adjusts_charge_id' => self::coveringChargeId($contract, $originItem),
            'description' => 'transfer.credit',
        ];
    }

    /**
     * @param  array{price_id: int, amount: string, tax_rate_snapshot: string|null, item_id: int}  $destinationItem
     * @return array<string, mixed>|null
     */
    private static function planDebit(
        Contract $contract,
        array $destinationItem,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): ?array {
        $periodAmount = (string) $destinationItem['amount'];
        if (bccomp($periodAmount, '0', 2) === 0) {
            return null;
        }

        $daysInPeriod = self::daysInBillingPeriod($contract, $windowEnd);
        $days = BillingMath::daysBetween($windowStart, $windowEnd);
        if ($days <= 0) {
            return null;
        }

        $net = BillingMath::prorate($periodAmount, $days, $daysInPeriod);
        $taxRate = $destinationItem['tax_rate_snapshot'];
        $breakdown = BillingMath::applyTax($net, $taxRate);

        return [
            'contract_item_id' => null,
            'charge_type' => ChargeType::Rent->value,
            'period_start' => $windowStart->toDateString(),
            'period_end' => $windowEnd->toDateString(),
            'days' => $days,
            'days_in_period' => $daysInPeriod,
            'net' => $breakdown->net,
            'tax' => $breakdown->tax,
            'gross' => $breakdown->gross,
            'tax_rate_snapshot' => $taxRate,
            'adjusts_charge_id' => null,
            'description' => 'transfer.debit',
        ];
    }

    /**
     * @return array{
     *     differential: string,
     *     surplus: string,
     *     new_deposit_amount: string,
     *     charge: array<string, mixed>|null
     * }
     */
    private static function planDeposit(Contract $contract): array
    {
        $held = BillingMath::round2((string) $contract->deposit_amount);
        $required = BillingMath::round2((string) Setting::billing()->defaultDepositAmount);

        if (bccomp($required, $held, 2) > 0) {
            $diff = bcsub($required, $held, 2);

            return [
                'differential' => $diff,
                'surplus' => '0.00',
                'new_deposit_amount' => $required,
                'charge' => [
                    'charge_type' => ChargeType::Deposit->value,
                    'net' => $diff,
                    'tax' => '0.00',
                    'gross' => $diff,
                    'tax_rate_snapshot' => null,
                    'description' => 'transfer.deposit_shortfall',
                ],
            ];
        }

        $surplus = bccomp($held, $required, 2) > 0
            ? bcsub($held, $required, 2)
            : '0.00';

        return [
            'differential' => '0.00',
            'surplus' => $surplus,
            'new_deposit_amount' => $held,
            'charge' => null,
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
}
