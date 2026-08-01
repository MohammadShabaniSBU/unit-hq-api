<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Enums\UnitState;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Occupancy\Availability;
use App\Support\Time\SiteClock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SeederIntegrityTest extends TestCase
{
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

    protected function tearDown(): void
    {
        // Leave an empty schema for subsequent RefreshDatabase tests.
        Artisan::call('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    public function test_seeded_dataset_integrity(): void
    {
        $exit = Artisan::call('db:seed', ['--force' => true]);
        $this->assertSame(0, $exit, Artisan::output());

        $this->assertNoOverlappingOccupancies();
        $this->assertNoOverlappingBlockingHolds();
        $this->assertEveryContractHasOccupancy();
        $this->assertAllUnitStatesPresent();
        $this->assertEveryContractIsSingleCurrency();
        $this->assertMultipleCurrenciesAndTimezonesPresent();
    }

    private function assertNoOverlappingOccupancies(): void
    {
        $occupancies = UnitOccupancy::query()
            ->orderBy('unit_id')
            ->orderBy('started_on')
            ->get()
            ->groupBy('unit_id');

        foreach ($occupancies as $unitId => $rows) {
            $sorted = $rows->values();
            for ($i = 0; $i < $sorted->count(); $i++) {
                for ($j = $i + 1; $j < $sorted->count(); $j++) {
                    $a = $sorted[$i];
                    $b = $sorted[$j];
                    $this->assertFalse(
                        $this->rangesOverlap(
                            $a->started_on->format('Y-m-d'),
                            $a->ended_on?->format('Y-m-d'),
                            $b->started_on->format('Y-m-d'),
                            $b->ended_on?->format('Y-m-d'),
                        ),
                        "Overlapping occupancies on unit {$unitId}",
                    );
                }
            }
        }
    }

    private function assertNoOverlappingBlockingHolds(): void
    {
        $holds = UnitHold::query()
            ->whereNull('released_at')
            ->where('hold_type', '<>', HoldType::Overlock->value)
            ->orderBy('unit_id')
            ->orderBy('starts_on')
            ->get()
            ->groupBy('unit_id');

        foreach ($holds as $unitId => $rows) {
            $sorted = $rows->values();
            for ($i = 0; $i < $sorted->count(); $i++) {
                for ($j = $i + 1; $j < $sorted->count(); $j++) {
                    $a = $sorted[$i];
                    $b = $sorted[$j];
                    $this->assertFalse(
                        $this->rangesOverlap(
                            $a->starts_on->format('Y-m-d'),
                            $a->ends_on?->format('Y-m-d'),
                            $b->starts_on->format('Y-m-d'),
                            $b->ends_on?->format('Y-m-d'),
                        ),
                        "Overlapping blocking holds on unit {$unitId}",
                    );
                }
            }
        }
    }

    private function assertEveryContractHasOccupancy(): void
    {
        // Cancelled contracts never commenced — no occupancy row by design (S02-01).
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
        $states = [];

        Unit::query()->with('site')->each(function (Unit $unit) use (&$states): void {
            $on = SiteClock::today($unit->site);
            $states[Availability::stateOn($unit->id, $on)->value] = true;
        });

        foreach (UnitState::cases() as $state) {
            $this->assertArrayHasKey(
                $state->value,
                $states,
                "Expected at least one unit in state {$state->value}",
            );
        }
    }

    private function assertEveryContractIsSingleCurrency(): void
    {
        // S05-02 seeds exactly one deliberate mixed-currency contract so
        // billing:run surfaces failed/currency_mismatch.
        $mixedCurrencyContracts = 0;

        Contract::query()->with('items.price')->each(function (Contract $contract) use (&$mixedCurrencyContracts): void {
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

            foreach (Charge::query()->where('contract_id', $contract->id)->pluck('currency') as $currency) {
                $this->assertSame($contract->currency, $currency);
            }

            foreach (Payment::query()->where('contract_id', $contract->id)->pluck('currency') as $currency) {
                $this->assertSame($contract->currency, $currency);
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

    private function rangesOverlap(
        string $aStart,
        ?string $aEnd,
        string $bStart,
        ?string $bEnd,
    ): bool {
        $aBeforeBEnd = $bEnd === null || $aStart < $bEnd;
        $bBeforeAEnd = $aEnd === null || $bStart < $aEnd;

        return $aBeforeBEnd && $bBeforeAEnd;
    }
}
