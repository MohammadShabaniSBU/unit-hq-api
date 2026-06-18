<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the central/platform database.
     *
     * Tenant-scoped data (sites, units, employees, etc.) lives in TenantSeeder:
     *   php artisan tenants:seed --seeder=TenantSeeder
     *
     * Reference data (countries) is seeded automatically on tenant creation via
     * TenantBootstrapSeeder, or manually:
     *   php artisan tenants:seed --seeder=CountrySeeder
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
