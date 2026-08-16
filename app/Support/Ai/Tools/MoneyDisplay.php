<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Billing\BillingMath;
use App\Support\Billing\TaxBreakdown;

/**
 * Renders BillingMath decimal strings for tool display. Never casts to float.
 */
final class MoneyDisplay
{
    public static function format(string $amount, string $currency, string $locale): string
    {
        $normalized = BillingMath::round2($amount);
        $decimal = self::decimalSeparator($locale) === ','
            ? str_replace('.', ',', $normalized)
            : $normalized;

        return match (strtoupper($currency)) {
            'EUR' => '€'.$decimal,
            'GBP' => '£'.$decimal,
            'USD' => '$'.$decimal,
            default => strtoupper($currency).' '.$decimal,
        };
    }

    public static function withTax(TaxBreakdown $breakdown, string $currency, string $locale, string $ratePct): string
    {
        $gross = self::format($breakdown->gross, $currency, $locale);
        $net = self::format($breakdown->net, $currency, $locale);
        $rate = self::trimRate(BillingMath::round2($ratePct));
        $label = str_starts_with(strtolower($locale), 'es') ? 'IVA' : 'tax';

        return "{$gross} (incl. {$rate}% {$label}; net {$net})";
    }

    public static function decimalSeparator(string $locale): string
    {
        $base = strtolower(str_replace('_', '-', $locale));
        $base = explode('-', $base)[0];

        return in_array($base, ['es', 'fr'], true) ? ',' : '.';
    }

    public static function trimRate(string $rate): string
    {
        return rtrim(rtrim($rate, '0'), '.');
    }
}
