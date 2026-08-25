<?php

declare(strict_types=1);

namespace App\Support\Facility;

interface Geocoder
{
    /**
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $query): ?array;
}
