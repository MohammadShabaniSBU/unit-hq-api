<?php

declare(strict_types=1);

namespace App\Support\Reports\Charts;

enum ChartSeriesKind: string
{
    case Column = 'column';
    case Line = 'line';
    case Area = 'area';
}
