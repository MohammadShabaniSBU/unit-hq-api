<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed tenant-scoped data. Run via:
     *   php artisan tenants:seed --seeder=TenantSeeder
     */
    public function run(): void
    {
        Employee::factory()->manager()->create();
        Employee::factory()->staff()->count(4)->create();

        $sites = Site::factory()->count(3)->create();

        $unitClasses = collect([
            ['code' => 'S', 'label' => 'Small Unit', 'size' => 5.00],
            ['code' => 'M', 'label' => 'Medium Unit', 'size' => 10.00],
            ['code' => 'L', 'label' => 'Large Unit', 'size' => 20.00],
            ['code' => 'XL', 'label' => 'Extra Large Unit', 'size' => 40.00],
        ])->map(fn (array $attributes) => UnitClass::factory()->create($attributes));

        Unit::factory()
            ->count(20)
            ->recycle($sites->merge($unitClasses))
            ->sequence(fn ($sequence) => [
                'unit_number' => sprintf('A-%03d', $sequence->index + 1),
            ])
            ->create();
    }
}
