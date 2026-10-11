<?php

declare(strict_types=1);

return [

    /*
    | Google developer token, OAuth client id, and client secret belong to the
    | Keevaris deployment. They are not operator credentials.
    */
    'google' => [
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_ADS_REDIRECT_URI'),
        'api_version' => env('GOOGLE_ADS_API_VERSION', 'v21'),
        'oauth_authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'oauth_token_url' => 'https://oauth2.googleapis.com/token',
        'api_base' => 'https://googleads.googleapis.com',
        'scope' => 'https://www.googleapis.com/auth/adwords',
    ],

    'meta' => [
        'api_version' => env('META_GRAPH_API_VERSION', 'v21.0'),
        'api_base' => 'https://graph.facebook.com',
    ],

    'http' => [
        'timeout' => (int) env('ADVERTISING_HTTP_TIMEOUT', 15),
        'retry' => 3,
        'retry_sleep_ms' => (int) env('ADVERTISING_HTTP_RETRY_SLEEP_MS', 200),
    ],

];
