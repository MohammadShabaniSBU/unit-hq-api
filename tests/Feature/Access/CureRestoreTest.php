<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessSuspensionLiftReason;
use App\Enums\AccessSuspensionReason;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Enums\LogChannel;
use App\Models\AccessSuspension;
use App\Models\Activity;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Support\Billing\PaymentAllocator;
use App\Support\Delinquency\DelinquencyEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class CureRestoreTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private DelinquencyPolicy $policy;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $this->policy = DelinquencyPolicy::factory()->create([
            'auto_release_overlock' => true,
            'auto_restore_access' => true,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 1,
            'action' => DelinquencyPolicyAction::RevokeAccess,
            'params' => [],
            'sort' => 1,
        ]);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'delinquency_policy_id' => $this->policy->id,
        ]);
        $this->unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->priceId = (int) $price->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_flag_matrix_manual_survives(): void
    {
        // Flag true: delinquency suspension lifted on cure.
        [$contractA] = $this->openSuspendedCase();
        $this->payOff($contractA);
        $lifted = AccessSuspension::query()->where('contract_id', $contractA->id)->firstOrFail();
        $this->assertNotNull($lifted->lifted_at);
        $this->assertSame(AccessSuspensionLiftReason::Cure, $lifted->lift_reason);

        // Flag false: delinquency suspension remains (pending restore).
        $this->policy->forceFill(['auto_restore_access' => false])->save();
        [$contractB, $caseB] = $this->openSuspendedCase();
        $this->payOff($contractB);
        $caseB->refresh();
        $this->assertNotNull($caseB->cured_on);
        $this->assertTrue(
            AccessSuspension::query()->active()->where('contract_id', $contractB->id)->exists()
        );

        $show = $this->getJson("/api/delinquencies/{$caseB->id}");
        $show->assertOk();
        $this->assertTrue($show->json('data.pending_restore'));
        $this->assertTrue($show->json('data.access_suspended'));

        // Manual suspension survives cure even when auto_restore_access is true.
        $this->policy->forceFill(['auto_restore_access' => true])->save();
        [$contractC, $caseC] = $this->openCaseWithoutSuspension();
        AccessSuspension::suspend($contractC, AccessSuspensionReason::Manual, $caseC, $this->employee);
        $this->payOff($contractC);
        $caseC->refresh();
        $this->assertNotNull($caseC->cured_on);
        $manual = AccessSuspension::query()->active()->where('contract_id', $contractC->id)->firstOrFail();
        $this->assertSame(AccessSuspensionReason::Manual, $manual->reason);

        // Manual API audits.
        [$contractD, $caseD] = $this->openCaseWithoutSuspension();
        $suspend = $this->postJson("/api/delinquencies/{$caseD->id}/suspend-access", [
            'reason' => 'safety hold',
        ]);
        $suspend->assertOk();
        $this->assertTrue($suspend->json('data.access_suspended'));

        $step = $caseD->timeline()->first(
            fn ($s) => $s->action === DelinquencyStepAction::RevokeAccess
                && $s->trigger === DelinquencyStepTrigger::Manual
        );
        $this->assertNotNull($step);
        $this->assertSame('safety hold', $step->detail['reason'] ?? null);

        $this->assertNotNull(
            Activity::query()
                ->where('log_name', LogChannel::Core->value)
                ->where('description', 'access.suspended')
                ->where('properties->contract_id', $contractD->id)
                ->first()
        );

        $restore = $this->postJson("/api/delinquencies/{$caseD->id}/restore-access", [
            'reason' => 'cleared',
        ]);
        $restore->assertOk();
        $this->assertFalse($restore->json('data.access_suspended'));
        $this->assertTrue(
            $caseD->timeline()->contains(
                fn ($s) => $s->action === DelinquencyStepAction::RestoreAccess
                    && $s->trigger === DelinquencyStepTrigger::Manual
            )
        );
    }

    /**
     * @return array{0: Contract, 1: Delinquency}
     */
    private function openSuspendedCase(): array
    {
        $contract = $this->makeDelinquentContract();
        (new DelinquencyEngine)->run((int) $contract->id);
        $case = Delinquency::query()->where('contract_id', $contract->id)->open()->firstOrFail();
        $this->assertTrue(
            AccessSuspension::query()->active()->where('contract_id', $contract->id)->exists()
        );

        return [$contract, $case];
    }

    /**
     * Open a case via notice only (no revoke step execution).
     *
     * @return array{0: Contract, 1: Delinquency}
     */
    private function openCaseWithoutSuspension(): array
    {
        DelinquencyPolicyStep::query()->where('delinquency_policy_id', $this->policy->id)->delete();
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 1,
            'action' => DelinquencyPolicyAction::RecordNotice,
            'params' => ['notice_type' => 'overdue'],
            'sort' => 1,
        ]);

        $contract = $this->makeDelinquentContract();
        (new DelinquencyEngine)->run((int) $contract->id);
        $case = Delinquency::query()->where('contract_id', $contract->id)->open()->firstOrFail();
        $this->assertFalse(
            AccessSuspension::query()->active()->where('contract_id', $contract->id)->exists()
        );

        // Restore revoke step for any subsequent suspended-case helpers.
        DelinquencyPolicyStep::query()->where('delinquency_policy_id', $this->policy->id)->delete();
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 1,
            'action' => DelinquencyPolicyAction::RevokeAccess,
            'params' => [],
            'sort' => 1,
        ]);

        return [$contract, $case];
    }

    private function makeDelinquentContract(): Contract
    {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-06-01',
        ]);
        $item = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'contract_item_id' => $item->id,
            'started_on' => '2026-06-01',
            'ended_on' => null,
            'created_by' => $this->employee->id,
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

        return $contract;
    }

    private function payOff(Contract $contract): void
    {
        $openTotal = '0.00';
        foreach (Charge::query()->where('contract_id', $contract->id)->with('allocations')->get() as $c) {
            $openTotal = bcadd($openTotal, $c->openAmount(), 2);
        }
        $payment = Payment::factory()->cash('2026-08-15')->create([
            'contract_id' => $contract->id,
            'amount' => $openTotal,
            'currency' => 'EUR',
        ]);
        DB::transaction(function () use ($contract, $payment): void {
            PaymentAllocator::allocate($contract->fresh(), $payment);
        });

        (new DelinquencyEngine)->evaluateContract(
            $contract->fresh(),
            DelinquencyCureTrigger::Payment,
        );
    }
}
