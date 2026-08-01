<?php

declare(strict_types=1);

namespace Tests\Feature\Playbook;

use App\Enums\AutomationCancelCause;
use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Enums\AutomationStatus;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Jobs\EvaluateRunGuards;
use App\Jobs\ExecuteAutomationRun;
use App\Jobs\MatchAutomationTriggers;
use App\Jobs\ResumeAutomationRun;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractNotice;
use App\Models\Country;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\DelinquencyStep;
use App\Models\Employee;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Automation\AutomationExecutor;
use App\Support\Automation\AutomationWatchCache;
use App\Support\Delinquency\DelinquencyLifecycle;
use App\Support\Playbooks\PlaybookCompiler;
use Carbon\Carbon;
use Database\Seeders\DebtPlaybookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class DebtKindTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'UTC'));
        Mail::fake();
        Queue::fake([ExecuteAutomationRun::class, ResumeAutomationRun::class]);
        Event::fake();
        Employee::factory()->create();
        $this->fakeCommunicationProviders();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_enrolment_filters_and_new_case_reenrolment(): void
    {
        [$contract, $site, $policy] = $this->seedDelinquencyCatalogue();
        $otherSite = Site::factory()->create([
            'country_id' => $site->country_id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'delinquency_policy_id' => $policy->id,
        ]);

        $gated = $this->makePlaybook(PlaybookKind::DebtProcess, 'Site gated', [
            ['offset_days' => 0, 'action' => PlaybookStepAction::CreateTask, 'params' => ['title' => 'Chase']],
            ['offset_days' => 3, 'action' => PlaybookStepAction::CreateTask, 'params' => ['title' => 'Later']],
        ], [
            'site_ids' => [$site->id],
            'policy_ids' => [$policy->id],
            'min_days_overdue' => 5,
        ]);

        $automation = $this->compileAndActivate($gated);
        $triggerConfig = $automation->nodes->first(
            fn (AutomationNode $n) => $n->type === AutomationNodeType::ObjectCreated,
        )?->config;
        $this->assertNotNull($triggerConfig);
        $conditions = $triggerConfig['filters']['conditions'] ?? [];
        $this->assertCount(3, $conditions);

        $case = DelinquencyLifecycle::openOrFail($contract);
        $attrs = $case->automationTriggerAttributes();
        $this->assertSame($site->id, $attrs['site_id']);
        $this->assertGreaterThanOrEqual(5, (int) $attrs['days_overdue']);

        $this->matchCreated($case);
        $run = $automation->runs()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame((int) $case->id, (int) $run->subject_id);

        // Wrong site must not enrol.
        $wrongBook = $this->makePlaybook(PlaybookKind::DebtProcess, 'Other site only', [
            ['offset_days' => 0, 'action' => PlaybookStepAction::CreateTask, 'params' => ['title' => 'Nope']],
        ], ['site_ids' => [$otherSite->id]]);
        $wrongAuto = $this->compileAndActivate($wrongBook);
        $this->matchCreated($case);
        $this->assertSame(0, $wrongAuto->runs()->count());

        // min_days_overdue too high gates out.
        $strict = $this->makePlaybook(PlaybookKind::DebtProcess, 'Strict days', [
            ['offset_days' => 0, 'action' => PlaybookStepAction::CreateTask, 'params' => ['title' => 'Strict']],
        ], ['min_days_overdue' => 90]);
        $strictAuto = $this->compileAndActivate($strict);
        $this->matchCreated($case);
        $this->assertSame(0, $strictAuto->runs()->count());

        // Cure then re-delinquency opens a new case → new enrolment (new subject).
        (new AutomationExecutor)->execute($run->fresh());
        $run->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $run->status);

        DelinquencyLifecycle::cure($case->fresh(), DelinquencyCureTrigger::Payment);
        (new EvaluateRunGuards('delinquency', (int) $case->id))->handle();
        $run->refresh();
        $this->assertSame(AutomationRunStatus::Cancelled, $run->status);

        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '50.00',
            'net_amount' => '50.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-10',
        ]);
        $contract->unsetRelation('charges');
        $contract->load(['charges', 'unitItem.item.site']);

        $newCase = DelinquencyLifecycle::openOrFail($contract);
        $this->assertNotSame($case->id, $newCase->id);

        $this->matchCreated($newCase);
        $newRun = $automation->runs()->where('subject_id', $newCase->id)->first();
        $this->assertNotNull($newRun, 'New case must enrol as a new subject');
    }

    public function test_all_three_cure_paths_exit_midwait(): void
    {
        foreach ([
            DelinquencyCureTrigger::Payment,
            DelinquencyCureTrigger::WriteOff,
            DelinquencyCureTrigger::Vacated,
        ] as $trigger) {
            [$contract] = $this->seedDelinquencyCatalogue();
            $playbook = $this->makePlaybook(PlaybookKind::DebtProcess, 'Cure '.$trigger->value, [
                ['offset_days' => 0, 'action' => PlaybookStepAction::CreateTask, 'params' => ['title' => 'Start']],
                ['offset_days' => 5, 'action' => PlaybookStepAction::CreateTask, 'params' => ['title' => 'After wait']],
            ]);
            $automation = $this->compileAndActivate($playbook);

            $case = DelinquencyLifecycle::openOrFail($contract);
            $this->matchCreated($case);
            $run = $automation->runs()->latest('id')->first();
            $this->assertNotNull($run);

            (new AutomationExecutor)->execute($run->fresh());
            $run->refresh();
            $this->assertSame(AutomationRunStatus::Waiting, $run->status);

            DelinquencyLifecycle::cure($case->fresh(), $trigger);
            (new EvaluateRunGuards('delinquency', (int) $case->id))->handle();
            $run->refresh();

            $this->assertSame(AutomationRunStatus::Cancelled, $run->status, $trigger->value);
            $this->assertSame(AutomationCancelCause::Guard, $run->cancel_cause);
        }
    }

    public function test_pairing_sugar_and_skipped_send_notice(): void
    {
        [$contract, $site] = $this->seedDelinquencyCatalogue();
        $this->seedSmsAccount($site);
        // No primary phone → send_sms skips; notice must still be recorded unsent.

        $playbook = $this->makePlaybook(PlaybookKind::DebtProcess, 'Paired notice', [
            [
                'offset_days' => 0,
                'action' => PlaybookStepAction::SendSms,
                'params' => [
                    'body' => 'Please pay',
                    'record_notice' => 'overdue',
                ],
            ],
        ]);

        $automation = PlaybookCompiler::compile($playbook->fresh(['steps']));
        $types = $this->nodeTypeSequence($automation);
        $this->assertSame(
            [
                AutomationNodeType::ObjectCreated->value,
                AutomationNodeType::SendSms->value,
                AutomationNodeType::RecordNotice->value,
            ],
            $types,
        );

        $noticeNode = $automation->nodes->first(
            fn (AutomationNode $n) => $n->type === AutomationNodeType::RecordNotice,
        );
        $this->assertNotNull($noticeNode);
        $this->assertSame('overdue', $noticeNode->config['notice_type'] ?? null);
        $this->assertSame('step_0', $noticeNode->config['sent_from_node_key'] ?? null);

        $automation->update(['status' => AutomationStatus::Active]);
        $playbook->update(['is_active' => true, 'automation_id' => $automation->id]);
        AutomationWatchCache::flushAll();

        $case = DelinquencyLifecycle::openOrFail($contract);
        $this->matchCreated($case);
        $run = $automation->runs()->latest('id')->first();
        $this->assertNotNull($run);

        (new AutomationExecutor)->execute($run->fresh());
        $run->refresh();
        $this->assertSame(AutomationRunStatus::Succeeded, $run->status);

        $smsStep = $run->steps()->where('node_type', AutomationNodeType::SendSms->value)->first();
        $this->assertSame(AutomationRunStepStatus::Succeeded, $smsStep?->status);
        $this->assertSame('no_channel', $smsStep?->output['skipped_reason'] ?? null);

        $notice = ContractNotice::query()->where('contract_id', $contract->id)->first();
        $this->assertNotNull($notice);
        $this->assertSame('overdue', $notice->notice_type->value);
        $this->assertNull($notice->sent_at);

        $timeline = DelinquencyStep::query()
            ->where('delinquency_id', $case->id)
            ->where('trigger', DelinquencyStepTrigger::Playbook)
            ->first();
        $this->assertNotNull($timeline);
        $this->assertSame($notice->id, $timeline->contract_notice_id);
    }

    public function test_timeline_interleave(): void
    {
        [$contract] = $this->seedDelinquencyCatalogue();
        $case = DelinquencyLifecycle::openOrFail($contract);
        $employee = Employee::query()->firstOrFail();

        DelinquencyLifecycle::recordStep(
            delinquency: $case,
            action: DelinquencyStepAction::AssessLateFee,
            trigger: DelinquencyStepTrigger::Ladder,
            executedOn: '2026-07-28',
        );

        DelinquencyLifecycle::recordStep(
            delinquency: $case,
            action: DelinquencyStepAction::RecordNotice,
            trigger: DelinquencyStepTrigger::Playbook,
            executedOn: '2026-07-30',
            detail: ['source' => 'playbook'],
        );

        DelinquencyLifecycle::recordStep(
            delinquency: $case,
            action: DelinquencyStepAction::CreateTask,
            trigger: DelinquencyStepTrigger::Manual,
            executedOn: '2026-08-01',
            createdBy: $employee,
        );

        $timeline = $case->timeline();
        $triggers = $timeline->pluck('trigger')->map(
            fn ($t) => $t instanceof DelinquencyStepTrigger ? $t->value : (string) $t,
        )->all();

        $this->assertSame(
            [
                DelinquencyStepTrigger::Ladder->value,
                DelinquencyStepTrigger::Playbook->value,
                DelinquencyStepTrigger::Manual->value,
            ],
            $triggers,
        );
        $this->assertSame(
            ['2026-07-28', '2026-07-30', '2026-08-01'],
            $timeline->map(fn (DelinquencyStep $s) => $s->executed_on->toDateString())->all(),
        );
        $this->assertCount(3, array_unique($triggers));
    }

    public function test_activation_overlap_rejected(): void
    {
        Sanctum::actingAs(Employee::factory()->manager()->create());

        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();

        $first = $this->makePlaybook(PlaybookKind::DebtProcess, 'Debt A', [
            ['offset_days' => 0, 'action' => PlaybookStepAction::CreateTask, 'params' => ['title' => 'A']],
        ], ['site_ids' => [$siteA->id]]);

        $overlap = $this->makePlaybook(PlaybookKind::DebtProcess, 'Debt overlap', [
            ['offset_days' => 0, 'action' => PlaybookStepAction::CreateTask, 'params' => ['title' => 'O']],
        ], ['site_ids' => [$siteA->id, $siteB->id]]);

        $disjoint = $this->makePlaybook(PlaybookKind::DebtProcess, 'Debt B', [
            ['offset_days' => 0, 'action' => PlaybookStepAction::CreateTask, 'params' => ['title' => 'B']],
        ], ['site_ids' => [$siteB->id]]);

        $this->postJson("/api/playbooks/{$first->id}/activate")->assertOk();

        $this->postJson("/api/playbooks/{$overlap->id}/activate")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->postJson("/api/playbooks/{$disjoint->id}/activate")->assertOk();

        // Empty site_ids (= all sites) overlaps any active debt playbook.
        $global = $this->makePlaybook(PlaybookKind::DebtProcess, 'Debt all', [
            ['offset_days' => 0, 'action' => PlaybookStepAction::CreateTask, 'params' => ['title' => 'All']],
        ], []);
        $this->postJson("/api/playbooks/{$global->id}/activate")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_seeded_default_compiles_and_runs(): void
    {
        [$contract, $site] = $this->seedDelinquencyCatalogue();
        $this->seedEmailAccount($site);
        $this->seedSmsAccount($site);
        $contact = $contract->contact;
        $this->givePrimaryEmail($contact, 'tenant@example.com');
        $this->givePrimaryPhone($contact, '+15550009999');

        $this->seed(DebtPlaybookSeeder::class);

        $playbook = Playbook::query()
            ->where('kind', PlaybookKind::DebtProcess)
            ->where('name', 'Default debt process')
            ->with('steps')
            ->first();
        $this->assertNotNull($playbook);
        $this->assertFalse($playbook->is_active);
        $this->assertCount(4, $playbook->steps);

        $automation = PlaybookCompiler::compile($playbook->fresh(['steps']));
        $types = $this->nodeTypeSequence($automation);

        // Fixture 10 skeleton: delinquency object_created → actions/waits, with notice pairing on D4.
        $this->assertSame(AutomationNodeType::ObjectCreated->value, $types[0]);
        $trigger = $automation->nodes->first(fn (AutomationNode $n) => $n->kind->value === 'trigger');
        $this->assertSame('delinquency', $trigger?->config['objectType'] ?? null);
        $this->assertContains(AutomationNodeType::SendEmail->value, $types);
        $this->assertContains(AutomationNodeType::SendSms->value, $types);
        $this->assertContains(AutomationNodeType::RecordNotice->value, $types);
        $this->assertContains(AutomationNodeType::Wait->value, $types);
        $this->assertContains(AutomationNodeType::CreateObject->value, $types);

        $this->assertSame(
            [
                'logic' => 'and',
                'conditions' => [
                    ['field' => 'cured_on', 'operator' => 'is_empty'],
                ],
            ],
            $automation->default_guard,
        );

        $urgentTask = $automation->nodes->first(
            fn (AutomationNode $n) => $n->type === AutomationNodeType::CreateObject
                && (($n->config['fields'][0]['value']['value'] ?? null) === 'Call the tenant'),
        );
        $this->assertNotNull($urgentTask);
        $priority = collect($urgentTask->config['fields'] ?? [])
            ->firstWhere('property', 'priority');
        $this->assertSame('urgent', $priority['value']['value'] ?? null);

        $automation->update(['status' => AutomationStatus::Active]);
        $playbook->update(['is_active' => true, 'automation_id' => $automation->id]);
        AutomationWatchCache::flushAll();

        $case = DelinquencyLifecycle::openOrFail($contract);
        $this->matchCreated($case);
        $run = $automation->runs()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertNotNull($run->guard);

        (new AutomationExecutor)->execute($run->fresh());
        $run->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $run->status);
        $this->assertSame(
            AutomationRunStepStatus::Succeeded,
            $run->steps()->where('node_type', AutomationNodeType::SendEmail->value)->first()?->status,
        );
    }

    /**
     * @param  list<array{offset_days: int, action: PlaybookStepAction, params: array<string, mixed>}>  $steps
     * @param  array<string, mixed>  $filters
     */
    private function makePlaybook(PlaybookKind $kind, string $name, array $steps, array $filters = []): Playbook
    {
        $playbook = Playbook::query()->create([
            'kind' => $kind,
            'name' => $name,
            'is_active' => false,
            'enrolment_filters' => $filters,
        ]);

        foreach ($steps as $index => $step) {
            PlaybookStep::query()->create([
                'playbook_id' => $playbook->id,
                'offset_days' => $step['offset_days'],
                'action' => $step['action'],
                'params' => $step['params'],
                'sort' => $index,
            ]);
        }

        return $playbook;
    }

    private function compileAndActivate(Playbook $playbook): Automation
    {
        $automation = PlaybookCompiler::compile($playbook->fresh(['steps']));
        $automation->update(['status' => AutomationStatus::Active]);
        $playbook->update(['is_active' => true, 'automation_id' => $automation->id]);
        AutomationWatchCache::flushAll();

        return $automation->fresh(['nodes', 'edges']) ?? $automation;
    }

    private function matchCreated(\App\Models\Delinquency $case): void
    {
        (new MatchAutomationTriggers(
            'created',
            (string) $case->getMorphClass(),
            $case->getKey(),
            [],
            $case->automationTriggerAttributes(),
            null,
            null,
        ))->handle();
    }

    /** @return list<string> */
    private function nodeTypeSequence(Automation $automation): array
    {
        $automation->loadMissing(['nodes', 'edges.sourceNode', 'edges.targetNode']);
        $trigger = $automation->nodes->first(fn (AutomationNode $n) => $n->kind->value === 'trigger');
        $this->assertNotNull($trigger);

        $order = [];
        $current = $trigger;
        while ($current !== null) {
            $order[] = $current->type instanceof AutomationNodeType
                ? $current->type->value
                : (string) $current->type;
            $edge = $automation->edges->first(
                fn ($e) => (int) $e->source_node_id === (int) $current->id,
            );
            $current = $edge?->targetNode;
        }

        return $order;
    }

    /** @return array{0: Contract, 1: Site, 2: DelinquencyPolicy} */
    private function seedDelinquencyCatalogue(): array
    {
        $employee = Employee::factory()->manager()->create();
        $country = Country::query()->firstOrCreate(
            ['code' => 'ES'],
            ['name' => 'Spain'],
        );
        $policy = DelinquencyPolicy::factory()->create(['name' => 'DebtKind ES']);
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

        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
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

        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-18',
        ]);

        return [
            $contract->fresh(['unitItem.item.site', 'charges', 'contact']) ?? $contract,
            $site,
            $policy,
        ];
    }
}
