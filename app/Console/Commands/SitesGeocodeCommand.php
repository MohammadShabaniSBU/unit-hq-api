<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\Facility\Geocoder;
use App\Support\Facility\NullGeocoder;
use App\Support\Facility\SiteMatch;
use Illuminate\Console\Command;

class SitesGeocodeCommand extends Command
{
    protected $signature = 'sites:geocode';

    protected $description = 'Backfill sites.latitude / sites.longitude from a configured geocoder; skips when none is set';

    public function handle(Geocoder $geocoder): int
    {
        $pending = Site::query()
            ->active()
            ->where(function ($query): void {
                $query->whereNull('latitude')->orWhereNull('longitude');
            })
            ->orderBy('id')
            ->get();

        if ($geocoder instanceof NullGeocoder) {
            $this->info("No geocoder configured. {$pending->count()} active site(s) still lack coordinates.");

            return self::SUCCESS;
        }

        $updated = 0;
        $skipped = 0;
        foreach ($pending as $site) {
            $query = SiteMatch::formatAddress($site);
            if ($query === '') {
                $query = $site->name;
            }
            $coords = $geocoder->geocode($query);
            if ($coords === null) {
                $skipped++;
                $this->warn("Site #{$site->id} {$site->name}: no result.");

                continue;
            }

            $site->update([
                'latitude' => $coords['lat'],
                'longitude' => $coords['lng'],
                'location' => ['lat' => $coords['lat'], 'lng' => $coords['lng']],
            ]);
            $updated++;
        }

        $this->info("Geocoded {$updated} site(s); {$skipped} skipped.");

        return self::SUCCESS;
    }
}
