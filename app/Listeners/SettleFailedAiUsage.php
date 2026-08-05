<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AiUsageEvent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Jobs\BroadcastAgent;
use Throwable;

final class SettleFailedAiUsage
{
    public function handle(JobFailed $event): void
    {
        try {
            $command = unserialize($event->job->payload()['data']['command'] ?? '');
        } catch (Throwable) {
            return;
        }

        if (! $command instanceof BroadcastAgent) {
            return;
        }

        $callId = Context::get('ai_call_id') ?? $command->invocationId;

        AiUsageEvent::markFailed(is_string($callId) ? $callId : null);
    }
}
