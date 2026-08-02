<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\ChannelSuppression;
use App\Models\CommsTriage;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Provider;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use App\Support\Communications\WhatsAppWindow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaInboundTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationAccount $account;

    private string $webhookToken = 'tok-wa-inbound';

    protected function setUp(): void
    {
        parent::setUp();

        Site::factory()->create();

        $this->account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Whatsapp,
            'provider' => Provider::Sinch,
            'is_active' => true,
            'credentials' => [
                'project_id' => 'proj-test',
                'key_id' => 'key-id',
                'key_secret' => 'key-secret',
                'app_id' => 'app-test',
                'region' => 'us',
            ],
            'webhook_url_token' => $this->webhookToken,
            'status' => CredentialStatus::Connected,
        ]);

        SiteSenderIdentity::query()->create([
            'site_id' => Site::query()->firstOrFail()->id,
            'channel' => Channel::Whatsapp,
            'from_number' => '+15550009999',
        ]);
    }

    public function test_opens_window_threads_triages_optout(): void
    {
        $contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Whatsapp,
            'value' => '+15551234567',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-02 10:42:00', 'UTC'));

        $this->postJson(
            "/api/webhooks/sinch/{$this->webhookToken}/inbound",
            $this->fixture('sinch_wa_mo.json'),
        )->assertOk();

        $inbound = Message::query()
            ->where('provider_message_id', '01WA-MO-0001')
            ->firstOrFail();
        $this->assertSame(MessageStatus::Received, $inbound->status);
        $this->assertSame(Channel::Whatsapp, $inbound->thread?->channel);
        $this->assertSame($contact->id, $inbound->thread?->contact_id);
        $this->assertSame('+15551234567', $inbound->thread?->channel_key);
        $this->assertNotNull($inbound->thread?->last_inbound_at);
        $this->assertTrue(WhatsAppWindow::isOpen($inbound->thread));

        // Unknown number → triage.
        $this->postJson(
            "/api/webhooks/sinch/{$this->webhookToken}/inbound",
            [
                'type' => 'whatsapp_mo',
                'id' => '01WA-MO-UNKNOWN',
                'from' => '+15550000000',
                'to' => '+15550009999',
                'body' => 'Who is this?',
                'received_at' => '2026-08-02T10:43:00.000Z',
            ],
        )->assertOk();

        $this->assertTrue(
            CommsTriage::query()
                ->where('provider_message_id', '01WA-MO-UNKNOWN')
                ->where('status', 'pending')
                ->exists(),
        );

        // Literal STOP → suppression on whatsapp channel.
        $this->postJson(
            "/api/webhooks/sinch/{$this->webhookToken}/inbound",
            $this->fixture('sinch_wa_mo_stop.json'),
        )->assertOk();

        $stop = ChannelSuppression::query()
            ->active()
            ->where('channel', Channel::Whatsapp)
            ->where('address', '+15551234567')
            ->firstOrFail();
        $this->assertSame(SuppressionScope::All, $stop->scope);
        $this->assertSame(SuppressionReason::StopKeyword, $stop->reason);

        Carbon::setTestNow();
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
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
