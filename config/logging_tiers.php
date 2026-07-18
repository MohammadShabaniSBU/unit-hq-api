<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tier 1 (system_events) retention
    |--------------------------------------------------------------------------
    |
    | Rows older than this many days are dropped by system-events:maintain
    | (partition drop on Postgres, DELETE on SQLite).
    |
    */

    'tier1_retention_days' => (int) env('SYSTEM_EVENTS_RETENTION_DAYS', 90),

];
