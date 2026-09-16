<?php

declare(strict_types=1);

namespace App\Support\Country\Validators;

use App\Enums\TaxIdType;
use App\Support\Country\TaxIdValidator;
use App\Support\Fiscal\TaxId;

final class GbTaxIdValidator implements TaxIdValidator
{
    public function validate(string $value): bool
    {
        return TaxId::validate($value, TaxIdType::Vat->value);
    }

    public function hint(): string
    {
        return 'GB VAT';
    }
}
