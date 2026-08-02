<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\AccessGrantState;
use App\Enums\AccessSuspensionLiftReason;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Enums\HoldType;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Models\AccessSuspension;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractNotice;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Access\AccessProviderRegistry;
use App\Support\Access\AccessSync;
use App\Support\Access\DesiredAccess;
use App\Support\Access\FakeAccessProvider;
use App\Support\Billing\PaymentAllocator;
use App\Support\Delinquency\DelinquencyEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

/**
 * Sprint 07 + S15-03 exit: seed → skip payment → delinquency runs → fee + revoke
 * + overlock + notice → doors denied → manual cash → cured, released, grants back.
 */
class DelinquencySpineTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_full_failure_branch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'Europe/Madrid'));

        FakeAccessProvider::reset();
        $registry = app(AccessProviderRegistry::class);
        $registry->register('sensorberg', FakeAccessProvider::class);
        $registry->set(FakeAccessProvider::make(['api_key' => 'fake_ok']));

        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $policy = DelinquencyPolicy::factory()->create([
            'name' => 'spine',
            'auto_release_overlock' => true,
            'auto_restore_access' => true,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 5,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '20.00'],
            'sort' => 1,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 8,
            'action' => DelinquencyPolicyAction::RecordNotice,
            'params' => ['notice_type' => 'overdue'],
            'sort' => 2,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 8,
            'action' => DelinquencyPolicyAction::RevokeAccess,
            'params' => [],
            'sort' => 3,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 12,
            'action' => DelinquencyPolicyAction::PlaceOverlock,
            'params' => [],
            'sort' => 4,
        ]);

        $site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'delinquency_policy_id' => $policy->id,
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $account = AccessProviderAccount::factory()->create([
            'provider' => 'sensorberg',
            'is_active' => true,
        ]);
        $gate = AccessPoint::factory()->gate()->create([
            'access_provider_account_id' => $account->id,
            'site_id' => $site->id,
            'provider_point_id' => 'spine-gate',
            'label' => 'Gate',
        ]);
        $door = AccessPoint::factory()->unitDoor($unit->id)->create([
            'access_provider_account_id' => $account->id,
            'site_id' => $site->id,
            'provider_point_id' => 'spine-door',
            'label' => 'Door',
        ]);

        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-06-01',
        ]);
        $item = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'contract_item_id' => $item->id,
            'started_on' => '2026-06-01',
            'ended_on' => null,
            'created_by' => $employee->id,
        ]);
        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-01',
        ]);

        AccessSync::nudge((int) $contract->id);
        $this->assertSame(2, AccessGrant::query()
            ->where('contract_id', $contract->id)
            ->where('state', AccessGrantState::Applied->value)
            ->count());

        // Skip payment — advance through the ladder (day 12+ covers revoke + overlock).
        Carbon::setTestNow(Carbon::parse('2026-08-13 12:00:00', 'Europe/Madrid'));
        Artisan::call('delinquency:run', ['--contract' => $contract->id]);

        $case = Delinquency::query()->where('contract_id', $contract->id)->open()->firstOrFail();
        $timeline = $case->timeline();
        $this->assertSame(4, $timeline->count());
        $this->assertTrue($timeline->contains(fn ($s) => $s->action === DelinquencyStepAction::AssessLateFee));
        $this->assertTrue($timeline->contains(fn ($s) => $s->action === DelinquencyStepAction::RecordNotice));
        $this->assertTrue($timeline->contains(fn ($s) => $s->action === DelinquencyStepAction::RevokeAccess));
        $this->assertTrue($timeline->contains(fn ($s) => $s->action === DelinquencyStepAction::PlaceOverlock));

        $revokeStep = $timeline->first(fn ($s) => $s->action === DelinquencyStepAction::RevokeAccess);
        $this->assertNotNull($revokeStep?->access_suspension_id);

        $this->assertSame(1, Charge::query()->where('charge_type', ChargeType::LateFee)->count());
        $this->assertSame(1, ContractNotice::query()->where('contract_id', $contract->id)->count());
        $hold = UnitHold::query()
            ->where('hold_type', HoldType::Overlock)
            ->whereNull('released_at')
            ->firstOrFail();

        $this->assertTrue(
            AccessSuspension::query()->active()->where('contract_id', $contract->id)->exists()
        );
        $this->assertTrue(DesiredAccess::forContract($contract->fresh())->isEmpty());
        $this->assertSame(0, AccessGrant::query()
            ->where('contract_id', $contract->id)
            ->whereIn('state', [AccessGrantState::Applying->value, AccessGrantState::Applied->value])
            ->count());
        $this->assertEmpty(FakeAccessProvider::make()->listGrants());

        // Manual cash at 14:00 — same afternoon cure + release via allocator hook.
        Carbon::setTestNow(Carbon::parse('2026-08-13 14:00:00', 'Europe/Madrid'));
        $openTotal = '0.00';
        foreach (Charge::query()->where('contract_id', $contract->id)->with('allocations')->get() as $c) {
            $openTotal = bcadd($openTotal, $c->openAmount(), 2);
        }
        $payment = Payment::factory()->cash('2026-08-13')->create([
            'contract_id' => $contract->id,
            'amount' => $openTotal,
            'currency' => 'EUR',
        ]);
        DB::transaction(function () use ($contract, $payment): void {
            PaymentAllocator::allocate($contract->fresh(), $payment);
        });

        $case->refresh();
        $this->assertNotNull($case->cured_on);
        $this->assertSame(DelinquencyCureTrigger::Payment, $case->cure_trigger);
        $this->assertSame('2026-08-13', $case->cured_on->toDateString());

        $hold->refresh();
        $this->assertNotNull($hold->released_at);

        $suspension = AccessSuspension::query()->where('contract_id', $contract->id)->latest('id')->firstOrFail();
        $this->assertNotNull($suspension->lifted_at);
        $this->assertSame(AccessSuspensionLiftReason::Cure, $suspension->lift_reason);

        $this->assertTrue(
            $case->timeline()->contains(fn ($s) => $s->action === DelinquencyStepAction::Cure)
        );

        $desired = DesiredAccess::forContract($contract->fresh());
        $this->assertCount(2, $desired);
        $this->assertSame(2, AccessGrant::query()
            ->where('contract_id', $contract->id)
            ->where('state', AccessGrantState::Applied->value)
            ->count());
        $this->assertNotEmpty(FakeAccessProvider::make()->listGrants());
        $this->assertTrue(
            AccessGrant::query()
                ->where('access_point_id', $gate->id)
                ->where('state', AccessGrantState::Applied->value)
                ->exists()
        );
        $this->assertTrue(
            AccessGrant::query()
                ->where('access_point_id', $door->id)
                ->where('state', AccessGrantState::Applied->value)
                ->exists()
        );

        // Re-run is a no-op on the cured case.
        (new DelinquencyEngine)->run((int) $contract->id);
        $this->assertSame(1, Delinquency::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(0, Delinquency::query()->where('contract_id', $contract->id)->open()->count());

        FakeAccessProvider::reset();
        $registry->set(null);
        Carbon::setTestNow();
    }
}
