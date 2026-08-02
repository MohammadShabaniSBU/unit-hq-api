<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_json_and_csv(): void
    {
        $this->getJson('/api/reports/demo')->assertUnauthorized();

        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'currency' => 'EUR',
            'name' => 'Alpha',
        ]);
        $unitClass = UnitClass::factory()->create();
        Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
            'enabled' => true,
        ]);

        Sanctum::actingAs($employee);

        $this->getJson('/api/reports/missing')->assertNotFound();

        $json = $this->getJson('/api/reports/demo?site_ids[]='.$site->id);
        $json->assertOk();
        $json->assertJsonPath('data.rows.0.site_name', 'Alpha');
        $json->assertJsonPath('data.rows.0.unit_count', 1);
        $json->assertJsonPath('data.columns.2.type', 'money');
        $json->assertJsonPath('data.columns.2.currency', 'EUR');

        $csv = $this->get('/api/reports/demo?format=csv&locale=es&site_ids[]='.$site->id);
        $csv->assertOk();
        $csv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename="demo-'.$site->id.'-all.csv"', $csv->headers->get('Content-Disposition'));
        $body = $csv->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('Alpha;1;0,00', $body);
    }

    public function test_rent_roll_and_occupancy_json_and_csv(): void
    {
        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'currency' => 'EUR',
            'name' => 'Beta',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create(['code' => 'X', 'size' => '5.00']);
        Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
            'enabled' => true,
        ]);

        Sanctum::actingAs($employee);

        foreach (['rent-roll', 'occupancy'] as $name) {
            $json = $this->getJson('/api/reports/'.$name.'?as_of=2026-06-15&site_ids[]='.$site->id);
            $json->assertOk();
            $json->assertJsonStructure([
                'data' => [
                    'columns',
                    'rows',
                    'meta',
                ],
            ]);

            $csv = $this->get('/api/reports/'.$name.'?format=csv&locale=en&as_of=2026-06-15&site_ids[]='.$site->id);
            $csv->assertOk();
            $csv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->getContent());
        }

        $this->getJson('/api/reports/occupancy?as_of=2026-06-15&site_ids[]='.$site->id)
            ->assertJsonPath('data.meta.headlines.unit.rentable', 1);
    }
}
