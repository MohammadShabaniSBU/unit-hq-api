<?php

declare(strict_types=1);

namespace App\Support\Advertising\Contracts;

use App\Support\Advertising\Results\SpendRow;
use Carbon\CarbonImmutable;

interface ReportsSpend
{
    /** @return iterable<SpendRow> */
    public function spend(CarbonImmutable $from, CarbonImmutable $to): iterable;
}
