<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ChargeType;
use App\Enums\ContactChannelType;
use App\Enums\ContractStatus;
use App\Enums\CredentialStatus;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Models\AircallUserLink;
use App\Models\Charge;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
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
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class CallContextTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Contact $contact;

    private Site $site;

    private Unit $unit;

    private int $priceId;

    private DelinquencyPolicy $policy;

    private string $webhookToken = 'tok-aircall-context';

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $this->policy = DelinquencyPolicy::factory()->create(['name' => 'call-context']);
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

        CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Call,
            'provider' => Provider::Aircall,
            'is_active' => true,
            'credentials' => [
                'api_id' => 'aircall-id',
                'api_token' => 'aircall-token',
            ],
            'webhook_url_token' => $this->webhookToken,
            'status' => CredentialStatus::Connected,
        ]);

        $this->contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $this->contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15559876543',
            'is_primary' => true,
            'opted_in' => true,
        ]);
    }

    public function test_delinquency_timeline_interleave(): void
    {
        AircallUserLink::query()->create([
            'employee_id' => $this->employee->id,
            'aircall_user_id' => '456',
            'aircall_user_label' => 'Jane Agent',
        ]);

        $contract = Contract::factory()->create([
            'contact_id' => $this->contact->id,
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
            'due_date' => now()->subDays(10)->toDateString(),
        ]);

        $case = Delinquency::factory()->create([
            'contract_id' => $contract->id,
            'delinquency_policy_id' => $this->policy->id,
            'opened_on' => now()->subDays(5)->toDateString(),
            'anchor_due_date' => now()->subDays(10)->toDateString(),
        ]);

        DelinquencyStep::query()->create([
            'delinquency_id' => $case->id,
            'policy_step_id' => null,
            'action' => DelinquencyStepAction::AssessLateFee,
            'executed_on' => now()->subDays(2)->toDateString(),
            'trigger' => DelinquencyStepTrigger::Ladder,
            'detail' => null,
            'charge_id' => null,
            'unit_hold_id' => null,
            'contract_notice_id' => null,
            'task_id' => null,
            'created_by' => null,
        ]);

        DelinquencyStep::query()->create([
            'delinquency_id' => $case->id,
            'policy_step_id' => null,
            'action' => DelinquencyStepAction::PlaceOverlock,
            'executed_on' => now()->addDay()->toDateString(),
            'trigger' => DelinquencyStepTrigger::Ladder,
            'detail' => null,
            'charge_id' => null,
            'unit_hold_id' => null,
            'contract_notice_id' => null,
            'task_id' => null,
            'created_by' => null,
        ]);

        Http::fake([
            'api.aircall.io/v1/users/456/dial' => Http::response(['call' => ['id' => 812100]], 200),
        ]);

        $this->postJson('/api/calls/dial', [
            'contact_id' => $this->contact->id,
            'context' => ['type' => 'delinquency', 'id' => $case->id],
        ])->assertOk();

        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $this->inboundFixture('aircall_call_created_outbound.json'),
        )->assertOk();

        $response = $this->getJson("/api/delinquencies/{$case->id}")->assertOk();
        $timeline = $response->json('data.timeline');
        $this->assertIsArray($timeline);

        $types = collect($timeline)->pluck('entry_type')->all();
        $this->assertContains('step', $types);
        $this->assertContains('call', $types);

        // Chronological: late fee (2 days ago) → call (now) → overlock (tomorrow).
        $callIndex = collect($timeline)->search(fn (array $e): bool => ($e['entry_type'] ?? null) === 'call');
        $this->assertNotFalse($callIndex);

        $feeIndex = collect($timeline)->search(
            fn (array $e): bool => ($e['entry_type'] ?? null) === 'step'
                && ($e['action'] ?? null) === DelinquencyStepAction::AssessLateFee->value,
        );
        $overlockIndex = collect($timeline)->search(
            fn (array $e): bool => ($e['entry_type'] ?? null) === 'step'
                && ($e['action'] ?? null) === DelinquencyStepAction::PlaceOverlock->value,
        );

        $this->assertNotFalse($feeIndex);
        $this->assertNotFalse($overlockIndex);
        $this->assertTrue($feeIndex < $callIndex);
        $this->assertTrue($callIndex < $overlockIndex);

        $call = $timeline[$callIndex];
        $this->assertSame('delinquency', $call['call_intent']['context_type'] ?? null);
        $this->assertSame($case->id, $call['call_intent']['context_id'] ?? null);
        $this->assertSame('outbound', $call['direction'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundFixture(string $name): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/communications/inbound/'.$name)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $data;
    }
}
