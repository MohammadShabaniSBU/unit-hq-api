<?php

declare(strict_types=1);

namespace Tests\Feature\Facility;

use App\Models\Country;
use App\Models\Site;
use App\Models\SiteMap;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Facility\SiteMapIdMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesAsEmployee;
use Tests\TestCase;

class SiteMapMatchingTest extends TestCase
{
    use RefreshDatabase;
    use AuthenticatesAsEmployee;

    private Site $site;

    private UnitClass $unitClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();

        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
        ]);
        $this->unitClass = UnitClass::factory()->create();
    }

    public function test_matches_on_data_unit_number(): void
    {
        $this->unit('A-1');
        $this->unit('A-2');

        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg">
          <g id="layer1">
            <g class="storage-unit" id="unit-1" data-unit-number="A-1"><rect width="10" height="10"/></g>
            <g class="storage-unit" id="unit-2" data-unit-number="A-2"><rect width="10" height="10"/></g>
          </g>
        </svg>
        SVG;

        $result = SiteMapIdMatcher::match($this->site, $svg);

        $this->assertSame(['A-1', 'A-2'], $result['matched']);
        $this->assertSame([], $result['orphan_shapes']);
        $this->assertSame([], $result['uncovered_units']);
    }

    public function test_structural_ids_are_not_shapes(): void
    {
        $this->unit('A-1');

        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg">
          <g id="layer1">
            <g id="row-3">
              <g class="storage-unit" id="unit-1" data-unit-number="A-1"><rect width="10" height="10"/></g>
            </g>
          </g>
        </svg>
        SVG;

        $result = SiteMapIdMatcher::match($this->site, $svg);

        $this->assertSame(['A-1'], $result['matched']);
        $this->assertSame([], $result['orphan_shapes']);
        $this->assertSame([], $result['uncovered_units']);
        $this->assertNotContains('layer1', $result['matched']);
        $this->assertNotContains('layer1', $result['orphan_shapes']);
        $this->assertNotContains('row-3', $result['orphan_shapes']);
        $this->assertNotContains('unit-1', $result['orphan_shapes']);
    }

    public function test_falls_back_to_id_for_legacy_maps(): void
    {
        $this->unit('A-1');
        $this->unit('A-2');

        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg">
          <g id="A-1"><rect width="10" height="10"/></g>
          <g id="A-2"><rect width="10" height="10"/></g>
        </svg>
        SVG;

        $result = SiteMapIdMatcher::match($this->site, $svg);

        $this->assertSame(['A-1', 'A-2'], $result['matched']);
        $this->assertSame([], $result['orphan_shapes']);
        $this->assertSame([], $result['uncovered_units']);
    }

    public function test_does_not_mix_conventions(): void
    {
        $this->unit('A-1');

        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg">
          <g id="layer1">
            <g class="storage-unit" id="unit-1" data-unit-number="A-1"><rect width="10" height="10"/></g>
            <g id="row-3"><rect width="10" height="10"/></g>
          </g>
        </svg>
        SVG;

        $result = SiteMapIdMatcher::match($this->site, $svg);

        $this->assertSame(['A-1'], $result['matched']);
        $this->assertSame([], $result['orphan_shapes']);
        $this->assertSame([], $result['uncovered_units']);
    }

    public function test_unit_from_another_site_is_an_orphan(): void
    {
        $this->unit('A-1');

        $otherSite = Site::factory()->create([
            'country_id' => $this->site->country_id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
        ]);
        Unit::factory()->create([
            'site_id' => $otherSite->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => 'B-99',
        ]);

        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg">
          <g class="storage-unit" id="unit-1" data-unit-number="A-1"><rect width="10" height="10"/></g>
          <g class="storage-unit" id="unit-2" data-unit-number="B-99"><rect width="10" height="10"/></g>
        </svg>
        SVG;

        $result = SiteMapIdMatcher::match($this->site, $svg);

        $this->assertSame(['A-1'], $result['matched']);
        $this->assertSame(['B-99'], $result['orphan_shapes']);
        $this->assertSame([], $result['uncovered_units']);
    }

    public function test_uncovered_units_reported(): void
    {
        $this->unit('A-1');
        $this->unit('A-2');

        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg">
          <g class="storage-unit" id="unit-1" data-unit-number="A-1"><rect width="10" height="10"/></g>
        </svg>
        SVG;

        $result = SiteMapIdMatcher::match($this->site, $svg);

        $this->assertSame(['A-1'], $result['matched']);
        $this->assertSame([], $result['orphan_shapes']);
        $this->assertSame(['A-2'], $result['uncovered_units']);
    }

    public function test_unparseable_svg_returns_empty_buckets(): void
    {
        $this->unit('A-1');

        $result = SiteMapIdMatcher::match($this->site, 'not-xml-at-all');

        $this->assertSame([], $result['matched']);
        $this->assertSame([], $result['orphan_shapes']);
        $this->assertSame([], $result['uncovered_units']);
    }

    public function test_validate_endpoint_persists_nothing(): void
    {
        $this->unit('A-1');

        $before = SiteMap::query()->count();

        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg">
          <g class="storage-unit" id="unit-1" data-unit-number="A-1"><rect width="10" height="10"/></g>
        </svg>
        SVG;

        $response = $this->postJson("/api/sites/{$this->site->id}/maps/validate", [
            'svg_map' => $svg,
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.id_match.matched', ['A-1']);

        $this->assertSame($before, SiteMap::query()->count());
    }

    public function test_store_matches_against_sanitized_svg(): void
    {
        $this->unit('A-1');

        // Unit number only appears on a <script> the sanitizer removes. Matching
        // the raw input would report A-1 as matched; store must not.
        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg">
          <script data-unit-number="A-1"></script>
          <g id="layer1"><rect width="10" height="10"/></g>
        </svg>
        SVG;

        $rawMatch = SiteMapIdMatcher::match($this->site, $svg);
        $this->assertSame(['A-1'], $rawMatch['matched']);

        $response = $this->postJson("/api/sites/{$this->site->id}/maps", [
            'floor_name' => 'Ground',
            'svg_map' => $svg,
            'sort_order' => 0,
        ]);

        $response->assertCreated();
        $this->assertNotContains('A-1', $response->json('data.id_match.matched') ?? []);
        $this->assertContains('A-1', $response->json('data.id_match.uncovered_units') ?? []);
    }

    private function unit(string $number): Unit
    {
        return Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => $number,
        ]);
    }
}
