<?php

declare(strict_types=1);

return [

    'default_model' => env('AGENTS_DEFAULT_MODEL', 'claude-sonnet-4-6'),

    'driver' => env('AGENTS_DRIVER', 'laravel'),

    'enabled' => filter_var(env('AGENTS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'demo_enabled' => env('AGENTS_DEMO_ENABLED', false),

    'max_turns' => 20,

    'max_tool_calls_per_turn' => 6,

    'max_tool_retries' => 2,

    'turn_timeout_ms' => 60_000,

    'conversation_token_budget' => 200_000,

    'conversation_call_budget' => 40,

    'pending_action_ttl_minutes' => (int) env('AGENTS_PENDING_ACTION_TTL_MINUTES', 120),

    'inbound_debounce_seconds' => (int) env('AGENTS_INBOUND_DEBOUNCE_SECONDS', 20),

    'channel' => [
        'sms' => [
            'warn_segments' => 3,
            'max_segments' => 5,
            'max_redraft_attempts' => 2,
        ],
        'voice' => [
            'turn_timeout_ms' => 15_000,
            'max_redraft_attempts' => 1,
        ],
    ],

    // Resize from measured p95 × arrival. Voice is reserved; batch cannot spend it.
    'provider_rate_per_minute' => [
        'voice' => (int) env('AGENTS_PROVIDER_RATE_VOICE_PER_MINUTE', 30),
        'batch' => (int) env('AGENTS_PROVIDER_RATE_BATCH_PER_MINUTE', 20),
    ],

    'context' => [
        'recent_turns' => (int) env('AGENTS_CONTEXT_RECENT_TURNS', 4),
        'max_history_chars' => (int) env('AGENTS_CONTEXT_MAX_HISTORY_CHARS', 6_000),
    ],

    'verification' => [
        'ttl_minutes' => 10,
        'code_length' => 6,
        'max_attempts' => 5,
        'max_issued_per_hour' => 3,
    ],

    'voice' => [
        // One number, one delegation every 10–20s; three concurrent calls ≈ 9–18/min.
        // Budget ~8 concurrent calls at the 10s floor (≈48/min) plus retry slack.
        // 20/min saturates at three concurrent calls.
        'bridge_rate_per_minute' => (int) env('AGENTS_VOICE_BRIDGE_RATE_PER_MINUTE', 60),
        'bridge_secret_header' => env('AGENTS_VOICE_BRIDGE_SECRET_HEADER', 'X-Voice-Bridge-Secret'),
        // Vocal Bridge dashboard values paired with channel.voice.turn_timeout_ms.
        'late_response_behavior' => 'store',
        'bridge_query_timeout_s' => 10,
        'handoff_sentence' => 'Let me put you through to someone who can help.',
        'voicemail_sentence' => 'The office is closed. I will put you through to voicemail.',
        'apology_sentence' => 'I am sorry, I cannot connect you right now. Please try again later.',
        'approved_destinations' => ['main_line', 'voicemail'],
        'outside_hours_destination' => 'voicemail',
        'reason_destinations' => [
            'legal_or_complaint' => 'main_line',
            'delinquency' => 'main_line',
            'price_negotiation' => 'main_line',
            'verification_required' => 'main_line',
            'unsupported_intent' => 'main_line',
            'grounding_failure' => 'main_line',
            'repeated_failure' => 'main_line',
            'customer_requested' => 'main_line',
            'out_of_hours' => 'voicemail',
            'budget_exceeded' => 'main_line',
            'turn_limit' => 'main_line',
            'error' => 'main_line',
            'channel_constraint' => 'main_line',
        ],
    ],

];
