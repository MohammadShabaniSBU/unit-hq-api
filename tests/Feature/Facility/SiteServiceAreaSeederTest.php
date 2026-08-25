<?php

declare(strict_types=1);

namespace Tests\Feature\Facility;

use App\Enums\SiteServiceAreaKind;
use App\Models\Site;
use App\Models\SiteServiceArea;
use Database\Seeders\SiteServiceAreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteServiceAreaSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeds_fourteen_rows_for_the_five_madrid_codes_and_is_idempotent(): void
    {
        $defs = [
            'MAD-01' => '28004',
            'MAD-02' => '28036',
            'MAD-03' => '28026',
            'MAD-04' => '28027',
            'MAD-05' => '28011',
        ];

        $sites = [];
        foreach ($defs as $code => $postcode) {
            $sites[$code] = Site::factory()->create([
                'code' => $code,
                'postal_code' => $postcode,
            ]);
        }

        $seeder = new SiteServiceAreaSeeder;
        $seeder->run();
        $seeder->run();

        $this->assertSame(14, SiteServiceArea::query()->count());

        foreach ($sites as $code => $site) {
            $exact = SiteServiceArea::query()
                ->where('site_id', $site->id)
                ->where('kind', SiteServiceAreaKind::Postcode)
                ->get();
            $this->assertCount(1, $exact, $code);
            $this->assertSame($site->postal_code, $exact->first()?->value);
        }

        $this->assertSame(9, SiteServiceArea::query()
            ->where('kind', SiteServiceAreaKind::PostcodePrefix)
            ->count());
    }

    #[Test]
    public function skips_codes_that_are_not_in_the_database(): void
    {
        Site::factory()->create(['code' => 'MAD-01', 'postal_code' => '28004']);

        (new SiteServiceAreaSeeder)->run();

        $this->assertSame(3, SiteServiceArea::query()->count());
        $this->assertSame(1, Site::query()->count());
    }
}
