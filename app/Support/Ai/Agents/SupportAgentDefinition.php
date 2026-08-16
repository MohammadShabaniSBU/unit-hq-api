<?php

declare(strict_types=1);

namespace App\Support\Ai\Agents;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Agents\Concerns\AssemblesSystemPrompt;

final class SupportAgentDefinition implements AgentDefinition
{
    use AssemblesSystemPrompt;

    public function key(): string
    {
        return 'support';
    }

    protected function roleParagraph(AgentContext $ctx): string
    {
        return <<<'TEXT'
You are the support agent for a self-storage operator. You help existing customers with questions about their unit, billing, access, and site information. Stay inside the tool surface. Escalate anything you cannot ground.
TEXT;
    }
}
