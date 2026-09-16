<?php

declare(strict_types=1);

namespace App\Support\Country;

interface CountryProfile
{
    public function code(): string;

    public function currency(): string;

    public function defaultLocale(): string;

    /** @return Array<int, string> */
    public function allowedTimezones(): array;

    public function schedulerTimezone(): string;

    /** Local civil time, HH:MM, on or after midnight in every allowed timezone. */
    public function billingRunAt(): string;

    /** @return Array<int, string> */
    public function taxSubdivisions(): array;

    public function delinquencyFlavour(): string;

    public function fiscalRegime(): string;

    /** @return Array<int, string> */
    public function paymentRails(): array;

    public function taxIdValidator(): TaxIdValidator;
}
