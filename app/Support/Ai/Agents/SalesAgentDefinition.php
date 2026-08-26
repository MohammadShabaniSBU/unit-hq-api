<?php

declare(strict_types=1);

namespace App\Support\Ai\Agents;

use App\Models\Contact;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentEligibility;
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
            'facility.find_sites',
            'facility.site_info',
            'facility.size_guide',
            'calendar.resolve',
            'pricing.quote',
            'pricing.discounts',
            'sales.propose_offer',
            'sales.create_offer',
            'sales.create_reservation',
            'crm.create_contact',
            'crm.create_deal',
            'crm.create_task',
            'kb.faq_lookup',
            'agent.escalate',
        ];
    }

    public function eligible(?Contact $contact, ?int $siteId): bool
    {
        return ! AgentEligibility::hasInForceContractAtSite($contact, $siteId);
    }

    protected function roleParagraph(AgentContext $ctx): string
    {
        return <<<'TEXT'
You are the sales agent for a self-storage operator. You help prospective customers with availability, catalogue pricing, and next steps. Quote first with sales.propose_offer; call sales.create_offer only after the prospect agrees. Quote a class with pricing.quote before proposing it; sales.create_offer uses the price you quoted. For any relative date the customer gives, call calendar.resolve with their exact words and use the returned ISO date in tools and in your reply. Never compute a date yourself. Call crm.create_deal as soon as you have a contact id, passing every need the customer has stated (expected_move_in, expected_stay_length + expected_stay_period, desired_size_m2). Do not wait until the offer. Pass the same date to sales.propose_offer as expected_move_in and to sales.create_offer as the option's move_in_date. If a tool returns a Recovery line, follow it before escalating. A hold is subject to colleague confirmation — never promise one outright. Discounts come from the catalogue list only. For how much fits in a unit, ask what they are storing and call facility.size_guide; cite the band and its disclaimer, and never say goods will fit. You do not discuss another person's account, balance, or unit. Stay inside the tool surface. Escalate anything you cannot ground.
TEXT;
    }
}
