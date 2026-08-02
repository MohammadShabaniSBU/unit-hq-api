<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessGrantState;
use App\Models\AccessGrant;
use App\Support\Access\AccessReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Access\AccessSyncTestSetup;
use Tests\TestCase;

class SyncIdempotenceTest extends TestCase
{
    use AccessSyncTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccessSyncFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownAccessSyncFixture();
        parent::tearDown();
    }

    public function test_zero_ops_when_converged(): void
    {
        $reconciler = new AccessReconciler;

        $first = $reconciler->run(contractId: (int) $this->contract->id);
        $this->assertGreaterThan(0, $first['granted']);
        $this->assertSame(0, $first['failed']);

        $countAfterFirst = AccessGrant::query()->count();

        $second = $reconciler->run(contractId: (int) $this->contract->id);
        $this->assertSame([], $second['to_grant']);
        $this->assertSame([], $second['to_revoke']);
        $this->assertSame([], $second['stuck']);
        $this->assertSame(0, $second['granted']);
        $this->assertSame(0, $second['revoked']);
        $this->assertSame(0, $second['failed']);
        $this->assertSame($countAfterFirst, AccessGrant::query()->count());

        // Dry-run prints the three sets and writes nothing.
        $beforeDry = AccessGrant::query()->count();
        $dry = $reconciler->run(contractId: (int) $this->contract->id, dryRun: true);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame([], $dry['to_grant']);
        $this->assertSame([], $dry['to_revoke']);
        $this->assertSame([], $dry['stuck']);
        $this->assertSame($beforeDry, AccessGrant::query()->count());

        $this->assertSame(
            2,
            AccessGrant::query()->where('state', AccessGrantState::Applied->value)->count(),
        );
    }
}
