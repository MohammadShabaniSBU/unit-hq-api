<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Site;
use App\Models\SiteMap;
use App\Models\Unit;
use App\Support\Facility\FloorPlanGenerator;
use App\Support\Facility\SiteMapIdMatcher;
use App\Support\Facility\SvgSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generates demo-world floor plans from seeded units.
 *
 * Deterministic only — no mt_rand(), fake(), shuffle(), Collection::random(),
 * or Str::random(). The StageSeeder RNG stream must not move.
 */
final class FloorPlanStage
{
    /**
     * One deliberately imperfect map so the validator's buckets have real data.
     *
     * @var array{
     *     site_code: string,
     *     floor_name: string,
     *     uncovered_units: int,
     *     orphan_shapes: array<int, string>
     * }
     */
    private const IMPERFECT_MAP = [
        'site_code' => 'MAD-05',
        'floor_name' => 'Planta 3',
        'uncovered_units' => 3,
        'orphan_shapes' => ['MAD-05-XX-01', 'MAD-05-XX-02'],
    ];

    /** @var array<int, array{floor_name: string, sort_order: int, entry: bool}> */
    private const FLOORS = [
        ['floor_name' => 'Planta baja', 'sort_order' => 0, 'entry' => true],
        ['floor_name' => 'Planta 1', 'sort_order' => 1, 'entry' => false],
        ['floor_name' => 'Planta 2', 'sort_order' => 2, 'entry' => false],
        ['floor_name' => 'Planta 3', 'sort_order' => 3, 'entry' => false],
    ];

    /**
     * @param  iterable<int, Site>  $sites
     */
    public static function seed(iterable $sites): void
    {
        foreach ($sites as $site) {
            self::seedSite($site);
        }
    }

    /**
     * Rows for the demo:seed summary table.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int, 4: int}>
     */
    public static function summaryRows(): array
    {
        $rows = [];

        $maps = SiteMap::query()
            ->with('site:id,code')
            ->orderBy('site_id')
            ->orderBy('sort_order')
            ->get();

        foreach ($maps as $map) {
            /** @var Site $site */
            $site = $map->site;
            $result = SiteMapIdMatcher::match($site, $map->svg_map);
            $shapeCount = count($result['matched']) + count($result['orphan_shapes']);

            $rows[] = [
                $site->code,
                $map->floor_name,
                $shapeCount,
                count($result['matched']),
                count($result['orphan_shapes']),
            ];
        }

        return $rows;
    }

    private static function seedSite(Site $site): void
    {
        DB::transaction(function () use ($site): void {
            $units = Unit::query()
                ->where('site_id', $site->id)
                ->get(['id', 'unit_number', 'actual_width', 'actual_depth'])
                ->sortBy(fn (Unit $u): array => [
                    (int) Str::afterLast($u->unit_number, '-'),
                    $u->unit_number,
                ])
                ->values();

            $chunks = $units->chunk(max(1, (int) ceil($units->count() / count(self::FLOORS))))->values();

            foreach (self::FLOORS as $index => $floor) {
                /** @var Collection<int, Unit> $chunk */
                $chunk = $chunks->get($index) ?? collect();

                $unitPayload = self::toGeneratorUnits($chunk);
                $options = ['entry' => $floor['entry']];

                if (
                    $site->code === self::IMPERFECT_MAP['site_code']
                    && $floor['floor_name'] === self::IMPERFECT_MAP['floor_name']
                ) {
                    $drop = self::IMPERFECT_MAP['uncovered_units'];
                    if ($drop > 0 && count($unitPayload) >= $drop) {
                        $unitPayload = array_slice($unitPayload, 0, count($unitPayload) - $drop);
                    }
                    $options['orphan_shapes'] = self::IMPERFECT_MAP['orphan_shapes'];
                }

                $svg = SvgSanitizer::sanitize(
                    FloorPlanGenerator::render($floor['floor_name'], $unitPayload, $options)
                );

                SiteMap::query()->updateOrCreate(
                    [
                        'site_id' => $site->id,
                        'floor_name' => $floor['floor_name'],
                    ],
                    [
                        'svg_map' => $svg,
                        'sort_order' => $floor['sort_order'],
                    ]
                );
            }
        });
    }

    /**
     * @param  Collection<int, Unit>  $units
     * @return array<int, array{unit_number: string, width_m: float, depth_m: float}>
     */
    private static function toGeneratorUnits(Collection $units): array
    {
        $payload = [];

        foreach ($units as $unit) {
            $payload[] = [
                'unit_number' => $unit->unit_number,
                'width_m' => $unit->actual_width !== null ? (float) $unit->actual_width : 3.0,
                'depth_m' => $unit->actual_depth !== null ? (float) $unit->actual_depth : 3.0,
            ];
        }

        return $payload;
    }
}
