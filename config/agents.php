<?php

declare(strict_types=1);

return [

    'default_model' => env('AGENTS_DEFAULT_MODEL', 'claude-sonnet-4-6'),

    'driver' => env('AGENTS_DRIVER', 'laravel'),

    'enabled' => filter_var(env('AGENTS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'demo_enabled' => env('AGENTS_DEMO_ENABLED', false),

    'max_turns' => 20,

    'max_tool_calls_per_turn' => 6,

    'turn_timeout_ms' => 60_000,

    'conversation_token_budget' => 200_000,

];
