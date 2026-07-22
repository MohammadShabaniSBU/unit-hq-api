<?php

declare(strict_types=1);

namespace App\Enums;

enum BillingInterval: string
{
    case Day   = 'day';
    case Week  = 'week';
    case Month = 'month';
}
