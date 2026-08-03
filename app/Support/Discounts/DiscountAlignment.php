<?php

declare(strict_types=1);

namespace App\Support\Discounts;

use App\Enums\DiscountKind;
use App\Models\Setting;
use App\Settings\BillingSettings;

/**
 * Non-blocking cadence alignment warnings for free_time tiers (DISC-00).
 */
final class DiscountAlignment
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<int, string>
     */
    public static function warnings(DiscountKind $kind, array $params, ?BillingSettings $billing = null): array
    {
        if ($kind !== DiscountKind::FreeTime) {
            return [];
        }

        $tiers = $params['tiers'] ?? null;
        if (! is_array($tiers) || $tiers === []) {
            return [];
        }

        $billing ??= Setting::billing();
        $periodDays = self::cadenceDays($billing);
        if ($periodDays <= 0) {
            return [];
        }

        $warnings = [];
        $label = self::cadenceLabel($billing);

        foreach ($tiers as $index => $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $freeWeeks = (int) ($tier['free_weeks'] ?? 0);
            if ($freeWeeks <= 0) {
                continue;
            }

            $freeDays = $freeWeeks * 7;
            if ($freeDays % $periodDays === 0) {
                continue;
            }

            $pct = bcmul(bcdiv((string) $freeDays, (string) $periodDays, 6), '100', 1);
            $tierNo = $index + 1;
            $warnings[] = "Tier {$tierNo}: {$freeWeeks} free weeks compiles to a {$pct}% period on {$label} billing";
        }

        return $warnings;
    }

    public static function cadenceDays(?BillingSettings $billing = null): int
    {
        $billing ??= Setting::billing();
        $count = max(1, $billing->defaultBillingIntervalCount);

        return match ($billing->defaultBillingInterval) {
            'day' => $count,
            'week' => $count * 7,
            default => $count * 30,
        };
    }

    public static function cadenceLabel(?BillingSettings $billing = null): string
    {
        $billing ??= Setting::billing();
        $count = max(1, $billing->defaultBillingIntervalCount);

        return match ($billing->defaultBillingInterval) {
            'day' => $count === 1 ? 'daily' : "{$count}-day",
            'week' => $count === 1 ? 'weekly' : "{$count}-week",
            default => $count === 1 ? 'monthly' : "{$count}-month",
        };
    }
}
