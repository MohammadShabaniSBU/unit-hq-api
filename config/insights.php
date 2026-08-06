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

];
