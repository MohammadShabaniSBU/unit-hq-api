<?php

declare(strict_types=1);

namespace App\Support\Billing\Exceptions;

final class UnsupportedCadence extends BillingException
{
    public static function forCalendarIntervalCount(int $intervalCount): self
    {
        return new self(
            "Calendar / calendar_week anchor models do not support interval_count > 1 (got {$intervalCount})."
        );
    }
}
