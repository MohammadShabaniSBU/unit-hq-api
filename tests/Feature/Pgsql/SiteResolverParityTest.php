<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Enums\SiteServiceAreaKind;
use App\Models\Site;
use App\Models\SiteServiceArea;
use App\Support\Facility\SiteMatchReason;
use App\Support\Facility\SiteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Postgres-only: same resolver ladder as SiteResolverTest. Skipped on SQLite.
 */
class SiteResolverParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Resolver parity is asserted on Postgres.');
        }
    }

    #[Test]
    public function ladder_matches_sqlite_cases(): void
    {
        $only = Site::factory()->create(['name' => 'Madrid Centro', 'postal_code' => '28004', 'city' => 'Madrid']);
        $onlySite = SiteResolver::resolve(null, null, null);
        $this->assertSame(SiteMatchReason::OnlySite, $onlySite[0]->reason);

        $barcelona = SiteResolver::resolve('Barcelona', null, null);
        $this->assertSame(SiteMatchReason::NoMatch, $barcelona[0]->reason);
        $this->assertSame($only->id, $barcelona[0]->site->id);

        $noCatchment = SiteResolver::resolve('28001', null, null);
        $this->assertSame(SiteMatchReason::NoMatch, $noCatchment[0]->reason);

        Site::factory()->create(['name' => 'Barcelona', 'postal_code' => '08001', 'city' => 'Barcelona']);
        SiteServiceArea::factory()->create([
            'site_id' => $only->id,
            'kind' => SiteServiceAreaKind::PostcodePrefix,
            'value' => '280',
        ]);

        $prefixed = SiteResolver::resolve('28001', null, null);
        $this->assertSame(SiteMatchReason::ServiceAreaPrefix, $prefixed[0]->reason);
        $this->assertSame($only->id, $prefixed[0]->site->id);

        $none = SiteResolver::resolve('Zaragoza', null, null);
        $this->assertCount(2, $none);
        $this->assertSame(SiteMatchReason::NoMatch, $none[0]->reason);
    }
}
