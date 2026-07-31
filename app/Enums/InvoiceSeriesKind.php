<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceSeriesKind: string
{
    case Ordinary = 'ordinary';
    case Simplified = 'simplified';
    case Rectificative = 'rectificative';
}
