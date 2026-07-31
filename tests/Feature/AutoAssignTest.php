<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HoldType;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class AutoAssignTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Site $site;

    private UnitClass $unitClass;

    private Contact $contact;

    /** @var list<int> */
    private array $blockedUnitIds = [];

    private Unit $availableUnit;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 12:00:00', 'Europe/Madrid'));

        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $this->unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $employee->id,
            [
                'amount' => '100.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $this->unitClass->update(['current_price_id' => $price->id]);
        $this->contact = Contact::factory()->create();

        // Occupied
        $occupied = $this->makeUnit('OCC-1');
        $contract = Contract::factory()->create([
            'contact_id' => $this->contact->id,
            'currency' => 'EUR',
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $occupied->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-01-01',
            'ended_on' => null,
        ]);
        $this->blockedUnitIds[] = $occupied->id;

        // Reserved
        $reserved = $this->makeUnit('RES-1');
        UnitHold::query()->create([
            'unit_id' => $reserved->id,
            'hold_type' => HoldType::Reservation,
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-08-01',
        ]);
        $this->blockedUnitIds[] = $reserved->id;

        // Maintenance / damaged / staff_use / other
        foreach ([
            [HoldType::Maintenance, 'MNT-1', 'Repair'],
            [HoldType::Damaged, 'DMG-1', 'Flood'],
            [HoldType::StaffUse, 'STF-1', 'Staff'],
            [HoldType::Other, 'OTH-1', 'Legal'],
        ] as [$type, $number, $reason]) {
            $unit = $this->makeUnit($number);
            UnitHold::query()->create([
                'unit_id' => $unit->id,
                'hold_type' => $type,
                'starts_on' => '2026-07-01',
                'ends_on' => null,
                'reason' => $reason,
            ]);
            $this->blockedUnitIds[] = $unit->id;
        }

        // Overlocked occupied — still blocked by occupancy
        $overlocked = $this->makeUnit('OVL-1');
        $ovContract = Contract::factory()->create([
            'contact_id' => $this->contact->id,
            'currency' => 'EUR',
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $overlocked->id,
            'contract_id' => $ovContract->id,
            'started_on' => '2026-01-01',
            'ended_on' => null,
        ]);
        UnitHold::query()->create([
            'unit_id' => $overlocked->id,
            'hold_type' => HoldType::Overlock,
            'starts_on' => '2026-07-01',
            'ends_on' => null,
        ]);
        $this->blockedUnitIds[] = $overlocked->id;

        $this->availableUnit = $this->makeUnit('AVL-1');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_picks_only_available_units(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/reservations', [
                'site_id' => $this->site->id,
                'unit_class_id' => $this->unitClass->id,
                // omit unit_id → auto-assign
                'contact_id' => Contact::factory()->create()->id,
                'expires_at' => '2026-07-29T12:00:00+02:00',
            ]);

            $response->assertCreated();
            $pickedId = (int) $response->json('data.unit_id');

            $this->assertNotContains($pickedId, $this->blockedUnitIds);
            $this->assertSame($this->availableUnit->id, $pickedId);

            // Release so the next iteration can pick the same available unit again.
            UnitHold::query()
                ->where('reservation_id', $response->json('data.id'))
                ->update(['released_at' => now()]);
        }
    }

    private function makeUnit(string $number): Unit
    {
        return Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => $number,
            'enabled' => true,
        ]);
    }
}
