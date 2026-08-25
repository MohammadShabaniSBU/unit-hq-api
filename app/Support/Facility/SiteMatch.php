<?php

declare(strict_types=1);

namespace App\Support\Facility;

use App\Models\Site;

final readonly class SiteMatch
{
    public function __construct(
        public Site $site,
        public SiteMatchReason $reason,
        public ?float $distanceKm = null,
    ) {}

    /**
     * @return array{site_id: int, name: string, address: string, city: string|null, postal_code: string|null, distance_km: float|null, match_reason: string}
     */
    public function toPayload(): array
    {
        return [
            'site_id' => $this->site->id,
            'name' => $this->site->name,
            'address' => self::formatAddress($this->site),
            'city' => $this->site->city,
            'postal_code' => $this->site->postal_code,
            'distance_km' => $this->distanceKm,
            'match_reason' => $this->reason->value,
        ];
    }

    public static function formatAddress(Site $site): string
    {
        $parts = array_values(array_filter([
            $site->address,
            $site->address_line_2,
            $site->city,
            $site->postal_code,
            $site->state_region,
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        return implode(', ', $parts);
    }
}
