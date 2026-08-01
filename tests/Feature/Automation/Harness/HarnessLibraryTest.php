<?php

declare(strict_types=1);

namespace Tests\Feature\Automation\Harness;

use App\Enums\AutopayAttemptStatus;
use App\Enums\AutomationCancelCause;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Models\AutopayAttempt;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Models\Site;
use App\Models\Task;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Delinquency\DelinquencyLifecycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AutomationHarness;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class HarnessLibraryTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'UTC'));
        Mail::fake();
        Employee::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_linear_three_actions(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Start',
            'last_name' => 'Contact',
            'email' => 'linear-'.uniqid().'@example.com',
        ]);

        AutomationHarness::load('linear_three_actions')
            ->trigger('object_created', $contact)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepSequence([
                'trigger',
                'update_one',
                'email',
                'update_two',
            ]);

        $contact->refresh();
        $this->assertSame('LinearOne', $contact->first_name);
        $this->assertSame('LinearTwo', $contact->last_name);
    }

    public function test_branch_true_false_pair(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'BranchPair',
            'last_name' => 'Contact',
            'email' => 'branch-'.uniqid().'@example.com',
        ]);

        AutomationHarness::load('branch_true_false_pair')
            ->trigger('object_created', $contact)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('yes_path', AutomationRunStepStatus::Succeeded)
            ->assertSkipped(['no_path']);

        $this->assertSame('YesPath', $contact->fresh()->last_name);
    }

    public function test_nested_branch_3deep(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Nested',
            'last_name' => 'Deep',
            'email' => 'nested-'.uniqid().'@example.com',
        ]);

        AutomationHarness::load('nested_branch_3deep')
            ->trigger('object_created', $contact)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('deep_yes', AutomationRunStepStatus::Succeeded)
            ->assertSkipped(['no_1', 'no_2', 'no_3']);

        $this->assertSame('DeepYes', $contact->fresh()->first_name);
    }

    public function test_wait_relative_then_branch_uses_snapshot(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'WaitSnap',
            'last_name' => 'Contact',
            'email' => 'waitsnap-'.uniqid().'@example.com',
        ]);

        $harness = AutomationHarness::load('wait_relative_then_branch')
            ->trigger('object_created', $contact)
            ->assertRunStatus(AutomationRunStatus::Waiting);

        $contact->update(['first_name' => 'MutatedLive']);

        $harness->travelTo('+1 hour', via: 'delayed')
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('yes_path', AutomationRunStepStatus::Succeeded)
            ->assertStepStatus('wait', AutomationRunStepStatus::Succeeded)
            ->assertSkipped(['no_path']);

        $this->assertSame('SnapYes', $contact->fresh()->last_name);
    }

    public function test_wait_until_token_delayed_and_sweeper(): void
    {
        // Delayed resume path
        $contactA = Contact::factory()->create([
            'first_name' => 'UntilA',
            'email' => 'until-a-'.uniqid().'@example.com',
        ]);

        AutomationHarness::load('wait_until_token')
            ->trigger('object_created', $contactA)
            ->assertRunStatus(AutomationRunStatus::Waiting)
            ->assertStepStatus('wait', AutomationRunStepStatus::Waiting)
            ->travelTo('+2 days', via: 'delayed')
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('wait', AutomationRunStepStatus::Succeeded);

        $this->assertSame('UntilDone', $contactA->fresh()->first_name);

        // Sweeper-only path
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'UTC'));
        $contactB = Contact::factory()->create([
            'first_name' => 'UntilB',
            'email' => 'until-b-'.uniqid().'@example.com',
        ]);

        AutomationHarness::load('wait_until_token')
            ->trigger('object_created', $contactB)
            ->assertRunStatus(AutomationRunStatus::Waiting)
            ->travelTo('+2 days', via: 'sweeper')
            ->assertRunStatus(AutomationRunStatus::Succeeded);

        $this->assertSame('UntilDone', $contactB->fresh()->first_name);
    }

    public function test_guarded_wait_cancelled(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'GuardMe',
            'last_name' => 'Contact',
            'email' => 'guard-cancel-'.uniqid().'@example.com',
        ]);

        AutomationHarness::load('guarded_wait_cancelled')
            ->trigger('object_created', $contact)
            ->assertRunStatus(AutomationRunStatus::Waiting)
            ->mutate(function () use ($contact): void {
                $contact->update(['first_name' => 'PaidOff']);
            }, evaluateGuards: true)
            ->assertRunStatus(AutomationRunStatus::Cancelled, cause: AutomationCancelCause::Guard);
    }

    public function test_guarded_completes_when_guard_holds(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'GuardMe',
            'last_name' => 'Contact',
            'email' => 'guard-hold-'.uniqid().'@example.com',
        ]);

        AutomationHarness::load('guarded_completes_when_guard_holds')
            ->trigger('object_created', $contact)
            ->assertRunStatus(AutomationRunStatus::Waiting)
            ->travelTo('+2 days', via: 'delayed')
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('after', AutomationRunStepStatus::Succeeded);

        $this->assertSame('AfterWait', $contact->fresh()->last_name);
    }

    public function test_create_object_chain(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Trigger',
            'last_name' => 'Subject',
            'email' => 'create-chain-'.uniqid().'@example.com',
        ]);

        AutomationHarness::load('create_object_chain')
            ->trigger('object_created', $contact)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepSequence([
                'trigger',
                'create_contact',
                'update_created',
            ]);

        $created = Contact::query()->where('email', 'created-chain@example.com')->first();
        $this->assertNotNull($created);
        $this->assertSame('UpdatedChain', $created->first_name);
    }

    public function test_cancel_midwait_manual(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'CancelMe',
            'email' => 'cancel-'.uniqid().'@example.com',
        ]);
        $employee = Employee::factory()->manager()->create();

        $harness = AutomationHarness::load('cancel_midwait_manual')
            ->trigger('object_created', $contact)
            ->assertRunStatus(AutomationRunStatus::Waiting)
            ->cancel($employee)
            ->assertRunStatus(AutomationRunStatus::Cancelled, cause: AutomationCancelCause::Manual);

        // Resume must no-op.
        $harness->travelTo('+3 days', via: 'delayed')
            ->assertRunStatus(AutomationRunStatus::Cancelled, cause: AutomationCancelCause::Manual);

        $this->assertSame('BeforeWait', $contact->fresh()->first_name);
        $this->assertNotSame('ShouldNotRun', $contact->fresh()->first_name);
    }

    public function test_delinquency_open_to_cure_exit(): void
    {
        [$contract] = $this->seedDelinquencyCatalogue();

        $case = DelinquencyLifecycle::open($contract);
        $this->assertNotNull($case);

        AutomationHarness::load('delinquency_open_to_cure_exit')
            ->trigger('object_created', $case)
            ->assertRunStatus(AutomationRunStatus::Waiting)
            ->assertStepStatus('wait', AutomationRunStepStatus::Waiting)
            ->mutate(function () use ($case): void {
                DelinquencyLifecycle::cure($case->fresh(), DelinquencyCureTrigger::Payment);
            }, evaluateGuards: true)
            ->assertRunStatus(AutomationRunStatus::Cancelled, cause: AutomationCancelCause::Guard);
    }

    public function test_retry_failed_autopay_recipe(): void
    {
        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
        ]);
        $method = PaymentMethod::factory()->create(['contact_id' => $contact->id]);

        $attempt = AutopayAttempt::factory()->create([
            'contract_id' => $contract->id,
            'payment_method_id' => $method->id,
            'status' => AutopayAttemptStatus::Pending,
        ]);

        $attempt->forceFill([
            'status' => AutopayAttemptStatus::Failed,
            'failure_code' => 'card_declined',
            'resolved_at' => now(),
        ])->save();

        AutomationHarness::load('retry_failed_autopay_recipe')
            ->trigger('object_updated', $attempt->fresh(), [
                'status' => [
                    'old' => AutopayAttemptStatus::Pending->value,
                    'new' => AutopayAttemptStatus::Failed->value,
                ],
            ])
            ->assertRunStatus(AutomationRunStatus::Waiting)
            ->travelTo('+3 days', via: 'delayed')
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('retry_task', AutomationRunStepStatus::Succeeded);

        $this->assertTrue(
            Task::query()->where('title', 'Retry failed autopay')->exists(),
        );
    }

    public function test_schedule_trigger_quiet_deal(): void
    {
        AutomationHarness::load('schedule_trigger_quiet_deal')
            ->triggerSchedule()
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepSequence([
                'trigger',
                'create_contact',
                'chase_task',
            ]);

        $this->assertTrue(
            Contact::query()->where('email', 'quiet-deal@example.com')->exists(),
        );
        $this->assertTrue(
            Task::query()->where('title', 'Chase quiet deal')->exists(),
        );
    }

    /** @return array{0: Contract, 1: Site} */
    private function seedDelinquencyCatalogue(): array
    {
        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $policy = DelinquencyPolicy::factory()->create(['name' => 'Harness ES']);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 5,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '10.00'],
            'sort' => 1,
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

        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ]);

        \App\Models\Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-18',
        ]);

        return [$contract->fresh(['unitItem.item.site', 'charges']) ?? $contract, $site];
    }
}
