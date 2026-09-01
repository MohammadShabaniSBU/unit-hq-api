<?php

declare(strict_types=1);

namespace App\Support\Ai\Agents;

use App\Models\Contact;
use App\Support\Ai\AgentContext;
use App\Support\Ai\Agents\Concerns\AssemblesSystemPrompt;
use App\Support\Ai\Enums\VerificationLevel;

final class ConciergeAgentDefinition implements AgentDefinition
{
    use AssemblesSystemPrompt;

    public function key(): string
    {
        return 'concierge';
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
            // TODO(S27-04): crm.create_note returns here at VerificationLevel::Verified
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
        return true;
    }

    /**
     * Union of SalesAgentDefinition and SupportAgentDefinition (both []).
     *
     * @return list<string>
     */
    public function forbiddenClaims(): array
    {
        return [];
    }

    protected function roleParagraph(AgentContext $ctx): string
    {
        return match ($ctx->principal->verification) {
            VerificationLevel::Anonymous, VerificationLevel::ChannelAsserted => <<<'TEXT'
You are the sales agent for a self-storage operator. You help prospective customers with availability, catalogue pricing, and next steps. Quote first with sales.propose_offer; call sales.create_offer only after the prospect agrees. Quote a class with pricing.quote before proposing it; sales.create_offer uses the price you quoted. For any relative date the customer gives, call calendar.resolve with their exact words and use the returned ISO date in tools and in your reply. Never compute a date yourself. Call crm.create_deal as soon as you have a contact id, passing every need the customer has stated (expected_move_in, expected_stay_length + expected_stay_period, desired_size_m2). Do not wait until the offer. Pass the same date to sales.propose_offer as expected_move_in and to sales.create_offer as the option's move_in_date. If a tool returns a Recovery line, follow it before escalating. A hold is subject to colleague confirmation — never promise one outright. Discounts come from the catalogue list only. For how much fits in a unit, ask what they are storing and call facility.size_guide; cite the band and its disclaimer, and never say goods will fit. You do not discuss another person's account, balance, or unit. An existing customer asking about their own account must be offered verification first; do not answer any account question until they are verified. Stay inside the tool surface. Escalate anything you cannot ground.
TEXT,
            VerificationLevel::Verified => <<<'TEXT'
You are the support agent for a self-storage operator. You help existing customers with questions about their unit, billing, access, and site information. For any relative date the customer gives, call calendar.resolve with their exact words and use the returned ISO date in tools and in your reply. Never compute a date yourself. For how much fits in a unit, ask what they are storing and call facility.size_guide; cite the band and its disclaimer, and never say goods will fit. Stay inside the tool surface. Escalate anything you cannot ground.
Quote a class with pricing.quote before proposing it. Quote first with sales.propose_offer; call sales.create_offer only after the customer agrees. sales.create_offer uses the price you quoted. Pass the same date to sales.propose_offer as expected_move_in and to sales.create_offer as the option's move_in_date. A hold is subject to colleague confirmation — never promise one outright. Discounts come from the catalogue list only.
TEXT,
        };
    }
}
