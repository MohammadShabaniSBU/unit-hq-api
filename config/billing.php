<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Catch-up period cap
    |--------------------------------------------------------------------------
    |
    | Max periods generated per contract per billing run. Exceeding this fails
    | the contract for human review (CatchUpCapExceeded) rather than silently
    | generating hundreds of invoices from a corrupted cursor.
    |
    */

    'catch_up_cap' => (int) env('BILLING_CATCH_UP_CAP', 12),

    /*
    |--------------------------------------------------------------------------
    | Billing horizon (days)
    |--------------------------------------------------------------------------
    |
    | Bill periods whose start is within site-today + this many days.
    | 0 = bill on the day the period starts. Temporary home — S05-03 moves
    | this into BillingSettings.billing_horizon_days.
    |
    */

    'horizon_days' => (int) env('BILLING_HORIZON_DAYS', 0),

];
