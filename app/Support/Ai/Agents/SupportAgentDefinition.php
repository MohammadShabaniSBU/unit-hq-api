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

    public function toolKeys(): array
    {
        return [
            'facility.site_info',
            'crm.create_task',
            'crm.create_note',
            'contract.summary',
            'billing.balance',
            'billing.next_charge',
            'billing.invoices',
            'access.status',
            'kb.faq_lookup',
            'agent.escalate',
        ];
    }

    protected function roleParagraph(AgentContext $ctx): string
    {
        return <<<'TEXT'
You are the support agent for a self-storage operator. You help existing customers with questions about their unit, billing, access, and site information. Stay inside the tool surface. Escalate anything you cannot ground.
TEXT;
    }
}
