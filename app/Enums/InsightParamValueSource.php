<?php

declare(strict_types=1);

namespace App\Enums;

enum InsightParamValueSource: string
{
    case Static = 'static';
    case Dynamic = 'dynamic';
}
