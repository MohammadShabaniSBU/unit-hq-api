<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Models\Contact;
use App\Support\Ai\AgentContext;
use App\Support\Ai\Agents\AgentDefinition;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Eval\CassetteKey;

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
        return CassetteKey::promptHash($this->systemPrompt($ctx));
    }

    /**
     * @return list<string>
     */
    public function toolKeys(?AgentChannel $channel = null): array
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

    public function eligible(?Contact $contact, ?int $siteId): bool
    {
        return true;
    }
}
