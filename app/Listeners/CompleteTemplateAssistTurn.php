<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Ai\Agents\TemplateAssistantAgent;
use App\Enums\TemplateAssistTurnStatus;
use App\Events\TemplateAssistTurnFinished;
use App\Models\TemplateAssistTurn;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;

/**
 * Marks a running assist turn completed when the SDK finishes the agent.
 */
final class CompleteTemplateAssistTurn
{
    public function handle(AgentPrompted|AgentStreamed $event): void
    {
        $agent = $event->prompt->agent;
        if (! $agent instanceof TemplateAssistantAgent) {
            return;
        }

        $turn = TemplateAssistTurn::query()->find($agent->turnId);
        if (! $turn instanceof TemplateAssistTurn || $turn->status !== TemplateAssistTurnStatus::Running) {
            return;
        }

        $turn->status = TemplateAssistTurnStatus::Completed;
        $turn->save();

        event(new TemplateAssistTurnFinished(
            sessionId: (int) $turn->session_id,
            turnId: $turn->id,
            status: TemplateAssistTurnStatus::Completed->value,
        ));
    }
}
