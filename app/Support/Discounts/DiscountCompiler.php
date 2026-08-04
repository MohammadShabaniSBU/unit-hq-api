<?php

declare(strict_types=1);

namespace App\Support\Discounts;

use App\Enums\DiscountKind;
use App\Models\Discount;
use App\Support\Billing\BillingMath;
use Carbon\CarbonImmutable;

/**
 * Pure catalogue → VersionPlan compiler (DISC-01). No DB writes.
 */
final class DiscountCompiler
{
    public static function compile(Discount $discount, CompileContext $ctx): VersionPlan
    {
        $list = BillingMath::round2($ctx->listAmount);
        $anchor = CarbonImmutable::parse($ctx->anchorDate)->startOfDay()->toDateString();

        return match ($discount->kind) {
            DiscountKind::Percent => self::compilePercent($discount, $list, $anchor),
            DiscountKind::FreeTime => self::compileFreeTime($discount, $ctx, $list, $anchor),
        };
    }

    private static function compilePercent(Discount $discount, string $list, string $anchor): VersionPlan
    {
        $percent = BillingMath::round2((string) ($discount->params['percent'] ?? '0'));
        $factor = bcsub('1', bcdiv($percent, '100', 8), 8);
        $amount = BillingMath::round2(bcmul($list, $factor, 8));

        return new VersionPlan(
            segments: [
                ['from' => $anchor, 'to' => null, 'amount' => $amount],
            ],
        );
    }

    private static function compileFreeTime(
        Discount $discount,
        CompileContext $ctx,
        string $list,
        string $anchor,
    ): VersionPlan {
        $tier = self::resolveTier($discount, $ctx->commitmentWeeks);
        if ($tier === null) {
            return new VersionPlan(
                segments: [
                    ['from' => $anchor, 'to' => null, 'amount' => $list],
                ],
                noop: true,
            );
        }

        $periodDays = $ctx->periodDays();
        if ($periodDays <= 0) {
            return new VersionPlan(
                segments: [
                    ['from' => $anchor, 'to' => null, 'amount' => $list],
                ],
                noop: true,
                resolvedTier: $tier,
            );
        }

        $freeDays = (int) $tier['free_weeks'] * 7;
        $fullFreePeriods = intdiv($freeDays, $periodDays);
        $remainderDays = $freeDays % $periodDays;

        $segments = [];
        $cursor = CarbonImmutable::parse($anchor)->startOfDay();

        for ($i = 0; $i < $fullFreePeriods; $i++) {
            $from = $cursor->toDateString();
            $cursor = $cursor->addDays($periodDays);
            $segments[] = [
                'from' => $from,
                'to' => $cursor->toDateString(),
                'amount' => '0.00',
            ];
        }

        if ($remainderDays > 0) {
            $occupied = $periodDays - $remainderDays;
            $amount = BillingMath::prorate($list, $occupied, $periodDays);
            $from = $cursor->toDateString();
            $cursor = $cursor->addDays($periodDays);
            $segments[] = [
                'from' => $from,
                'to' => $cursor->toDateString(),
                'amount' => $amount,
            ];
        }

        $segments[] = [
            'from' => $cursor->toDateString(),
            'to' => null,
            'amount' => $list,
        ];

        // Collapse accidental empty leading window when free_days == 0 (should not happen).
        if ($segments === []) {
            $segments[] = ['from' => $anchor, 'to' => null, 'amount' => $list];
        }

        return new VersionPlan(
            segments: $segments,
            noop: false,
            resolvedTier: $tier,
        );
    }

    /**
     * Highest tier with min_commitment_weeks <= commitment; null if none.
     *
     * @return array{min_commitment_weeks: int, free_weeks: int}|null
     */
    public static function resolveTier(Discount $discount, ?int $commitmentWeeks): ?array
    {
        if ($commitmentWeeks === null || $commitmentWeeks < 1) {
            return null;
        }

        $tiers = $discount->params['tiers'] ?? null;
        if (! is_array($tiers) || $tiers === []) {
            return null;
        }

        $best = null;
        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $min = (int) ($tier['min_commitment_weeks'] ?? 0);
            $free = (int) ($tier['free_weeks'] ?? 0);
            if ($min < 1 || $free < 1 || $min > $commitmentWeeks) {
                continue;
            }

            if ($best === null || $min > $best['min_commitment_weeks']) {
                $best = [
                    'min_commitment_weeks' => $min,
                    'free_weeks' => $free,
                ];
            }
        }

        return $best;
    }
}
