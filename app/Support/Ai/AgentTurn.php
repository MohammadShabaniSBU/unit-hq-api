<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentHandoff;
use App\Models\AgentToolInvocation;
use App\Models\AiUsageEvent;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Tools\FactBag;

final readonly class AgentTurn
{
    /**
     * @param  list<AgentToolInvocation>  $invocations
     */
    public function __construct(
        public string $draft,
        public ChannelProfile $channel,
        public FactBag $facts,
        public array $invocations,
        public ?AgentHandoff $handoff,
        public ?AiUsageEvent $usage,
        public ConversationState $state,
        public ?string $blockedBy,
    ) {}
}
