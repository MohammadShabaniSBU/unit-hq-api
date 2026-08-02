<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessSuspensionReason: string
{
    case Delinquency = 'delinquency';
    case Manual = 'manual';
}
