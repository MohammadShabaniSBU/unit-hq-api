<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\Site;
use App\Models\SiteMap;
use App\Models\Unit;
use App\Support\Facility\SiteMapIdMatcher;
use App\Support\Facility\SvgSanitizer;
use Database\Seeders\Demo\FloorPlanStage;
use Database\Seeders\Demo\StageSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * S20-03: demo stage floor plans.
 *
 * StageSeeder runs once for the class (transactions disabled) so the 2 000-unit
 * stage is not rebuilt per method. Schema is wiped in tearDownAfterClass so
 * later RefreshDatabase suites start clean.
 */
class DemoFloorPlanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string|null>
     */
    protected $connectionsToTransact = [];

    private static bool $stageSeeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$stageSeeded) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            $this->seed(StageSeeder::class);
            self::$stageSeeded = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$stageSeeded) {
            $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$stageSeeded = false;
            HandleExceptions::flushState();
        }

        parent::tearDownAfterClass();
    }

    public function test_demo_seed_creates_four_floors_per_site(): void
    {
        $this->assertSame(20, SiteMap::query()->count());

        $sites = Site::query()->orderBy('code')->get();
        $this->assertCount(5, $sites);

        foreach ($sites as $site) {
            $maps = SiteMap::query()
                ->where('site_id', $site->id)
                ->orderBy('sort_order')
                ->get();

            $this->assertCount(4, $maps);
            $this->assertSame([0, 1, 2, 3], $maps->pluck('sort_order')->all());
            $this->assertSame(
                ['Ground floor', 'First floor', 'Second floor', 'Third floor'],
                $maps->pluck('floor_name')->all()
            );
            $this->assertCount(4, $maps->pluck('floor_name')->unique());
        }
    }

    public function test_every_intact_map_has_no_orphan_shapes(): void
    {
        $maps = SiteMap::query()->with('site')->get();

        foreach ($maps as $map) {
            if (
                $map->site->code === 'PAR-01'
                && $map->floor_name === 'Third floor'
            ) {
                continue;
            }

            $result = SiteMapIdMatcher::match($map->site, $map->svg_map);
            $this->assertSame(
                [],
                $result['orphan_shapes'],
                "{$map->site->code} / {$map->floor_name} should have no orphan shapes"
            );
        }
    }

    public function test_site_units_are_fully_covered(): void
    {
        $intactCodes = Site::query()
            ->where('code', '!=', 'PAR-01')
            ->pluck('code');

        foreach ($intactCodes as $code) {
            $site = Site::query()->where('code', $code)->firstOrFail();
            $unitNumbers = Unit::query()
                ->where('site_id', $site->id)
                ->pluck('unit_number')
                ->sort()
                ->values()
                ->all();

            $matched = [];
            foreach (SiteMap::query()->where('site_id', $site->id)->get() as $map) {
                $result = SiteMapIdMatcher::match($site, $map->svg_map);
                foreach ($result['matched'] as $number) {
                    $matched[$number] = true;
                }
            }

            $union = array_keys($matched);
            sort($union);

            $this->assertSame($unitNumbers, $union, "{$code} units must be fully covered");
            $this->assertCount(400, $union);
        }
    }

    public function test_imperfect_map_reports_expected_buckets(): void
    {
        $site = Site::query()->where('code', 'PAR-01')->firstOrFail();
        $map = SiteMap::query()
            ->where('site_id', $site->id)
            ->where('floor_name', 'Third floor')
            ->firstOrFail();

        $result = SiteMapIdMatcher::match($site, $map->svg_map);

        $this->assertSame(
            ['PAR-01-XX-01', 'PAR-01-XX-02'],
            $result['orphan_shapes']
        );

        $matched = [];
        foreach (SiteMap::query()->where('site_id', $site->id)->get() as $floorMap) {
            $floorResult = SiteMapIdMatcher::match($site, $floorMap->svg_map);
            foreach ($floorResult['matched'] as $number) {
                $matched[$number] = true;
            }
        }

        $unitNumbers = Unit::query()
            ->where('site_id', $site->id)
            ->pluck('unit_number')
            ->all();

        $uncovered = array_values(array_diff($unitNumbers, array_keys($matched)));
        sort($uncovered);

        $this->assertCount(3, $uncovered, 'PAR-01 site should report exactly 3 uncovered units');
    }

    public function test_stored_svg_is_already_sanitized(): void
    {
        foreach (SiteMap::query()->cursor() as $map) {
            $this->assertSame(
                $map->svg_map,
                SvgSanitizer::sanitize($map->svg_map),
                "SiteMap #{$map->id} svg_map should equal its own sanitize output"
            );
        }
    }

    public function test_floor_assignment_is_deterministic(): void
    {
        $first = $this->mapSnapshot();

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(StageSeeder::class);
        self::$stageSeeded = true;

        $second = $this->mapSnapshot();

        $this->assertSame($first, $second);
    }

    public function test_generation_performs_no_random_draws(): void
    {
        $sites = Site::query()->orderBy('id')->get();

        mt_srand(1);
        $expected = mt_rand();

        mt_srand(1);
        FloorPlanStage::seed($sites);
        $actual = mt_rand();

        $this->assertSame($expected, $actual);
    }

    public function test_generation_stays_within_budget(): void
    {
        $sites = Site::query()->orderBy('id')->get();
        SiteMap::query()->delete();

        $start = hrtime(true);
        FloorPlanStage::seed($sites);
        $elapsedSeconds = (hrtime(true) - $start) / 1_000_000_000;

        $this->assertSame(20, SiteMap::query()->count());
        $this->assertLessThan(
            5.0,
            $elapsedSeconds,
            sprintf('Floor plan generation took %.2f s (budget 5 s)', $elapsedSeconds)
        );
    }

    /**
     * @return array<string, string>
     */
    private function mapSnapshot(): array
    {
        $snapshot = [];

        $maps = SiteMap::query()
            ->with('site:id,code')
            ->get()
            ->sortBy([
                fn (SiteMap $map): string => $map->site->code,
                fn (SiteMap $map): int => $map->sort_order,
            ]);

        foreach ($maps as $map) {
            $snapshot[$map->site->code.'|'.$map->floor_name] = $map->svg_map;
        }

        return $snapshot;
    }
}
