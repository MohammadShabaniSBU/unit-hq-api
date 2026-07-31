<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Insurance;
use App\Models\InsuranceRate;
use App\Models\Price;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Models\User;
use App\Support\Billing\CurrencyGuard;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $rngSeed = (int) (env('SEED_RNG', 424242));
        mt_srand($rngSeed);
        fake()->seed($rngSeed);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(CountrySeeder::class);
        $this->call(DefaultAttributeLayoutSeeder::class);

        Employee::factory()->manager()->create();
        Employee::factory()->staff()->count(4)->create();

        $manager = Employee::query()->where('role', 'manager')->firstOrFail();

        $spain = Country::query()->where('code', 'ES')->firstOrFail();
        $uk = Country::query()->where('code', 'GB')->firstOrFail();
        $france = Country::query()->where('code', 'FR')->firstOrFail();

        // Sites 1–3 EUR/ES/Madrid; 4 GBP/GB/London; 5 EUR/FR/Paris
        $siteDefs = [
            ['name' => 'Madrid Centro', 'code' => 'MAD-01', 'country_id' => $spain->id, 'timezone' => 'Europe/Madrid', 'currency' => 'EUR'],
            ['name' => 'Barcelona Port', 'code' => 'BCN-01', 'country_id' => $spain->id, 'timezone' => 'Europe/Madrid', 'currency' => 'EUR'],
            ['name' => 'Valencia Norte', 'code' => 'VLC-01', 'country_id' => $spain->id, 'timezone' => 'Europe/Madrid', 'currency' => 'EUR'],
            ['name' => 'London East', 'code' => 'LON-01', 'country_id' => $uk->id, 'timezone' => 'Europe/London', 'currency' => 'GBP'],
            ['name' => 'Paris Sud', 'code' => 'PAR-01', 'country_id' => $france->id, 'timezone' => 'Europe/Paris', 'currency' => 'EUR'],
        ];

        $sites = collect();
        foreach ($siteDefs as $def) {
            $sites->push(Site::factory()->create($def));
        }

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

        // Static junction per site × class; catalogue price timeline on prices.
        $seededHistorical = false;
        foreach ($unitClasses as $unitClass) {
            $cataloguePrice = null;

            foreach ($sites as $site) {
                $rate = UnitClassRate::query()->create([
                    'unit_class_id' => $unitClass->id,
                    'site_id'       => $site->id,
                ]);

                $from = now()->subMonths(6)->toDateString();
                $amount = fake()->randomFloat(2, 50, 300);

                // At least one pairing carries a closed historical catalogue price.
                if (! $seededHistorical) {
                    Price::query()->create([
                        'priceable_type' => 'unit_class_rate',
                        'priceable_id'   => $rate->id,
                        'scope'          => Price::SCOPE_CATALOGUE,
                        'amount'         => round($amount * 0.9, 2),
                        'currency'       => $site->currency,
                        'effective_from' => now()->subYear()->toDateString(),
                        'effective_to'   => $from,
                        'created_by'     => $manager->id,
                    ]);
                    $seededHistorical = true;
                }

                $price = Price::query()->create([
                    'priceable_type' => 'unit_class_rate',
                    'priceable_id'   => $rate->id,
                    'scope'          => Price::SCOPE_CATALOGUE,
                    'amount'         => $amount,
                    'currency'       => $site->currency,
                    'effective_from' => $from,
                    'effective_to'   => null,
                    'created_by'     => $manager->id,
                ]);

                CurrencyGuard::assertRateJunction($site->currency, $price->currency);

                $cataloguePrice ??= $price;
            }

            $unitClass->update(['current_price_id' => $cataloguePrice->id]);
        }

        $insurances = [
            ['name' => 'Basic', 'coverage' => 3000, 'amount' => 3],
            ['name' => 'Premium', 'coverage' => 5000, 'amount' => 5],
        ];

        foreach ($insurances as $insuranceData) {
            $insurance = Insurance::query()->create([
                'name'     => $insuranceData['name'],
                'coverage' => $insuranceData['coverage'],
                'currency' => 'EUR',
            ]);

            foreach ($sites as $site) {
                $rate = InsuranceRate::query()->create([
                    'insurance_id' => $insurance->id,
                    'site_id'      => $site->id,
                ]);

                $price = Price::query()->create([
                    'priceable_type' => 'insurance_rate',
                    'priceable_id'   => $rate->id,
                    'scope'          => Price::SCOPE_CATALOGUE,
                    'amount'         => $insuranceData['amount'],
                    'currency'       => $site->currency,
                    'effective_from' => now()->subMonths(6)->toDateString(),
                    'effective_to'   => null,
                    'created_by'     => $manager->id,
                ]);

                CurrencyGuard::assertRateJunction($site->currency, $price->currency);
            }
        }

        // 20 classes × 5 sites × 5 units = 500; even distribution for pool draws.
        foreach ($unitClasses as $unitClass) {
            foreach ($sites as $site) {
                foreach (range(1, 5) as $n) {
                    Unit::factory()->create([
                        'site_id'       => $site->id,
                        'unit_class_id' => $unitClass->id,
                        'unit_number'   => sprintf('%s-%s-%02d', $site->code, $unitClass->code, $n),
                        'actual_width'  => fake()->randomFloat(2, 1.5, 5.0),
                        'actual_depth'  => fake()->randomFloat(2, 2.0, 6.0),
                        'actual_height' => fake()->randomFloat(2, 2.0, 3.5),
                    ]);
                }
            }
        }

        Contact::factory()->count(50)->create();

        $this->call(DealSeeder::class);
        $this->call(OccupancySeeder::class);
        $this->call(BillingSeeder::class);

        $this->command?->info("RNG seed: {$rngSeed}");
    }
}
