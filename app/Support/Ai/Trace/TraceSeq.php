<?php

declare(strict_types=1);

namespace App\Support\Ai\Trace;

use App\Models\AgentGuardrailEvent;
use App\Models\AgentHandoff;
use App\Models\AgentToolInvocation;
use App\Models\AiUsageEvent;

final class TraceSeq
{
    public static function max(int $conversationId): int
    {
        $values = [
            (int) AgentToolInvocation::query()->where('agent_conversation_id', $conversationId)->max('seq'),
            (int) AgentHandoff::query()->where('agent_conversation_id', $conversationId)->max('seq'),
            (int) AgentGuardrailEvent::query()->where('agent_conversation_id', $conversationId)->max('seq'),
            (int) AiUsageEvent::query()->where('agent_conversation_id', $conversationId)->max('seq'),
        ];

        return max($values);
    }
}
