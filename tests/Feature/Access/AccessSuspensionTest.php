<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessSuspensionLiftReason;
use App\Enums\AccessSuspensionReason;
use App\Enums\LogChannel;
use App\Models\AccessSuspension;
use App\Models\Activity;
use App\Models\Contract;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessSuspensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_audited_idempotent(): void
    {
        $employee = Employee::factory()->manager()->create();
        $contract = Contract::factory()->create();

        $first = AccessSuspension::suspend(
            $contract,
            AccessSuspensionReason::Manual,
            null,
            $employee,
        );
        $second = AccessSuspension::suspend(
            $contract,
            AccessSuspensionReason::Delinquency,
            null,
            $employee,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AccessSuspension::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(1, AccessSuspension::query()->active()->where('contract_id', $contract->id)->count());
        $this->assertTrue($first->isActive());
        $this->assertSame(AccessSuspensionReason::Manual, $first->reason);

        $suspended = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'access.suspended')
            ->where('subject_id', $first->id)
            ->first();
        $this->assertNotNull($suspended);
        $this->assertSame('manual', $suspended->properties['reason'] ?? null);
        $this->assertSame($contract->id, $suspended->properties['contract_id'] ?? null);

        $lifted = AccessSuspension::lift(
            $contract,
            AccessSuspensionLiftReason::Manual,
            $employee,
        );
        $this->assertNotNull($lifted);
        $this->assertFalse($lifted->isActive());
        $this->assertNotNull($lifted->lifted_at);
        $this->assertSame(AccessSuspensionLiftReason::Manual, $lifted->lift_reason);
        $this->assertSame(1, AccessSuspension::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(0, AccessSuspension::query()->active()->where('contract_id', $contract->id)->count());

        $liftActivity = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'access.lifted')
            ->where('subject_id', $first->id)
            ->first();
        $this->assertNotNull($liftActivity);
        $this->assertSame('manual', $liftActivity->properties['lift_reason'] ?? null);

        $again = AccessSuspension::lift(
            $contract,
            AccessSuspensionLiftReason::Cure,
            $employee,
        );
        $this->assertNull($again);
        $this->assertSame(
            1,
            Activity::query()
                ->where('description', 'access.lifted')
                ->where('subject_id', $first->id)
                ->count(),
        );

        // Re-suspend after lift creates a new row (history retained).
        $third = AccessSuspension::suspend(
            $contract,
            AccessSuspensionReason::Delinquency,
            null,
            $employee,
        );
        $this->assertNotSame($first->id, $third->id);
        $this->assertSame(2, AccessSuspension::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(1, AccessSuspension::query()->active()->count());
    }

    public function test_active_suspension_uniqueness(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index is Postgres-only.');
        }

        $contract = Contract::factory()->create();
        AccessSuspension::query()->create([
            'contract_id' => $contract->id,
            'reason' => AccessSuspensionReason::Manual,
            'created_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        AccessSuspension::query()->create([
            'contract_id' => $contract->id,
            'reason' => AccessSuspensionReason::Delinquency,
            'created_at' => now(),
        ]);
    }
}
