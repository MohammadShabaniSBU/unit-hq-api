<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

use App\Models\Site;
use App\Models\TaxRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Jurisdiction-aware tax resolution (S03-05 / D2).
 *
 * Order: explicit override → product code (prefer site country, else NULL,
 * else 422) → org default code with the same preference → null (0%).
 */
final class TaxResolver
{
    public static function resolve(
        ?int $overrideId,
        ?string $code,
        Site $site,
        CarbonImmutable|string|null $at = null,
    ): ?TaxRate {
        $atDate = self::normalizeDate($at);

        if ($overrideId !== null) {
            $rate = TaxRate::query()->find($overrideId);

            if ($rate !== null) {
                $site->loadMissing('country');

                Log::info('tax.override', [
                    'tax_rate_id' => $rate->id,
                    'site_id' => $site->id,
                    'country_code' => $site->country?->code,
                ]);
            }

            return $rate;
        }

        if ($code !== null && $code !== '') {
            return self::resolveForCode($code, $site, $atDate);
        }

        $default = TaxRate::query()->current()->where('is_default', true)->first();

        if ($default === null) {
            return null;
        }

        return self::resolveForCode($default->code, $site, $atDate);
    }

    private static function resolveForCode(string $code, Site $site, string $atDate): TaxRate
    {
        $site->loadMissing('country');
        $countryCode = $site->country?->code;

        $candidates = TaxRate::query()
            ->activeForCode($code, $atDate)
            ->get();

        if ($countryCode !== null) {
            $match = $candidates->first(
                fn (TaxRate $rate) => $rate->jurisdiction === $countryCode
            );

            if ($match !== null) {
                return $match;
            }
        }

        $universal = $candidates->first(
            fn (TaxRate $rate) => $rate->jurisdiction === null
        );

        if ($universal !== null) {
            return $universal;
        }

        throw ValidationException::withMessages([
            'tax_rate' => [__('errors.tax.unresolvable_for_jurisdiction', [
                'code' => $code,
                'jurisdiction' => $countryCode ?? 'unknown',
            ])],
        ]);
    }

    private static function normalizeDate(CarbonImmutable|string|null $at): string
    {
        if ($at instanceof CarbonImmutable) {
            return $at->toDateString();
        }

        if (is_string($at) && $at !== '') {
            return CarbonImmutable::parse($at)->toDateString();
        }

        return CarbonImmutable::today()->toDateString();
    }
}
