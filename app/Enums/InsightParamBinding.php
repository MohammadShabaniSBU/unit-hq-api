<?php

declare(strict_types=1);

namespace App\Enums;

enum InsightParamBinding: string
{
    case Locked = 'locked';
    case Default = 'default';
}
