<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Models\AgentConversation;
use App\Models\AiUsageEvent;
use App\Support\Ai\Enums\HandoffReason;

final class BudgetGuard
{
    public function evaluate(AgentConversation $conversation): ?HandoffMatch
    {
        $tokens = (int) AiUsageEvent::query()
            ->where('agent_conversation_id', $conversation->id)
            ->selectRaw('coalesce(sum(input_tokens + cached_input_tokens + output_tokens + reasoning_tokens), 0) as total')
            ->value('total');

        $tokenBudget = (int) config('agents.conversation_token_budget');
        if ($tokens >= $tokenBudget) {
            return new HandoffMatch(
                HandoffReason::BudgetExceeded,
                CannedReply::Budget,
                ['detail' => 'conversation_token_budget'],
                'budget',
            );
        }

        $calls = AiUsageEvent::query()
            ->where('agent_conversation_id', $conversation->id)
            ->count();

        $callBudget = (int) config('agents.conversation_call_budget');
        if ($calls >= $callBudget) {
            return new HandoffMatch(
                HandoffReason::BudgetExceeded,
                CannedReply::Budget,
                ['detail' => 'conversation_call_budget'],
                'budget',
            );
        }

        return null;
    }
}
