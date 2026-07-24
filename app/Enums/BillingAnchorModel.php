<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a contract's billing_anchor_date is derived from move_in_date.
 *
 * anniversary:    anchor = move_in. Every period is full length — no stub.
 * calendar:       anchor = fixed day-of-month boundary. Requires monthly cadence.
 * calendar_week:  anchor = fixed ISO weekday boundary (1=Mon..7=Sun). Requires weekly cadence.
 *
 * Off-boundary move-in on either calendar model produces a prorated stub period.
 */
enum BillingAnchorModel: string
{
    case Anniversary  = 'anniversary';
    case Calendar     = 'calendar';
    case CalendarWeek = 'calendar_week';
}
