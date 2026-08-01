<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Enums\AutomationStatus;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepTrigger;
use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Models\Automation;
use App\Models\AutomationEdge;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
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
use App\Support\Delinquency\DelinquencyLifecycle;
use App\Support\Playbooks\PlaybookCompiler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\AutomationHarness;
use Tests\Support\CreatesCataloguePrices;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class RecordNoticeTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'UTC'));
        Employee::factory()->create();
        $this->fakeCommunicationProviders();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_kind_restriction_and_sent_pairing(): void
    {
        // Lead chase rejects record_notice at compile.
        $lead = Playbook::query()->create([
            'kind' => PlaybookKind::LeadChase,
            'name' => 'Lead no notice',
            'is_active' => false,
            'enrolment_filters' => [],
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $lead->id,
            'offset_days' => 0,
            'action' => PlaybookStepAction::RecordNotice,
            'params' => ['notice_type' => 'payment_reminder'],
            'sort' => 0,
        ]);

        try {
            PlaybookCompiler::compile($lead->fresh(['steps']));
            $this->fail('Expected ValidationException for record_notice on lead chase');
        } catch (ValidationException $e) {
            $messages = data_get($e->errors(), 'steps', []);
            $this->assertNotEmpty($messages);
            $this->assertStringContainsString('not allowed', implode(' ', $messages));
        }

        // Debt chain: notice + playbook timeline row.
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
        $this->assertNull($notice->sent_at);

        $timeline = DelinquencyStep::query()
            ->where('delinquency_id', $case->id)
            ->where('trigger', DelinquencyStepTrigger::Playbook)
            ->first();
        $this->assertNotNull($timeline);
        $this->assertSame($notice->id, $timeline->contract_notice_id);

        // Sent pairing from prior send step output.
        $site = Site::factory()->create();
        $this->seedEmailAccount($site);
        $contact = Contact::factory()->create([
            'first_name' => 'Pair',
            'email' => 'pair-'.uniqid().'@example.com',
        ]);
        $this->givePrimaryEmail($contact, 'pair-primary@example.com');

        $pairContract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-06-01',
        ]);

        $automation = Automation::query()->create([
            'name' => 'pair notice',
            'status' => AutomationStatus::Active,
            'version' => 1,
        ]);

        $trigger = AutomationNode::query()->create([
            'automation_id' => $automation->id,
            'node_key' => 'trigger',
            'kind' => 'trigger',
            'type' => 'trigger.object_created',
            'label' => 'Contract',
            'position_x' => 0,
            'position_y' => 0,
            'config' => ['objectType' => 'contract'],
        ]);
        $email = AutomationNode::query()->create([
            'automation_id' => $automation->id,
            'node_key' => 'email',
            'kind' => 'action',
            'type' => 'action.send_email',
            'label' => 'Email',
            'position_x' => 200,
            'position_y' => 0,
            'config' => [
                'bodyType' => 'custom',
                'subject' => ['kind' => 'static', 'value' => 'Notice'],
                'body' => ['kind' => 'static', 'value' => 'Body'],
            ],
        ]);
        $noticeNode = AutomationNode::query()->create([
            'automation_id' => $automation->id,
            'node_key' => 'notice',
            'kind' => 'action',
            'type' => 'action.record_notice',
            'label' => 'Notice',
            'position_x' => 400,
            'position_y' => 0,
            'config' => [
                'notice_type' => 'overdue',
                'sent_from_node_key' => 'email',
            ],
        ]);
        AutomationEdge::query()->create([
            'automation_id' => $automation->id,
            'source_node_id' => $trigger->id,
            'target_node_id' => $email->id,
            'source_handle' => 'default',
            'condition' => ['type' => 'always'],
        ]);
        AutomationEdge::query()->create([
            'automation_id' => $automation->id,
            'source_node_id' => $email->id,
            'target_node_id' => $noticeNode->id,
            'source_handle' => 'default',
            'condition' => ['type' => 'always'],
        ]);

        $run = AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'trigger_node_id' => $trigger->id,
            'status' => AutomationRunStatus::Pending,
            'subject_type' => 'contract',
            'subject_id' => $pairContract->id,
            'trigger_payload' => [
                'lifecycle' => 'created',
                'attributes' => $pairContract->attributesToArray(),
            ],
            'depth' => 0,
        ]);

        (new AutomationExecutor)->execute($run->fresh());
        $run->refresh();
        $this->assertSame(AutomationRunStatus::Succeeded, $run->status);

        $paired = ContractNotice::query()
            ->where('contract_id', $pairContract->id)
            ->where('notice_type', 'overdue')
            ->first();
        $this->assertNotNull($paired);
        $this->assertNotNull($paired->sent_at);
        $this->assertSame('email', $paired->sent_channel);
        $this->assertSame('pair-primary@example.com', $paired->sent_to);
    }

    /** @return array{0: Contract, 1: Site} */
    private function seedDelinquencyCatalogue(): array
    {
        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $policy = DelinquencyPolicy::factory()->create(['name' => 'Notice ES']);
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
