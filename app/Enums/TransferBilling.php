<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferBilling: string
{
    case ProrateImmediately = 'prorate_immediately';
    case NextPeriod = 'next_period';
}
