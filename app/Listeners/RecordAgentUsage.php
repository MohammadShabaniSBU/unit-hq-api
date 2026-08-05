<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AiUsageEvent;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Settles the reserved usage row when an agent turn completes successfully.
 *
 * Prefer Context ai_call_id over $event->invocationId — BroadcastAgent rewrites
 * broadcast event ids but stream/prompt paths mint their own invocation ids.
 */
final class RecordAgentUsage
{
    public function handle(AgentPrompted|AgentStreamed $event): void
    {
        $callId = Context::get('ai_call_id') ?? $event->invocationId;
        $usage = $event->response->usage ?? new Usage;
        $meta = $event->response->meta ?? null;

        AiUsageEvent::settle(
            callId: is_string($callId) ? $callId : null,
            usage: $usage,
            status: AiUsageEvent::STATUS_OK,
            provider: $meta?->provider,
            model: $meta?->model,
            raw: $usage,
        );
    }
}
