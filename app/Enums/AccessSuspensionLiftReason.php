<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessSuspensionLiftReason: string
{
    case Cure = 'cure';
    case Manual = 'manual';
    case Vacated = 'vacated';
}
