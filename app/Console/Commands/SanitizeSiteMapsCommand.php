<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SiteMap;
use App\Support\Facility\SvgSanitizer;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class SanitizeSiteMapsCommand extends Command
{
    protected $signature = 'site-maps:sanitize';

    protected $description = 'Re-sanitize every stored site_maps.svg_map (strip script, on* handlers, foreignObject, external hrefs)';

    public function handle(): int
    {
        $total = 0;
        $updated = 0;
        $failed = 0;

        SiteMap::query()
            ->select(['id', 'svg_map'])
            ->orderBy('id')
            ->chunkById(50, function ($siteMaps) use (&$total, &$updated, &$failed): void {
                foreach ($siteMaps as $siteMap) {
                    $total++;

                    try {
                        $sanitized = SvgSanitizer::sanitize((string) $siteMap->svg_map);
                    } catch (ValidationException $exception) {
                        $failed++;
                        $this->warn("Site map #{$siteMap->id}: could not be sanitized — {$exception->getMessage()}");

                        continue;
                    }

                    if ($sanitized !== $siteMap->svg_map) {
                        $siteMap->forceFill(['svg_map' => $sanitized])->saveQuietly();
                        $updated++;
                    }
                }
            });

        $this->info("Processed {$total} site map(s): {$updated} sanitized, {$failed} failed.");

        return self::SUCCESS;
    }
}
