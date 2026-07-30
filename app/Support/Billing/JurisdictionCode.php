<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * tax_rates.jurisdiction: NULL (applies anywhere) or ISO 3166-1 alpha-2
 * with optional ISO 3166-2 subdivision (ES, ES-CN, FR).
 */
final class JurisdictionCode implements ValidationRule
{
    private const PATTERN = '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('The :attribute must be a null, an ISO 3166-1 alpha-2 country code, or an ISO 3166-2 subdivision (e.g. ES, ES-CN).');
        }
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return preg_match(self::PATTERN, $value) === 1;
    }
}
