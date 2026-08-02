<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Reports;

use App\Models\Country;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Reports\AbstractReport;
use App\Support\Reports\DemoReport;
use App\Support\Reports\ReportFilters;
use App\Support\Reports\ReportRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_bounded_query_inheritance(): void
    {
        foreach (ReportRegistry::classes() as $class) {
            $this->assertTrue(
                is_subclass_of($class, AbstractReport::class),
                "{$class} must extend AbstractReport.",
            );

            $report = new $class;
            $this->assertInstanceOf(AbstractReport::class, $report);
            $this->assertIsInt($report->maxQueries());
            $this->assertGreaterThan(0, $report->maxQueries());
        }

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'currency' => 'EUR',
            'name' => 'Demo Site',
        ]);
        $unitClass = UnitClass::factory()->create();
        Unit::factory()->count(2)->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
            'enabled' => true,
        ]);

        $demo = new DemoReport;
        $this->assertTrue(is_a($demo, AbstractReport::class));

        $result = $demo->runBounded(new ReportFilters(siteIds: [$site->id]));
        $this->assertCount(1, $result->rows);
        $this->assertSame('Demo Site', $result->rows[0]['site_name']);
        $this->assertSame(2, $result->rows[0]['unit_count']);
        $this->assertSame('EUR', $result->columns[2]->currency);
    }
}
