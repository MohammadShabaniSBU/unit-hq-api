<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Knowledge\KnowledgeBase;
use App\Support\Facility\SiteMatch;
use App\Support\Facility\SiteResolver;

final class FacilitySiteInfoTool implements AgentTool
{
    public function key(): string
    {
        return 'facility.site_info';
    }

    public function description(): string
    {
        return 'Public site details: address, hours, access hours, contact number, prefill currency, and timezone. Quote currency still comes from a price row.';
    }

    public function schema(): array
    {
        return [
            'site_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Site id; defaults to the conversation site',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::Anonymous;
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function entityArguments(): array
    {
        return [
            'site_id' => EntityType::Site,
        ];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $siteId = isset($arguments['site_id']) ? (int) $arguments['site_id'] : $principal->siteId;
        $matchReason = null;

        if ($siteId === null) {
            $matches = SiteResolver::resolve(null, null, null);
            if (count($matches) === 1) {
                $siteId = $matches[0]->site->id;
                $matchReason = $matches[0]->reason->value;
            } else {
                $candidates = array_map(
                    fn (SiteMatch $match): EntityRef => EntityRef::site($match->site),
                    $matches,
                );

                return ToolResult::fail(ToolError::siteUnresolved(
                    'site_id is required when no site is in context and more than one active site exists.',
                    $candidates,
                ));
            }
        }

        $site = Site::query()->with('country')->find($siteId);
        if ($site === null) {
            return ToolResult::notFound('Site not found.');
        }

        $hours = KnowledgeBase::snippet('access_hours', $principal->locale, $site);
        $address = SiteMatch::formatAddress($site);

        $data = [
            'site_id' => $site->id,
            'name' => $site->name,
            'address' => $address,
            'hours' => $hours,
            'access_hours' => $hours,
            'contact_phone' => $site->contact_phone,
            'contact_email' => $site->contact_email,
            'currency' => $site->currency,
            'timezone' => $site->timezone,
        ];
        if ($matchReason !== null) {
            $data['match_reason'] = $matchReason;
        }

        $bits = ["{$site->name}."];
        if ($address !== '') {
            $bits[] = $address.'.';
        }
        if ($hours !== null) {
            $bits[] = $hours;
        }
        if ($site->contact_phone !== null && $site->contact_phone !== '') {
            $bits[] = "Phone: {$site->contact_phone}.";
        }
        $bits[] = "Timezone: {$site->timezone}.";
        if ($matchReason !== null) {
            $bits[] = "match_reason: {$matchReason}.";
        }

        $display = implode(' ', $bits);
        $facts = (new FactBag)->absorb($display, $site);
        $facts->identifier((string) $site->id);
        if ($site->code !== null && $site->code !== '') {
            $facts->identifier($site->code);
        }

        return ToolResult::ok($data, $display, $facts, entities: [EntityRef::site($site)]);
    }
}
