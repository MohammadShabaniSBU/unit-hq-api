<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | JSON keys nullified by contacts:redact
    |--------------------------------------------------------------------------
    |
    | Applied to activity_log.properties, system_events.payload, and
    | automation_run_steps.input/output + automation_runs.trigger_payload
    | for rows whose subject is the contact being redacted.
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
        'to',
        'subject',
        'trigger_payload.attributes.email',
        'trigger_payload.attributes.phone',
        'input.to',
        'input.subject',
        'output.to',
        'output.subject',
    ],

];
