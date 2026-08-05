<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Models\AiUsageEvent;
use Closure;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * Reserves an ai_usage_events row before the provider call.
 * Settlement happens in event listeners (RecordAgentUsage et al.).
 */
class MetersUsage
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        AiUsageEvent::reserve(
            callId: Context::get('ai_call_id'),
            employeeId: Context::get('employee_id') !== null
                ? (int) Context::get('employee_id')
                : null,
            conversationId: Context::get('conversation_id'),
            purpose: Context::get('ai_purpose', 'copilot') ?? 'copilot',
            requestId: Context::get('request_id'),
        );

        return $next($prompt);
    }
}
