<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ChargeType;
use App\Enums\ContactChannelType;
use App\Enums\ContractStatus;
use App\Enums\CredentialStatus;
use App\Enums\DelinquencyPolicyAction;
use App\Models\AircallUserLink;
use App\Models\CallWrapup;
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
use App\Models\Employee;
use App\Models\Message;
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

class WrapupTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Contact $contact;

    private string $webhookToken = 'tok-aircall-wrapup';

    protected function setUp(): void
    {
        parent::setUp();

        Site::factory()->create();
        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

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

        AircallUserLink::query()->create([
            'employee_id' => $this->employee->id,
            'aircall_user_id' => '456',
            'aircall_user_label' => 'Jane Agent',
        ]);

        $this->contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);
        ContactChannel::query()->create([
            'contact_id' => $this->contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15559876543',
            'is_primary' => true,
            'opted_in' => true,
        ]);
    }

    public function test_own_call_prompt_and_edit(): void
    {
        $message = $this->dialCorrelateAndEnd();

        $badge = $this->getJson('/api/inbox/badge')->assertOk();
        $badge->assertJsonCount(1, 'data.pending_wrapups');
        $badge->assertJsonPath('data.pending_wrapups.0.message_id', $message->id);
        $badge->assertJsonPath('data.pending_wrapups.0.contact.id', $this->contact->id);

        $other = Employee::factory()->staff()->create();
        Sanctum::actingAs($other);
        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.pending_wrapups', []);

        Sanctum::actingAs($this->employee);

        $this->putJson("/api/messages/{$message->id}/wrapup", [
            'disposition' => 'reached',
            'note' => 'Talked about unit size',
        ])->assertOk()
            ->assertJsonPath('data.disposition', 'reached')
            ->assertJsonPath('data.note', 'Talked about unit size');

        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.pending_wrapups', []);

        $this->putJson("/api/messages/{$message->id}/wrapup", [
            'disposition' => 'payment_promised',
            'note' => 'Will pay Friday',
        ])->assertOk()
            ->assertJsonPath('data.disposition', 'payment_promised')
            ->assertJsonPath('data.note', 'Will pay Friday');

        $this->assertSame(1, CallWrapup::query()->where('message_id', $message->id)->count());

        // Dismiss path on a fresh call: null disposition, strip leaves.
        $message2 = $this->dialCorrelateAndEnd(callId: '812101');
        $this->putJson("/api/messages/{$message2->id}/wrapup", [
            'disposition' => null,
            'note' => null,
        ])->assertOk()
            ->assertJsonPath('data.disposition', null);

        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.pending_wrapups', []);

        $threadShow = $this->getJson("/api/inbox/threads/{$message2->message_thread_id}")->assertOk();
        $card = collect($threadShow->json('data.messages'))
            ->firstWhere('id', $message2->id);
        $this->assertNotNull($card);
        $this->assertIsArray($card['wrapup'] ?? null);
        $this->assertArrayHasKey('disposition', $card['wrapup']);
        $this->assertNull($card['wrapup']['disposition']);
        $this->assertArrayNotHasKey('recording_url', $card['source_ref'] ?? []);
    }

    public function test_disposition_surfaces_thrice(): void
    {
        $country = Country::factory()->create(['code' => 'ES']);
        $policy = DelinquencyPolicy::factory()->create(['name' => 'wrapup-surfaces']);
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
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
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
            'due_date' => now()->subDays(10)->toDateString(),
        ]);
        $case = Delinquency::factory()->create([
            'contract_id' => $contract->id,
            'delinquency_policy_id' => $policy->id,
            'opened_on' => now()->subDays(5)->toDateString(),
            'anchor_due_date' => now()->subDays(10)->toDateString(),
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
        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $this->inboundFixture('aircall_call_ended_outbound.json'),
        )->assertOk();

        $message = Message::query()->where('provider_message_id', '812100')->firstOrFail();

        $this->putJson("/api/messages/{$message->id}/wrapup", [
            'disposition' => 'payment_promised',
            'note' => 'Pays Monday',
        ])->assertOk();

        // 1) Call card / inbox message
        $thread = $this->getJson("/api/inbox/threads/{$message->message_thread_id}")->assertOk();
        $card = collect($thread->json('data.messages'))->firstWhere('id', $message->id);
        $this->assertSame('payment_promised', $card['wrapup']['disposition'] ?? null);

        // 2) Delinquency case timeline
        $timeline = $this->getJson("/api/delinquencies/{$case->id}")->assertOk()->json('data.timeline');
        $callEntry = collect($timeline)->firstWhere('entry_type', 'call');
        $this->assertNotNull($callEntry);
        $this->assertSame('payment_promised', $callEntry['disposition'] ?? null);

        // 3) Context panel recent
        $context = $this->getJson("/api/inbox/threads/{$message->message_thread_id}/context")->assertOk();
        $recent = $context->json('data.recent');
        $callRecent = collect($recent)->firstWhere('type', 'call');
        $this->assertNotNull($callRecent);
        $this->assertSame('payment_promised', $callRecent['disposition'] ?? null);
        $this->assertStringContainsString('Payment Promised', (string) ($callRecent['summary'] ?? ''));
    }

    private function dialCorrelateAndEnd(string $callId = '812100'): Message
    {
        Http::fake([
            'api.aircall.io/v1/users/456/dial' => Http::response(['call' => ['id' => (int) $callId]], 200),
        ]);

        $this->postJson('/api/calls/dial', [
            'contact_id' => $this->contact->id,
            'context' => ['type' => 'contact', 'id' => $this->contact->id],
        ])->assertOk();

        $created = $this->inboundFixture('aircall_call_created_outbound.json');
        $created['data']['id'] = (int) $callId;
        $this->postJson("/api/webhooks/aircall/{$this->webhookToken}", $created)->assertOk();

        $ended = $this->inboundFixture('aircall_call_ended_outbound.json');
        $ended['data']['id'] = (int) $callId;
        $ended['data']['recording'] = "https://assets.aircall.io/calls/{$callId}/recording.mp3";
        $this->postJson("/api/webhooks/aircall/{$this->webhookToken}", $ended)->assertOk();

        return Message::query()->where('provider_message_id', $callId)->firstOrFail();
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
