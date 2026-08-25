<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Knowledge\KnowledgeBase;

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
        if ($siteId === null) {
            return ToolResult::fail(ToolError::siteUnresolved('site_id is required when no site is in context.'));
        }

        $site = Site::query()->with('country')->find($siteId);
        if ($site === null) {
            return ToolResult::notFound('Site not found.');
        }

        $hours = KnowledgeBase::snippet('access_hours', $principal->locale, $site);
        $addressParts = array_values(array_filter([
            $site->address,
            $site->address_line_2,
            $site->city,
            $site->postal_code,
            $site->state_region,
        ], fn (?string $part): bool => $part !== null && $part !== ''));
        $address = implode(', ', $addressParts);

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

        $display = implode(' ', $bits);
        $facts = (new FactBag)->absorb($display, $site);
        $facts->identifier((string) $site->id);
        if ($site->code !== null && $site->code !== '') {
            $facts->identifier($site->code);
        }

        return ToolResult::ok($data, $display, $facts, entities: [EntityRef::site($site)]);
    }
}
