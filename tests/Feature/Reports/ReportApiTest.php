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
}
