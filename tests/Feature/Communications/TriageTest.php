<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\CredentialStatus;
use App\Models\CommsTriage;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TriageTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationAccount $account;

    private string $webhookToken = 'tok-triage';

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

    public function test_unmatched_flow_three_resolutions(): void
    {
        $contactsBefore = Contact::query()->count();

        $payload = $this->inboundFixture('postmark_inbound_multipart.json');
        $payload['From'] = 'unknown-spam@example.com';
        $payload['FromFull'] = ['Email' => 'unknown-spam@example.com', 'Name' => 'Spam', 'MailboxHash' => ''];
        $payload['MessageID'] = 'triage-msg-001';
        $payload['Headers'] = [
            ['Name' => 'Message-ID', 'Value' => '<triage-msg-001@mtasv.net>'],
        ];
        $payload['Attachments'] = [];

        $this->postJson(
            "/api/webhooks/postmark/{$this->webhookToken}/inbound",
            $payload,
        )->assertOk();

        $this->assertSame($contactsBefore, Contact::query()->count());
        $this->assertSame(0, Message::query()->where('direction', 'inbound')->count());

        $triage = CommsTriage::query()->where('provider_message_id', 'triage-msg-001')->firstOrFail();
        $this->assertSame('pending', $triage->status);

        // attach → existing contact
        $existing = Contact::factory()->create();
        $this->postJson("/api/comms-triage/{$triage->id}/attach", [
            'contact_id' => $existing->id,
        ])->assertOk();

        $triage->refresh();
        $this->assertSame('resolved', $triage->status);
        $this->assertSame($existing->id, $triage->resolved_contact_id);
        $this->assertNotNull($triage->resolved_message_id);
        $this->assertTrue(
            ContactChannel::query()->where('contact_id', $existing->id)->exists()
            || Message::query()->where('id', $triage->resolved_message_id)->exists()
        );

        // create-and-attach
        $payload2 = $payload;
        $payload2['MessageID'] = 'triage-msg-002';
        $payload2['From'] = 'brand-new@example.com';
        $payload2['FromFull'] = ['Email' => 'brand-new@example.com', 'Name' => 'New', 'MailboxHash' => ''];
        $payload2['Headers'] = [
            ['Name' => 'Message-ID', 'Value' => '<triage-msg-002@mtasv.net>'],
        ];

        $this->postJson(
            "/api/webhooks/postmark/{$this->webhookToken}/inbound",
            $payload2,
        )->assertOk();

        $triage2 = CommsTriage::query()->where('provider_message_id', 'triage-msg-002')->firstOrFail();
        $beforeCreate = Contact::query()->count();

        $this->postJson("/api/comms-triage/{$triage2->id}/create-and-attach", [
            'first_name' => 'Brand',
            'last_name' => 'New',
        ])->assertOk();

        $this->assertSame($beforeCreate + 1, Contact::query()->count());
        $triage2->refresh();
        $this->assertSame('resolved', $triage2->status);
        $this->assertNotNull($triage2->resolved_contact_id);
        $this->assertNotNull($triage2->resolved_message_id);

        // discard
        $payload3 = $payload;
        $payload3['MessageID'] = 'triage-msg-003';
        $payload3['From'] = 'discard-me@example.com';
        $payload3['FromFull'] = ['Email' => 'discard-me@example.com', 'Name' => 'X', 'MailboxHash' => ''];
        $payload3['Headers'] = [
            ['Name' => 'Message-ID', 'Value' => '<triage-msg-003@mtasv.net>'],
        ];

        $this->postJson(
            "/api/webhooks/postmark/{$this->webhookToken}/inbound",
            $payload3,
        )->assertOk();

        $triage3 = CommsTriage::query()->where('provider_message_id', 'triage-msg-003')->firstOrFail();
        $contactsBeforeDiscard = Contact::query()->count();

        $this->postJson("/api/comms-triage/{$triage3->id}/discard")->assertOk();

        $triage3->refresh();
        $this->assertSame('discarded', $triage3->status);
        $this->assertSame($contactsBeforeDiscard, Contact::query()->count());
        $this->assertNull(
            Message::query()->where('provider_message_id', 'triage-msg-003')->first()
        );
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
