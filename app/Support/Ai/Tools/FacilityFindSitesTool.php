<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;

final class FacilityFindSitesTool implements AgentTool
{
    public function key(): string
    {
        return 'facility.find_sites';
    }

    public function description(): string
    {
        return 'Resolve a city, postcode, or coordinates to matching sites. Call when no site is in context or site_info returned site_unresolved. Each result includes match_reason: only_site, service_area, service_area_prefix, site_postcode, locality, and distance mean this is the customer\'s site; no_match means present the list and ask which one.';
    }

    public function schema(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'required' => false,
                'description' => 'City, postcode, or region the customer stated',
            ],
            'latitude' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Latitude for distance fallback',
            ],
            'longitude' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Longitude for distance fallback',
            ],
            'limit' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Maximum sites to return; defaults to 5',
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
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        return ToolResult::ok(
            ['sites' => []],
            'No matching sites.',
            new FactBag,
        );
    }
}
