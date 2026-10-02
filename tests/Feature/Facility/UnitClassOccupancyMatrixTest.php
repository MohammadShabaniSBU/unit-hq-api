<?php

declare(strict_types=1);

namespace Tests\Feature\Facility;

use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Reports\OccupancyMetrics;
use App\Support\Time\SiteClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class UnitClassOccupancyMatrixTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $offeredClass;

    private UnitClass $bareClass;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'name' => 'Madrid Hub',
        ]);
        $this->offeredClass = UnitClass::factory()->create([
            'code' => 'S10',
            'label' => 'Small 10',
        ]);
        $this->bareClass = UnitClass::factory()->create([
            'code' => 'SS9',
            'label' => 'Unpriced',
        ]);
        $this->createUnitClassCataloguePrice(
            $this->offeredClass->id,
            $this->site->id,
            $this->employee->id,
        );

        $occupied = $this->makeUnit('A-1');
        $this->occupy($occupied);
        $this->makeUnit('A-2');
        $blocked = $this->makeUnit('M-1');
        $this->hold($blocked, HoldType::Maintenance);
        $reserved = $this->makeUnit('R-1');
        $this->hold($reserved, HoldType::Reservation);
        $this->makeUnit('X-1', enabled: false);

        Sanctum::actingAs($this->employee);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_cell_counts_match_rentable_rules(): void
    {
        $response = $this->getJson('/api/unit-class-occupancy-matrix');
        $response->assertOk();

        $offered = $this->cell($response->json('data.rows'), $this->offeredClass->id, $this->site->id);
        $this->assertSame([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->offeredClass->id,
            'offered' => true,
            'total' => 4,
            'rentable' => 3,
            'occupied' => 1,
            'held_blocking' => 1,
            'free' => 1,
        ], $offered);

        $bare = $this->cell($response->json('data.rows'), $this->bareClass->id, $this->site->id);
        $this->assertFalse($bare['offered']);
        $this->assertSame(0, $bare['total']);
        $this->assertSame(0, $bare['rentable']);
        $this->assertSame(0, $bare['occupied']);
        $this->assertSame(0, $bare['held_blocking']);
        $this->assertSame(0, $bare['free']);
    }

    public function test_counts_match_occupancy_report(): void
    {
        $response = $this->getJson('/api/unit-class-occupancy-matrix');
        $response->assertOk();

        $asOf = SiteClock::today($this->site)->toDateString();
        $snap = OccupancyMetrics::snapshot($asOf, [$this->site->id]);

        $this->assertNotEmpty($snap['by_site_class']);

        foreach ($snap['by_site_class'] as $bucket) {
            $cell = $this->cell(
                $response->json('data.rows'),
                $bucket['unit_class_id'],
                $bucket['site_id'],
            );
            $this->assertSame($bucket['occupied'], $cell['occupied']);
            $this->assertSame($bucket['rentable'], $cell['rentable']);
        }
    }

    public function test_site_id_limits_columns(): void
    {
        $other = Site::factory()->create([
            'country_id' => $this->site->country_id,
            'legal_entity_id' => $this->site->legal_entity_id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'name' => 'Barcelona Hub',
        ]);
        Unit::factory()->create([
            'site_id' => $other->id,
            'unit_class_id' => $this->offeredClass->id,
            'unit_number' => 'B-1',
            'enabled' => true,
        ]);

        $response = $this->getJson('/api/unit-class-occupancy-matrix?site_id='.$other->id);
        $response->assertOk();

        $sites = $response->json('data.sites');
        $this->assertCount(1, $sites);
        $this->assertSame($other->id, $sites[0]['id']);

        $cell = $this->cell($response->json('data.rows'), $this->offeredClass->id, $other->id);
        $this->assertFalse($cell['offered']);
        $this->assertSame(1, $cell['total']);
        $this->assertSame(1, $cell['rentable']);
        $this->assertSame(0, $cell['occupied']);
        $this->assertSame(1, $cell['free']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function cell(array $rows, int $unitClassId, int $siteId): array
    {
        foreach ($rows as $row) {
            if ((int) $row['unit_class_id'] !== $unitClassId) {
                continue;
            }

            return $row['occupancy'][(string) $siteId];
        }

        $this->fail("Missing matrix row for unit class {$unitClassId}.");
    }

    private function makeUnit(string $number, bool $enabled = true): Unit
    {
        return Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->offeredClass->id,
            'unit_number' => $number,
            'enabled' => $enabled,
        ]);
    }

    private function occupy(Unit $unit): void
    {
        $contract = Contract::factory()->create([
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-01-01',
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-01-01',
            'ended_on' => null,
        ]);
    }

    private function hold(Unit $unit, HoldType $type): void
    {
        UnitHold::query()->create([
            'unit_id' => $unit->id,
            'hold_type' => $type,
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'reason' => 'Matrix fixture',
        ]);
    }
}
