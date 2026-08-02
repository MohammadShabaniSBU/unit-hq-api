<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessGrantState;
use App\Enums\AccessSuspensionLiftReason;
use App\Enums\AccessSuspensionReason;
use App\Models\AccessGrant;
use App\Models\AccessSuspension;
use App\Models\Employee;
use App\Models\UnitOccupancy;
use App\Support\Access\AccessSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Access\AccessSyncTestSetup;
use Tests\TestCase;

class LifecycleInterplayTest extends TestCase
{
    use AccessSyncTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccessSyncFixture(withSecondUnit: true);
        Sanctum::actingAs(Employee::factory()->manager()->create());
    }

    protected function tearDown(): void
    {
        $this->tearDownAccessSyncFixture();
        parent::tearDown();
    }

    public function test_vacate_lifts_transfer_preserves(): void
    {
        $this->assertVacateLifts();
        // Transfer half runs in the same method after rebuilding occupancy on a free unit.
        // Vacate ended unit A; use second unit as origin and a new third unit as destination.
        $thirdUnit = \App\Models\Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unit->unit_class_id,
        ]);
        $thirdDoor = \App\Models\AccessPoint::factory()->unitDoor($thirdUnit->id)->create([
            'access_provider_account_id' => $this->account->id,
            'site_id' => $this->site->id,
            'provider_point_id' => 'fake-door-c',
            'label' => 'Unit door C',
        ]);

        $this->contact = \App\Models\Contact::factory()->create([
            'email' => 'transfer@example.com',
        ]);
        $this->contract = \App\Models\Contract::factory()->create([
            'contact_id' => $this->contact->id,
            'status' => \App\Enums\ContractStatus::Active,
            'start_date' => '2026-07-01',
            'move_in_date' => '2026-07-01',
            'billing_anchor_date' => '2026-07-01',
            'billed_through' => '2026-07-01',
        ]);
        $item = \App\Models\ContractItem::query()->create([
            'contract_id' => $this->contract->id,
            'item_type' => 'unit',
            'item_id' => $this->secondUnit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-07-01',
            'effective_to' => null,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $this->secondUnit->id,
            'contract_id' => $this->contract->id,
            'contract_item_id' => $item->id,
            'started_on' => '2026-07-01',
            'ended_on' => null,
        ]);

        AccessSync::nudge((int) $this->contract->id);

        $suspension = AccessSuspension::suspend(
            $this->contract,
            AccessSuspensionReason::Manual,
        );
        AccessSync::nudge((int) $this->contract->id);

        $this->assertSame(0, AccessGrant::query()
            ->where('contract_id', $this->contract->id)
            ->whereIn('state', [AccessGrantState::Applying->value, AccessGrantState::Applied->value])
            ->count());

        $this->postJson("/api/contracts/{$this->contract->id}/transfer", [
            'to_unit_id' => $thirdUnit->id,
            'transfer_date' => '2026-08-15',
        ])->assertOk();

        $suspension->refresh();
        $this->assertTrue($suspension->isActive());
        $this->assertSame($this->contract->id, $suspension->contract_id);

        $occupancy = UnitOccupancy::query()
            ->where('contract_id', $this->contract->id)
            ->whereNull('ended_on')
            ->firstOrFail();
        $this->assertSame($thirdUnit->id, $occupancy->unit_id);

        $this->assertSame(0, AccessGrant::query()
            ->where('contract_id', $this->contract->id)
            ->whereIn('state', [AccessGrantState::Applying->value, AccessGrantState::Applied->value])
            ->count());

        AccessSuspension::lift($this->contract, AccessSuspensionLiftReason::Manual);
        AccessSync::nudge((int) $this->contract->id);

        $this->assertTrue(
            AccessGrant::query()
                ->where('access_point_id', $this->gate->id)
                ->where('contact_id', $this->contact->id)
                ->where('state', AccessGrantState::Applied->value)
                ->exists()
        );
        $this->assertTrue(
            AccessGrant::query()
                ->where('access_point_id', $thirdDoor->id)
                ->where('contact_id', $this->contact->id)
                ->where('state', AccessGrantState::Applied->value)
                ->exists()
        );
        $this->assertFalse(
            AccessGrant::query()
                ->where('access_point_id', $this->secondDoor->id)
                ->where('contact_id', $this->contact->id)
                ->whereIn('state', [AccessGrantState::Applying->value, AccessGrantState::Applied->value])
                ->exists()
        );
    }

    private function assertVacateLifts(): void
    {
        AccessSync::nudge((int) $this->contract->id);
        AccessSuspension::suspend($this->contract, AccessSuspensionReason::Manual);

        $this->postJson("/api/contracts/{$this->contract->id}/vacate", [
            'move_out_on' => '2026-08-15',
            'deposit' => ['outcome' => 'released'],
        ])->assertOk();

        $lifted = AccessSuspension::query()
            ->where('contract_id', $this->contract->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertNotNull($lifted->lifted_at);
        $this->assertSame(AccessSuspensionLiftReason::Vacated, $lifted->lift_reason);
    }
}
