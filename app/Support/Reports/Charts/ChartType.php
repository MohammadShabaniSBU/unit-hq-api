<?php

declare(strict_types=1);

namespace App\Support\Reports\Charts;

enum ChartType: string
{
    case Line = 'line';
    case Area = 'area';
    case Column = 'column';
    case StackedColumn = 'stacked_column';
    case StackedArea = 'stacked_area';
    case Bar = 'bar';
    case Combo = 'combo';
    case Donut = 'donut';
    case Heatmap = 'heatmap';
    case Funnel = 'funnel';
}
