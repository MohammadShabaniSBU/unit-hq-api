<?php

declare(strict_types=1);

namespace App\Support\Reports\Charts;

enum ChartFormat: string
{
    case Int = 'int';
    case Percent = 'percent';
    case Money = 'money';
    case AreaM2 = 'area_m2';
    case Days = 'days';
}
