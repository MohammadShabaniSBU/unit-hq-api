<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\UnitState;
use App\Models\Contract;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitOccupancy;
use App\Support\Occupancy\Availability;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\AssertsOccupancyIntegrity;
use Tests\TestCase;

class SeederIntegrityTest extends TestCase
{
    use AssertsOccupancyIntegrity;
    use RefreshDatabase;

    /**
     * Pgsql race tests disable transactions and commit fixtures. Re-migrate so
     * this seed always starts from an empty schema.
     *
     * @var list<string|null>
     */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Prior tests may leave a frozen clock; SiteClock drives all seed dates.
        Carbon::setTestNow();
        $migrate = Artisan::call('migrate:fresh', ['--force' => true]);
        $this->assertSame(0, $migrate, Artisan::output());
    }

    public static function tearDownAfterClass(): void
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        Artisan::call('migrate:fresh', ['--force' => true]);
        HandleExceptions::flushState();

        parent::tearDownAfterClass();
    }

    public function test_seeded_dataset_integrity(): void
    {
        $exit = Artisan::call('db:seed', ['--force' => true]);
        $this->assertSame(0, $exit, Artisan::output());

        $this->assertGreaterThan(0, Site::query()->count());
        $this->assertSame(0, Site::query()->whereNull('legal_entity_id')->count());

        $this->assertNoOverlappingOccupancies();
        $this->assertNoOverlappingBlockingHolds();
        $this->assertEveryNonCancelledContractHasOccupancy();
        $this->assertAllUnitStatesPresent();
        $this->assertEveryContractIsSingleCurrency();
        $this->assertMultipleCurrenciesAndTimezonesPresent();
    }

    /**
     * DatabaseSeeder rule: cancelled never commenced; other statuses (incl.
     * awaiting_signature in fixtures) carry occupancy when the seed creates them.
     */
    private function assertEveryNonCancelledContractHasOccupancy(): void
    {
        $contractIds = Contract::query()
            ->where('status', '!=', ContractStatus::Cancelled->value)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
        $occupancyContractIds = UnitOccupancy::query()
            ->distinct()
            ->pluck('contract_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $missingOccupancy = $contractIds->diff($occupancyContractIds)->values();
        $orphanOccupancy = $occupancyContractIds->diff($contractIds)->values();

        $this->assertTrue(
            $missingOccupancy->isEmpty(),
            'Non-cancelled contracts without occupancy: '.$missingOccupancy->implode(', '),
        );
        $this->assertTrue(
            $orphanOccupancy->isEmpty(),
            'Occupancy rows for cancelled/missing contracts: '.$orphanOccupancy->implode(', '),
        );
        $this->assertGreaterThan(0, $contractIds->count());
        $this->assertTrue(
            Contract::query()->where('status', ContractStatus::Cancelled->value)->exists(),
            'Expected at least one cancelled contract in the seed',
        );
    }

    private function assertAllUnitStatesPresent(): void
    {
        foreach (UnitState::cases() as $state) {
            $this->assertTrue(
                Availability::scopeStateTodayPerSite(Unit::query(), $state)->exists(),
                "Expected at least one unit in state {$state->value}",
            );
        }
    }

    private function assertEveryContractIsSingleCurrency(): void
    {
        // S05-02 seeds exactly one deliberate mixed-currency contract so
        // billing:run surfaces failed/currency_mismatch.
        $mixedCurrencyContracts = 0;

        Contract::query()->with(['items.price', 'charges', 'payments'])->each(function (Contract $contract) use (&$mixedCurrencyContracts): void {
            $currencies = $contract->items
                ->map(fn ($item) => $item->price?->currency)
                ->unique()
                ->filter()
                ->values();

            if ($currencies->count() > 1) {
                $mixedCurrencyContracts++;

                return;
            }

            $this->assertCount(1, $currencies, "Contract {$contract->id} has mixed item currencies");
            $this->assertSame($contract->currency, $currencies->first());

            foreach ($contract->charges as $charge) {
                $this->assertSame($contract->currency, $charge->currency);
            }

            foreach ($contract->payments as $payment) {
                $this->assertSame($contract->currency, $payment->currency);
            }
        });

        $this->assertSame(
            1,
            $mixedCurrencyContracts,
            'Expected exactly one billing_currency_mismatch edge contract in the seed',
        );
    }

    private function assertMultipleCurrenciesAndTimezonesPresent(): void
    {
        $sites = Site::query()->get();

        $currencies = $sites->pluck('currency')->unique()->filter()->values();
        $timezones = $sites->pluck('timezone')->unique()->filter()->values();

        $this->assertGreaterThanOrEqual(2, $currencies->count());
        $this->assertGreaterThanOrEqual(2, $timezones->count());
        $this->assertTrue($currencies->contains('EUR'));
        $this->assertTrue($currencies->contains('GBP'));

        $contractCurrencies = Contract::query()->pluck('currency')->unique()->values();
        $this->assertTrue($contractCurrencies->contains('EUR'));
        $this->assertTrue($contractCurrencies->contains('GBP'));
    }
}
