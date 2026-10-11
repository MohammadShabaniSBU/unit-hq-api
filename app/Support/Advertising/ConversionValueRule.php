<?php

declare(strict_types=1);

namespace App\Support\Advertising;

enum ConversionValueRule: string
{
    case None = 'none';
    case FirstPeriodNet = 'first_period_net';
}
