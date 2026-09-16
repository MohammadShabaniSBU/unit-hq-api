<?php

declare(strict_types=1);

namespace App\Support\Country;

use App\Support\Country\Validators\EsTaxIdValidator;

final class EsProfile implements CountryProfile
{
    public const CODE = 'ES';

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
        return 'es';
    }

    public function allowedTimezones(): array
    {
        return ['Europe/Madrid'];
    }

    public function schedulerTimezone(): string
    {
        return 'Europe/Madrid';
    }

    public function billingRunAt(): string
    {
        return '00:30';
    }

    public function taxSubdivisions(): array
    {
        return ['ES-CN'];
    }

    public function delinquencyFlavour(): string
    {
        return 'es';
    }

    public function fiscalRegime(): string
    {
        return 'es_verifactu';
    }

    public function paymentRails(): array
    {
        return ['stripe', 'sepa_dd', 'manual'];
    }

    public function taxIdValidator(): TaxIdValidator
    {
        return new EsTaxIdValidator;
    }
}
