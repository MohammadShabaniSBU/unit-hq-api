<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\AircallUserLink;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\CallRecordingProxy;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecordingTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Contact $contact;

    private string $webhookToken = 'tok-aircall-recording';

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

        $this->contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $this->contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15559876543',
            'is_primary' => true,
            'opted_in' => true,
        ]);
    }

    public function test_fresh_fetch_never_persisted(): void
    {
        $proxySource = (string) file_get_contents(app_path('Support/Communications/CallRecordingProxy.php'));
        $this->assertStringNotContainsString('forceFill', $proxySource);
        $this->assertStringNotContainsString('->save(', $proxySource);
        $this->assertStringContainsString('never persist', strtolower($proxySource));

        Http::fake([
            'api.aircall.io/v1/users/456/dial' => Http::response(['call' => ['id' => 812100]], 200),
        ]);

        $this->postJson('/api/calls/dial', [
            'contact_id' => $this->contact->id,
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
        $storedBefore = $message->source_ref;
        $this->assertSame(
            'https://assets.aircall.io/calls/812100/recording.mp3',
            $storedBefore['recording_url'] ?? null,
        );

        $freshUrl = 'https://media.aircall.io/fresh-signed/812100.mp3?sig=abc';
        Http::fake([
            'api.aircall.io/v1/calls/812100' => Http::response([
                'call' => [
                    'id' => 812100,
                    'recording' => $freshUrl,
                    'voicemail' => null,
                ],
            ], 200),
            'media.aircall.io/*' => Http::response('ID3fake-audio-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $response = $this->get("/api/messages/{$message->id}/recording");
        $response->assertOk();
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('ID3fake-audio-bytes', $response->streamedContent());

        $message->refresh();
        $this->assertSame($storedBefore, $message->source_ref);
        $this->assertNotSame($freshUrl, $message->source_ref['recording_url'] ?? null);

        // Client-facing map strips signed URLs.
        $sanitized = CallRecordingProxy::sanitizeSourceRef($message->source_ref);
        $this->assertArrayNotHasKey('recording_url', $sanitized ?? []);
        $this->assertArrayNotHasKey('voicemail_url', $sanitized ?? []);

        $threadUnread = (int) $message->thread()->value('unread_count');
        $this->get("/api/messages/{$message->id}/recording")->assertOk();
        $this->assertSame($threadUnread, (int) $message->thread()->value('unread_count'));
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
