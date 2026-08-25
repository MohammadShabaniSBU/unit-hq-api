<?php

declare(strict_types=1);

namespace Tests\Feature\Facility;

use App\Enums\SiteServiceAreaKind;
use App\Models\Site;
use App\Models\SiteServiceArea;
use App\Support\Facility\SiteMatchReason;
use App\Support\Facility\SiteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function no_query_and_one_active_site_is_only_site(): void
    {
        $site = Site::factory()->create(['name' => 'Madrid Centro', 'postal_code' => '28004']);

        $matches = SiteResolver::resolve(null, null, null);

        $this->assertCount(1, $matches);
        $this->assertSame($site->id, $matches[0]->site->id);
        $this->assertSame(SiteMatchReason::OnlySite, $matches[0]->reason);
    }

    #[Test]
    public function one_site_with_non_matching_locality_is_no_match_not_only_site(): void
    {
        $site = Site::factory()->create([
            'name' => 'Madrid Centro',
            'city' => 'Madrid',
            'postal_code' => '28004',
        ]);

        $matches = SiteResolver::resolve('Barcelona', null, null);

        $this->assertCount(1, $matches);
        $this->assertSame($site->id, $matches[0]->site->id);
        $this->assertSame(SiteMatchReason::NoMatch, $matches[0]->reason);
    }

    #[Test]
    public function one_site_postcode_28001_without_catchment_is_no_match(): void
    {
        $site = Site::factory()->create([
            'name' => 'Madrid Centro',
            'postal_code' => '28004',
            'city' => 'Madrid',
        ]);

        $matches = SiteResolver::resolve('28001', null, null);

        $this->assertCount(1, $matches);
        $this->assertSame($site->id, $matches[0]->site->id);
        $this->assertSame(SiteMatchReason::NoMatch, $matches[0]->reason);
    }

    #[Test]
    public function prefix_row_resolves_28001_when_a_second_site_exists(): void
    {
        $madrid = Site::factory()->create(['name' => 'Madrid Centro', 'postal_code' => '28004']);
        Site::factory()->create(['name' => 'Barcelona', 'postal_code' => '08001', 'city' => 'Barcelona']);
        SiteServiceArea::factory()->create([
            'site_id' => $madrid->id,
            'kind' => SiteServiceAreaKind::PostcodePrefix,
            'value' => '280',
        ]);

        $matches = SiteResolver::resolve('28001', null, null);

        $this->assertCount(1, $matches);
        $this->assertSame($madrid->id, $matches[0]->site->id);
        $this->assertSame(SiteMatchReason::ServiceAreaPrefix, $matches[0]->reason);
    }

    #[Test]
    public function longest_prefix_wins(): void
    {
        $madrid = Site::factory()->create(['name' => 'Madrid Centro']);
        $wider = Site::factory()->create(['name' => 'Central Spain']);
        SiteServiceArea::factory()->create([
            'site_id' => $wider->id,
            'kind' => SiteServiceAreaKind::PostcodePrefix,
            'value' => '28',
        ]);
        SiteServiceArea::factory()->create([
            'site_id' => $madrid->id,
            'kind' => SiteServiceAreaKind::PostcodePrefix,
            'value' => '280',
        ]);

        $matches = SiteResolver::resolve('28001', null, null);

        $this->assertCount(1, $matches);
        $this->assertSame($madrid->id, $matches[0]->site->id);
        $this->assertSame(SiteMatchReason::ServiceAreaPrefix, $matches[0]->reason);
    }

    #[Test]
    public function archived_sites_never_appear(): void
    {
        $live = Site::factory()->create(['name' => 'Live', 'city' => 'Madrid']);
        Site::factory()->create(['name' => 'Gone', 'city' => 'Madrid', 'archived_at' => now()]);

        $matches = SiteResolver::resolve('Madrid', null, null);

        $this->assertCount(1, $matches);
        $this->assertSame($live->id, $matches[0]->site->id);
        $this->assertSame(SiteMatchReason::Locality, $matches[0]->reason);
    }

    #[Test]
    public function archived_catchment_is_ignored(): void
    {
        $madrid = Site::factory()->create(['name' => 'Madrid Centro', 'postal_code' => '28004']);
        Site::factory()->create(['name' => 'Other', 'postal_code' => '08001']);
        SiteServiceArea::factory()->create([
            'site_id' => $madrid->id,
            'kind' => SiteServiceAreaKind::PostcodePrefix,
            'value' => '280',
            'archived_at' => now(),
        ]);

        $matches = SiteResolver::resolve('28001', null, null);

        $this->assertSame(SiteMatchReason::NoMatch, $matches[0]->reason);
        $this->assertCount(2, $matches);
    }

    #[Test]
    public function zero_match_returns_every_active_site(): void
    {
        $a = Site::factory()->create(['name' => 'Alpha', 'city' => 'Seville']);
        $b = Site::factory()->create(['name' => 'Beta', 'city' => 'Valencia']);
        $c = Site::factory()->create(['name' => 'Gamma', 'city' => 'Bilbao']);

        $matches = SiteResolver::resolve('Zaragoza', null, null);

        $this->assertCount(3, $matches);
        $this->assertTrue(collect($matches)->every(
            fn ($match): bool => $match->reason === SiteMatchReason::NoMatch,
        ));
        $ids = collect($matches)->map(fn ($match): int => $match->site->id)->sort()->values()->all();
        $this->assertSame(collect([$a->id, $b->id, $c->id])->sort()->values()->all(), $ids);
    }

    #[Test]
    public function haversine_orders_by_distance_when_coordinates_are_passed(): void
    {
        $near = Site::factory()->create([
            'name' => 'Near',
            'latitude' => 40.42,
            'longitude' => -3.70,
            'location' => ['lat' => 40.42, 'lng' => -3.70],
        ]);
        $far = Site::factory()->create([
            'name' => 'Far',
            'latitude' => 41.39,
            'longitude' => 2.17,
            'location' => ['lat' => 41.39, 'lng' => 2.17],
        ]);

        $matches = SiteResolver::resolve(null, 40.425, -3.703);

        $this->assertCount(2, $matches);
        $this->assertSame(SiteMatchReason::Distance, $matches[0]->reason);
        $this->assertSame($near->id, $matches[0]->site->id);
        $this->assertSame($far->id, $matches[1]->site->id);
        $this->assertNotNull($matches[0]->distanceKm);
        $this->assertTrue($matches[0]->distanceKm < $matches[1]->distanceKm);
    }
}
