<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the first (possibly partial) billing period is charged.
 *
 * daily: calendar-days proration of the stub window.
 * full_period: charge a full period amount even for a stub window.
 * none: skip the first-period charge entirely; billed_through advances
 *       to the anchor and the first real charge waits for the recurring job.
 */
enum ProrationMethod: string
{
    case Daily      = 'daily';
    case FullPeriod = 'full_period';
    case None       = 'none';
}
