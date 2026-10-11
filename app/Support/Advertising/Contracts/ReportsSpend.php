<?php

declare(strict_types=1);

namespace App\Support\Advertising\Contracts;

use App\Support\Advertising\Results\SpendRow;
use Carbon\CarbonImmutable;

interface ReportsSpend
{
    /**
     * Currency the platform reports now. Compared with the currency stored
     * at verify. A mismatch fails the spend sync before any row is written.
     */
    public function reportedCurrency(): string;

    /** @return iterable<SpendRow> */
    public function spend(CarbonImmutable $from, CarbonImmutable $to): iterable;
}
