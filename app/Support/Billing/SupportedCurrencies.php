<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Illuminate\Validation\Rule;

/**
 * Allowlisted ISO 4217 codes for price / settings writes.
 * Expand when a country is onboarded — never accept free-form three-char codes
 * (prices are immutable; a typo cannot be fixed in place).
 */
final class SupportedCurrencies
{
    /** @return list<string> */
    public static function codes(): array
    {
        return ['EUR', 'GBP'];
    }

    public static function normalize(string $currency): string
    {
        return strtoupper(trim($currency));
    }

    public static function isAllowed(string $currency): bool
    {
        return in_array(self::normalize($currency), self::codes(), true);
    }

    /**
     * @return list<\Illuminate\Contracts\Validation\ValidationRule|string>
     */
    public static function rules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            Rule::in(self::codes()),
        ];
    }
}
