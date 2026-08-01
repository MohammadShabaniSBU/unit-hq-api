<?php

declare(strict_types=1);

namespace App\Enums;

enum BillingRunItemOutcome: string
{
    case Billed = 'billed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
