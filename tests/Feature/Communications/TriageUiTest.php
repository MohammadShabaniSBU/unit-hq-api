<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\CredentialStatus;
use App\Enums\LogChannel;
use App\Models\CommsTriage;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TriageUiTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationAccount $account;

    private string $webhookToken = 'tok-triage-ui';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Site::factory()->create();
        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $this->account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Postmark,
            'is_active' => true,
            'credentials' => ['server_token' => 'test-token'],
            'webhook_url_token' => $this->webhookToken,
            'status' => CredentialStatus::Connected,
        ]);
    }

    public function test_three_resolutions_end_to_end(): void
    {
        $this->parkTriage('triage-ui-001', 'unknown-a@example.com');
        $this->parkTriage('triage-ui-002', 'unknown-b@example.com');
        $this->parkTriage('triage-ui-003', 'unknown-c@example.com');

        $list = $this->getJson('/api/comms-triage')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->json('data');

        $senders = collect($list)->pluck('sender_value')->all();
        $this->assertEqualsCanonicalizing(
            ['unknown-a@example.com', 'unknown-b@example.com', 'unknown-c@example.com'],
            $senders,
        );

        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.triage_count', 3);

        $triage1 = CommsTriage::query()->where('provider_message_id', 'triage-ui-001')->firstOrFail();
        $this->getJson("/api/comms-triage/{$triage1->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $triage1->id)
            ->assertJsonStructure(['data' => ['body' => ['format', 'content']]]);

        $existing = Contact::factory()->create();
        $attach = $this->postJson("/api/comms-triage/{$triage1->id}/attach", [
            'contact_id' => $existing->id,
        ])->assertOk();

        $attach->assertJsonPath('data.status', 'resolved');
        $this->assertNotNull($attach->json('data.message_thread_id'));
        $this->assertNotNull($attach->json('data.message_id'));

        $this->assertTrue(
            Activity::query()
                ->where('log_name', LogChannel::Comms->value)
                ->where(function ($q): void {
                    $q->where('event', 'triage.resolved')
                        ->orWhere('description', 'triage.resolved');
                })
                ->where('properties->how', 'attach')
                ->exists()
        );

        $triage2 = CommsTriage::query()->where('provider_message_id', 'triage-ui-002')->firstOrFail();
        $create = $this->postJson("/api/comms-triage/{$triage2->id}/create-and-attach", [
            'first_name' => 'Brand',
            'last_name' => 'New',
        ])->assertOk();

        $create->assertJsonPath('data.status', 'resolved');
        $this->assertNotNull($create->json('data.message_thread_id'));

        $this->assertTrue(
            Activity::query()
                ->where('log_name', LogChannel::Comms->value)
                ->where(function ($q): void {
                    $q->where('event', 'triage.resolved')
                        ->orWhere('description', 'triage.resolved');
                })
                ->where('properties->how', 'create_and_attach')
                ->exists()
        );

        $triage3 = CommsTriage::query()->where('provider_message_id', 'triage-ui-003')->firstOrFail();
        $this->postJson("/api/comms-triage/{$triage3->id}/discard", [
            'reason' => 'Obvious spam',
        ])->assertOk()->assertJsonPath('data.status', 'discarded');

        $this->assertTrue(
            Activity::query()
                ->where('log_name', LogChannel::Comms->value)
                ->where(function ($q): void {
                    $q->where('event', 'triage.discarded')
                        ->orWhere('description', 'triage.discarded');
                })
                ->where('properties->reason', 'Obvious spam')
                ->exists()
        );

        $this->assertNull(
            Message::query()->where('provider_message_id', 'triage-ui-003')->first()
        );

        $this->getJson('/api/comms-triage')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.triage_count', 0);
    }

    private function parkTriage(string $messageId, string $from): void
    {
        $payload = $this->inboundFixture('postmark_inbound_multipart.json');
        $payload['From'] = $from;
        $payload['FromFull'] = ['Email' => $from, 'Name' => 'Unknown', 'MailboxHash' => ''];
        $payload['MessageID'] = $messageId;
        $payload['Headers'] = [
            ['Name' => 'Message-ID', 'Value' => '<'.$messageId.'@mtasv.net>'],
        ];
        $payload['Attachments'] = [];

        $this->postJson(
            "/api/webhooks/postmark/{$this->webhookToken}/inbound",
            $payload,
        )->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundFixture(string $name): array
    {
        $path = base_path('tests/fixtures/communications/inbound/'.$name);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
