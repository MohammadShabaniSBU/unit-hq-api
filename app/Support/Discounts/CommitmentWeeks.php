<?php

declare(strict_types=1);

namespace App\Support\Discounts;

use App\Enums\StayPeriod;
use App\Models\Deal;

/**
 * Resolve deal stay length into whole weeks for free_time tier selection.
 * Month → weeks uses ×4 so "2 months" pins the 8-week / 4-free tier.
 */
final class CommitmentWeeks
{
    public static function fromDeal(?Deal $deal): ?int
    {
        if ($deal === null) {
            return null;
        }

        $length = $deal->expected_stay_length;
        $period = $deal->expected_stay_period;

        if ($length === null || $length < 1 || $period === null) {
            return null;
        }

        return self::fromLengthAndPeriod((int) $length, $period);
    }

    public static function fromLengthAndPeriod(int $length, StayPeriod|string $period): int
    {
        $period = $period instanceof StayPeriod ? $period : StayPeriod::from($period);

        return match ($period) {
            StayPeriod::Day => (int) max(1, (int) ceil($length / 7)),
            StayPeriod::Week => $length,
            StayPeriod::Month => $length * 4,
        };
    }
}
