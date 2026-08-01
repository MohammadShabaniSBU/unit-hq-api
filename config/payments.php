<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Payment request link TTL
    |--------------------------------------------------------------------------
    |
    | Default lifetime for payment_requests.expires_at when an operator creates
    | a "Request payment" link. Expiry is enforced at read time (invariant 13).
    |
    */

    'payment_request_ttl_days' => (int) env('PAYMENT_REQUEST_TTL_DAYS', 7),

];
