<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferPricingMode: string
{
    case DestinationRate = 'destination_rate';
    case RetainRate = 'retain_rate';
}
