<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SiteServiceAreaKind;
use App\Models\Site;
use App\Models\SiteServiceArea;
use Illuminate\Database\Seeder;

/**
 * Fixed Madrid catchment. Prefixes never overlap across sites; each site's
 * own postcode is seeded as an exact `postcode` row read from the Site
 * row so a StageSeeder postal_code change cannot desync the two.
 */
class SiteServiceAreaSeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    private const PREFIXES = [
        'MAD-01' => ['2800', '2801'],
        'MAD-02' => ['2803', '2804'],
        'MAD-03' => ['2805', '2819'],
        'MAD-04' => ['2802', '2807'],
        'MAD-05' => ['2811'],
    ];

    public function run(): void
    {
        foreach (self::PREFIXES as $code => $prefixes) {
            $site = Site::query()->where('code', $code)->first();
            if ($site === null) {
                continue;
            }

            foreach ($prefixes as $prefix) {
                SiteServiceArea::query()->updateOrCreate(
                    [
                        'site_id' => $site->id,
                        'kind' => SiteServiceAreaKind::PostcodePrefix,
                        'value' => $prefix,
                    ],
                    ['archived_at' => null],
                );
            }

            $postcode = $site->postal_code;
            if ($postcode !== null && $postcode !== '') {
                SiteServiceArea::query()->updateOrCreate(
                    [
                        'site_id' => $site->id,
                        'kind' => SiteServiceAreaKind::Postcode,
                        'value' => $postcode,
                    ],
                    ['archived_at' => null],
                );
            }
        }
    }
}
