<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Enums\ProrationMethod;
use App\Models\TaxRate;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;

/**
 * Orchestrates BillingMath for the contract-creation spine (store / convert /
 * convert-preview), so the panel preview never diverges from the charges a
 * real create actually writes. Contract-level (window/anchor/billed_through)
 * is computed once via planFirstPeriod(); callers loop items themselves and
 * call firstPeriodNetForItem() + BillingMath::applyTax() per line, since tax
 * and amount differ per item while the window is shared.
 */
final class ContractBilling
{
    /**
     * Anchor + first-charge window for the whole contract, computed once.
     * Does not touch the database — callers decide whether to persist.
     */
    public static function planFirstPeriod(
        CarbonImmutable $moveIn,
        BillingAnchorModel|string $anchorModel,
        BillingInterval|string $interval,
        int $intervalCount,
        int $anchorDayOfMonth,
    ): FirstPeriodPlan {
        $anchor = BillingMath::resolveAnchorDate($moveIn, $anchorModel, $interval, $intervalCount, $anchorDayOfMonth);
        $window = BillingMath::firstChargeWindow($moveIn, $anchor, $anchorDayOfMonth);

        if ($window === null) {
            $periodEnd = BillingMath::advancePeriod($moveIn, $interval, $intervalCount);

            return new FirstPeriodPlan(
                anchorDate: $anchor,
                windowStart: $moveIn,
                windowEnd: $periodEnd,
                billedThrough: $periodEnd,
                hasStub: false,
            );
        }

        return new FirstPeriodPlan(
            anchorDate: $anchor,
            windowStart: $window->start,
            windowEnd: $window->end,
            billedThrough: $anchor,
            hasStub: true,
            daysOccupied: $window->daysOccupied,
            daysInPeriod: $window->daysInPeriod,
        );
    }

    /**
     * The net amount one line item should be charged for the first period.
     * proration_method only bites when a stub actually exists — a full first
     * period is always charged in full regardless of the setting.
     */
    public static function firstPeriodNetForItem(
        FirstPeriodPlan $plan,
        string $itemAmount,
        ProrationMethod|string $prorationMethod,
    ): string {
        $method = $prorationMethod instanceof ProrationMethod ? $prorationMethod : ProrationMethod::from($prorationMethod);

        if (! $plan->hasStub || $method === ProrationMethod::FullPeriod) {
            return BillingMath::round2($itemAmount);
        }

        return BillingMath::prorate($itemAmount, $plan->daysOccupied, $plan->daysInPeriod);
    }

    /**
     * Resolution order at item creation: product's tax_rate_code -> active
     * version at $at -> org default -> null (0% — request may still override
     * with an explicit tax_rate_id upstream).
     */
    public static function resolveTaxRate(?string $productTaxRateCode, CarbonImmutable $at): ?TaxRate
    {
        if ($productTaxRateCode !== null) {
            $rate = TaxRate::query()
                ->activeForCode($productTaxRateCode, $at->toDateString())
                ->first();

            if ($rate !== null) {
                return $rate;
            }
        }

        return TaxRate::query()->current()->where('is_default', true)->first();
    }

    public static function billingSettingsSnapshot(BillingSettings $settings): array
    {
        return [
            'billing_interval'       => $settings->defaultBillingInterval,
            'billing_interval_count' => $settings->defaultBillingIntervalCount,
            'billing_anchor_model'   => $settings->billingAnchorModel,
            'proration_method'       => $settings->prorationMethod,
        ];
    }
}
