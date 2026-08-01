<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Enums\HoldType;
use App\Models\Allocation;
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
use App\Support\Delinquency\DelinquencyEngine;
use App\Support\Delinquency\DelinquencyLifecycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class EngineTest extends TestCase
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

        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->policy = DelinquencyPolicy::factory()->create(['name' => 'ladder']);
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

    public function test_ladder_day_by_day_idempotent(): void
    {
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 1,
            'action' => DelinquencyPolicyAction::RecordNotice,
            'params' => ['notice_type' => 'payment_reminder'],
            'sort' => 1,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 3,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '10.00'],
            'sort' => 2,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 5,
            'action' => DelinquencyPolicyAction::PlaceOverlock,
            'params' => [],
            'sort' => 3,
        ]);

        // Due 2026-08-01; site-today must be > due for delinquency (due < today).
        $contract = $this->makeContract(dueDate: '2026-08-01');

        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00', 'Europe/Madrid'));
        (new DelinquencyEngine)->run((int) $contract->id);
        $case = Delinquency::query()->where('contract_id', $contract->id)->open()->first();
        $this->assertNotNull($case);
        $this->assertSame('2026-08-01', $case->anchor_due_date->toDateString());
        // elapsed = 1 → notice only
        $this->assertSame(1, $case->steps()->count());
        $this->assertSame(DelinquencyStepAction::RecordNotice, $case->steps()->first()->action);

        // Re-run same day — idempotent.
        (new DelinquencyEngine)->run((int) $contract->id);
        $this->assertSame(1, $case->fresh()->steps()->count());

        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'Europe/Madrid'));
        (new DelinquencyEngine)->run((int) $contract->id);
        $this->assertSame(2, $case->fresh()->steps()->count());
        $this->assertSame(1, Charge::query()->where('charge_type', ChargeType::LateFee)->count());

        // Re-run — still 2.
        (new DelinquencyEngine)->run((int) $contract->id);
        $this->assertSame(2, $case->fresh()->steps()->count());

        // Downtime catch-up: jump past offset 5 — overlock fires once, no extra grace from opened_on.
        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'Europe/Madrid'));
        (new DelinquencyEngine)->run((int) $contract->id);
        $steps = $case->fresh()->steps()->orderBy('id')->get();
        $this->assertSame(3, $steps->count());
        $this->assertSame(DelinquencyStepAction::PlaceOverlock, $steps->last()->action);
        $this->assertSame(1, UnitHold::query()->where('hold_type', HoldType::Overlock)->whereNull('released_at')->count());

        (new DelinquencyEngine)->run((int) $contract->id);
        $this->assertSame(3, $case->fresh()->steps()->count());
    }

    public function test_anchor_not_moving(): void
    {
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 10,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '15.00'],
            'sort' => 1,
        ]);

        $contract = $this->makeContract(dueDate: '2026-08-01', amount: '100.00');
        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '50.00',
            'net_amount' => '50.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-05',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-08 12:00:00', 'Europe/Madrid'));
        (new DelinquencyEngine)->run((int) $contract->id);
        $case = Delinquency::query()->where('contract_id', $contract->id)->open()->firstOrFail();
        $this->assertSame('2026-08-01', $case->anchor_due_date->toDateString());
        // elapsed = 7 < 10 → no fee yet
        $this->assertSame(0, $case->steps()->where('action', DelinquencyStepAction::AssessLateFee)->count());

        // Clear oldest charge — display daysOverdue would re-anchor to Aug 5, but ladder must not.
        $oldest = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('due_date', '2026-08-01')
            ->firstOrFail();
        $payment = Payment::factory()->cash('2026-08-08')->create([
            'contract_id' => $contract->id,
            'amount' => '100.00',
            'currency' => 'EUR',
        ]);
        // Allocate without triggering cure evaluation for this partial (still delinquent).
        Allocation::query()->create([
            'payment_id' => $payment->id,
            'charge_id' => $oldest->id,
            'amount' => '100.00',
        ]);

        $case->refresh();
        $this->assertSame('2026-08-01', $case->anchor_due_date->toDateString());

        // elapsed vs anchor = 10 on Aug 11 → fee fires. If clock moved to Aug 5, elapsed would be 6.
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Europe/Madrid'));
        (new DelinquencyEngine)->evaluateContract($contract->fresh());
        $this->assertSame(1, $case->fresh()->steps()->where('action', DelinquencyStepAction::AssessLateFee)->count());
    }

    public function test_pause_resume(): void
    {
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 2,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '10.00'],
            'sort' => 1,
        ]);

        $contract = $this->makeContract(dueDate: '2026-08-01');
        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00', 'Europe/Madrid'));
        (new DelinquencyEngine)->evaluateContract($contract);
        $case = Delinquency::query()->where('contract_id', $contract->id)->open()->firstOrFail();

        DelinquencyLifecycle::pause($case, 'dispute', $this->employee);

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'Europe/Madrid'));
        (new DelinquencyEngine)->evaluateContract($contract->fresh());
        // Pause writes a timeline step; ladder must not fire while paused.
        $this->assertSame(1, $case->fresh()->steps()->where('action', DelinquencyStepAction::Pause)->count());
        $this->assertSame(0, $case->fresh()->steps()->where('action', DelinquencyStepAction::AssessLateFee)->count());

        DelinquencyLifecycle::resume($case->fresh(), $this->employee);
        (new DelinquencyEngine)->evaluateContract($contract->fresh(), afterPause: true);

        $step = $case->fresh()->steps()
            ->where('action', DelinquencyStepAction::AssessLateFee)
            ->first();
        $this->assertNotNull($step);
        $this->assertTrue($step->detail['executed_after_pause'] ?? false);
        $this->assertSame(DelinquencyStepAction::AssessLateFee, $step->action);
    }

    private function makeContract(string $dueDate = '2026-08-01', string $amount = '100.00'): Contract
    {
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-06-01',
        ]);

        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ]);

        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => $amount,
            'net_amount' => $amount,
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => $dueDate,
        ]);

        return $contract->fresh(['unitItem.item.site']) ?? $contract;
    }
}
