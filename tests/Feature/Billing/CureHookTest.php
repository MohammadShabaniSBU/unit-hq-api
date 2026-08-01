<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\HoldType;
use App\Enums\PaymentMethod;
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
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Billing\PaymentAllocator;
use App\Support\Delinquency\DelinquencyEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class CureHookTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private DelinquencyPolicy $policy;

    private Unit $unit;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->policy = DelinquencyPolicy::factory()->create([
            'name' => 'cure',
            'auto_release_overlock' => true,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 1,
            'action' => DelinquencyPolicyAction::PlaceOverlock,
            'params' => [],
            'sort' => 1,
        ]);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'delinquency_policy_id' => $this->policy->id,
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->priceId = $price->id;
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_allocation_triggers_same_day_cure(): void
    {
        // Rail 1: manual-style allocate (oldest-due-first)
        $this->assertRailCures(function (Contract $contract, Charge $charge): void {
            $payment = Payment::factory()->cash('2026-08-15')->create([
                'contract_id' => $contract->id,
                'amount' => '100.00',
                'currency' => 'EUR',
            ]);
            DB::transaction(function () use ($contract, $payment): void {
                PaymentAllocator::allocate($contract, $payment);
            });
        });

        // Rail 2: Stripe-style targeted then oldest
        $this->assertRailCures(function (Contract $contract, Charge $charge): void {
            $payment = Payment::factory()->create([
                'contract_id' => $contract->id,
                'amount' => '100.00',
                'currency' => 'EUR',
                'stripe_payment_intent_id' => 'pi_cure_'.uniqid(),
            ]);
            DB::transaction(function () use ($contract, $payment, $charge): void {
                PaymentAllocator::allocateTargetedThenOldest($contract, $payment, [(int) $charge->id]);
            });
        });

        // Rail 3: explicit allocate (payment-link / counter with line picks)
        $this->assertRailCures(function (Contract $contract, Charge $charge): void {
            $payment = Payment::factory()->cash('2026-08-15')->create([
                'contract_id' => $contract->id,
                'amount' => '100.00',
                'currency' => 'EUR',
                'method' => PaymentMethod::Cash,
            ]);
            DB::transaction(function () use ($contract, $payment, $charge): void {
                PaymentAllocator::allocate($contract, $payment, [
                    ['charge_id' => (int) $charge->id, 'amount' => '100.00'],
                ]);
            });
        });
    }

    /**
     * @param  callable(Contract, Charge): void  $pay
     */
    private function assertRailCures(callable $pay): void
    {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unit->unit_class_id,
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

        $contract = $contract->fresh(['unitItem.item.site']) ?? $contract;

        (new DelinquencyEngine)->evaluateContract($contract);
        $case = Delinquency::query()->where('contract_id', $contract->id)->open()->firstOrFail();
        $this->assertNotNull(
            UnitHold::query()
                ->where('hold_type', HoldType::Overlock)
                ->whereNull('released_at')
                ->where('reason', 'delinquency:'.$case->id)
                ->first()
        );

        $charge = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::Rent)
            ->firstOrFail();

        $pay($contract->fresh(), $charge);

        $case->refresh();
        $this->assertNotNull($case->cured_on);
        $this->assertSame(DelinquencyCureTrigger::Payment, $case->cure_trigger);
        $this->assertSame('2026-08-15', $case->cured_on->toDateString());
        $this->assertSame(
            0,
            UnitHold::query()
                ->where('hold_type', HoldType::Overlock)
                ->whereNull('released_at')
                ->where('reason', 'delinquency:'.$case->id)
                ->count()
        );
    }
}
