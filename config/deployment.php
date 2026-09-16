<?php

declare(strict_types=1);

return [
    /*
    | ISO 3166-1 alpha-2. Required. Country-dependent behaviour is resolved
    | from App\Support\Country\CountryProfiles — never from operator settings.
    */
    'country' => strtoupper((string) env('KEEVARIS_COUNTRY', '')),
];
