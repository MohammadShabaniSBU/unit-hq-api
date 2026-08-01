<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Enums\LogChannel;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\DelinquencyStep;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Delinquency\DelinquencyLifecycle;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class DelinquencyCaseTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private DelinquencyPolicy $policy;

    private DelinquencyPolicyStep $policyStep;

    private Unit $unit;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->policy = DelinquencyPolicy::factory()->create(['name' => 'ES test']);
        $this->policyStep = DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 5,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '10.00'],
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

    public function test_open_cure_reopen(): void
    {
        $contract = $this->makeDelinquentContract();

        $case = DelinquencyLifecycle::open($contract);
        $this->assertNotNull($case);
        $this->assertSame($this->policy->id, $case->delinquency_policy_id);
        $this->assertSame('2026-08-01', $case->anchor_due_date->toDateString());
        $this->assertSame('2026-08-15', $case->opened_on->toDateString());
        $this->assertNull($case->cured_on);

        // Mid-case policy swap on site must not retarget the open case.
        $other = DelinquencyPolicy::factory()->create(['name' => 'other']);
        $this->site->update(['delinquency_policy_id' => $other->id]);
        $again = DelinquencyLifecycle::open($contract->fresh());
        $this->assertSame($case->id, $again?->id);
        $this->assertSame($this->policy->id, $again->delinquency_policy_id);

        DelinquencyLifecycle::cure($case, DelinquencyCureTrigger::Payment);
        $case->refresh();
        $this->assertSame('2026-08-15', $case->cured_on->toDateString());
        $this->assertSame(DelinquencyCureTrigger::Payment, $case->cure_trigger);
        $this->assertSame(1, $case->steps()->where('trigger', DelinquencyStepTrigger::Cure)->count());

        // Re-delinquency opens a new case — history stays legible.
        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '50.00',
            'net_amount' => '50.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-08-10',
        ]);
        // Restore site policy for reopen.
        $this->site->update(['delinquency_policy_id' => $this->policy->id]);

        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'Europe/Madrid'));
        $reopened = DelinquencyLifecycle::open($contract->fresh());
        $this->assertNotNull($reopened);
        $this->assertNotSame($case->id, $reopened->id);
        $this->assertSame(2, Delinquency::query()->where('contract_id', $contract->id)->count());
        $this->assertSame(1, Delinquency::query()->where('contract_id', $contract->id)->open()->count());
    }

    public function test_single_open_case(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index is Postgres-only.');
        }

        $contract = $this->makeDelinquentContract();
        DelinquencyLifecycle::open($contract);

        $this->expectException(QueryException::class);
        Delinquency::query()->create([
            'contract_id' => $contract->id,
            'delinquency_policy_id' => $this->policy->id,
            'anchor_due_date' => '2026-08-01',
            'opened_on' => '2026-08-15',
        ]);
    }

    public function test_pause_resume_backfill_flagged(): void
    {
        $contract = $this->makeDelinquentContract();
        $case = DelinquencyLifecycle::openOrFail($contract);

        DelinquencyLifecycle::pause($case, 'tenant dispute', $this->employee);
        $case->refresh();
        $this->assertTrue($case->isPaused());

        $paused = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'delinquency.paused')
            ->where('subject_id', $case->id)
            ->first();
        $this->assertNotNull($paused);
        $this->assertSame('tenant dispute', $paused->properties['reason'] ?? null);

        // Aging continues while paused — freeze later date.
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00', 'Europe/Madrid'));

        $afterPause = DelinquencyLifecycle::resume($case->fresh(), $this->employee);
        $this->assertTrue($afterPause);
        $case->refresh();
        $this->assertFalse($case->isPaused());

        $step = DelinquencyLifecycle::recordStep(
            delinquency: $case,
            action: DelinquencyStepAction::fromPolicyAction(DelinquencyPolicyAction::AssessLateFee),
            trigger: DelinquencyStepTrigger::Ladder,
            executedOn: '2026-08-25',
            policyStep: $this->policyStep,
            afterPause: true,
        );

        $this->assertTrue($step->detail['executed_after_pause'] ?? false);
        $this->assertSame(1, DelinquencyStep::query()->where('delinquency_id', $case->id)->count());
    }

    public function test_vacate_and_write_off_cure(): void
    {
        $contract = $this->makeDelinquentContract();
        $case = DelinquencyLifecycle::openOrFail($contract);

        DelinquencyLifecycle::cure($case, DelinquencyCureTrigger::Vacated);
        $case->refresh();
        $this->assertSame(DelinquencyCureTrigger::Vacated, $case->cure_trigger);

        $contract2 = $this->makeDelinquentContract(dueDate: '2026-07-15');
        $case2 = DelinquencyLifecycle::openOrFail($contract2);
        DelinquencyLifecycle::cure($case2, DelinquencyCureTrigger::WriteOff);
        $case2->refresh();
        $this->assertSame(DelinquencyCureTrigger::WriteOff, $case2->cure_trigger);

        foreach ([$case, $case2] as $cured) {
            $cureStep = $cured->steps()->where('trigger', DelinquencyStepTrigger::Cure)->first();
            $this->assertNotNull($cureStep);
            $this->assertSame(DelinquencyStepAction::Cure, $cureStep->action);
        }
    }

    private function makeDelinquentContract(string $dueDate = '2026-08-01'): Contract
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

        return $contract->fresh(['unitItem.item.site']) ?? $contract;
    }
}
