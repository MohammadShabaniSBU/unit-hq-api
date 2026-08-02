<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\CommsTriage;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActiveCallsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private string $webhookToken = 'tok-aircall-active';

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
    }

    public function test_badge_payload_phases_and_matching(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15551234567',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        // Known contact: ringing.
        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $this->inboundFixture('aircall_call_created.json'),
        )->assertOk();

        $badge = $this->getJson('/api/inbox/badge')->assertOk();
        $badge->assertJsonPath('data.active_calls.0.phase', 'ringing');
        $badge->assertJsonPath('data.active_calls.0.contact.id', $contact->id);
        $badge->assertJsonPath('data.active_calls.0.contact.name', 'Ada Lovelace');
        $badge->assertJsonPath('data.active_calls.0.number', '+15551234567');
        $this->assertNotNull($badge->json('data.active_calls.0.thread_id'));
        $this->assertNotNull($badge->json('data.active_calls.0.message_id'));
        $this->assertNull($badge->json('data.active_calls.0.triage_id'));

        // Answered → ongoing.
        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $this->inboundFixture('aircall_call_answered.json'),
        )->assertOk();

        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.active_calls.0.phase', 'ongoing')
            ->assertJsonCount(1, 'data.active_calls');

        // Ended → cleared.
        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $this->inboundFixture('aircall_call_ended.json'),
        )->assertOk();

        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.active_calls', []);

        // Unknown number → triage entry with triage_id.
        $unknownCreated = $this->inboundFixture('aircall_call_created.json');
        $unknownCreated['data']['id'] = 900001;
        $unknownCreated['data']['raw_digits'] = '+34999111222';
        $unknownCreated['timestamp'] = time();

        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $unknownCreated,
        )->assertOk();

        $this->assertSame(0, \App\Models\Message::query()->where('provider_message_id', '900001')->count());
        $triage = CommsTriage::query()->where('provider_message_id', '900001')->firstOrFail();

        $unknownBadge = $this->getJson('/api/inbox/badge')->assertOk();
        $unknownBadge->assertJsonPath('data.active_calls.0.phase', 'ringing');
        $unknownBadge->assertJsonPath('data.active_calls.0.contact', null);
        $unknownBadge->assertJsonPath('data.active_calls.0.triage_id', $triage->id);
        $unknownBadge->assertJsonPath('data.active_calls.0.number', '+34999111222');

        // Lifecycle update on triage → ongoing, then ended clears.
        $unknownAnswered = $this->inboundFixture('aircall_call_answered.json');
        $unknownAnswered['data']['id'] = 900001;
        $unknownAnswered['data']['raw_digits'] = '+34999111222';

        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $unknownAnswered,
        )->assertOk();

        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.active_calls.0.phase', 'ongoing')
            ->assertJsonPath('data.active_calls.0.triage_id', $triage->id);

        $unknownEnded = $this->inboundFixture('aircall_call_ended.json');
        $unknownEnded['data']['id'] = 900001;
        $unknownEnded['data']['raw_digits'] = '+34999111222';

        $this->postJson(
            "/api/webhooks/aircall/{$this->webhookToken}",
            $unknownEnded,
        )->assertOk();

        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.active_calls', []);

        // Two simultaneous known calls stack.
        $callA = $this->inboundFixture('aircall_call_created.json');
        $callA['data']['id'] = 910001;
        $callA['data']['raw_digits'] = '+15551234567';
        $callA['timestamp'] = time() - 10;

        $contactB = Contact::factory()->create([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
        ]);
        ContactChannel::query()->create([
            'contact_id' => $contactB->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15550009999',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        $callB = $this->inboundFixture('aircall_call_created.json');
        $callB['data']['id'] = 910002;
        $callB['data']['raw_digits'] = '+15550009999';
        $callB['timestamp'] = time();

        $this->postJson("/api/webhooks/aircall/{$this->webhookToken}", $callA)->assertOk();
        $this->postJson("/api/webhooks/aircall/{$this->webhookToken}", $callB)->assertOk();

        $stacked = $this->getJson('/api/inbox/badge')->assertOk();
        $stacked->assertJsonCount(2, 'data.active_calls');
        $phases = collect($stacked->json('data.active_calls'))->pluck('phase')->all();
        $this->assertSame(['ringing', 'ringing'], $phases);
        $names = collect($stacked->json('data.active_calls'))->pluck('contact.name')->sort()->values()->all();
        $this->assertSame(['Ada Lovelace', 'Grace Hopper'], $names);
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
