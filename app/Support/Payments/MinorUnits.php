<?php

declare(strict_types=1);

namespace App\Support\Payments;

use InvalidArgumentException;

/**
 * Stripe amounts are integer minor units; the ledger is NUMERIC(10,2) strings.
 * One conversion pair used everywhere — never a stray float * 100.
 */
final class MinorUnits
{
    /**
     * ISO 4217 currencies with zero decimal places (Stripe zero-decimal list subset).
     *
     * @var list<string>
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public static function toMinor(string $amount, string $currency): int
    {
        $currency = strtoupper($currency);
        $amount = self::normalizeAmount($amount);

        if (self::isZeroDecimal($currency)) {
            $whole = bcdiv($amount, '1', 0);
            if (bccomp($amount, bcadd($whole, '0', 2), 2) !== 0) {
                throw new InvalidArgumentException(
                    "Amount {$amount} is not a whole number for zero-decimal currency {$currency}."
                );
            }

            return (int) $whole;
        }

        return (int) bcmul($amount, '100', 0);
    }

    public static function fromMinor(int $minor, string $currency): string
    {
        $currency = strtoupper($currency);

        if ($minor < 0) {
            throw new InvalidArgumentException('Minor units cannot be negative.');
        }

        if (self::isZeroDecimal($currency)) {
            return bcadd((string) $minor, '0', 2);
        }

        return bcdiv((string) $minor, '100', 2);
    }

    public static function isZeroDecimal(string $currency): bool
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true);
    }

    private static function normalizeAmount(string $amount): string
    {
        $amount = trim($amount);

        if ($amount === '' || ! is_numeric($amount)) {
            throw new InvalidArgumentException("Invalid money amount: {$amount}");
        }

        $normalized = bcadd($amount, '0', 2);

        if (bccomp($normalized, '0', 2) < 0) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        return $normalized;
    }
}
