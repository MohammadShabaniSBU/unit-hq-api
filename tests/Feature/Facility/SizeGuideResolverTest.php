<?php

declare(strict_types=1);

namespace Tests\Feature\Facility;

use App\Enums\SizeGuideMetric;
use App\Models\Site;
use App\Models\SizeGuide;
use App\Models\UnitClass;
use App\Support\Facility\SizeGuideResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SizeGuideResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function site_specific_row_beats_company_default(): void
    {
        $site = Site::factory()->create();
        SizeGuide::factory()->create([
            'metric' => SizeGuideMetric::StandardBoxes,
            'min_quantity' => 17,
            'max_quantity' => 28,
            'min_size' => '12.00',
            'max_size' => '16.00',
        ]);
        $siteRow = SizeGuide::factory()->create([
            'site_id' => $site->id,
            'metric' => SizeGuideMetric::StandardBoxes,
            'min_quantity' => 17,
            'max_quantity' => 28,
            'min_size' => '16.00',
            'max_size' => '20.00',
        ]);

        $resolved = app(SizeGuideResolver::class)->resolve(
            SizeGuideMetric::StandardBoxes,
            24,
            $site->id,
        );

        $this->assertCount(1, $resolved);
        $this->assertTrue($resolved->first()->is($siteRow));
    }

    #[Test]
    public function class_specific_row_beats_size_band(): void
    {
        $site = Site::factory()->create();
        $class = UnitClass::factory()->create(['label' => 'Trastero 15 m²', 'size' => 15]);
        SizeGuide::factory()->create([
            'site_id' => $site->id,
            'metric' => SizeGuideMetric::StandardBoxes,
            'min_quantity' => 17,
            'max_quantity' => 28,
            'min_size' => '12.00',
            'max_size' => '16.00',
        ]);
        $classRow = SizeGuide::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
            'metric' => SizeGuideMetric::StandardBoxes,
            'min_quantity' => 17,
            'max_quantity' => 28,
            'min_size' => null,
            'max_size' => null,
        ]);

        $resolved = app(SizeGuideResolver::class)->resolve(
            SizeGuideMetric::StandardBoxes,
            24,
            $site->id,
        );

        $this->assertCount(1, $resolved);
        $this->assertTrue($resolved->first()->is($classRow));
    }

    #[Test]
    public function archived_rows_never_resolve(): void
    {
        SizeGuide::factory()->archived()->create([
            'metric' => SizeGuideMetric::StandardBoxes,
            'min_quantity' => 17,
            'max_quantity' => 28,
            'min_size' => '12.00',
            'max_size' => '16.00',
        ]);

        $resolved = app(SizeGuideResolver::class)->resolve(
            SizeGuideMetric::StandardBoxes,
            24,
            null,
        );

        $this->assertCount(0, $resolved);
    }

    #[Test]
    public function omitted_quantity_keeps_distinct_company_bands_when_a_site_overrides_one(): void
    {
        $site = Site::factory()->create();
        $small = SizeGuide::factory()->create([
            'metric' => SizeGuideMetric::StandardBoxes,
            'min_quantity' => 1,
            'max_quantity' => 8,
            'min_size' => '5.00',
            'max_size' => '8.00',
        ]);
        SizeGuide::factory()->create([
            'metric' => SizeGuideMetric::StandardBoxes,
            'min_quantity' => 17,
            'max_quantity' => 28,
            'min_size' => '12.00',
            'max_size' => '16.00',
        ]);
        $siteOverride = SizeGuide::factory()->create([
            'site_id' => $site->id,
            'metric' => SizeGuideMetric::StandardBoxes,
            'min_quantity' => 17,
            'max_quantity' => 28,
            'min_size' => '16.00',
            'max_size' => '20.00',
        ]);

        $resolved = app(SizeGuideResolver::class)->resolve(
            SizeGuideMetric::StandardBoxes,
            null,
            $site->id,
        );

        $ids = $resolved->pluck('id')->all();
        $this->assertContains($small->id, $ids);
        $this->assertContains($siteOverride->id, $ids);
        $this->assertCount(2, $resolved);
    }
}
