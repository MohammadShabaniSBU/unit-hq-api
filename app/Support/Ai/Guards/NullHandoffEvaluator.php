<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Models\AgentConversation;
use App\Support\Ai\AgentPrincipal;

final class NullHandoffEvaluator implements HandoffEvaluator
{
    public function match(AgentConversation $conversation, AgentPrincipal $principal, string $input): ?HandoffMatch
    {
        return null;
    }
}
