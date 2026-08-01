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
use App\Enums\DelinquencyStepTrigger;
use App\Models\AutopayAttempt;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractNotice;
use App\Models\Country;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\DelinquencyStep;
use App\Models\EmailBlock;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\Interaction;
use App\Models\PaymentMethod;
use App\Models\Site;
use App\Models\Task;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Delinquency\DelinquencyLifecycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AutomationHarness;
use Tests\Support\CreatesCataloguePrices;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class HarnessLibraryTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'UTC'));
        $this->fakeCommunicationProviders();
        Employee::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_linear_three_actions(): void
    {
        $this->seedEmailAccount(Site::factory()->create());
        $contact = Contact::factory()->create([
            'first_name' => 'Start',
            'last_name' => 'Contact',
            'email' => 'linear-'.uniqid().'@example.com',
        ]);
        $this->givePrimaryEmail($contact, (string) $contact->email);

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
        $this->assertTrue(
            Interaction::query()->where('contact_id', $contact->id)->where('channel', 'email')->exists(),
        );
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

    public function test_single_enrolment_per_subject(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Once',
            'email' => 'once-'.uniqid().'@example.com',
        ]);

        $harness = AutomationHarness::load('single_enrolment_per_subject')
            ->trigger('object_created', $contact)
            ->assertRunStatus(AutomationRunStatus::Waiting);

        $firstRunId = $harness->run()->id;

        // Re-dispatch matcher while first enrolment is still active — no second run.
        (new \App\Jobs\MatchAutomationTriggers(
            'created',
            (string) $contact->getMorphClass(),
            $contact->getKey(),
            [],
            $contact->attributesToArray(),
            null,
            null,
        ))->handle();

        $this->assertSame(
            1,
            \App\Models\AutomationRun::query()
                ->where('automation_id', $harness->automation()->id)
                ->where('subject_id', $contact->id)
                ->count(),
        );
        $this->assertSame($firstRunId, $harness->run()->fresh()->id);
    }

    public function test_sms_send_and_skip_no_channel(): void
    {
        $this->seedSmsAccount(Site::factory()->create());

        $withPhone = Contact::factory()->create([
            'first_name' => 'HasPhone',
            'last_name' => 'Before',
            'email' => 'sms-ok-'.uniqid().'@example.com',
        ]);
        $this->givePrimaryPhone($withPhone, '+15550001234');

        AutomationHarness::load('sms_send_and_skip_no_channel')
            ->trigger('object_created', $withPhone)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepSequence(['trigger', 'sms', 'after']);

        $this->assertSame('SmsContinued', $withPhone->fresh()->last_name);
        $this->assertTrue(
            Interaction::query()->where('contact_id', $withPhone->id)->where('channel', 'sms')->exists(),
        );

        $noPhone = Contact::factory()->create([
            'first_name' => 'NoPhone',
            'last_name' => 'Before',
            'email' => 'sms-skip-'.uniqid().'@example.com',
        ]);

        $harness = AutomationHarness::load('sms_send_and_skip_no_channel')
            ->trigger('object_created', $noPhone)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('sms', AutomationRunStepStatus::Succeeded)
            ->assertStepStatus('after', AutomationRunStepStatus::Succeeded);

        $smsNodeId = $harness->automation()->nodes->firstWhere('node_key', 'sms')?->id;
        $smsStep = $harness->run()->steps->firstWhere('node_id', $smsNodeId);
        $this->assertSame('no_channel', $smsStep?->output['skipped_reason'] ?? null);
        $this->assertSame('SmsContinued', $noPhone->fresh()->last_name);
    }

    public function test_email_template_reference(): void
    {
        $this->seedEmailAccount(Site::factory()->create());

        $template = EmailTemplate::query()->create(['name' => 'Debt reminder']);
        EmailBlock::query()->create([
            'email_template_id' => $template->id,
            'type' => 'text',
            'props' => [
                'content' => 'Hello {{trigger.attributes.first_name}}',
                'align' => 'left',
                'fontSize' => 16,
                'color' => '#000000',
            ],
            'order' => 0,
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Templated',
            'last_name' => 'Before',
            'email' => 'tmpl-'.uniqid().'@example.com',
        ]);
        $this->givePrimaryEmail($contact, 'tmpl-primary@example.com');

        $harness = AutomationHarness::load('email_template_reference');
        $emailNode = $harness->automation()->nodes()->where('node_key', 'email')->first();
        $this->assertNotNull($emailNode);
        $config = $emailNode->config;
        $config['templateId'] = $template->id;
        $emailNode->update(['config' => $config]);

        $harness->trigger('object_created', $contact)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepSequence(['trigger', 'email', 'after']);

        $this->assertSame('TemplateSent', $contact->fresh()->last_name);

        $interaction = Interaction::query()
            ->where('contact_id', $contact->id)
            ->where('channel', 'email')
            ->first();
        $this->assertNotNull($interaction);
        $this->assertSame('Template subject', $interaction->summary);
        $this->assertStringContainsString('Hello Templated', (string) $interaction->content);
    }

    public function test_record_notice_debt_chain(): void
    {
        [$contract] = $this->seedDelinquencyCatalogue();
        $case = DelinquencyLifecycle::open($contract);
        $this->assertNotNull($case);

        AutomationHarness::load('record_notice_debt_chain')
            ->trigger('object_created', $case)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('notice', AutomationRunStepStatus::Succeeded);

        $notice = ContractNotice::query()->where('contract_id', $contract->id)->first();
        $this->assertNotNull($notice);
        $this->assertSame('payment_reminder', $notice->notice_type->value);

        $this->assertTrue(
            DelinquencyStep::query()
                ->where('delinquency_id', $case->id)
                ->where('trigger', DelinquencyStepTrigger::Playbook)
                ->where('contract_notice_id', $notice->id)
                ->exists(),
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
