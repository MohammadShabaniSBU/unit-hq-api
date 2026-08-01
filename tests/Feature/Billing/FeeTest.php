<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Delinquency\DelinquencyEngine;
use App\Support\Fiscal\InvoiceIssuer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class FeeTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Unit $unit;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
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

    public function test_flat_percent_cap_zero_nocompound(): void
    {
        $policy = DelinquencyPolicy::factory()->create(['name' => 'fees']);
        $this->site->update(['delinquency_policy_id' => $policy->id]);

        // Day 1: flat 10; day 2: 10% of base; day 3: flat 100 with cap 25 (already 10+10=20 → 5 left);
        // day 4: flat 10 but base 0 after we only have fees overdue → skipped_zero when rent paid? 
        // Better: day 4 percent with base that compounds if wrong.
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 1,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '10.00'],
            'sort' => 1,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 2,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'percent', 'percent' => '10.00'],
            'sort' => 2,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 3,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '100.00', 'cap_per_case' => '25.00'],
            'sort' => 3,
        ]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 4,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '10.00', 'cap_per_case' => '25.00'],
            'sort' => 4,
        ]);

        // Rent 100 overdue — percent of 100 = 10; fee #2 must ignore fee #1.
        $contract = $this->makeContract(dueDate: '2026-08-01', amount: '100.00');

        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00', 'Europe/Madrid'));
        (new DelinquencyEngine)->evaluateContract($contract);

        $case = Delinquency::query()->where('contract_id', $contract->id)->open()->firstOrFail();
        $feeSteps = $case->steps()
            ->where('action', DelinquencyStepAction::AssessLateFee)
            ->orderBy('id')
            ->get();
        $this->assertSame(4, $feeSteps->count());

        $fee1 = Charge::query()->findOrFail($feeSteps[0]->charge_id);
        $this->assertSame('10.00', (string) $fee1->net_amount);
        $this->assertSame('100.00', $feeSteps[0]->detail['base'] ?? null);

        $fee2 = Charge::query()->findOrFail($feeSteps[1]->charge_id);
        // 10% of rent base 100 — NOT 10% of 110 (no compound)
        $this->assertSame('10.00', (string) $fee2->net_amount);
        $this->assertSame('100.00', $feeSteps[1]->detail['base'] ?? null);

        $fee3 = Charge::query()->findOrFail($feeSteps[2]->charge_id);
        // cap 25 − prior 20 = 5
        $this->assertSame('5.00', (string) $fee3->net_amount);

        // Cap exhausted → zero skip
        $this->assertNull($feeSteps[3]->charge_id);
        $this->assertTrue($feeSteps[3]->detail['skipped_zero'] ?? false);
    }

    public function test_invoice_exclusion_flag(): void
    {
        $policy = DelinquencyPolicy::factory()->create();
        $this->site->update(['delinquency_policy_id' => $policy->id]);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 1,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '12.00'],
            'sort' => 1,
        ]);

        $contract = $this->makeContract(dueDate: '2026-08-01');
        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00', 'Europe/Madrid'));
        (new DelinquencyEngine)->evaluateContract($contract);

        $lateFee = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::LateFee)
            ->firstOrFail();
        $rent = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::Rent)
            ->firstOrFail();

        config(['fiscal.invoice_late_fees' => false]);
        $filtered = InvoiceIssuer::filterCharges($contract, collect([$rent, $lateFee]));
        $this->assertTrue($filtered->contains('id', $rent->id));
        $this->assertFalse($filtered->contains('id', $lateFee->id));

        config(['fiscal.invoice_late_fees' => true]);
        $included = InvoiceIssuer::filterCharges($contract, collect([$rent, $lateFee]));
        $this->assertTrue($included->contains('id', $lateFee->id));
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
