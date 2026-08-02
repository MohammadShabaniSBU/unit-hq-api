<?php

declare(strict_types=1);

namespace App\Support\Reports;

enum ReportColumnType: string
{
    case Money = 'money';
    case Percent = 'percent';
    case Int = 'int';
    case Date = 'date';
    case String = 'string';
}
