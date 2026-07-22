<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a contract's billing_anchor_date is derived from move_in_date.
 *
 * anniversary: anchor = move_in. Every period is full length — no stub.
 * calendar: anchor = fixed day-of-month boundary. Off-boundary move-in
 *           produces a prorated stub period. Calendar is month-cadence only in v1.
 */
enum BillingAnchorModel: string
{
    case Anniversary = 'anniversary';
    case Calendar     = 'calendar';
}
