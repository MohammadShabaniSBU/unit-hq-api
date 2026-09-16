<?php

declare(strict_types=1);

namespace App\Support\Country;

use App\Support\Country\Validators\GbTaxIdValidator;

final class GbProfile implements CountryProfile
{
    public const CODE = 'GB';

    public function code(): string
    {
        return self::CODE;
    }

    public function currency(): string
    {
        return 'GBP';
    }

    public function defaultLocale(): string
    {
        return 'en';
    }

    public function allowedTimezones(): array
    {
        return ['Europe/London'];
    }

    public function schedulerTimezone(): string
    {
        return 'Europe/London';
    }

    public function billingRunAt(): string
    {
        return '00:30';
    }

    public function taxSubdivisions(): array
    {
        return [];
    }

    public function delinquencyFlavour(): string
    {
        return 'gb';
    }

    public function fiscalRegime(): string
    {
        return 'none';
    }

    public function paymentRails(): array
    {
        return ['stripe', 'manual'];
    }

    public function taxIdValidator(): TaxIdValidator
    {
        return new GbTaxIdValidator;
    }
}
