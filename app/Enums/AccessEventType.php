<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessEventType: string
{
    case Granted = 'granted';
    case Denied = 'denied';
}
