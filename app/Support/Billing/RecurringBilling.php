<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Models\Charge;
use App\Models\Contract;
use App\Support\Billing\Exceptions\BillingRunFailure;
use App\Support\Fiscal\InvoiceIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Per-period charge + invoice generation for the recurring billing job.
 */
final class RecurringBilling
{
    /**
     * Stop line for notice_given contracts — same later-of expression as S02's
     * final billing date, using scheduled_move_out_on while still on notice.
     */
    public static function stopDate(Contract $contract): ?CarbonImmutable
    {
        if ($contract->notice_given_on === null || $contract->scheduled_move_out_on === null) {
            return null;
        }

        $noticeOn = CarbonImmutable::parse($contract->notice_given_on)->startOfDay();
        $moveOut = CarbonImmutable::parse($contract->scheduled_move_out_on)->startOfDay();
        $noticeDerived = $noticeOn->addDays((int) ($contract->notice_period_days ?? 0));

        return $noticeDerived->gt($moveOut) ? $noticeDerived : $moveOut;
    }

    /**
     * Next period window + estimated gross for panel display. Never writes.
     * Returns null when the contract is not eligible for a next bill.
     *
     * @return array{
     *     window: array{start: string, end: string},
     *     amount: string,
     *     currency: string
     * }|null
     */
    public static function nextBillEstimate(Contract $contract): ?array
    {
        $status = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::tryFrom((string) $contract->status);

        if ($status !== ContractStatus::Active && $status !== ContractStatus::NoticeGiven) {
            return null;
        }

        $cursorYmd = $contract->billedThrough();
        if ($cursorYmd === null || $contract->billing_anchor_date === null) {
            return null;
        }

        // Civil Y-m-d via billedThrough() — same as BillingRunEngine::civilDate.
        // Parsing the date-cast Carbon can shift the boundary across timezones.
        $cursor = CarbonImmutable::parse($cursorYmd)->startOfDay();

        if ($status === ContractStatus::NoticeGiven) {
            $stop = self::stopDate($contract);
            if ($stop !== null && $cursor->gte($stop)) {
                return null;
            }
        }

        try {
            $window = BillingMath::nextPeriod($contract, $cursor);
        } catch (Throwable) {
            return null;
        }

        if ($status === ContractStatus::NoticeGiven) {
            $stop = self::stopDate($contract);
            if ($stop !== null && $window['start']->gte($stop)) {
                return null;
            }
        }

        $amount = self::estimatePeriodGross($contract, $window);

        return [
            'window' => [
                'start' => $window['start']->toDateString(),
                'end' => $window['end']->toDateString(),
            ],
            'amount' => $amount,
            'currency' => (string) $contract->currency,
        ];
    }

    /**
     * Read-only gross estimate for dry-run. Never writes.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $window
     */
    public static function estimatePeriodGross(Contract $contract, array $window): string
    {
        if (self::isPastWindowStop($contract, $window['start'])) {
            return '0.00';
        }

        $items = $contract->itemsOn($window['start']);
        if ($items->isEmpty()) {
            return '0.00';
        }

        try {
            CurrencyGuard::assertItemsAgree($items);
        } catch (ValidationException) {
            return '0.00';
        }

        $amountTotal = '0.00';
        foreach ($items as $item) {
            $item->loadMissing('price');
            if ($item->price === null) {
                continue;
            }
            $rate = $item->tax_rate_snapshot !== null ? (string) $item->tax_rate_snapshot : null;
            $breakdown = BillingMath::applyTax((string) $item->price->amount, $rate);
            $amountTotal = bcadd($amountTotal, $breakdown->gross, 2);
        }

        return $amountTotal;
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $window
     */
    public static function generatePeriod(Contract $contract, array $window): PeriodResult
    {
        if (self::isPastWindowStop($contract, $window['start'])) {
            return new PeriodResult(periodsBilled: 0, skipDetail: 'stop_line');
        }

        $items = $contract->itemsOn($window['start']);
        if ($items->isEmpty()) {
            throw new BillingRunFailure('error', 'No contract items effective on period start.');
        }

        try {
            $currency = CurrencyGuard::assertItemsAgree($items);
        } catch (ValidationException $e) {
            throw new BillingRunFailure(
                'currency_mismatch',
                self::validationMessage($e) ?? 'Contract item currencies do not agree.',
            );
        }

        /** @var Collection<int, Charge> $charges */
        $charges = collect();
        $amountTotal = '0.00';

        foreach ($items as $item) {
            $item->loadMissing('price');
            $price = $item->price;
            if ($price === null) {
                throw new BillingRunFailure('error', "Contract item {$item->id} has no price.");
            }

            $net = (string) $price->amount;
            $rate = $item->tax_rate_snapshot !== null ? (string) $item->tax_rate_snapshot : null;
            $breakdown = BillingMath::applyTax($net, $rate);

            $charge = Charge::query()->create([
                'contract_id' => $contract->id,
                'contract_item_id' => $item->id,
                'charge_type' => self::chargeTypeForItem((string) $item->item_type),
                'period_start' => $window['start']->toDateString(),
                'period_end' => $window['end']->toDateString(),
                'net_amount' => $breakdown->net,
                'tax_rate_snapshot' => $item->tax_rate_snapshot,
                'tax_amount' => $breakdown->tax,
                'amount' => $breakdown->gross,
                'currency' => SupportedCurrencies::normalize((string) $price->currency),
                'due_date' => $window['start']->toDateString(),
                'description' => self::chargeDescriptionForItem((string) $item->item_type),
            ]);

            $charges->push($charge);
            $amountTotal = bcadd($amountTotal, $breakdown->gross, 2);
        }

        try {
            $invoice = InvoiceIssuer::issue($contract, $charges);
        } catch (ValidationException $e) {
            throw new BillingRunFailure(
                'fiscal_blocker',
                self::validationMessage($e) ?? 'Invoice issuance refused.',
            );
        } catch (Throwable $e) {
            throw new BillingRunFailure('fiscal_blocker', $e->getMessage());
        }

        $invoiceIds = $invoice !== null ? [(int) $invoice->id] : [];

        return new PeriodResult(
            periodsBilled: 1,
            amountTotal: $amountTotal,
            currency: $currency,
            invoiceIds: $invoiceIds,
        );
    }

    private static function isPastWindowStop(Contract $contract, CarbonImmutable $windowStart): bool
    {
        $status = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::tryFrom((string) $contract->status);

        if ($status !== ContractStatus::NoticeGiven) {
            return false;
        }

        $stop = self::stopDate($contract);

        return $stop !== null && $windowStart->gte($stop);
    }

    private static function chargeTypeForItem(string $itemType): ChargeType
    {
        return match ($itemType) {
            'unit' => ChargeType::Rent,
            'insurance' => ChargeType::Insurance,
            default => ChargeType::from($itemType),
        };
    }

    private static function chargeDescriptionForItem(string $itemType): string
    {
        return match ($itemType) {
            'unit' => 'Rent',
            'insurance' => 'Insurance',
            default => ucfirst($itemType),
        };
    }

    private static function validationMessage(ValidationException $e): ?string
    {
        $messages = $e->errors();
        foreach ($messages as $fieldMessages) {
            foreach ($fieldMessages as $message) {
                if (is_string($message) && $message !== '') {
                    return $message;
                }
            }
        }

        return $e->getMessage() !== '' ? $e->getMessage() : null;
    }
}
