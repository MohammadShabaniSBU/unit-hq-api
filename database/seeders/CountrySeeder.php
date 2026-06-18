<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed European countries. Run via:
     *   php artisan tenants:seed --seeder=CountrySeeder
     */
    public function run(): void
    {
        $countries = [
            ['code' => 'AT', 'name' => 'Austria'],
            ['code' => 'BE', 'name' => 'Belgium'],
            ['code' => 'BG', 'name' => 'Bulgaria'],
            ['code' => 'HR', 'name' => 'Croatia'],
            ['code' => 'CY', 'name' => 'Cyprus'],
            ['code' => 'CZ', 'name' => 'Czechia'],
            ['code' => 'DK', 'name' => 'Denmark'],
            ['code' => 'EE', 'name' => 'Estonia'],
            ['code' => 'FI', 'name' => 'Finland'],
            ['code' => 'FR', 'name' => 'France'],
            ['code' => 'DE', 'name' => 'Germany'],
            ['code' => 'GR', 'name' => 'Greece'],
            ['code' => 'HU', 'name' => 'Hungary'],
            ['code' => 'IS', 'name' => 'Iceland'],
            ['code' => 'IE', 'name' => 'Ireland'],
            ['code' => 'IT', 'name' => 'Italy'],
            ['code' => 'LV', 'name' => 'Latvia'],
            ['code' => 'LT', 'name' => 'Lithuania'],
            ['code' => 'LU', 'name' => 'Luxembourg'],
            ['code' => 'MT', 'name' => 'Malta'],
            ['code' => 'NL', 'name' => 'Netherlands'],
            ['code' => 'NO', 'name' => 'Norway'],
            ['code' => 'PL', 'name' => 'Poland'],
            ['code' => 'PT', 'name' => 'Portugal'],
            ['code' => 'RO', 'name' => 'Romania'],
            ['code' => 'SK', 'name' => 'Slovakia'],
            ['code' => 'SI', 'name' => 'Slovenia'],
            ['code' => 'ES', 'name' => 'Spain'],
            ['code' => 'SE', 'name' => 'Sweden'],
            ['code' => 'CH', 'name' => 'Switzerland'],
            ['code' => 'GB', 'name' => 'United Kingdom'],
        ];

        foreach ($countries as $country) {
            Country::query()->updateOrCreate(
                ['code' => $country['code']],
                ['name' => $country['name']]
            );
        }
    }
}
