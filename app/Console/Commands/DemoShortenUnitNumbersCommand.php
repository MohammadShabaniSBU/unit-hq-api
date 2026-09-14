<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteMap;
use App\Models\Unit;
use Database\Seeders\Demo\DemoUnitNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remap existing demo units from `{SITE}-{CLASS}-{NN}` onto the short
 * StageSeeder format and patch stored floor-map labels in place.
 */
class DemoShortenUnitNumbersCommand extends Command
{
    protected $signature = 'demo:shorten-units {--dry-run : Preview remaps without writing}';

    protected $description = 'Rename existing demo units to the short StageSeeder format and patch floor-map labels';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('demo:shorten-units refuses to run in the production environment.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run — no writes.');
        }

        $renamed = 0;
        $alreadyShort = 0;
        $unrecognized = 0;
        $targetTaken = 0;
        $mapsUpdated = 0;

        $sites = Site::query()
            ->with(['units.unitClass', 'siteMaps'])
            ->orderBy('id')
            ->get();

        foreach ($sites as $site) {
            $result = $this->planSite($site);
            $alreadyShort += $result['already_short'];
            $unrecognized += $result['unrecognized'];
            $targetTaken += $result['target_taken'];

            if ($result['replacements'] === []) {
                continue;
            }

            $renamed += count($result['replacements']);

            if ($dryRun) {
                $mapsUpdated += $this->countMapsThatWouldChange($site, $result['replacements']);

                continue;
            }

            DB::transaction(function () use ($site, $result, &$mapsUpdated): void {
                $this->applyUnitRenames($result['renames']);
                $mapsUpdated += $this->patchSiteMaps($site, $result['replacements']);
            });
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Renamed', (string) $renamed],
                ['Skipped (already short)', (string) $alreadyShort],
                ['Skipped (unrecognized)', (string) $unrecognized],
                ['Skipped (target taken)', (string) $targetTaken],
                ['Maps updated', (string) $mapsUpdated],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     replacements: array<string, string>,
     *     renames: list<array{id: int, target: string}>,
     *     already_short: int,
     *     unrecognized: int,
     *     target_taken: int
     * }
     */
    private function planSite(Site $site): array
    {
        $replacements = [];
        $renames = [];
        $alreadyShort = 0;
        $unrecognized = 0;
        $targetTaken = 0;

        $siteCode = (string) $site->code;
        $occupied = [];
        foreach ($site->units as $unit) {
            $occupied[$unit->unit_number] = $unit->id;
        }

        $plannedTargets = [];

        foreach ($site->units as $unit) {
            $classCode = (string) ($unit->unitClass?->code ?? '');
            $current = $unit->unit_number;

            if ($classCode !== '' && DemoUnitNumber::isCurrentFormat($classCode, $current)) {
                $alreadyShort++;

                continue;
            }

            $n = $siteCode === '' || $classCode === ''
                ? null
                : DemoUnitNumber::parseLegacyIndex($siteCode, $classCode, $current);
            $target = $n === null ? null : DemoUnitNumber::tryFormat($classCode, $n);

            if ($target === null) {
                $unrecognized++;

                continue;
            }

            $takenBy = $occupied[$target] ?? $plannedTargets[$target] ?? null;
            if ($takenBy !== null && $takenBy !== $unit->id) {
                $targetTaken++;
                $this->warn("{$siteCode} {$current} → {$target} is already taken; skipped.");

                continue;
            }

            $replacements[$current] = $target;
            $renames[] = ['id' => $unit->id, 'target' => $target];
            $plannedTargets[$target] = $unit->id;
        }

        uksort($replacements, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return [
            'replacements' => $replacements,
            'renames' => $renames,
            'already_short' => $alreadyShort,
            'unrecognized' => $unrecognized,
            'target_taken' => $targetTaken,
        ];
    }

    /**
     * @param  list<array{id: int, target: string}>  $renames
     */
    private function applyUnitRenames(array $renames): void
    {
        foreach ($renames as $rename) {
            Unit::query()->whereKey($rename['id'])->update([
                'unit_number' => '__rename_'.$rename['id'],
            ]);
        }

        foreach ($renames as $rename) {
            Unit::query()->whereKey($rename['id'])->update([
                'unit_number' => $rename['target'],
            ]);
        }
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function patchSiteMaps(Site $site, array $replacements): int
    {
        $updated = 0;

        foreach ($site->siteMaps as $map) {
            if ($this->patchMap($map, $replacements)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function countMapsThatWouldChange(Site $site, array $replacements): int
    {
        $count = 0;

        foreach ($site->siteMaps as $map) {
            $svg = self::applyReplacements((string) $map->svg_map, $replacements);
            $scene = $this->remapScene($map->scene, $replacements);

            if ($svg !== $map->svg_map || $scene !== $map->scene) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function patchMap(SiteMap $map, array $replacements): bool
    {
        $svg = self::applyReplacements((string) $map->svg_map, $replacements);
        $scene = $this->remapScene($map->scene, $replacements);

        if ($svg === $map->svg_map && $scene === $map->scene) {
            return false;
        }

        $map->forceFill([
            'svg_map' => $svg,
            'scene' => $scene,
        ])->saveQuietly();

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $scene
     * @param  array<string, string>  $replacements
     * @return array<string, mixed>|null
     */
    private function remapScene(?array $scene, array $replacements): ?array
    {
        if ($scene === null) {
            return null;
        }

        if (isset($scene['items']) && is_array($scene['items'])) {
            foreach ($scene['items'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $number = $item['unit_number'] ?? null;
                if (is_string($number) && isset($replacements[$number])) {
                    $scene['items'][$index]['unit_number'] = $replacements[$number];
                }
            }
        }

        if (isset($scene['backgroundSvg']) && is_string($scene['backgroundSvg'])) {
            $scene['backgroundSvg'] = self::applyReplacements($scene['backgroundSvg'], $replacements);
        }

        return $scene;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private static function applyReplacements(string $haystack, array $replacements): string
    {
        if ($replacements === []) {
            return $haystack;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $haystack);
    }
}
