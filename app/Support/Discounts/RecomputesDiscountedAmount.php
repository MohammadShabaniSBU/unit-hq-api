<?php

declare(strict_types=1);

namespace App\Support\Discounts;

use App\Enums\DiscountKind;
use App\Models\Discount;
use App\Support\Billing\BillingMath;

/**
 * Pure amount recompute for scheduled rate changes on discounted items (DISC-02).
 * API new_amount is treated as the new list; this returns the contract amount.
 */
final class RecomputesDiscountedAmount
{
    /**
     * @return array{amount: string, list_amount: string, percent: string|null}
     */
    public static function recompute(
        ?Discount $discount,
        string $newList,
        string $currentAmount,
        ?string $baseRate,
    ): array {
        $list = BillingMath::round2($newList);

        if ($discount === null) {
            return [
                'amount' => $list,
                'list_amount' => $list,
                'percent' => null,
            ];
        }

        return match ($discount->kind) {
            DiscountKind::Percent => self::percent($discount, $list),
            DiscountKind::FreeTime => self::freeTime($list, $currentAmount, $baseRate),
        };
    }

    /**
     * @return array{amount: string, list_amount: string, percent: string|null}
     */
    private static function percent(Discount $discount, string $list): array
    {
        $percent = BillingMath::round2((string) ($discount->params['percent'] ?? '0'));

        if (! $discount->tracks_rate_changes) {
            return [
                'amount' => $list,
                'list_amount' => $list,
                'percent' => $percent,
            ];
        }

        $factor = bcsub('1', bcdiv($percent, '100', 8), 8);
        $amount = BillingMath::round2(bcmul($list, $factor, 8));

        return [
            'amount' => $amount,
            'list_amount' => $list,
            'percent' => $percent,
        ];
    }

    /**
     * @return array{amount: string, list_amount: string, percent: string|null}
     */
    private static function freeTime(string $list, string $currentAmount, ?string $baseRate): array
    {
        $base = BillingMath::round2((string) ($baseRate ?? '0'));
        $current = BillingMath::round2($currentAmount);

        if (bccomp($base, '0', 2) === 0) {
            return [
                'amount' => '0.00',
                'list_amount' => $list,
                'percent' => null,
            ];
        }

        $multiplier = bcdiv($current, $base, 8);
        $amount = BillingMath::round2(bcmul($list, $multiplier, 8));

        return [
            'amount' => $amount,
            'list_amount' => $list,
            'percent' => null,
        ];
    }
}
