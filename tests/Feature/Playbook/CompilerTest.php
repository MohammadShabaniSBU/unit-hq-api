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
use App\Enums\DealStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Jobs\ExecuteAutomationRun;
use App\Jobs\MatchAutomationTriggers;
use App\Jobs\ResumeAutomationRun;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Deal;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\Employee;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Automation\AutomationExecutor;
use App\Support\Automation\AutomationWatchCache;
use App\Support\Delinquency\DelinquencyLifecycle;
use App\Support\Playbooks\PlaybookCompiler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class CompilerTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'UTC'));
        Mail::fake();
        Queue::fake([ExecuteAutomationRun::class, ResumeAutomationRun::class]);
        Event::fake();
        Employee::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reference_playbooks_match_fixture_semantics(): void
    {
        [$contract] = $this->seedDelinquencyCatalogue();

        $debt = $this->makePlaybook(PlaybookKind::DebtProcess, 'Debt reference', [
            ['offset_days' => 0, 'action' => PlaybookStepAction::SendEmail, 'params' => [
                'to' => ['kind' => 'static', 'value' => 'debt-notice@example.com'],
                'subject' => ['kind' => 'static', 'value' => 'Overdue balance'],
                'body' => ['kind' => 'static', 'value' => 'Please pay your balance.'],
            ]],
            ['offset_days' => 3, 'action' => PlaybookStepAction::CreateTask, 'params' => [
                'title' => 'Debt follow-up',
            ]],
        ]);

        $automation = PlaybookCompiler::compile($debt->fresh(['steps']));
        $automation->update(['status' => AutomationStatus::Active]);
        $debt->update(['is_active' => true, 'automation_id' => $automation->id]);
        AutomationWatchCache::flushAll();

        $this->assertSame(
            [
                AutomationNodeType::ObjectCreated->value,
                AutomationNodeType::SendEmail->value,
                AutomationNodeType::Wait->value,
                AutomationNodeType::CreateObject->value,
            ],
            $this->nodeTypeSequence($automation),
        );

        $case = DelinquencyLifecycle::open($contract);
        $this->assertNotNull($case);

        (new MatchAutomationTriggers(
            'created',
            (string) $case->getMorphClass(),
            $case->getKey(),
            [],
            $case->attributesToArray(),
            null,
            null,
        ))->handle();

        $run = $automation->runs()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertNotNull($run->guard);
        $this->assertTrue($automation->single_active_run_per_subject);

        (new AutomationExecutor)->execute($run->fresh());
        $run->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $run->status);

        DelinquencyLifecycle::cure($case->fresh(), DelinquencyCureTrigger::Payment);
        \App\Support\Automation\RunLifecycle::evaluateGuard($run->fresh());
        $run->refresh();
        $this->assertSame(AutomationRunStatus::Cancelled, $run->status);
        $this->assertSame(AutomationCancelCause::Guard, $run->cancel_cause);

        // Lead chase linear sequence
        $site = Site::factory()->create();
        $contact = Contact::factory()->create();
        $lead = $this->makePlaybook(PlaybookKind::LeadChase, 'Lead reference', [
            ['offset_days' => 0, 'action' => PlaybookStepAction::SendEmail, 'params' => [
                'to' => ['kind' => 'static', 'value' => 'lead@example.com'],
                'subject' => ['kind' => 'static', 'value' => 'Thanks'],
                'body' => ['kind' => 'static', 'value' => 'Hello'],
            ]],
            ['offset_days' => 1, 'action' => PlaybookStepAction::CreateTask, 'params' => [
                'title' => 'Call the lead',
            ]],
        ], ['stages' => [DealStatus::New->value]]);

        $leadAutomation = PlaybookCompiler::compile($lead->fresh(['steps']));
        $leadAutomation->update(['status' => AutomationStatus::Active]);
        AutomationWatchCache::flushAll();

        $this->assertSame(
            [
                AutomationNodeType::ObjectCreated->value,
                AutomationNodeType::SendEmail->value,
                AutomationNodeType::Wait->value,
                AutomationNodeType::CreateObject->value,
            ],
            $this->nodeTypeSequence($leadAutomation),
        );

        $deal = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $site->id,
            'status' => DealStatus::New,
        ]);

        (new MatchAutomationTriggers(
            'created',
            (string) $deal->getMorphClass(),
            $deal->getKey(),
            [],
            $deal->attributesToArray(),
            null,
            null,
        ))->handle();

        $leadRun = $leadAutomation->runs()->latest('id')->first();
        $this->assertNotNull($leadRun);
        (new AutomationExecutor)->execute($leadRun->fresh());
        $leadRun->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $leadRun->status);
        $this->assertSame(AutomationRunStepStatus::Succeeded, $leadRun->steps()->where('node_type', 'action.send_email')->first()?->status);
    }

    public function test_offset_edge_cases(): void
    {
        // Zero-offset first step: no leading wait; equal offsets: back-to-back.
        $playbook = $this->makePlaybook(PlaybookKind::DebtProcess, 'Offsets', [
            ['offset_days' => 0, 'action' => PlaybookStepAction::SendEmail, 'params' => [
                'to' => ['kind' => 'static', 'value' => 'a@example.com'],
                'subject' => ['kind' => 'static', 'value' => 'A'],
                'body' => ['kind' => 'static', 'value' => 'A'],
            ]],
            ['offset_days' => 0, 'action' => PlaybookStepAction::CreateTask, 'params' => [
                'title' => 'Same day',
            ]],
            ['offset_days' => 2, 'action' => PlaybookStepAction::CreateTask, 'params' => [
                'title' => 'Day two',
            ]],
        ]);

        $automation = PlaybookCompiler::compile($playbook->fresh(['steps']));
        $types = $this->nodeTypeSequence($automation);

        $this->assertSame(
            [
                AutomationNodeType::ObjectCreated->value,
                AutomationNodeType::SendEmail->value,
                AutomationNodeType::CreateObject->value,
                AutomationNodeType::Wait->value,
                AutomationNodeType::CreateObject->value,
            ],
            $types,
        );

        $wait = $automation->nodes->first(fn (AutomationNode $n) => $n->type === AutomationNodeType::Wait);
        $this->assertNotNull($wait);
        $this->assertSame(2, $wait->config['amount'] ?? null);
        $this->assertSame('send_window', $wait->config['align'] ?? null);

        // Window resolution: wait parks at send-window start in site TZ.
        Setting::setGeneral(Setting::general()->with(sendWindowStart: '09:00'));
        [$contract, $site] = $this->seedDelinquencyCatalogue();
        $this->assertSame('Europe/Madrid', $site->timezone);

        $windowBook = $this->makePlaybook(PlaybookKind::DebtProcess, 'Window', [
            ['offset_days' => 1, 'action' => PlaybookStepAction::CreateTask, 'params' => [
                'title' => 'After window',
            ]],
        ]);
        $windowAuto = PlaybookCompiler::compile($windowBook->fresh(['steps']));
        $windowAuto->update(['status' => AutomationStatus::Active]);
        AutomationWatchCache::flushAll();

        $case = DelinquencyLifecycle::open($contract);
        (new MatchAutomationTriggers(
            'created',
            (string) $case->getMorphClass(),
            $case->getKey(),
            [],
            $case->attributesToArray(),
            null,
            null,
        ))->handle();

        $run = $windowAuto->runs()->latest('id')->first();
        $this->assertNotNull($run);
        (new AutomationExecutor)->execute($run->fresh());
        $run->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $run->status);

        // now (Aug 1 10:00 UTC / 12:00 Madrid) + 1 day → Aug 2 12:00 Madrid → past 09:00 → next day 09:00
        $expectedLocal = Carbon::parse('2026-08-03 09:00:00', 'Europe/Madrid');
        $this->assertTrue(
            $run->waiting_until->equalTo($expectedLocal->utc()),
            'Expected '.$expectedLocal->utc()->toIso8601String().' got '.$run->waiting_until?->toIso8601String(),
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

    /** @return array{0: Contract, 1: Site} */
    private function seedDelinquencyCatalogue(): array
    {
        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $policy = DelinquencyPolicy::factory()->create(['name' => 'Compiler ES']);
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

        Charge::factory()->create([
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
