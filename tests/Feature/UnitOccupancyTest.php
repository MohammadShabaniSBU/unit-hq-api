<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;
use Tests\Support\AuthenticatesAsEmployee;

class UnitOccupancyTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;
    use AuthenticatesAsEmployee;

    private static bool $failChargeCreate = false;

    private Employee $employee;

    private Site $site;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();

        // Re-register each test — RefreshDatabase reboots the app and clears listeners.
        Charge::creating(function (): void {
            if (self::$failChargeCreate) {
                throw new \RuntimeException('Forced charge failure');
            }
        });

        $this->employee = Employee::factory()->manager()->create();

        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employee->id,
            [
                'amount' => '196.72',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);
    }

    public function test_contract_signing_creates_occupancy(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-03-15',
            'move_in_date' => '2026-03-15',
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);

        $response->assertCreated();
        $contractId = $response->json('data.id');

        $this->assertTrue(
            UnitOccupancy::query()
                ->where('unit_id', $this->unit->id)
                ->where('contract_id', $contractId)
                ->whereDate('started_on', '2026-03-15')
                ->whereNull('ended_on')
                ->exists()
        );

        $this->assertCount(1, $response->json('data.occupancies'));
        $this->assertSame($this->unit->id, $response->json('data.occupancies.0.unit_id'));
        $this->assertSame('2026-03-15', $response->json('data.occupancies.0.started_on'));
        $this->assertNull($response->json('data.occupancies.0.ended_on'));

        $unitShow = $this->getJson("/api/units/{$this->unit->id}");
        $unitShow->assertOk();
        $this->assertSame($contractId, $unitShow->json('data.current_occupancy.contract_id'));
        $this->assertSame('2026-03-15', $unitShow->json('data.current_occupancy.started_on'));
    }

    public function test_signing_occupied_unit_is_rejected(): void
    {
        $contact = Contact::factory()->create();

        $first = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-03-01',
            'move_in_date' => '2026-03-01',
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);
        $first->assertCreated();

        $beforeContracts = Contract::query()->count();
        $beforeOccupancies = UnitOccupancy::query()->count();
        $beforeCharges = Charge::query()->count();

        $second = $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-03-15',
            'move_in_date' => '2026-03-15',
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);

        $second->assertStatus(422)->assertJsonValidationErrors(['unit_id']);
        $this->assertSame($beforeContracts, Contract::query()->count());
        $this->assertSame($beforeOccupancies, UnitOccupancy::query()->count());
        $this->assertSame($beforeCharges, Charge::query()->count());
    }

    public function test_adjacent_ranges_do_not_conflict(): void
    {
        $contact = Contact::factory()->create();

        $first = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-02-01',
            'end_date' => '2026-03-01',
            'move_in_date' => '2026-02-01',
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);
        $first->assertCreated();
        $this->assertSame('2026-03-01', $first->json('data.occupancies.0.ended_on'));

        $second = $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-03-01',
            'move_in_date' => '2026-03-01',
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);

        $second->assertCreated();
        $this->assertDatabaseCount('unit_occupancies', 2);
    }

    public function test_occupancy_rolls_back_with_contract(): void
    {
        $contact = Contact::factory()->create();

        $beforeContracts = Contract::query()->count();
        $beforeOccupancies = UnitOccupancy::query()->count();

        self::$failChargeCreate = true;
        try {
            $response = $this->postJson('/api/contracts', [
                'contact_id' => $contact->id,
                'start_date' => '2026-04-01',
                'move_in_date' => '2026-04-01',
                'items' => [
                    [
                        'item_type' => 'unit',
                        'item_id' => $this->unit->id,
                        'amount' => '196.72',
                    ],
                ],
            ]);
            $response->assertStatus(500);
        } finally {
            self::$failChargeCreate = false;
        }

        $this->assertSame($beforeContracts, Contract::query()->count());
        $this->assertSame($beforeOccupancies, UnitOccupancy::query()->count());
    }

    public function test_end_dated_contract_sets_ended_on(): void
    {
        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-05-01',
            'end_date' => '2026-08-01',
            'move_in_date' => '2026-05-01',
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertTrue(
            UnitOccupancy::query()
                ->where('contract_id', $response->json('data.id'))
                ->whereDate('started_on', '2026-05-01')
                ->whereDate('ended_on', '2026-08-01')
                ->exists()
        );
        $this->assertSame('2026-08-01', $response->json('data.occupancies.0.ended_on'));
    }

    public function test_dates_are_site_local(): void
    {
        // 2026-07-30 22:30 UTC → 2026-07-31 in Madrid, still 2026-07-30 in UTC.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 22:30:00', 'UTC'));

        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-31',
            'move_in_date' => '2026-07-31',
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);

        $response->assertCreated();
        $contractId = $response->json('data.id');
        $this->assertSame('2026-07-31', $response->json('data.occupancies.0.started_on'));
        $this->assertTrue(
            UnitOccupancy::query()
                ->where('contract_id', $contractId)
                ->whereDate('started_on', '2026-07-31')
                ->exists()
        );

        // Must not stamp the server UTC civil date.
        $this->assertFalse(
            UnitOccupancy::query()
                ->where('contract_id', $contractId)
                ->whereDate('started_on', '2026-07-30')
                ->exists()
        );

        CarbonImmutable::setTestNow();
    }
}
