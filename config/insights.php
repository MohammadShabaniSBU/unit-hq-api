<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | iframe host allowlist
    |--------------------------------------------------------------------------
    |
    | Comma-separated hosts permitted for the generic iframe analytics
    | provider. An empty list fails closed — every template is rejected.
    |
    */
    'iframe_host_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('INSIGHTS_IFRAME_HOST_ALLOWLIST', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Operator label locales
    |--------------------------------------------------------------------------
    |
    | Keys allowed in insight_reports.labels / description JSONB. At least one
    | locale must be present when an operator supplies labels.
    |
    */
    'locales' => ['en', 'es', 'fr'],

    /*
    |--------------------------------------------------------------------------
    | Embed token TTL
    |--------------------------------------------------------------------------
    |
    | Minutes until a minted Metabase JWT expires. Configurable downward only
    | (invariant 31): clamped to 1..10.
    |
    */
    'embed_ttl_minutes' => max(1, min(10, (int) env('INSIGHTS_EMBED_TTL_MINUTES', 10))),

    /*
    |--------------------------------------------------------------------------
    | Resource discovery cache
    |--------------------------------------------------------------------------
    |
    | Seconds to cache Metabase dashboard/card lists and param descriptors.
    | Keep short: operators edit the remote instance in another tab.
    |
    */
    'discovery_cache_seconds' => max(1, (int) env('INSIGHTS_DISCOVERY_CACHE_SECONDS', 60)),

];
