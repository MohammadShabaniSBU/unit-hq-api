<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Price;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
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
        $this->call(CountrySeeder::class);

        Employee::factory()->manager()->create();
        Employee::factory()->staff()->count(4)->create();

        $spain = Country::query()->where('code', 'ES')->firstOrFail();
        $sites = Site::factory()->count(5)->create(['country_id' => $spain->id]);

        $unitClasses = collect();
        foreach (range(1, 10) as $n) {
            $unitClasses->push(UnitClass::factory()->create([
                'code' => "SS{$n}",
                'label' => "SS Unit {$n}",
                'size' => 5.00 + ($n - 1),
            ]));
            $unitClasses->push(UnitClass::factory()->create([
                'code' => "AL{$n}",
                'label' => "AL Unit {$n}",
                'size' => 10.00 + ($n - 1) * 2,
            ]));
        }

        $manager = Employee::query()->where('role', 'manager')->firstOrFail();

        foreach ($unitClasses as $unitClass) {
            $price = Price::create([
                'amount'         => fake()->randomFloat(2, 50, 300),
                'currency'       => 'EUR',
                'billing_period' => 'monthly',
                'effective_from' => now()->subMonths(6)->toDateString(),
                'effective_to'   => null,
                'created_by'     => $manager->id,
            ]);
            $unitClass->update(['current_price_id' => $price->id]);

            foreach ($sites as $site) {
                UnitClassRate::create([
                    'unit_class_id' => $unitClass->id,
                    'site_id'       => $site->id,
                    'price_id'      => $price->id,
                ]);
            }
        }

        foreach ($unitClasses as $unitClass) {
            foreach (range(1, 10) as $n) {
                Unit::factory()->create([
                    'site_id' => $sites->random()->id,
                    'unit_class_id' => $unitClass->id,
                    'unit_number' => sprintf('%s-%02d', $unitClass->code, $n),
                ]);
            }
        }

        Contact::factory()->count(30)->create();

        $this->call(DealSeeder::class);
    }
}
