<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Insights\Provisioning\MetabaseBlueprints;
use App\Support\Reports\OccupancyMetrics;
use App\Support\Time\SiteClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class OccupancyBlueprintTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Analytics MV is Postgres-only.');
        }
    }

    public function test_card_sql_unit_rate_matches_native_snapshot(): void
    {
        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'UTC',
            'currency' => 'EUR',
        ]);
        $unitClass = UnitClass::factory()->create(['size' => '10.00']);
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );

        $occupied = $this->makeUnit($site, $unitClass, 'OCC-1');
        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
        ]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $occupied->id,
            'price_id' => $price->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $today = SiteClock::today($site)->format('Y-m-d');
        UnitOccupancy::query()->create([
            'unit_id' => $occupied->id,
            'contract_id' => $contract->id,
            'started_on' => $today,
            'ended_on' => null,
        ]);

        $this->makeUnit($site, $unitClass, 'AVL-1');

        foreach (HoldType::cases() as $holdType) {
            $unit = $this->makeUnit($site, $unitClass, 'H-'.$holdType->value);
            UnitHold::query()->create([
                'unit_id' => $unit->id,
                'hold_type' => $holdType,
                'starts_on' => $today,
                'ends_on' => null,
                'reason' => $holdType->requiresReason() ? 'fixture' : null,
            ]);
        }

        $this->makeUnit($site, $unitClass, 'DIS-1', enabled: false);

        DB::statement('REFRESH MATERIALIZED VIEW analytics.mv_unit_state_daily');

        $card = MetabaseBlueprints::get('occupancy')['cards'][0];
        $sql = preg_replace('/\[\[.*?\]\]/s', '', $card['sql']) ?? $card['sql'];
        $row = DB::selectOne($sql);
        $this->assertNotNull($row);

        $asOf = substr((string) $row->as_of, 0, 10);
        $maxDay = DB::selectOne('select max(day)::date::text as d from analytics.mv_unit_state_daily');
        $this->assertSame($maxDay->d, $asOf);

        $snap = OccupancyMetrics::snapshot($asOf, [$site->id]);
        $this->assertEquals($snap['unit_rate'], (float) $row->unit_rate);
        $this->assertSame($snap['occupied_units'], (int) $row->occupied_units);
        $this->assertSame($snap['rentable_units'], (int) $row->rentable_units);
    }

    private function makeUnit(Site $site, UnitClass $unitClass, string $number, bool $enabled = true): Unit
    {
        return Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
            'unit_number' => $number,
            'enabled' => $enabled,
        ]);
    }
}
