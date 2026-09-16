<?php

declare(strict_types=1);

namespace App\Support\Country;

use App\Support\Country\Validators\FrTaxIdValidator;

final class FrProfile implements CountryProfile
{
    public const CODE = 'FR';

    public function code(): string
    {
        return self::CODE;
    }

    public function currency(): string
    {
        return 'EUR';
    }

    public function defaultLocale(): string
    {
        return 'fr';
    }

    public function allowedTimezones(): array
    {
        return ['Europe/Paris'];
    }

    public function schedulerTimezone(): string
    {
        return 'Europe/Paris';
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
        return 'fr';
    }

    public function fiscalRegime(): string
    {
        return 'fr_einvoicing';
    }

    public function paymentRails(): array
    {
        return ['stripe', 'sepa_dd', 'manual'];
    }

    public function taxIdValidator(): TaxIdValidator
    {
        return new FrTaxIdValidator;
    }
}
