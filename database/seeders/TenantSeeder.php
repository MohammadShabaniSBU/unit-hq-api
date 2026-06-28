<?php

namespace Database\Seeders;

use App\Enums\ContractStatus;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Insurance;
use App\Models\InsuranceRate;
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

        $priceByUnitClass = [];
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
            $priceByUnitClass[$unitClass->id] = $price;

            foreach ($sites as $site) {
                UnitClassRate::create([
                    'unit_class_id' => $unitClass->id,
                    'site_id'       => $site->id,
                    'price_id'      => $price->id,
                ]);
            }
        }

        $insurances = [
            ['name' => 'Basic', 'coverage' => 3000, 'amount' => 3],
            ['name' => 'Premium', 'coverage' => 5000, 'amount' => 5],
        ];

        foreach ($insurances as $insuranceData) {
            $insurance = Insurance::create([
                'name'     => $insuranceData['name'],
                'coverage' => $insuranceData['coverage'],
                'currency' => 'EUR',
            ]);

            $price = Price::create([
                'amount'         => $insuranceData['amount'],
                'currency'       => 'EUR',
                'billing_period' => 'monthly',
                'effective_from' => now()->subMonths(6)->toDateString(),
                'effective_to'   => null,
                'created_by'     => $manager->id,
            ]);

            foreach ($sites as $site) {
                InsuranceRate::create([
                    'insurance_id' => $insurance->id,
                    'site_id'      => $site->id,
                    'price_id'     => $price->id,
                ]);
            }
        }

        $units = collect();
        foreach ($unitClasses as $unitClass) {
            foreach (range(1, 25) as $n) {
                $units->push(Unit::factory()->create([
                    'site_id'        => $sites->random()->id,
                    'unit_class_id'  => $unitClass->id,
                    'unit_number'    => sprintf('%s-%02d', $unitClass->code, $n),
                    'actual_width'   => fake()->randomFloat(2, 1.5, 5.0),
                    'actual_depth'   => fake()->randomFloat(2, 2.0, 6.0),
                    'actual_height'  => fake()->randomFloat(2, 2.0, 3.5),
                ]));
            }
        }

        Contact::factory()->count(50)->create();

        $this->call(DealSeeder::class);

        $contacts = Contact::all();
        $occupiedUnits = $units->shuffle()->take((int) ($units->count() * 0.40));

        foreach ($occupiedUnits as $unit) {
            $contact   = $contacts->random();
            $price     = $priceByUnitClass[$unit->unit_class_id];
            $startDate = now()->subDays(fake()->numberBetween(30, 365));

            $contract = Contract::create([
                'contact_id'  => $contact->id,
                'start_date'  => $startDate->toDateString(),
                'end_date'    => null,
                'status'      => ContractStatus::Active,
                'signed_at'   => $startDate,
            ]);

            ContractItem::create([
                'contract_id' => $contract->id,
                'item_type'   => 'unit',
                'item_id'     => $unit->id,
                'rate'        => $price->amount,
            ]);
        }
    }
}
