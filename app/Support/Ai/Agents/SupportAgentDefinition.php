<?php

declare(strict_types=1);

namespace App\Support\Ai\Agents;

use App\Models\Contact;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentEligibility;
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
            'facility.find_sites',
            'facility.site_info',
            'facility.size_guide',
            'calendar.resolve',
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

    public function eligible(?Contact $contact, ?int $siteId): bool
    {
        return AgentEligibility::hasInForceContractAtSite($contact, $siteId);
    }

    protected function roleParagraph(AgentContext $ctx): string
    {
        return <<<'TEXT'
You are the support agent for a self-storage operator. You help existing customers with questions about their unit, billing, access, and site information. For any relative date the customer gives, call calendar.resolve with their exact words and use the returned ISO date in tools and in your reply. Never compute a date yourself. For how much fits in a unit, ask what they are storing and call facility.size_guide; cite the band and its disclaimer, and never say goods will fit. Stay inside the tool surface. Escalate anything you cannot ground.
TEXT;
    }
}
