<?php

declare(strict_types=1);

namespace App\Support\Facility;

enum SiteMatchReason: string
{
    case OnlySite = 'only_site';
    case ServiceArea = 'service_area';
    case ServiceAreaPrefix = 'service_area_prefix';
    case SitePostcode = 'site_postcode';
    case Locality = 'locality';
    case Distance = 'distance';
    case NoMatch = 'no_match';

    public function isConfident(): bool
    {
        return $this !== self::NoMatch;
    }
}
