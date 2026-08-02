<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessGrantState;
use App\Enums\AccessSuspensionReason;
use App\Enums\LogChannel;
use App\Models\AccessGrant;
use App\Models\AccessSuspension;
use App\Models\Activity;
use App\Models\SystemEvent;
use App\Support\Access\AccessReconciler;
use App\Support\Access\FakeAccessProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Access\AccessSyncTestSetup;
use Tests\TestCase;

class DriftTest extends TestCase
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

    public function test_three_cases_three_postures(): void
    {
        $reconciler = new AccessReconciler;
        $reconciler->run(contractId: (int) $this->contract->id);

        $doorGrant = AccessGrant::query()
            ->where('access_point_id', $this->door->id)
            ->where('state', AccessGrantState::Applied->value)
            ->firstOrFail();
        $gateGrant = AccessGrant::query()
            ->where('access_point_id', $this->gate->id)
            ->where('state', AccessGrantState::Applied->value)
            ->firstOrFail();

        // 1) Unknown grant at provider — attention only, never auto-revoked.
        FakeAccessProvider::injectGrant('human-placed-ref', 'fake-gate-1');

        // 2) Missing at provider — our applied door vanished remotely.
        FakeAccessProvider::dropGrant((string) $doorGrant->provider_grant_id);

        $afterMissing = $reconciler->run(withDrift: true);
        $this->assertGreaterThanOrEqual(1, $afterMissing['drift']['unknown']);
        $this->assertGreaterThanOrEqual(1, $afterMissing['drift']['missing']);

        $remoteRefs = collect(FakeAccessProvider::make()->listGrants())->pluck('grant_ref')->all();
        $this->assertContains('human-placed-ref', $remoteRefs);

        $this->assertTrue(
            SystemEvent::query()->where('event', 'access.drift.unknown_grant')->exists(),
        );
        $this->assertTrue(
            SystemEvent::query()->where('event', 'access.drift.missing_at_provider')->exists(),
        );

        $doorGrant->refresh();
        $this->assertSame(AccessGrantState::Applied, $doorGrant->state);
        $this->assertNotNull($doorGrant->provider_grant_id);
        $this->assertNotSame(
            '',
            (string) $doorGrant->provider_grant_id,
        );

        $this->account->refresh();
        $this->assertNotNull($this->account->last_full_synced_at);
        $this->assertNotEmpty($this->account->sync_attention['unknown_grants'] ?? []);

        // 3) Denied-but-granted — local cache says revoked but provider still has it,
        //    and desired now denies (active suspension).
        AccessSuspension::query()->create([
            'contract_id' => $this->contract->id,
            'reason' => AccessSuspensionReason::Manual,
            'created_at' => now(),
        ]);

        $gateRef = (string) $gateGrant->provider_grant_id;
        $gateGrant->forceFill([
            'state' => AccessGrantState::Revoked,
            'revoked_at' => now(),
        ])->save();
        // Leave $gateRef at the Fake provider (desynced cache).

        $afterDenied = $reconciler->run(withDrift: true);
        $this->assertGreaterThanOrEqual(1, $afterDenied['drift']['denied_but_granted']);

        $tier3 = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'access.drift_denied_but_granted')
            ->exists();
        $this->assertTrue($tier3);

        $remoteAfter = collect(FakeAccessProvider::make()->listGrants())->pluck('grant_ref')->all();
        $this->assertNotContains($gateRef, $remoteAfter);
        // Unknown human grant still never auto-revoked.
        $this->assertContains('human-placed-ref', $remoteAfter);
    }
}
