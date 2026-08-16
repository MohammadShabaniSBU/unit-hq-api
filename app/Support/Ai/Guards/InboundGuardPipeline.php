<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentDefinition;

final class InboundGuardPipeline
{
    public function __construct(
        private readonly LoopGuard $loop,
        private readonly BudgetGuard $budget,
        private readonly HandoffEvaluator $rules,
    ) {}

    public function evaluate(
        AgentConversation $conversation,
        AgentPrincipal $principal,
        string $input,
        AgentDefinition $definition,
        AiAgent $agent,
    ): ?HandoffMatch {
        return $this->loop->evaluate($conversation, $definition, $agent)
            ?? $this->budget->evaluate($conversation)
            ?? $this->rules->match($conversation, $principal, $input);
    }
}
