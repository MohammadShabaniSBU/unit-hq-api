<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Price;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Reports\OccupancyMetrics;
use App\Support\Reports\OccupancyReport;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class OccupancyTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private Price $cataloguePrice;

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
        $this->unitClass = UnitClass::factory()->create([
            'code' => 'S10',
            'label' => 'Small 10',
            'size' => '10.00',
        ]);
        [, $this->cataloguePrice] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->unitClass->update(['current_price_id' => $this->cataloguePrice->id]);

        Sanctum::actingAs($this->employee);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_three_definitions_constructed_seeds(): void
    {
        // 4 enabled units, one under maintenance (shrinks denom to 3).
        $u1 = $this->makeUnit('A-1');
        $this->makeUnit('A-2');
        $this->makeUnit('A-3');
        $maint = $this->makeUnit('M-1');

        UnitHold::query()->create([
            'unit_id' => $maint->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'reason' => 'Roof',
        ]);

        // One discounted tenant at 50 vs catalogue 100 → economic below unit.
        $this->occupy($u1, '50.00');

        $snap = OccupancyMetrics::snapshot('2026-06-15', [$this->site->id]);

        $this->assertSame(1, $snap['occupied_units']);
        $this->assertSame(3, $snap['rentable_units']);
        $this->assertSame(33.3, $snap['unit_rate']);
        $this->assertSame('10.00', $snap['occupied_area']);
        $this->assertSame('30.00', $snap['rentable_area']);
        $this->assertSame(33.3, $snap['area_rate']);
        $this->assertSame('50.00', $snap['economic_numerator']);
        $this->assertSame('300.00', $snap['economic_denominator']);
        $this->assertSame(16.7, $snap['economic_rate']);
        $this->assertLessThan($snap['unit_rate'], $snap['economic_rate']);

        $report = (new OccupancyReport)->run(new ReportFilters(
            siteIds: [$this->site->id],
            asOf: '2026-06-15',
        ));
        $this->assertSame(33.3, $report->meta['headlines']['unit']['rate']);
        $this->assertSame(16.7, $report->meta['headlines']['economic']['rate']);
        $this->assertSame(3, $report->meta['headlines']['unit']['rentable']);
        $this->assertNotEmpty($report->rows);
    }

    public function test_cross_surface_consistency(): void
    {
        $occupiedA = $this->makeUnit('C-1');
        $occupiedB = $this->makeUnit('C-2');
        $this->makeUnit('C-3'); // vacant rentable

        $this->occupy($occupiedA, '100.00');
        $this->occupy($occupiedB, '100.00');

        $report = (new OccupancyReport)->run(new ReportFilters(
            siteIds: [$this->site->id],
            asOf: '2026-06-15',
        ));
        $reportOccupied = (int) $report->meta['headlines']['unit']['occupied'];

        $matrix = $this->getJson('/api/unit-class-occupancy-matrix');
        $matrix->assertOk();
        $matrixOccupied = 0;
        foreach ($matrix->json('data.rows') as $row) {
            $cell = $row['occupancy'][(string) $this->site->id] ?? null;
            if (is_array($cell)) {
                $matrixOccupied += (int) $cell['occupied'];
            }
        }

        $unitsList = $this->getJson('/api/units?state=occupied&site_id='.$this->site->id.'&per_page=100');
        $unitsList->assertOk();
        $listOccupied = count($unitsList->json('data'));

        $this->assertSame(2, $reportOccupied);
        $this->assertSame($reportOccupied, $matrixOccupied);
        $this->assertSame($reportOccupied, $listOccupied);
    }

    public function test_trend_bounded_spot_checks(): void
    {
        $unit = $this->makeUnit('S-1');
        $this->occupy($unit, '100.00', '2026-03-01');

        $report = new OccupancyReport;
        $result = $report->runBounded(new ReportFilters(
            siteIds: [$this->site->id],
            asOf: '2026-06-15',
            from: '2026-01-01',
            to: '2026-06-30',
        ));

        $series = $result->meta['series'];
        $this->assertIsArray($series);
        $this->assertLessThanOrEqual(24, count($series));
        $this->assertGreaterThanOrEqual(2, count($series));

        $byMonth = collect($series)->keyBy('month_end');

        $jan = OccupancyMetrics::snapshot('2026-01-31', [$this->site->id]);
        $this->assertArrayHasKey('2026-01-31', $byMonth->all());
        $this->assertSame($jan['occupied_units'], $byMonth['2026-01-31']['occupied_units']);
        $this->assertSame($jan['unit_rate'], $byMonth['2026-01-31']['unit_rate']);

        $mar = OccupancyMetrics::snapshot('2026-03-31', [$this->site->id]);
        $this->assertArrayHasKey('2026-03-31', $byMonth->all());
        $this->assertSame($mar['occupied_units'], $byMonth['2026-03-31']['occupied_units']);
        $this->assertSame(1, $byMonth['2026-03-31']['occupied_units']);
        $this->assertSame($mar['unit_rate'], $byMonth['2026-03-31']['unit_rate']);
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

    private function occupy(Unit $unit, string $amount, string $from = '2026-01-01'): Contract
    {
        $price = Price::query()->create([
            'scope' => Price::SCOPE_CONTRACT,
            'amount' => $amount,
            'currency' => 'EUR',
            'effective_from' => null,
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);

        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => $from,
            'deposit_amount' => '0.00',
        ]);

        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => $from,
            'effective_to' => null,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'started_on' => $from,
            'ended_on' => null,
        ]);

        return $contract;
    }
}
