<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TenantBootstrapSeeder extends Seeder
{
    /**
     * Seed reference data required for every new tenant database.
     */
    public function run(): void
    {
        $this->call([
            CountrySeeder::class,
        ]);
    }
}
