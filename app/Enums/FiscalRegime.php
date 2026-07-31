<?php

declare(strict_types=1);

namespace App\Enums;

enum FiscalRegime: string
{
    case None = 'none';
    case Verifactu = 'verifactu';
    case NoVerificable = 'no_verificable';
    case Ticketbai = 'ticketbai';
    case Sii = 'sii';
}
