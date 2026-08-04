<?php

declare(strict_types=1);

namespace App\Support\Discounts;

use App\Enums\DiscountKind;
use App\Models\Deal;
use App\Models\Discount;
use App\Models\Setting;
use Illuminate\Support\Facades\App;

/**
 * Operator/tenant-facing resolution + promo copy (DISC-03).
 * No billing logic — wraps CommitmentWeeks + DiscountCompiler.
 */
final class DiscountSurface
{
    public const WARNING_NO_STAY_LENGTH = 'no_stay_length';

    /**
     * @return array{
     *     commitment_weeks: int|null,
     *     warning: string|null,
     *     resolved_tier: array{min_commitment_weeks: int, free_weeks: int}|null,
     *     noop: bool,
     *     promo_line: string|null,
     *     discount_schedule: array{
     *         noop: bool,
     *         resolved_tier: array{min_commitment_weeks: int, free_weeks: int}|null,
     *         segments: array<int, array{from: string, to: string|null, amount: string}>
     *     }|null
     * }
     */
    public static function resolve(
        Discount $discount,
        ?Deal $deal = null,
        ?int $commitmentWeeks = null,
        ?string $listAmount = null,
        ?string $currency = null,
        ?string $locale = null,
        ?string $anchorDate = null,
        ?string $interval = null,
        ?int $intervalCount = null,
    ): array {
        $weeks = $commitmentWeeks ?? CommitmentWeeks::fromDeal($deal);

        $warning = null;
        if ($discount->kind === DiscountKind::FreeTime && $weeks === null) {
            $warning = self::WARNING_NO_STAY_LENGTH;
        }

        $billing = Setting::billing();
        $resolvedInterval = $interval ?? $billing->defaultBillingInterval;
        $resolvedCount = $intervalCount ?? $billing->defaultBillingIntervalCount;
        $anchor = $anchorDate ?? now()->toDateString();
        $list = $listAmount ?? '0.00';
        $curr = $currency ?? ($billing->defaultCurrency !== '' ? $billing->defaultCurrency : 'EUR');

        $plan = DiscountCompiler::compile($discount, new CompileContext(
            listAmount: $list,
            currency: $curr,
            interval: $resolvedInterval,
            intervalCount: $resolvedCount,
            anchorDate: $anchor,
            commitmentWeeks: $weeks,
        ));

        $promoLine = self::promoLine($discount, $plan, $locale);

        return [
            'commitment_weeks' => $weeks,
            'warning' => $warning,
            'resolved_tier' => $plan->resolvedTier,
            'noop' => $plan->noop,
            'promo_line' => $promoLine,
            'discount_schedule' => $listAmount !== null ? $plan->toArray() : null,
        ];
    }

    public static function promoLine(
        Discount $discount,
        VersionPlan $plan,
        ?string $locale = null,
    ): ?string {
        $locale = self::normalizeLocale($locale ?? App::getLocale());

        return match ($discount->kind) {
            DiscountKind::Percent => (string) __(
                'discounts.promo.percent',
                ['percent' => rtrim(rtrim((string) ($discount->params['percent'] ?? '0'), '0'), '.')],
                $locale,
            ),
            DiscountKind::FreeTime => $plan->resolvedTier !== null
                ? (string) __(
                    'discounts.promo.free_weeks',
                    ['weeks' => $plan->resolvedTier['free_weeks']],
                    $locale,
                )
                : null,
        };
    }

    public static function normalizeLocale(?string $locale): string
    {
        if ($locale === null || $locale === '') {
            return 'en';
        }

        $base = strtolower(str_replace('_', '-', $locale));
        $base = explode('-', $base)[0];

        return in_array($base, ['en', 'es', 'fr'], true) ? $base : 'en';
    }
}
