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
use App\Support\Delinquency\DelinquencyLifecycle;
use App\Support\Delinquency\DelinquencyPrediction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class PredictionTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Unit $unit;

    private int $priceId;

    private DelinquencyPolicyStep $feeStep;

    private DelinquencyPolicyStep $overlockStep;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $policy = DelinquencyPolicy::factory()->create(['name' => 'predict-policy']);
        $this->feeStep = DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 5,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '10.00'],
            'sort' => 1,
        ]);
        $this->overlockStep = DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 10,
            'action' => DelinquencyPolicyAction::PlaceOverlock,
            'params' => [],
            'sort' => 2,
        ]);

        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'delinquency_policy_id' => $policy->id,
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

    public function test_next_step_matches_engine(): void
    {
        // Anchor due 2026-08-01 → on Aug 5 elapsed=4; next is fee at offset 5 (in 1 day).
        $contract = $this->makeContract('2026-08-01');
        $case = DelinquencyLifecycle::openOrFail($contract);
        $case->load(['policy.steps', 'steps', 'contract.unitItem.item.site']);

        $prediction = DelinquencyPrediction::nextStep($case);
        $this->assertNotNull($prediction);
        $this->assertSame('assess_late_fee', $prediction['action']);
        $this->assertSame(5, $prediction['offset_days']);
        $this->assertSame(1, $prediction['days_until']);
        $this->assertSame('2026-08-06', $prediction['predicted_on']);
        $this->assertSame($this->feeStep->id, $prediction['policy_step_id']);

        $api = $this->getJson('/api/delinquencies/'.$case->id);
        $api->assertOk();
        $api->assertJsonPath('data.next_step.policy_step_id', $this->feeStep->id);
        $api->assertJsonPath('data.next_step.days_until', 1);

        // Advance to predicted day and run — engine must execute that step.
        Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00', 'Europe/Madrid'));
        (new DelinquencyEngine)->evaluateContract($contract->fresh() ?? $contract);

        $case->refresh();
        $executed = $case->steps()
            ->where('policy_step_id', $prediction['policy_step_id'])
            ->first();
        $this->assertNotNull($executed);
        $this->assertSame(DelinquencyStepAction::AssessLateFee, $executed->action);

        // After fee, next prediction is overlock at offset 10 (4 days from Aug 6; elapsed=5).
        $case->load(['policy.steps', 'steps', 'contract.unitItem.item.site']);
        $next = DelinquencyPrediction::nextStep($case);
        $this->assertNotNull($next);
        $this->assertSame('place_overlock', $next['action']);
        $this->assertSame($this->overlockStep->id, $next['policy_step_id']);
        $this->assertSame(5, $next['days_until']);
        $this->assertSame('2026-08-11', $next['predicted_on']);

        Carbon::setTestNow(Carbon::parse($next['predicted_on'].' 12:00:00', 'Europe/Madrid'));
        (new DelinquencyEngine)->evaluateContract($contract->fresh() ?? $contract);

        $this->assertNotNull(
            Delinquency::query()->findOrFail($case->id)
                ->steps()
                ->where('policy_step_id', $next['policy_step_id'])
                ->first()
        );
    }

    private function makeContract(string $dueDate): Contract
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
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => $dueDate,
        ]);

        return $contract->fresh(['unitItem.item.site', 'charges.allocations']) ?? $contract;
    }
}
