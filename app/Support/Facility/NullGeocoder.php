<?php

declare(strict_types=1);

namespace App\Support\Facility;

final class NullGeocoder implements Geocoder
{
    public function geocode(string $query): ?array
    {
        return null;
    }
}
