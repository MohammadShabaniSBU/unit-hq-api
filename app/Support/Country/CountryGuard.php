<?php

declare(strict_types=1);

namespace App\Support\Country;

use App\Support\Billing\SupportedCurrencies;
use App\Support\RecordsActivity;
use Illuminate\Validation\ValidationException;

/**
 * Write-time guards that refuse data contradicting the deployment country.
 * All country-code decisions stay in this namespace (invariant 73).
 */
final class CountryGuard
{
    public static function assertSiteCountryId(?int $countryId): int
    {
        $expected = CountryProfiles::countryId();

        if ($countryId === null || $countryId === $expected) {
            return $expected;
        }

        throw ValidationException::withMessages([
            'country_id' => [__('errors.deployment.site_country_mismatch')],
        ]);
    }

    public static function assertSiteTimezone(string $timezone): void
    {
        if (! in_array($timezone, CountryProfiles::current()->allowedTimezones(), true)) {
            throw ValidationException::withMessages([
                'timezone' => [__('errors.deployment.site_timezone_not_allowed')],
            ]);
        }
    }

    public static function assertLegalEntityCountry(?string $countryCode): string
    {
        $expected = CountryProfiles::current()->code();
        $normalized = $countryCode === null || $countryCode === ''
            ? $expected
            : strtoupper($countryCode);

        if ($normalized !== $expected) {
            throw ValidationException::withMessages([
                'country_code' => [__('errors.deployment.legal_entity_country_mismatch')],
            ]);
        }

        return $expected;
    }

    public static function assertLegalEntityTaxId(string $taxId): void
    {
        if (! CountryProfiles::current()->taxIdValidator()->validate($taxId)) {
            throw ValidationException::withMessages([
                'tax_id' => [__('errors.deployment.invalid_tax_id', [
                    'hint' => CountryProfiles::current()->taxIdValidator()->hint(),
                ])],
            ]);
        }
    }

    public static function assertTaxJurisdiction(?string $jurisdiction): void
    {
        if ($jurisdiction === null || $jurisdiction === '') {
            return;
        }

        $profile = CountryProfiles::current();
        $allowed = array_merge([$profile->code()], $profile->taxSubdivisions());

        if (! in_array($jurisdiction, $allowed, true)) {
            throw ValidationException::withMessages([
                'jurisdiction' => [__('errors.deployment.tax_jurisdiction_outside_country')],
            ]);
        }
    }

    public static function assertDefaultCurrency(string $currency): void
    {
        $normalized = SupportedCurrencies::normalize($currency);
        if ($normalized !== CountryProfiles::current()->currency()) {
            throw ValidationException::withMessages([
                'default_currency' => [__('errors.deployment.default_currency_outside_country')],
            ]);
        }
    }

    public static function assertSiteCurrency(?string $currency): void
    {
        if ($currency === null || $currency === '') {
            return;
        }

        $normalized = SupportedCurrencies::normalize($currency);
        if ($normalized !== CountryProfiles::current()->currency()) {
            throw ValidationException::withMessages([
                'currency' => [__('errors.deployment.site_currency_outside_country')],
            ]);
        }
    }

    public static function assertPriceCurrency(string $currency, bool $allowMismatch, mixed $causer = null): void
    {
        $normalized = SupportedCurrencies::normalize($currency);
        $country = CountryProfiles::current()->currency();

        if ($normalized === $country) {
            return;
        }

        if (! $allowMismatch) {
            throw ValidationException::withMessages([
                'currency' => [__('errors.deployment.price_currency_outside_country')],
            ]);
        }

        RecordsActivity::core('price.currency_mismatch_allowed', null, [
            'currency' => $normalized,
            'country_currency' => $country,
        ], $causer instanceof \Illuminate\Database\Eloquent\Model ? $causer : null);
    }

    public static function assertDelinquencyJurisdiction(?string $jurisdiction): void
    {
        if ($jurisdiction === null || $jurisdiction === '') {
            return;
        }

        if (strtoupper($jurisdiction) !== CountryProfiles::current()->code()) {
            throw ValidationException::withMessages([
                'delinquency_policy_id' => [__('errors.deployment.delinquency_policy_outside_country')],
            ]);
        }
    }

    public static function assertPaymentRail(string $rail): void
    {
        if (! in_array($rail, CountryProfiles::current()->paymentRails(), true)) {
            throw ValidationException::withMessages([
                'rail' => [__('errors.deployment.payment_rail_not_available')],
            ]);
        }
    }

    public static function allowsPaymentRail(string $rail): bool
    {
        return in_array($rail, CountryProfiles::current()->paymentRails(), true);
    }
}
