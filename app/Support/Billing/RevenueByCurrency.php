<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\ChargeType;
use App\Models\Charge;

/**
 * Groups revenue charges by ISO currency. Never returns a cross-currency scalar total.
 */
final class RevenueByCurrency
{
    /**
     * @param  iterable<int, Charge|object>  $charges
     * @return array<string, string> currency => decimal string sum
     */
    public static function group(iterable $charges): array
    {
        $buckets = [];

        foreach ($charges as $charge) {
            $type = $charge->charge_type ?? null;

            if ($type instanceof ChargeType) {
                if (! $type->isRevenue()) {
                    continue;
                }
            } elseif (is_string($type)) {
                $enum = ChargeType::tryFrom($type);
                if ($enum === null || ! $enum->isRevenue()) {
                    continue;
                }
            } else {
                continue;
            }

            $currency = SupportedCurrencies::normalize((string) ($charge->currency ?? ''));

            if ($currency === '') {
                continue;
            }

            $amount = BillingMath::round2((string) ($charge->amount ?? '0'));
            $buckets[$currency] = isset($buckets[$currency])
                ? bcadd($buckets[$currency], $amount, 2)
                : $amount;
        }

        ksort($buckets);

        return $buckets;
    }
}
