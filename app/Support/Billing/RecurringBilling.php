<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Contract;
use Carbon\CarbonImmutable;

/**
 * Per-period charge + invoice generation for the recurring billing job.
 *
 * S05-01 ships a no-op stub so the run engine can exercise eligibility,
 * locking, cursor advance, and failure isolation. S05-02 replaces the body.
 */
final class RecurringBilling
{
    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $window
     */
    public static function generatePeriod(Contract $contract, array $window): PeriodResult
    {
        return PeriodResult::empty();
    }
}
