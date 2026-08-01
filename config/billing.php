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

];
