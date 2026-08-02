<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\AutomationNodeKind;
use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Enums\AutomationStatus;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Enums\HoldType;
use App\Enums\PlaybookKind;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\Automation;
use App\Models\AutopayAttempt;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\Employee;
use App\Models\Interaction;
use App\Models\Playbook;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Support\Delinquency\DelinquencyState;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class ContextTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;
    use SeedsInboxThreads;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_delinquent_tenant_shows_all_chips(): void
    {
        $contact = Contact::factory()->create(['first_name' => 'Dana', 'last_name' => 'Delinquent']);
        [$contract, $unit, $site] = $this->makeActiveContract($contact);

        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '120.00',
            'net_amount' => '120.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-01',
        ]);

        $contract->update(['autopay_enabled' => true]);
        $attempt = AutopayAttempt::factory()->failed()->create(['contract_id' => $contract->id]);

        $policy = DelinquencyPolicy::factory()->create();
        $case = Delinquency::query()->create([
            'contract_id' => $contract->id,
            'delinquency_policy_id' => $policy->id,
            'anchor_due_date' => '2026-07-01',
            'opened_on' => '2026-07-15',
            'cured_on' => null,
        ]);

        UnitHold::query()->create([
            'unit_id' => $unit->id,
            'hold_type' => HoldType::Overlock,
            'reservation_id' => null,
            'starts_on' => '2026-07-20',
            'ends_on' => null,
            'released_at' => null,
            'reason' => "delinquency:{$case->id}",
            'created_by' => $this->employee->id,
        ]);

        $thread = $this->makeInboxThread($contact);

        $response = $this->getJson("/api/inbox/threads/{$thread->id}/context")
            ->assertOk();

        $response->assertJsonPath('data.contact.id', $contact->id)
            ->assertJsonPath('data.contact.name', 'Dana Delinquent')
            ->assertJsonCount(1, 'data.tenancy.active_contracts');

        $block = $response->json('data.tenancy.active_contracts.0');
        $this->assertSame($contract->id, $block['id']);
        $this->assertSame($unit->unit_number, $block['unit_number']);
        $this->assertSame($site->name, $block['site_name']);
        $this->assertSame('failing', $block['autopay']);
        $this->assertSame($attempt->id, $block['autopay_attempt_id']);
        $this->assertNotNull($block['delinquency']);
        $this->assertSame($case->id, $block['delinquency']['id']);
        $this->assertSame('Overlocked', $block['delinquency']['stage_label']);
        $this->assertIsInt($block['delinquency']['days']);
    }

    public function test_multi_contract_tenant_lists_each_contract(): void
    {
        $contact = Contact::factory()->create();
        [$first] = $this->makeActiveContract($contact, ['unit_number' => 'A-101', 'site_name' => 'North Site']);
        [$second] = $this->makeActiveContract($contact, ['unit_number' => 'B-202', 'site_name' => 'South Site']);

        $thread = $this->makeInboxThread($contact);

        $response = $this->getJson("/api/inbox/threads/{$thread->id}/context")
            ->assertOk()
            ->assertJsonCount(2, 'data.tenancy.active_contracts');

        $ids = collect($response->json('data.tenancy.active_contracts'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids);
    }

    public function test_prospect_with_open_deal_and_enrolment(): void
    {
        $contact = Contact::factory()->create();
        $site = Site::factory()->create(['name' => 'Prospect Site']);
        $deal = Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => $site->id,
            'status' => DealStatus::New,
            'expected_move_in' => '2026-09-01',
        ]);

        [$playbookId] = $this->makeActiveEnrolment($deal->id);

        $thread = $this->makeInboxThread($contact);

        $response = $this->getJson("/api/inbox/threads/{$thread->id}/context")
            ->assertOk()
            ->assertJsonCount(0, 'data.tenancy.active_contracts');

        $response->assertJsonPath('data.pipeline.open_deal.id', $deal->id)
            ->assertJsonPath('data.pipeline.open_deal.stage', 'new')
            ->assertJsonPath('data.pipeline.open_deal.move_in', '2026-09-01')
            ->assertJsonPath('data.pipeline.lead_enrolment.playbook_id', $playbookId)
            ->assertJsonPath('data.pipeline.lead_enrolment.step_x_of_y', '1 of 2');

        $this->assertStringContainsString('Prospect Site', (string) $response->json('data.pipeline.open_deal.title'));
    }

    public function test_clean_tenant_has_no_chips(): void
    {
        $contact = Contact::factory()->create();
        [$contract] = $this->makeActiveContract($contact);
        $contract->update(['autopay_enabled' => false]);

        // Only a future charge — never overdue, never delinquent.
        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '90.00',
            'net_amount' => '90.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-09-01',
        ]);

        $thread = $this->makeInboxThread($contact);

        $response = $this->getJson("/api/inbox/threads/{$thread->id}/context")->assertOk();

        $block = $response->json('data.tenancy.active_contracts.0');
        $this->assertSame('off', $block['autopay']);
        $this->assertNull($block['autopay_attempt_id']);
        $this->assertNull($block['delinquency']);
        $response->assertJsonPath('data.pipeline.open_deal', null)
            ->assertJsonPath('data.pipeline.lead_enrolment', null);
    }

    public function test_figures_equal_source_pages(): void
    {
        $contact = Contact::factory()->create();
        [$contract] = $this->makeActiveContract($contact);

        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '150.00',
            'net_amount' => '150.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-07-01',
        ]);

        $policy = DelinquencyPolicy::factory()->create();
        Delinquency::query()->create([
            'contract_id' => $contract->id,
            'delinquency_policy_id' => $policy->id,
            'anchor_due_date' => '2026-07-01',
            'opened_on' => '2026-07-15',
            'cured_on' => null,
        ]);

        $thread = $this->makeInboxThread($contact);

        $response = $this->getJson("/api/inbox/threads/{$thread->id}/context")->assertOk();
        $block = $response->json('data.tenancy.active_contracts.0');

        $fresh = $contract->fresh();
        $this->assertSame($fresh->balanceOwed(), $block['balance']['owed']);
        $this->assertSame($fresh->overdueAmount(), $block['balance']['overdue']);
        $this->assertSame(DelinquencyState::daysOverdue($fresh), $block['delinquency']['days']);
    }

    public function test_aggregate_is_bounded(): void
    {
        $contact = Contact::factory()->create();
        [$first] = $this->makeActiveContract($contact);
        [$second] = $this->makeActiveContract($contact);
        [$third] = $this->makeActiveContract($contact);

        foreach ([$first, $second, $third] as $contract) {
            Charge::factory()->create([
                'contract_id' => $contract->id,
                'charge_type' => ChargeType::Rent,
                'amount' => '75.00',
                'net_amount' => '75.00',
                'tax_amount' => '0.00',
                'currency' => 'EUR',
                'due_date' => '2026-07-01',
            ]);
        }

        for ($i = 0; $i < 10; $i++) {
            Interaction::query()->create([
                'contact_id' => $contact->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'occurred_at' => now()->subDays($i),
                'content' => "Note {$i}",
            ]);
        }

        $thread = $this->makeInboxThread($contact);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson("/api/inbox/threads/{$thread->id}/context")
            ->assertOk()
            ->assertJsonCount(3, 'data.tenancy.active_contracts')
            ->assertJsonCount(3, 'data.recent');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(40, $queryCount, "Expected bounded queries, got {$queryCount}");
    }

    /**
     * @param  array{unit_number?: string, site_name?: string}  $overrides
     * @return array{0: Contract, 1: Unit, 2: Site}
     */
    private function makeActiveContract(Contact $contact, array $overrides = []): array
    {
        $site = Site::factory()->create(['name' => $overrides['site_name'] ?? 'Test Storage']);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $this->employee->id,
            ['amount' => '150.00', 'currency' => 'EUR'],
        );
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
            'unit_number' => $overrides['unit_number'] ?? ('U-'.random_int(100, 999)),
        ]);

        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
        ]);
        $contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => $contract->start_date,
            'effective_to' => null,
        ]);

        return [$contract, $unit, $site];
    }

    /**
     * Builds a minimal two-action-step playbook enrolment (one step completed)
     * without running the full compiler/executor pipeline.
     *
     * @return array{0: int} playbook id
     */
    private function makeActiveEnrolment(int $dealId): array
    {
        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::LeadChase,
            'name' => 'Lead nurture',
            'is_active' => true,
            'enrolment_filters' => [],
        ]);

        $automation = Automation::query()->create([
            'name' => 'Lead nurture v1',
            'status' => AutomationStatus::Active,
            'version' => 1,
            'single_active_run_per_subject' => true,
            'playbook_id' => $playbook->id,
        ]);

        $playbook->update(['automation_id' => $automation->id]);

        $stepOne = AutomationNode::query()->create([
            'automation_id' => $automation->id,
            'node_key' => 'step_1',
            'kind' => AutomationNodeKind::Action,
            'type' => AutomationNodeType::SendSms,
            'label' => 'Step 1',
            'position_x' => 0,
            'position_y' => 0,
            'config' => [],
        ]);
        $stepTwo = AutomationNode::query()->create([
            'automation_id' => $automation->id,
            'node_key' => 'step_2',
            'kind' => AutomationNodeKind::Action,
            'type' => AutomationNodeType::SendEmail,
            'label' => 'Step 2',
            'position_x' => 0,
            'position_y' => 1,
            'config' => [],
        ]);

        $run = AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'subject_type' => 'deal',
            'subject_id' => $dealId,
            'status' => AutomationRunStatus::Waiting,
            'waiting_until' => now()->addDay(),
            'depth' => 0,
        ]);

        AutomationRunStep::query()->create([
            'run_id' => $run->id,
            'node_id' => $stepOne->id,
            'node_type' => $stepOne->type->value,
            'status' => AutomationRunStepStatus::Succeeded,
        ]);

        return [$playbook->id];
    }
}
