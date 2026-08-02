<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessGrantState;
use App\Enums\AccessSuspensionReason;
use App\Models\AccessGrant;
use App\Models\AccessSuspension;
use App\Models\Employee;
use App\Models\MessageThread;
use App\Support\Communications\Channel;
use App\Support\Access\AccessReconciler;
use App\Support\Access\FakeAccessProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Access\AccessSyncTestSetup;
use Tests\TestCase;

class AccessStateTest extends TestCase
{
    use AccessSyncTestSetup;
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccessSyncFixture();
        $this->employee = Employee::factory()->manager()->create();
        $this->actingAs($this->employee);
    }

    protected function tearDown(): void
    {
        $this->tearDownAccessSyncFixture();
        parent::tearDown();
    }

    public function test_honest_states_three_surfaces(): void
    {
        Queue::fake();

        (new AccessReconciler)->run(contractId: (int) $this->contract->id);

        $applied = AccessGrant::query()
            ->where('contract_id', $this->contract->id)
            ->where('state', AccessGrantState::Applied->value)
            ->get();
        $this->assertGreaterThanOrEqual(2, $applied->count());

        $contractShow = $this->getJson("/api/contracts/{$this->contract->id}");
        $contractShow->assertOk();
        $grants = collect($contractShow->json('data.access_grants'));
        $this->assertTrue($grants->contains(fn (array $g): bool => $g['state'] === 'applied'));
        $this->assertFalse((bool) $contractShow->json('data.access_suspension.active'));

        $unitShow = $this->getJson("/api/units/{$this->unit->id}");
        $unitShow->assertOk()
            ->assertJsonPath('data.access.mapped', true);
        $this->assertNotEmpty($unitShow->json('data.access.grants'));

        AccessGrant::query()
            ->where('access_point_id', $this->door->id)
            ->where('contract_id', $this->contract->id)
            ->update([
                'state' => AccessGrantState::Failed->value,
                'last_error' => 'Simulated failure',
                'applied_at' => null,
            ]);

        $failedShow = $this->getJson("/api/contracts/{$this->contract->id}");
        $failedShow->assertOk();
        $failedGrant = collect($failedShow->json('data.access_grants'))
            ->firstWhere('point_id', $this->door->id);
        $this->assertSame('failed', $failedGrant['state']);
        $this->assertTrue($failedGrant['can_retry']);

        $retry = $this->postJson("/api/access/grants/{$failedGrant['id']}/retry");
        $retry->assertOk();

        // Seeded cure posture: suspension lifted → new/desired grants show applying,
        // never a premature applied claim from the API.
        AccessSuspension::query()->create([
            'contract_id' => $this->contract->id,
            'reason' => AccessSuspensionReason::Manual,
            'created_by' => $this->employee->id,
            'created_at' => Carbon::parse('2026-08-06 10:00:00', 'Europe/Madrid'),
        ]);

        AccessGrant::query()
            ->where('contract_id', $this->contract->id)
            ->whereIn('state', [
                AccessGrantState::Applied->value,
                AccessGrantState::Failed->value,
            ])
            ->update([
                'state' => AccessGrantState::Revoked->value,
                'revoked_at' => now(),
            ]);

        AccessGrant::factory()->applying()->create([
            'access_point_id' => $this->door->id,
            'contact_id' => $this->contact->id,
            'contract_id' => $this->contract->id,
        ]);

        // Lift suspension (cure) but leave the applying grant — honest surface.
        AccessSuspension::query()
            ->where('contract_id', $this->contract->id)
            ->whereNull('lifted_at')
            ->update([
                'lifted_at' => now(),
                'lift_reason' => 'manual',
                'lifted_by' => $this->employee->id,
            ]);

        $afterCure = $this->getJson("/api/contracts/{$this->contract->id}");
        $afterCure->assertOk();
        $this->assertFalse((bool) $afterCure->json('data.access_suspension.active'));
        $applying = collect($afterCure->json('data.access_grants'))
            ->firstWhere('state', 'applying');
        $this->assertNotNull($applying);
        $this->assertNull($applying['applied_at']);

        // Re-suspend for inbox day_count glyph.
        AccessSuspension::query()->create([
            'contract_id' => $this->contract->id,
            'reason' => AccessSuspensionReason::Delinquency,
            'created_by' => $this->employee->id,
            'created_at' => Carbon::parse('2026-07-24 09:00:00', 'Europe/Madrid'),
        ]);

        $thread = MessageThread::query()->create([
            'contact_id' => $this->contact->id,
            'channel' => Channel::Email->value,
            'subject' => 'Access question',
            'last_message_at' => now(),
        ]);

        $context = $this->getJson("/api/inbox/threads/{$thread->id}/context");
        $context->assertOk();
        $tenancy = collect($context->json('data.tenancy.active_contracts'))
            ->firstWhere('id', $this->contract->id);
        $this->assertNotNull($tenancy);
        $this->assertTrue($tenancy['access']['suspended']);
        $this->assertSame('delinquency', $tenancy['access']['reason']);
        $this->assertGreaterThanOrEqual(21, (int) $tenancy['access']['day_count']);

        // Unknown grant human revoke only — reconciler never auto-revokes.
        FakeAccessProvider::injectGrant('human-placed-ref', 'fake-gate-1');
        $this->account->forceFill([
            'sync_attention' => [
                'applied_count' => 0,
                'failed_count' => 1,
                'unknown_grants' => [[
                    'grant_ref' => 'human-placed-ref',
                    'provider_point_id' => 'fake-gate-1',
                    'credential_ref' => 'cred-injected',
                ]],
                'drift_denied_but_granted' => [],
            ],
        ])->save();

        $revoke = $this->postJson('/api/settings/access/unknown-grants/revoke', [
            'grant_ref' => 'human-placed-ref',
        ]);
        $revoke->assertOk();
        $this->account->refresh();
        $this->assertEmpty($this->account->sync_attention['unknown_grants'] ?? []);
        $remote = collect(FakeAccessProvider::make()->listGrants())->pluck('grant_ref')->all();
        $this->assertNotContains('human-placed-ref', $remote);
    }
}
