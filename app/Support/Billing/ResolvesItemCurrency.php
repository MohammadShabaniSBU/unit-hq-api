<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Insurance;
use App\Models\InsuranceRate;
use App\Models\Price;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\UnitClassRate;

/**
 * Resolve the currency to snapshot onto a contract item at signing.
 * Prefer the price row behind the item; fall back to org default.
 */
final class ResolvesItemCurrency
{
    public static function forItem(string $itemType, int $itemId, ?int $priceId = null, ?int $siteId = null): string
    {
        if ($priceId !== null) {
            $priceCurrency = Price::query()->whereKey($priceId)->value('currency');
            if (is_string($priceCurrency) && $priceCurrency !== '') {
                return SupportedCurrencies::normalize($priceCurrency);
            }
        }

        return match ($itemType) {
            'unit' => self::forUnit($itemId, $siteId),
            'insurance' => self::forInsurance($itemId, $siteId),
            default => SupportedCurrencies::normalize((string) Setting::billing()->defaultCurrency),
        };
    }

    private static function forUnit(int $unitId, ?int $siteId): string
    {
        $unit = Unit::query()->with('unitClass')->find($unitId);

        if ($unit === null) {
            return SupportedCurrencies::normalize((string) Setting::billing()->defaultCurrency);
        }

        $resolvedSiteId = $siteId ?? $unit->site_id;

        $ratePriceCurrency = UnitClassRate::query()
            ->where('unit_class_id', $unit->unit_class_id)
            ->where('site_id', $resolvedSiteId)
            ->latest('id')
            ->with('price')
            ->first()
            ?->price
            ?->currency;

        if (is_string($ratePriceCurrency) && $ratePriceCurrency !== '') {
            return SupportedCurrencies::normalize($ratePriceCurrency);
        }

        $currentPriceCurrency = Price::query()
            ->whereKey($unit->unitClass?->current_price_id)
            ->value('currency');

        if (is_string($currentPriceCurrency) && $currentPriceCurrency !== '') {
            return SupportedCurrencies::normalize($currentPriceCurrency);
        }

        return SupportedCurrencies::normalize((string) Setting::billing()->defaultCurrency);
    }

    private static function forInsurance(int $insuranceId, ?int $siteId): string
    {
        if ($siteId !== null) {
            $ratePriceCurrency = InsuranceRate::query()
                ->where('insurance_id', $insuranceId)
                ->where('site_id', $siteId)
                ->latest('id')
                ->with('price')
                ->first()
                ?->price
                ?->currency;

            if (is_string($ratePriceCurrency) && $ratePriceCurrency !== '') {
                return SupportedCurrencies::normalize($ratePriceCurrency);
            }
        }

        $insuranceCurrency = Insurance::query()->whereKey($insuranceId)->value('currency');

        if (is_string($insuranceCurrency) && $insuranceCurrency !== '') {
            return SupportedCurrencies::normalize($insuranceCurrency);
        }

        return SupportedCurrencies::normalize((string) Setting::billing()->defaultCurrency);
    }
}
