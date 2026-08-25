<?php

return [

    'geocoder' => env('FACILITY_GEOCODER'),

    'nominatim_url' => env('FACILITY_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),

    'nominatim_user_agent' => env('FACILITY_NOMINATIM_USER_AGENT', 'Keevaris/site-geocode'),

];
