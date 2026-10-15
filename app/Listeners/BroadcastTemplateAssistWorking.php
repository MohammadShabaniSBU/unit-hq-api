<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Ai\Agents\TemplateAssistantAgent;
use App\Events\TemplateAssistWorking;
use App\Models\TemplateAssistTurn;
use Laravel\Ai\Events\InvokingTool;

/**
 * Tells the builder a tool is running. The payload has no tool name.
 */
final class BroadcastTemplateAssistWorking
{
    public function handle(InvokingTool $event): void
    {
        if (! $event->agent instanceof TemplateAssistantAgent) {
            return;
        }

        $turn = TemplateAssistTurn::query()->find($event->agent->turnId);
        if (! $turn instanceof TemplateAssistTurn) {
            return;
        }

        event(new TemplateAssistWorking(
            sessionId: (int) $turn->session_id,
            turnId: $turn->id,
        ));
    }
}
