<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\TestCase;

/**
 * S17-04 — query-count bound on the heaviest scoped endpoints, mirroring the
 * S01-03 availability-query posture: a company-wide grant must stay on the
 * no-filter fast path, and a site-scoped grant must not turn into an
 * N+1 per row despite the added whereExists.
 */
class VisibilityPerformanceTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoSiteRbacFixture();
    }

    #[Test]
    public function scoped_list_has_no_n_plus_one(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $unit = \App\Models\Unit::factory()->create([
                'site_id' => $this->siteA->id,
                'unit_class_id' => $this->unitClass->id,
            ]);
            $this->signContractAsOwner($unit);
        }
        for ($i = 0; $i < 4; $i++) {
            $unit = \App\Models\Unit::factory()->create([
                'site_id' => $this->siteB->id,
                'unit_class_id' => $this->unitClass->id,
            ]);
            $this->signContractAsOwner($unit);
        }

        Sanctum::actingAs($this->owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $ownerResponse = $this->getJson('/api/contracts?per_page=50')->assertOk();
        $ownerQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(12, $ownerResponse->json('meta.total'));
        // Contract list runs attention-chip aggregates + eager loads; bound
        // catches N+1 growth, not a minimal SELECT count.
        $this->assertLessThanOrEqual(
            100,
            $ownerQueryCount,
            "Expected bounded queries for the company-wide contract list, got {$ownerQueryCount}",
        );

        Sanctum::actingAs($this->agent);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $agentResponse = $this->getJson('/api/contracts?per_page=50')->assertOk();
        $agentQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(8, $agentResponse->json('meta.total'));
        $this->assertLessThanOrEqual(
            100,
            $agentQueryCount,
            "Expected bounded queries for the site-scoped contract list, got {$agentQueryCount}",
        );
        // Site scoping is one whereExists on the list query, not per-row work.
        $this->assertLessThanOrEqual(
            $ownerQueryCount + 5,
            $agentQueryCount,
            "Site-scoped list should not add substantial queries vs company-wide ({$ownerQueryCount} vs {$agentQueryCount})",
        );

        // A second heavy scoped endpoint: the delinquency board aggregate.
        $manager = Employee::factory()->withoutRoleGrant()->create();
        $this->grantRole($manager, 'site_manager', $this->siteA);
        Sanctum::actingAs($manager);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/delinquencies?per_page=50')->assertOk();
        $delinquencyQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            40,
            $delinquencyQueryCount,
            "Expected bounded queries for the scoped delinquency board, got {$delinquencyQueryCount}",
        );
    }
}
