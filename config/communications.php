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

    /*
    |--------------------------------------------------------------------------
    | Inbound attachment caps
    |--------------------------------------------------------------------------
    |
    | Over-cap attachments are stored as stub rows (oversize = true) with
    | content dropped — honest in the Inbox UI.
    |
    */
    'inbound' => [
        'max_attachment_bytes' => (int) env('COMMS_INBOUND_MAX_ATTACHMENT_BYTES', 10 * 1024 * 1024),
        'max_total_attachment_bytes' => (int) env('COMMS_INBOUND_MAX_TOTAL_ATTACHMENT_BYTES', 25 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Staged outbound attachment orphans
    |--------------------------------------------------------------------------
    |
    | Uploads created via POST /api/inbox/attachments with message_id null.
    | Linked on send; anything older than this TTL is swept daily.
    |
    */
    'staging' => [
        'orphan_ttl_hours' => (int) env('COMMS_STAGING_ORPHAN_TTL_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS STOP keywords
    |--------------------------------------------------------------------------
    |
    | Exact match (case-insensitive, whitespace-collapsed) against inbound SMS
    | body. Match → sms scope=all suppression. No auto-acknowledgement is sent.
    |
    */
    'stop_keywords' => [
        'STOP',
        'BAJA',
        'STOP TODO',
    ],
];

