<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessGrantState;
use App\Enums\ContractEndedReason;
use App\Enums\ContractStatus;
use App\Models\AccessGrant;
use App\Models\ContractItem;
use App\Models\UnitOccupancy;
use App\Support\Access\AccessSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Access\AccessSyncTestSetup;
use Tests\TestCase;

class SyncConvergeTest extends TestCase
{
    use AccessSyncTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccessSyncFixture(withSecondUnit: true);
    }

    protected function tearDown(): void
    {
        $this->tearDownAccessSyncFixture();
        parent::tearDown();
    }

    public function test_lifecycle_via_nudges_only(): void
    {
        // Move-in facts already placed in fixture — nudge only (no access:sync).
        AccessSync::nudge((int) $this->contract->id);

        $gateGrant = AccessGrant::query()
            ->where('access_point_id', $this->gate->id)
            ->where('contact_id', $this->contact->id)
            ->where('state', AccessGrantState::Applied->value)
            ->first();
        $doorGrant = AccessGrant::query()
            ->where('access_point_id', $this->door->id)
            ->where('contact_id', $this->contact->id)
            ->where('state', AccessGrantState::Applied->value)
            ->first();

        $this->assertNotNull($gateGrant);
        $this->assertNotNull($doorGrant);
        $this->assertNotNull($gateGrant->provider_grant_id);
        $this->assertNotNull($doorGrant->provider_grant_id);

        // Transfer: move door, keep gate — via nudge only.
        $occupancy = UnitOccupancy::query()
            ->where('contract_id', $this->contract->id)
            ->whereNull('ended_on')
            ->firstOrFail();
        $occupancy->forceFill([
            'ended_on' => '2026-08-15',
            'ended_reason' => ContractEndedReason::TransferredOut->value,
        ])->save();

        $originItem = ContractItem::query()
            ->where('contract_id', $this->contract->id)
            ->whereNull('effective_to')
            ->firstOrFail();
        $originItem->forceFill(['effective_to' => '2026-08-15'])->save();

        $newItem = ContractItem::query()->create([
            'contract_id' => $this->contract->id,
            'item_type' => 'unit',
            'item_id' => $this->secondUnit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-08-15',
            'effective_to' => null,
            'supersedes_id' => $originItem->id,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $this->secondUnit->id,
            'contract_id' => $this->contract->id,
            'contract_item_id' => $newItem->id,
            'started_on' => '2026-08-15',
            'ended_on' => null,
        ]);

        AccessSync::nudge((int) $this->contract->id);

        $this->assertSame(
            AccessGrantState::Revoked,
            AccessGrant::query()->find($doorGrant->id)?->state,
        );
        $this->assertSame(
            AccessGrantState::Applied,
            AccessGrant::query()->find($gateGrant->id)?->state,
        );

        $newDoorGrant = AccessGrant::query()
            ->where('access_point_id', $this->secondDoor->id)
            ->where('contact_id', $this->contact->id)
            ->where('state', AccessGrantState::Applied->value)
            ->first();
        $this->assertNotNull($newDoorGrant);

        // Vacate — revoke everything remaining.
        UnitOccupancy::query()
            ->where('contract_id', $this->contract->id)
            ->whereNull('ended_on')
            ->update([
                'ended_on' => '2026-08-15',
                'ended_reason' => ContractEndedReason::Vacated->value,
            ]);

        ContractItem::query()
            ->where('contract_id', $this->contract->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => '2026-08-15']);

        $this->contract->forceFill([
            'status' => ContractStatus::Ended,
            'move_out_on' => '2026-08-15',
            'ended_reason' => ContractEndedReason::Vacated,
        ])->save();

        AccessSync::nudge((int) $this->contract->id);

        $live = AccessGrant::query()
            ->where('contract_id', $this->contract->id)
            ->whereIn('state', [
                AccessGrantState::Applying->value,
                AccessGrantState::Applied->value,
            ])
            ->count();
        $this->assertSame(0, $live);

        $this->assertSame(
            AccessGrantState::Revoked,
            AccessGrant::query()->find($gateGrant->id)?->state,
        );
        $this->assertSame(
            AccessGrantState::Revoked,
            AccessGrant::query()->find($newDoorGrant->id)?->state,
        );
    }
}
