<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Ai\Agents\CrmCopilotAgent;
use App\Events\CopilotToolInvoking;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Tools\ToolNameResolver;

final class BroadcastCopilotToolInvoking
{
    public function handle(InvokingTool $event): void
    {
        if (! $event->agent instanceof CrmCopilotAgent) {
            return;
        }

        $conversationId = $event->agent->currentConversation();
        if ($conversationId === null || $conversationId === '') {
            return;
        }

        event(new CopilotToolInvoking(
            conversationId: $conversationId,
            toolName: ToolNameResolver::resolve($event->tool),
            callId: $event->toolInvocationId,
        ));
    }
}
