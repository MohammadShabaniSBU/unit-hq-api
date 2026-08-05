<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AiUsageEvent;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentFailedOver;

/**
 * Terminalise the abandoned failover leg, then mint a new call_id so the
 * retry leg reserves a separate row when MetersUsage runs again.
 */
final class RecordAgentFailoverUsage
{
    public function handle(AgentFailedOver $event): void
    {
        $callId = Context::get('ai_call_id');

        AiUsageEvent::markFailedOver(
            callId: is_string($callId) ? $callId : null,
            provider: $event->provider->name(),
            model: $event->model,
        );

        Context::add('ai_call_id', (string) Str::uuid7());
    }
}
