<?php

declare(strict_types=1);

namespace App\Support\Discounts;

use App\Models\Discount;
use App\Models\Site;
use App\Support\Communications\SiteLocale;

/**
 * Locale-resolved customer-facing terms for agent-offerable catalogue rows.
 */
final class DiscountTerms
{
    public static function resolve(Discount $discount, ?string $principalLocale, ?Site $site): ?string
    {
        $terms = $discount->customer_terms;
        if (! is_array($terms) || $terms === []) {
            return null;
        }

        $ladder = [];
        if ($principalLocale !== null && $principalLocale !== '') {
            $ladder[] = DiscountSurface::normalizeLocale($principalLocale);
        }
        $ladder[] = SiteLocale::for($site);
        $ladder[] = 'en';

        foreach (array_unique($ladder) as $locale) {
            $value = $terms[$locale] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
