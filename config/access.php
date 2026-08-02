<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Stuck grant threshold
    |--------------------------------------------------------------------------
    |
    | applying / revoking rows older than this many seconds are retried by the
    | reconciliation engine (nudges are latency; the schedule is truth).
    |
    */

    'stuck_threshold_seconds' => (int) env('ACCESS_STUCK_THRESHOLD_SECONDS', 300),

];
