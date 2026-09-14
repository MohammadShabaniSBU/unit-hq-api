<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Country;
use App\Models\Site;
use App\Models\SiteMap;
use App\Models\Unit;
use App\Models\UnitClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoShortenUnitNumbersCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function remaps_units_and_floor_map_labels(): void
    {
        [$site, $map] = $this->seedLegacySite();

        $this->artisan('demo:shorten-units')
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Renamed', '2'],
                    ['Skipped (already short)', '0'],
                    ['Skipped (unrecognized)', '0'],
                    ['Skipped (target taken)', '0'],
                    ['Maps updated', '1'],
                ],
            )
            ->assertSuccessful();

        $this->assertSame(
            ['J1', 'J7'],
            Unit::query()->where('site_id', $site->id)->orderBy('id')->pluck('unit_number')->all()
        );

        $map->refresh();
        $this->assertStringContainsString('id="J1"', $map->svg_map);
        $this->assertStringContainsString('data-unit-number="J1"', $map->svg_map);
        $this->assertStringContainsString('>J1</text>', $map->svg_map);
        $this->assertStringContainsString('id="J7"', $map->svg_map);
        $this->assertStringContainsString('data-unit-number="J7"', $map->svg_map);
        $this->assertStringContainsString('>J7</text>', $map->svg_map);
        $this->assertStringNotContainsString('MAD-01-SS3-01', $map->svg_map);
        $this->assertStringNotContainsString('MAD-01-SS3-07', $map->svg_map);

        $this->assertSame('J1', $map->scene['items'][0]['unit_number'] ?? null);
        $this->assertStringContainsString('J1', (string) ($map->scene['backgroundSvg'] ?? ''));
        $this->assertStringNotContainsString('MAD-01-SS3-01', (string) ($map->scene['backgroundSvg'] ?? ''));
    }

    #[Test]
    public function second_run_is_a_noop(): void
    {
        $this->seedLegacySite();

        $this->artisan('demo:shorten-units')->assertSuccessful();

        $svg = SiteMap::query()->value('svg_map');
        $numbers = Unit::query()->orderBy('id')->pluck('unit_number')->all();

        $this->artisan('demo:shorten-units')
            ->assertSuccessful()
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Renamed', '0'],
                    ['Skipped (already short)', '2'],
                    ['Skipped (unrecognized)', '0'],
                    ['Skipped (target taken)', '0'],
                    ['Maps updated', '0'],
                ],
            );

        $this->assertSame($numbers, Unit::query()->orderBy('id')->pluck('unit_number')->all());
        $this->assertSame($svg, SiteMap::query()->value('svg_map'));
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $this->seedLegacySite();

        $this->artisan('demo:shorten-units', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run');

        $this->assertSame(
            ['MAD-01-SS3-01', 'MAD-01-SS3-07'],
            Unit::query()->orderBy('id')->pluck('unit_number')->all()
        );
        $this->assertStringContainsString('MAD-01-SS3-01', (string) SiteMap::query()->value('svg_map'));
    }

    #[Test]
    public function refuses_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('demo:shorten-units')
            ->expectsOutputToContain('production')
            ->assertFailed();
    }

    /**
     * @return array{0: Site, 1: SiteMap}
     */
    private function seedLegacySite(): array
    {
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create([
            'code' => 'MAD-01',
            'country_id' => $country->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
        ]);
        $class = UnitClass::factory()->create([
            'code' => 'SS3',
            'label' => 'Trastero 7 m²',
        ]);

        Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
            'unit_number' => 'MAD-01-SS3-01',
        ]);
        Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
            'unit_number' => 'MAD-01-SS3-07',
        ]);

        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg">
  <g class="storage-unit" id="MAD-01-SS3-01" data-unit-number="MAD-01-SS3-01">
    <text class="unit-label">MAD-01-SS3-01</text>
  </g>
  <g class="storage-unit" id="MAD-01-SS3-07" data-unit-number="MAD-01-SS3-07">
    <text class="unit-label">MAD-01-SS3-07</text>
  </g>
</svg>
SVG;

        $map = SiteMap::query()->create([
            'site_id' => $site->id,
            'floor_name' => 'Planta baja',
            'svg_map' => $svg,
            'sort_order' => 0,
            'scene' => [
                'version' => 1,
                'viewBox' => ['width' => 100.0, 'height' => 100.0],
                'backgroundSvg' => '<text>MAD-01-SS3-01</text>',
                'items' => [
                    [
                        'id' => 'u1',
                        'type' => 'unit',
                        'x' => 10.0,
                        'y' => 10.0,
                        'width' => 20.0,
                        'height' => 20.0,
                        'unit_number' => 'MAD-01-SS3-01',
                    ],
                ],
            ],
        ]);

        return [$site, $map];
    }
}
