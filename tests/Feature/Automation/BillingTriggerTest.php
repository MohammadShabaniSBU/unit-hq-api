<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Enums\AutomationNodeType;
use App\Enums\AutopayAttemptStatus;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Events\ModelCreated;
use App\Events\ModelUpdated;
use App\Http\Resources\AutomationRunResource;
use App\Models\AutopayAttempt;
use App\Models\AutomationRun;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\TriggerConfigValidator;
use App\Support\Delinquency\DelinquencyLifecycle;
use App\Support\Delinquency\DelinquencyState;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class BillingTriggerTest extends TestCase
{
    use AutomationGraph;
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

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->policy = DelinquencyPolicy::factory()->create(['name' => 'ES test']);
        DelinquencyPolicyStep::query()->create([
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
        AutomationContext::clear();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_case_open_fires_from_queue_null_causer(): void
    {
        $this->buildGraph(
            [
                [
                    'key' => 't',
                    'type' => AutomationNodeType::ObjectCreated,
                    'config' => ['objectType' => 'delinquency', 'filters' => ['logic' => 'and', 'conditions' => []]],
                ],
            ],
            [],
        );

        $contract = $this->makeDelinquentContract();

        // No actingAs — engine / queue context.
        $case = DelinquencyLifecycle::open($contract);
        $this->assertNotNull($case);

        $run = AutomationRun::query()->where('subject_type', 'delinquency')->where('subject_id', $case->id)->first();
        $this->assertNotNull($run);
        $this->assertNull($run->causer_type);
        $this->assertNull($run->causer_id);

        // Null causer must not blow up resource rendering.
        $payload = AutomationRunResource::make($run->load(['subject', 'causer', 'triggerNode', 'steps']))->resolve();
        $this->assertNull($payload['causer']);
        $this->assertSame('delinquency', $payload['subject_type']);

        $runsBefore = AutomationRun::query()->count();

        // Suppression: automation-originated write must not re-trigger.
        $contract2 = $this->makeDelinquentContract(dueDate: '2026-07-20');
        AutomationContext::run(1, function () use ($contract2): void {
            $opened = DelinquencyLifecycle::open($contract2);
            $this->assertNotNull($opened);
        });

        $this->assertSame($runsBefore, AutomationRun::query()->count());
    }

    public function test_cure_diff_enables_exit_recipe(): void
    {
        $this->buildGraph(
            [
                [
                    'key' => 't',
                    'type' => AutomationNodeType::ObjectUpdated,
                    'config' => [
                        'objectType' => 'delinquency',
                        'property' => 'cured_on',
                        'conditions' => [
                            ['operator' => 'is_not_empty'],
                        ],
                    ],
                ],
            ],
            [],
        );

        $contract = $this->makeDelinquentContract();
        $case = DelinquencyLifecycle::open($contract);
        $this->assertNotNull($case);
        $this->assertSame(0, AutomationRun::query()->count());

        DelinquencyLifecycle::cure($case, DelinquencyCureTrigger::Payment);

        $run = AutomationRun::query()->where('subject_type', 'delinquency')->where('subject_id', $case->id)->first();
        $this->assertNotNull($run);
        $this->assertSame('updated', $run->trigger_payload['lifecycle'] ?? null);
        $this->assertArrayHasKey('cured_on', $run->trigger_payload['dirty'] ?? []);
        $this->assertNotNull($run->trigger_payload['dirty']['cured_on']['new'] ?? null);
        $this->assertNull($run->trigger_payload['dirty']['cured_on']['old']);
    }

    public function test_snapshot_payload_fields(): void
    {
        $this->buildGraph(
            [
                [
                    'key' => 't',
                    'type' => AutomationNodeType::ObjectCreated,
                    'config' => ['objectType' => 'delinquency', 'filters' => ['logic' => 'and', 'conditions' => []]],
                ],
            ],
            [],
        );

        $contract = $this->makeDelinquentContract();
        $case = DelinquencyLifecycle::open($contract);
        $this->assertNotNull($case);

        $run = AutomationRun::query()->where('subject_id', $case->id)->first();
        $this->assertNotNull($run);

        $snapDays = $run->trigger_payload['attributes']['days_overdue'] ?? null;
        $snapBase = $run->trigger_payload['attributes']['overdue_base'] ?? null;
        $this->assertSame(14, $snapDays);
        $this->assertSame('100.00', $snapBase);

        // Live severity drifts; frozen payload must not.
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00', 'Europe/Madrid'));
        $liveDays = DelinquencyState::daysOverdue($contract->fresh());
        $this->assertSame(24, $liveDays);
        $this->assertNotSame($snapDays, $liveDays);

        $run->refresh();
        $this->assertSame($snapDays, $run->trigger_payload['attributes']['days_overdue']);
        $this->assertSame($snapBase, $run->trigger_payload['attributes']['overdue_base']);
    }

    public function test_payment_created_only(): void
    {
        $this->buildGraph(
            [
                [
                    'key' => 't',
                    'type' => AutomationNodeType::ObjectCreated,
                    'config' => ['objectType' => 'payment', 'filters' => ['logic' => 'and', 'conditions' => []]],
                ],
            ],
            [],
        );

        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
        ]);

        $payment = Payment::factory()->cash()->create([
            'contract_id' => $contract->id,
            'amount' => '50.00',
            'currency' => 'EUR',
            'method' => PaymentMethodEnum::Cash,
        ]);

        $run = AutomationRun::query()->where('subject_type', 'payment')->where('subject_id', $payment->id)->first();
        $this->assertNotNull($run);

        Event::fake([ModelUpdated::class, ModelCreated::class]);

        $payment->forceFill(['reference' => 'should-not-trigger'])->save();

        Event::assertNotDispatched(ModelUpdated::class);
        Event::assertNotDispatched(ModelCreated::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('payment supports object_created only');

        TriggerConfigValidator::assertValid([
            [
                'node_key' => 'bad',
                'type' => AutomationNodeType::ObjectUpdated->value,
                'config' => [
                    'objectType' => 'payment',
                    'property' => 'amount',
                    'conditions' => [],
                ],
            ],
        ]);
    }

    public function test_failed_autopay_triggers(): void
    {
        $this->buildGraph(
            [
                [
                    'key' => 't',
                    'type' => AutomationNodeType::ObjectUpdated,
                    'config' => [
                        'objectType' => 'autopay_attempt',
                        'property' => 'status',
                        'conditions' => [
                            ['operator' => 'equals', 'value' => AutopayAttemptStatus::Failed->value],
                        ],
                    ],
                ],
            ],
            [],
        );

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

        $this->assertSame(0, AutomationRun::query()->count());

        $attempt->forceFill([
            'status' => AutopayAttemptStatus::Failed,
            'failure_code' => 'card_declined',
            'resolved_at' => now(),
        ])->save();

        $run = AutomationRun::query()->where('subject_type', 'autopay_attempt')->where('subject_id', $attempt->id)->first();
        $this->assertNotNull($run);
        $this->assertArrayHasKey('status', $run->trigger_payload['dirty'] ?? []);
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

        return $contract->fresh(['unitItem.item.site', 'charges']) ?? $contract;
    }
}
