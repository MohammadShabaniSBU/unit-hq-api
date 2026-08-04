<?php

declare(strict_types=1);

namespace App\Enums;

enum ContractItemChangeReason: string
{
    case RateChange = 'rate_change';
    case Transfer = 'transfer';
    case Correction = 'correction';
    case DiscountRemoved = 'discount_removed';
}
