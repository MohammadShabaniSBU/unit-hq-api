<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Carbon\CarbonImmutable;

/**
 * The stub period a contract's first charge covers, when move-in doesn't land
 * exactly on the billing anchor. Null from BillingMath::firstChargeWindow()
 * means "no stub — bill a full period".
 */
final readonly class FirstChargeWindow
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $daysOccupied,
        public int $daysInPeriod,
    ) {}
}
