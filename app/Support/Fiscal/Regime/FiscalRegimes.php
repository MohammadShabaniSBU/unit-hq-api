<?php

declare(strict_types=1);

namespace App\Support\Fiscal\Regime;

use App\Support\Country\CountryProfiles;

final class FiscalRegimes
{
    public static function current(): FiscalRegimeAdapter
    {
        return match (CountryProfiles::current()->fiscalRegime()) {
            'es_verifactu' => new EsVerifactuAdapter,
            'fr_einvoicing' => new FrEinvoicingAdapter,
            default => new NoneAdapter,
        };
    }
}
