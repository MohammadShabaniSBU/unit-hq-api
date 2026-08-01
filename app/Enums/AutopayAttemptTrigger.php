<?php

declare(strict_types=1);

namespace App\Enums;

enum AutopayAttemptTrigger: string
{
    case BillingRun = 'billing_run';
    case Sweep = 'sweep';
    case Manual = 'manual';
}
