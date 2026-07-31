<?php

declare(strict_types=1);

namespace App\Enums;

enum TaxIdType: string
{
    case Nif = 'nif';
    case Nie = 'nie';
    case Siren = 'siren';
    case Siret = 'siret';
    case UkCrn = 'uk_crn';
    case Vat = 'vat';
    case Other = 'other';
}
