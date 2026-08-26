<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Facility\SiteMatch;
use App\Support\Facility\SiteMatchReason;
use App\Support\Facility\SiteResolver;

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

    public function retainInSummary(): bool
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
        $query = isset($arguments['query']) ? trim((string) $arguments['query']) : null;
        if ($query === '') {
            $query = null;
        }
        $lat = isset($arguments['latitude']) ? (float) $arguments['latitude'] : null;
        $lng = isset($arguments['longitude']) ? (float) $arguments['longitude'] : null;
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 5;

        $matches = SiteResolver::resolve($query, $lat, $lng, $limit);
        $rows = array_map(fn (SiteMatch $match): array => $match->toPayload(), $matches);
        $entities = array_map(fn (SiteMatch $match) => EntityRef::site($match->site), $matches);
        $display = self::summary($matches);

        $facts = new FactBag;
        $facts->absorb($display);
        foreach ($matches as $match) {
            $facts->identifier((string) $match->site->id);
            if ($match->site->code !== null && $match->site->code !== '') {
                $facts->identifier($match->site->code);
            }
        }

        return ToolResult::ok(
            ['sites' => $rows],
            $display,
            $facts,
            entities: $entities,
        );
    }

    /**
     * @param  list<SiteMatch>  $matches
     */
    public static function summary(array $matches): string
    {
        if ($matches === []) {
            return 'No matching sites. match_reason: no_match.';
        }

        $reason = $matches[0]->reason;
        $labels = [];
        foreach ($matches as $match) {
            $bit = $match->site->name;
            if ($match->site->postal_code !== null && $match->site->postal_code !== '') {
                $bit .= ', '.$match->site->postal_code;
            }
            if ($match->distanceKm !== null) {
                $bit .= ' ('.$match->distanceKm.' km)';
            }
            $labels[] = $bit;
        }
        $list = implode('; ', $labels);

        if ($reason === SiteMatchReason::NoMatch) {
            return "Sites (match_reason: no_match — present the list and ask which one): {$list}.";
        }

        $guidance = $reason === SiteMatchReason::OnlySite
            ? 'This is the only active site.'
            : 'This is the customer\'s site.';

        return "{$list} (match_reason: {$reason->value}). {$guidance}";
    }
}
