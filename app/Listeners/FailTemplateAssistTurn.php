<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Ai\Agents\TemplateAssistantAgent;
use App\Enums\TemplateAssistTurnStatus;
use App\Events\TemplateAssistTurnFinished;
use App\Models\SystemEvent;
use App\Models\TemplateAssistTurn;
use App\Support\Ai\AiProviderRegistry;
use Illuminate\Queue\Events\JobFailed;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Jobs\BroadcastAgent;
use Throwable;

/**
 * Marks a running assist turn failed when its broadcast job dies.
 */
final class FailTemplateAssistTurn
{
    public function handle(JobFailed $event): void
    {
        try {
            $command = unserialize($event->job->payload()['data']['command'] ?? '');
        } catch (Throwable) {
            return;
        }

        if (! $command instanceof BroadcastAgent || ! $command->agent instanceof TemplateAssistantAgent) {
            return;
        }

        $turn = TemplateAssistTurn::query()->find($command->agent->turnId);
        if (! $turn instanceof TemplateAssistTurn || $turn->status !== TemplateAssistTurnStatus::Running) {
            return;
        }

        $errorKey = $this->errorKey($event->exception);
        $turn->status = TemplateAssistTurnStatus::Failed;
        $turn->error_key = $errorKey;
        $turn->save();

        event(new TemplateAssistTurnFinished(
            sessionId: (int) $turn->session_id,
            turnId: $turn->id,
            status: TemplateAssistTurnStatus::Failed->value,
        ));

        SystemEvent::record('template.assist.failed', null, [
            'turn_id' => $turn->id,
            'error_key' => $errorKey,
            'model' => app(AiProviderRegistry::class)->activeModel(),
        ]);
    }

    private function errorKey(Throwable $exception): string
    {
        if ($exception instanceof RateLimitedException) {
            return 'assist.budget';
        }

        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'assist.timeout';
        }

        return 'assist.provider_error';
    }
}
