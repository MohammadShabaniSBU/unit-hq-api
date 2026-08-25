<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Support\Ai\Agents\AgentDefinition;
use App\Support\Ai\Enums\ForbiddenClaimKey;
use App\Support\Ai\Tools\FactRegistry;

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
        public ?FactRegistry $factRegistry = null,
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
            $this->factRegistry,
        );
    }

    public function withFactRegistry(FactRegistry $factRegistry): self
    {
        return new self(
            $this->principal,
            $this->channel,
            $this->definition,
            $this->conversation,
            $this->agent,
            $this->licensedClaims,
            $factRegistry,
        );
    }

    public function withPrincipal(AgentPrincipal $principal): self
    {
        return new self(
            $principal,
            $this->channel,
            $this->definition,
            $this->conversation,
            $this->agent,
            $this->licensedClaims,
            $this->factRegistry,
        );
    }
}
