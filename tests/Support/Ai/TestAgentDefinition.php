<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Agents\AgentDefinition;

final class TestAgentDefinition implements AgentDefinition
{
    /**
     * @param  list<string>  $toolKeys
     */
    public function __construct(
        private readonly string $key = 'test',
        private readonly array $toolKeys = ['test.record', 'agent.escalate'],
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function systemPrompt(AgentContext $ctx): string
    {
        return 'Test agent.';
    }

    public function promptVersion(AgentContext $ctx): string
    {
        return \App\Support\Ai\Eval\CassetteKey::promptHash($this->systemPrompt($ctx));
    }

    public function toolKeys(): array
    {
        return $this->toolKeys;
    }

    public function handoffRules(): array
    {
        return [];
    }

    public function maxTurns(): int
    {
        return (int) config('agents.max_turns');
    }

    public function forbiddenClaims(): array
    {
        return [];
    }
}
