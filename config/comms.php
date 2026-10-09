<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Preference-center HMAC key
    |--------------------------------------------------------------------------
    |
    | Signs PreferenceToken links. Empty falls back to app.key inside
    | PreferenceToken. Unsubscribe links do not expire, so rotate by
    | accepting a new token version rather than by dropping this key.
    |
    */
    'preference_token_key' => env('COMMS_PREFERENCE_TOKEN_KEY'),
];
