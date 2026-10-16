<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Country;
use App\Models\Employee;
use App\Models\Insurance;
use App\Models\Price;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class DemoRenameInsurancePlansCommandTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    #[Test]
    public function renames_plans_and_opens_new_prices(): void
    {
        $seed = $this->seedPlans();

        $this->artisan('demo:rename-insurance-plans')
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Plans updated', '2'],
                    ['Prices replaced', '2'],
                ],
            )
            ->assertSuccessful();

        $this->assertSame(
            [
                ['Simple', '5000.00'],
                ['VIP', '10000.00'],
            ],
            Insurance::query()->orderBy('id')->get(['name', 'coverage'])->map(
                fn (Insurance $plan): array => [$plan->name, (string) $plan->coverage],
            )->all(),
        );

        $this->assertSame('3.00', (string) Price::query()->findOrFail($seed['basic_price'])->amount);
        $this->assertSame('5.00', (string) Price::query()->findOrFail($seed['premium_price'])->amount);
        $this->assertNotNull(Price::query()->findOrFail($seed['basic_price'])->effective_to);
        $this->assertNotNull(Price::query()->findOrFail($seed['premium_price'])->effective_to);

        $open = Price::query()->whereNull('effective_to')->orderBy('amount')->pluck('amount')->all();
        $this->assertSame(['5.00', '10.00'], array_map(strval(...), $open));
    }

    #[Test]
    public function second_run_changes_nothing(): void
    {
        $this->seedPlans();
        $this->artisan('demo:rename-insurance-plans')->assertSuccessful();

        $names = Insurance::query()->orderBy('id')->pluck('name')->all();
        $priceCount = Price::query()->count();

        $this->artisan('demo:rename-insurance-plans')
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Plans updated', '0'],
                    ['Prices replaced', '0'],
                ],
            )
            ->assertSuccessful();

        $this->assertSame($names, Insurance::query()->orderBy('id')->pluck('name')->all());
        $this->assertSame($priceCount, Price::query()->count());
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $this->seedPlans();

        $this->artisan('demo:rename-insurance-plans', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(
            ['Básico', 'Premium'],
            Insurance::query()->orderBy('id')->pluck('name')->all(),
        );
        $this->assertSame(2, Price::query()->whereNull('effective_to')->count());
        $this->assertSame(
            ['3.00', '5.00'],
            array_map(strval(...), Price::query()->orderBy('id')->pluck('amount')->all()),
        );
    }

    #[Test]
    public function refuses_production(): void
    {
        $this->seedPlans();
        $this->app['env'] = 'production';

        $this->artisan('demo:rename-insurance-plans')
            ->expectsOutputToContain('production')
            ->assertFailed();

        $this->assertSame(
            ['Básico', 'Premium'],
            Insurance::query()->orderBy('id')->pluck('name')->all(),
        );
    }

    /**
     * @return array{basic_price: int, premium_price: int}
     */
    private function seedPlans(): array
    {
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create([
            'code' => 'MAD-01',
            'country_id' => $country->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
        ]);
        $employee = Employee::factory()->create();

        $basic = Insurance::query()->create([
            'name' => 'Básico',
            'coverage' => '3000.00',
            'currency' => 'EUR',
        ]);
        $premium = Insurance::query()->create([
            'name' => 'Premium',
            'coverage' => '5000.00',
            'currency' => 'EUR',
        ]);

        [, $basicPrice] = $this->createInsuranceCataloguePrice($basic->id, $site->id, $employee->id, [
            'amount' => '3.00',
        ]);
        [, $premiumPrice] = $this->createInsuranceCataloguePrice($premium->id, $site->id, $employee->id, [
            'amount' => '5.00',
        ]);

        return [
            'basic_price' => $basicPrice->id,
            'premium_price' => $premiumPrice->id,
        ];
    }
}
