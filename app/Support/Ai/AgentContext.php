<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Support\Ai\Agents\AgentDefinition;

final readonly class AgentContext
{
    public function __construct(
        public AgentPrincipal $principal,
        public ChannelProfile $channel,
        public AgentDefinition $definition,
        public AgentConversation $conversation,
        public AiAgent $agent,
    ) {}
}
