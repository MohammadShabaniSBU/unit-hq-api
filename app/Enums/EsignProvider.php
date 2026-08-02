<?php

declare(strict_types=1);

namespace App\Enums;

enum EsignProvider: string
{
    case Signable = 'signable';

    public function label(): string
    {
        return match ($this) {
            self::Signable => 'Signable',
        };
    }
}
