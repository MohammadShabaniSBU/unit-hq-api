<?php

declare(strict_types=1);

namespace App\Support\Country\Validators;

use App\Enums\TaxIdType;
use App\Support\Country\TaxIdValidator;
use App\Support\Fiscal\TaxId;

final class EsTaxIdValidator implements TaxIdValidator
{
    public function validate(string $value): bool
    {
        return TaxId::validate($value, TaxIdType::Nif->value);
    }

    public function hint(): string
    {
        return 'NIF / CIF';
    }
}
