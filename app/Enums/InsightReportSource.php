<?php

declare(strict_types=1);

namespace App\Enums;

enum InsightReportSource: string
{
    case Native = 'native';
    case Embedded = 'embedded';
}
