<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SiteMap;
use App\Models\UnitClass;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rename existing demo unit class codes from SS* / AL* onto XS* / XL*
 * and patch stored floor-map data-class attributes in place.
 */
class DemoRenameSizeCodesCommand extends Command
{
    protected $signature = 'demo:rename-size-codes {--dry-run : Preview remaps without writing}';

    protected $description = 'Rename demo unit class codes SS* to XS* and AL* to XL*, and patch floor-map data-class values';

    /** @var array<string, string> */
    private const PREFIXES = [
        'SS' => 'XS',
        'AL' => 'XL',
    ];

    /**
     * Longer attributes first so SS2.5 is not clipped by SS2.
     *
     * @var array<string, string>
     */
    private const MAP_REPLACEMENTS = [
        'data-class="SS2.5"' => 'data-class="XS2.5"',
        'data-class="SS1.5"' => 'data-class="XS1.5"',
        'data-class="SS3"' => 'data-class="XS3"',
        'data-class="SS2"' => 'data-class="XS2"',
    ];

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('demo:rename-size-codes refuses to run in the production environment.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run — no writes.');
        }

        $renames = $this->plannedClassRenames();
        $collision = $this->collision($renames);
        if ($collision !== null) {
            $this->error($collision);

            return self::FAILURE;
        }

        $maps = SiteMap::query()->orderBy('id')->get();
        $mapsUpdated = 0;

        if ($dryRun) {
            foreach ($maps as $map) {
                if ($this->patchedSvg((string) $map->svg_map) !== $map->svg_map) {
                    $mapsUpdated++;
                }
            }
        } else {
            DB::transaction(function () use ($renames, $maps, &$mapsUpdated): void {
                foreach ($renames as $rename) {
                    UnitClass::query()->whereKey($rename['id'])->update([
                        'code' => $rename['target'],
                    ]);
                }

                foreach ($maps as $map) {
                    $svg = $this->patchedSvg((string) $map->svg_map);
                    if ($svg === $map->svg_map) {
                        continue;
                    }

                    $map->forceFill(['svg_map' => $svg])->saveQuietly();
                    $mapsUpdated++;
                }
            });
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Classes renamed', (string) count($renames)],
                ['Maps updated', (string) $mapsUpdated],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @return list<array{id: int, target: string}>
     */
    private function plannedClassRenames(): array
    {
        $renames = [];

        foreach (UnitClass::query()->orderBy('id')->get(['id', 'code']) as $class) {
            $target = $this->targetCode((string) $class->code);
            if ($target === null) {
                continue;
            }

            $renames[] = [
                'id' => $class->id,
                'target' => $target,
            ];
        }

        return $renames;
    }

    private function targetCode(string $code): ?string
    {
        foreach (self::PREFIXES as $from => $to) {
            if (str_starts_with($code, $from)) {
                return $to.substr($code, strlen($from));
            }
        }

        return null;
    }

    /**
     * @param  list<array{id: int, target: string}>  $renames
     */
    private function collision(array $renames): ?string
    {
        if ($renames === []) {
            return null;
        }

        $claimed = [];
        foreach ($renames as $rename) {
            if (isset($claimed[$rename['target']])) {
                return "Target code {$rename['target']} is claimed by more than one class.";
            }

            $claimed[$rename['target']] = $rename['id'];
        }

        $existing = UnitClass::query()
            ->whereIn('code', array_keys($claimed))
            ->value('code');

        if (is_string($existing) && $existing !== '') {
            return "Target code {$existing} already exists.";
        }

        return null;
    }

    private function patchedSvg(string $svg): string
    {
        return str_replace(
            array_keys(self::MAP_REPLACEMENTS),
            array_values(self::MAP_REPLACEMENTS),
            $svg,
        );
    }
}
