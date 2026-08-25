<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Support\Ai\Agents\AgentDefinition;
use App\Support\Ai\Enums\ForbiddenClaimKey;

final readonly class AgentContext
{
    /**
     * @param  list<ForbiddenClaimKey>  $licensedClaims  Turn-scoped. Never persisted.
     */
    public function __construct(
        public AgentPrincipal $principal,
        public ChannelProfile $channel,
        public AgentDefinition $definition,
        public AgentConversation $conversation,
        public AiAgent $agent,
        public array $licensedClaims = [],
    ) {}

    /**
     * @param  list<ForbiddenClaimKey>  $licensedClaims
     */
    public function withLicensedClaims(array $licensedClaims): self
    {
        return new self(
            $this->principal,
            $this->channel,
            $this->definition,
            $this->conversation,
            $this->agent,
            $licensedClaims,
        );
    }
}
