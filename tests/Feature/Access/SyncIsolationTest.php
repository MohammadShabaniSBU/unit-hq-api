<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessGrantState;
use App\Models\AccessGrant;
use App\Support\Access\AccessReconciler;
use App\Support\Access\FakeAccessProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Access\AccessSyncTestSetup;
use Tests\TestCase;

class SyncIsolationTest extends TestCase
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

    public function test_failed_and_stuck_retry(): void
    {
        FakeAccessProvider::failNextGrant('boom on first point');

        $reconciler = new AccessReconciler;
        $summary = $reconciler->run(contractId: (int) $this->contract->id);

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['granted']);

        $states = AccessGrant::query()
            ->where('contract_id', $this->contract->id)
            ->pluck('state')
            ->map(fn ($s) => $s instanceof AccessGrantState ? $s->value : (string) $s)
            ->sort()
            ->values()
            ->all();

        $this->assertContains(AccessGrantState::Failed->value, $states);
        $this->assertContains(AccessGrantState::Applied->value, $states);

        // Stuck applying: age a leftover applying row and ensure schedule retry converges.
        $failed = AccessGrant::query()
            ->where('contract_id', $this->contract->id)
            ->where('state', AccessGrantState::Failed->value)
            ->firstOrFail();

        // Retry failed via stuck path.
        $retry = $reconciler->run(contractId: (int) $this->contract->id);
        $this->assertGreaterThanOrEqual(1, $retry['retried'] + $retry['granted']);
        $this->assertSame(
            AccessGrantState::Applied,
            $failed->fresh()?->state,
        );

        // Stuck applying older than threshold is retried.
        $applied = AccessGrant::query()
            ->where('contract_id', $this->contract->id)
            ->where('access_point_id', $this->door->id)
            ->where('state', AccessGrantState::Applied->value)
            ->firstOrFail();

        // Force a stuck applying by inserting a row for a fresh contact on gate… simpler:
        // mark the door grant applying with old updated_at and clear provider ref, then retry.
        $applied->forceFill([
            'state' => AccessGrantState::Applying,
            'provider_grant_id' => null,
            'updated_at' => Carbon::now()->subMinutes(10),
        ])->save();

        // Bump the timestamp explicitly (Eloquent may touch updated_at on save).
        AccessGrant::query()->whereKey($applied->id)->update([
            'updated_at' => Carbon::now()->subMinutes(10),
        ]);

        $stuckRun = $reconciler->run(contractId: (int) $this->contract->id);
        $this->assertNotEmpty($stuckRun['stuck']);
        $this->assertSame(
            AccessGrantState::Applied,
            $applied->fresh()?->state,
        );
        $this->assertNotNull($applied->fresh()?->provider_grant_id);
    }
}
