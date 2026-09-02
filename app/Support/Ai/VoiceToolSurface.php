<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Milestone-one voice allowlist. Fail-closed: a tool added to concierge
 * later is not on voice until it is listed here.
 *
 * @phpstan-type ToolKey string
 */
final class VoiceToolSurface
{
    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            'facility.find_sites',
            'facility.site_info',
            'facility.size_guide',
            'facility.availability',
            'calendar.resolve',
            'pricing.quote',
            'crm.create_contact',
            'crm.create_deal',
            'agent.escalate',
            'voice.send_quote_by_text',
        ];
    }
}
