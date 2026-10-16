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

class DemoRenameSizeCodesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function renames_class_codes_and_map_data_class(): void
    {
        $this->seedWorld();

        $this->artisan('demo:rename-size-codes')
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Classes renamed', '3'],
                    ['Maps updated', '1'],
                ],
            )
            ->assertSuccessful();

        $this->assertSame(
            ['XL10', 'XS2', 'XS2.5'],
            UnitClass::query()->orderBy('code')->pluck('code')->all(),
        );

        $svg = (string) SiteMap::query()->value('svg_map');
        $this->assertStringContainsString('data-class="XS2.5"', $svg);
        $this->assertStringContainsString('data-class="XS2"', $svg);
        $this->assertStringNotContainsString('data-class="SS', $svg);
        $this->assertStringContainsString('data-unit-number="AL1"', $svg);
        $this->assertSame(['AL1'], Unit::query()->orderBy('id')->pluck('unit_number')->all());
    }

    #[Test]
    public function second_run_renames_nothing(): void
    {
        $this->seedWorld();

        $this->artisan('demo:rename-size-codes')->assertSuccessful();

        $codes = UnitClass::query()->orderBy('code')->pluck('code')->all();
        $svg = SiteMap::query()->value('svg_map');
        $numbers = Unit::query()->orderBy('id')->pluck('unit_number')->all();

        $this->artisan('demo:rename-size-codes')
            ->expectsTable(
                ['Metric', 'Count'],
                [
                    ['Classes renamed', '0'],
                    ['Maps updated', '0'],
                ],
            )
            ->assertSuccessful();

        $this->assertSame($codes, UnitClass::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame($svg, SiteMap::query()->value('svg_map'));
        $this->assertSame($numbers, Unit::query()->orderBy('id')->pluck('unit_number')->all());
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $this->seedWorld();

        $this->artisan('demo:rename-size-codes', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(
            ['AL10', 'SS2', 'SS2.5'],
            UnitClass::query()->orderBy('code')->pluck('code')->all(),
        );
        $svg = (string) SiteMap::query()->value('svg_map');
        $this->assertStringContainsString('data-class="SS2.5"', $svg);
        $this->assertStringContainsString('data-unit-number="AL1"', $svg);
        $this->assertSame(['AL1'], Unit::query()->pluck('unit_number')->all());
    }

    #[Test]
    public function refuses_production(): void
    {
        $this->seedWorld();
        $this->app['env'] = 'production';

        $this->artisan('demo:rename-size-codes')
            ->expectsOutputToContain('production')
            ->assertFailed();

        $this->assertSame(
            ['AL10', 'SS2', 'SS2.5'],
            UnitClass::query()->orderBy('code')->pluck('code')->all(),
        );
    }

    private function seedWorld(): void
    {
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create([
            'code' => 'MAD-01',
            'country_id' => $country->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
        ]);

        foreach (['SS2', 'SS2.5', 'AL10'] as $code) {
            UnitClass::factory()->create([
                'code' => $code,
                'label' => $code,
            ]);
        }

        $class = UnitClass::query()->where('code', 'AL10')->firstOrFail();
        Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
            'unit_number' => 'AL1',
        ]);

        SiteMap::query()->create([
            'site_id' => $site->id,
            'floor_name' => 'Planta baja',
            'sort_order' => 0,
            'svg_map' => <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg">
  <g class="storage-unit" id="AL1" data-unit-number="AL1" data-class="SS2.5">
    <text class="unit-label">AL1</text>
  </g>
  <g class="storage-unit" id="B1" data-unit-number="B1" data-class="SS2">
    <text class="unit-label">B1</text>
  </g>
</svg>
SVG,
        ]);
    }
}
