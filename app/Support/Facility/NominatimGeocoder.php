<?php

declare(strict_types=1);

namespace App\Support\Facility;

use Illuminate\Support\Facades\Http;
use Throwable;

final class NominatimGeocoder implements Geocoder
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userAgent,
    ) {}

    public function geocode(string $query): ?array
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => $this->userAgent])
                ->acceptJson()
                ->get(rtrim($this->baseUrl, '/').'/search', [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $first = $response->json('0');
        if (! is_array($first) || ! isset($first['lat'], $first['lon'])) {
            return null;
        }

        return [
            'lat' => (float) $first['lat'],
            'lng' => (float) $first['lon'],
        ];
    }
}
