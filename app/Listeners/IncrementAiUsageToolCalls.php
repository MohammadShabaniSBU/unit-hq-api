<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AiUsageEvent;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\ToolInvoked;

final class IncrementAiUsageToolCalls
{
    public function handle(ToolInvoked $event): void
    {
        $callId = Context::get('ai_call_id') ?? $event->invocationId;

        AiUsageEvent::incrementToolCalls(is_string($callId) ? $callId : null);
    }
}
