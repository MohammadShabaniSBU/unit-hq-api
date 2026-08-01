<?php

declare(strict_types=1);

namespace App\Enums;

enum BillingRunTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case Retry = 'retry';
}
