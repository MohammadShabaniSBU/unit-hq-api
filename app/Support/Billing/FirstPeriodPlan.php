<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Carbon\CarbonImmutable;

/**
 * Contract-level first-period plan, computed once and shared across every
 * line item's charge (see ContractBilling::planFirstPeriod). Tax and rate
 * differ per item; the window/anchor/billed_through do not.
 */
final readonly class FirstPeriodPlan
{
    public function __construct(
        public CarbonImmutable $anchorDate,
        public CarbonImmutable $windowStart,
        public CarbonImmutable $windowEnd,
        public CarbonImmutable $billedThrough,
        public bool $hasStub,
        public ?int $daysOccupied = null,
        public ?int $daysInPeriod = null,
    ) {}
}
