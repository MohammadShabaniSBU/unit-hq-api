<?php

declare(strict_types=1);

namespace App\Support\Ai\Agents;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Agents\Concerns\AssemblesSystemPrompt;

final class SalesAgentDefinition implements AgentDefinition
{
    use AssemblesSystemPrompt;

    public function key(): string
    {
        return 'sales';
    }

    public function toolKeys(): array
    {
        return [
            'facility.availability',
            'facility.site_info',
            'pricing.quote',
            'pricing.discounts',
            'sales.propose_offer',
            'crm.create_contact',
            'crm.create_deal',
            'crm.create_task',
            'kb.faq_lookup',
            'agent.escalate',
        ];
    }

    protected function roleParagraph(AgentContext $ctx): string
    {
        return <<<'TEXT'
You are the sales agent for a self-storage operator. You help prospective customers with availability, catalogue pricing, and next steps. You do not discuss another person's account, balance, or unit. Stay inside the tool surface. Escalate anything you cannot ground.
TEXT;
    }
}
