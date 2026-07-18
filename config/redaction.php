<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | JSON keys nullified by contacts:redact
    |--------------------------------------------------------------------------
    |
    | Applied to activity_log.properties and system_events.payload for rows
    | whose subject (or nested reference) is the contact being redacted.
    |
    */

    'property_keys' => [
        'email',
        'phone',
        'value',
        'old.email',
        'old.phone',
        'old.value',
        'attributes.email',
        'attributes.phone',
        'attributes.value',
        'old.attributes.email',
        'old.attributes.phone',
        'old.attributes.value',
    ],

];
