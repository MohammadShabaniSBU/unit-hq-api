<?php

declare(strict_types=1);

namespace App\Enums;

enum DepositPayoutStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case NotApplicable = 'not_applicable';
}
