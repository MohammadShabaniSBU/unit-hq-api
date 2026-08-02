<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\CallWrapup;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrapups_and_recordings(): void
    {
        Site::factory()->create();
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Call,
            'provider' => Provider::Aircall,
            'is_active' => true,
            'credentials' => [
                'api_id' => 'aircall-id',
                'api_token' => 'aircall-token',
            ],
            'webhook_url_token' => 'tok-redact-call',
            'status' => CredentialStatus::Connected,
        ]);

        $contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15551112222',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Call,
            'channel_key' => '+15551112222',
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);

        $message = Message::query()->create([
            'message_thread_id' => $thread->id,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Received,
            'body_text' => 'Outbound call · answered · 80s',
            'from_address' => '+15550001111',
            'to_address' => '+15551112222',
            'provider' => Provider::Aircall,
            'communication_account_id' => $account->id,
            'provider_message_id' => '900001',
            'source' => MessageSource::System,
            'source_ref' => [
                'event' => 'call.ended',
                'outcome' => 'answered',
                'recording_url' => 'https://assets.aircall.io/calls/900001/recording.mp3',
                'voicemail_url' => null,
                'call' => [
                    'id' => 900001,
                    'recording' => 'https://assets.aircall.io/calls/900001/recording.mp3',
                ],
            ],
            'sent_at' => now(),
        ]);

        CallWrapup::query()->create([
            'message_id' => $message->id,
            'disposition' => 'payment_promised',
            'note' => 'Tenant SSN 123-45-6789 will pay',
            'employee_id' => $employee->id,
        ]);

        $redactionConfig = (string) file_get_contents(config_path('redaction.php'));
        $this->assertStringContainsString('Call wrap-ups', $redactionConfig);
        $this->assertStringContainsString('recording_redacted', $redactionConfig);

        $this->artisan('contacts:redact', ['contact' => $contact->id])->assertSuccessful();

        $wrapup = CallWrapup::query()->where('message_id', $message->id)->firstOrFail();
        $this->assertSame('payment_promised', $wrapup->disposition);
        $this->assertNull($wrapup->note);

        $message->refresh();
        $ref = $message->source_ref;
        $this->assertTrue($ref['recording_redacted'] ?? false);
        $this->assertArrayHasKey('recording_url', $ref);
        $this->assertNull($ref['recording_url']);
        $this->assertArrayHasKey('recording', $ref['call']);
        $this->assertNull($ref['call']['recording']);

        Http::fake([
            'api.aircall.io/v1/calls/900001' => Http::response([
                'call' => ['id' => 900001, 'recording' => 'https://media.aircall.io/should-not-use.mp3'],
            ], 200),
        ]);

        $this->getJson("/api/messages/{$message->id}/recording")
            ->assertNotFound()
            ->assertJsonPath('message', 'Recording unavailable');
    }
}
