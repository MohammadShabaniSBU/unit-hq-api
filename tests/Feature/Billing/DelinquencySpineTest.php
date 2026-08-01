<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Enums\HoldType;
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
use App\Support\Billing\PaymentAllocator;
use App\Support\Delinquency\DelinquencyEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

/**
 * Sprint 07 exit: seed → skip payment → delinquency runs → fee + overlock + notice
 * → manual cash → cured, released, timeline complete.
 */
class DelinquencySpineTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_full_failure_branch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'Europe/Madrid'));

        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $policy = DelinquencyPolicy::factory()->create([
            'name' => 'spine',
            'auto_release_overlock' => true,
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
            'offset_days' => 12,
            'action' => DelinquencyPolicyAction::PlaceOverlock,
            'params' => [],
            'sort' => 3,
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

        // Skip payment — advance through the ladder.
        Carbon::setTestNow(Carbon::parse('2026-08-13 12:00:00', 'Europe/Madrid'));
        Artisan::call('delinquency:run', ['--contract' => $contract->id]);

        $case = Delinquency::query()->where('contract_id', $contract->id)->open()->firstOrFail();
        $timeline = $case->timeline();
        $this->assertSame(3, $timeline->count());
        $this->assertTrue($timeline->contains(fn ($s) => $s->action === DelinquencyStepAction::AssessLateFee));
        $this->assertTrue($timeline->contains(fn ($s) => $s->action === DelinquencyStepAction::RecordNotice));
        $this->assertTrue($timeline->contains(fn ($s) => $s->action === DelinquencyStepAction::PlaceOverlock));

        $this->assertSame(1, Charge::query()->where('charge_type', ChargeType::LateFee)->count());
        $this->assertSame(1, ContractNotice::query()->where('contract_id', $contract->id)->count());
        $hold = UnitHold::query()
            ->where('hold_type', HoldType::Overlock)
            ->whereNull('released_at')
            ->firstOrFail();

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

        $this->assertTrue(
            $case->timeline()->contains(fn ($s) => $s->action === DelinquencyStepAction::Cure)
        );

        // Re-run is a no-op on the cured case.
        (new DelinquencyEngine)->run((int) $contract->id);
        $this->assertSame(1, Delinquency::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(0, Delinquency::query()->where('contract_id', $contract->id)->open()->count());

        Carbon::setTestNow();
    }
}
