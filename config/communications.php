<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Status callback URL (Twilio)
    |--------------------------------------------------------------------------
    |
    | Twilio reads a status callback per message / messaging service. When null,
    | adapters fall back to PublicUrlGuard::webhookUrl() with the account token.
    |
    */
    'status_callback_url' => env('COMMUNICATIONS_STATUS_CALLBACK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Public base URL for webhook registration
    |--------------------------------------------------------------------------
    |
    | Used by the webhook-creation guard. Defaults to APP_URL. Localhost /
    | private addresses are refused so operators never register a dead endpoint.
    |
    */
    'public_base_url' => env('COMMUNICATIONS_PUBLIC_BASE_URL', env('APP_URL')),
];
