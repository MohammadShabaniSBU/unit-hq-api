<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Reports;

use App\Models\Country;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Support\Reports\ReportFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReportFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_and_defaults(): void
    {
        $defaults = ReportFilters::validateAndMake([]);
        $this->assertNull($defaults->siteIds);
        $this->assertNull($defaults->from);
        $this->assertNull($defaults->to);
        $this->assertNull($defaults->asOf);
        $this->assertSame('en', $defaults->locale);

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'currency' => 'EUR',
        ]);

        $filters = ReportFilters::validateAndMake([
            'site_ids' => [$site->id],
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'as_of' => '2026-01-15',
            'locale' => 'es',
        ]);
        $this->assertSame([$site->id], $filters->siteIds);
        $this->assertSame('2026-01-01', $filters->from);
        $this->assertSame('2026-01-31', $filters->to);
        $this->assertSame('2026-01-15', $filters->asOf);
        $this->assertSame('es', $filters->locale);

        try {
            ReportFilters::validateAndMake(['locale' => 'de']);
            $this->fail('Expected invalid locale to throw.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('locale', $e->errors());
        }

        try {
            ReportFilters::validateAndMake([
                'from' => '2026-02-01',
                'to' => '2026-01-01',
            ]);
            $this->fail('Expected to < from to throw.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('to', $e->errors());
        }

        try {
            ReportFilters::validateAndMake(['site_ids' => [999999]]);
            $this->fail('Expected missing site id to throw.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('site_ids.0', $e->errors());
        }
    }
}
