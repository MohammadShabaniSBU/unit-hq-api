<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\ChannelSuppression;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Message;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Messages\SmsMessage;
use App\Support\Communications\Provider;
use App\Support\Communications\SendClass;
use App\Support\Communications\SendContext;
use App\Support\Communications\Senders\SmsSender;
use App\Support\Communications\SuppressionReason;
use App\Support\Communications\SuppressionScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SinchAdapterTest extends TestCase
{
    use RefreshDatabase;

    private CommunicationAccount $account;

    private string $webhookToken = 'tok-sinch-pipeline';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();

        $this->account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Sms,
            'provider' => Provider::Sinch,
            'is_active' => true,
            'credentials' => [
                'service_plan_id' => 'plan-test',
                'api_token' => 'sinch-token',
                'region' => 'us',
            ],
            'webhook_url_token' => $this->webhookToken,
            'status' => CredentialStatus::Connected,
        ]);

        SiteSenderIdentity::query()->create([
            'site_id' => $this->site->id,
            'channel' => Channel::Sms,
            'from_number' => '+15550001111',
        ]);
    }

    public function test_full_pipeline_zero_core_diffs(): void
    {
        $this->assertStringNotContainsString(
            'Sinch',
            (string) file_get_contents(app_path('Support/Communications/Senders/SmsSender.php')),
        );
        $this->assertStringNotContainsString(
            'Sinch',
            (string) file_get_contents(app_path('Support/Communications/DeliveryEventApplier.php')),
        );

        Http::fake([
            'us.sms.api.sinch.com/*' => Http::response([
                'id' => '01FC66621XXXXX',
                'to' => ['+15551234567'],
                'from' => '+15550001111',
                'body' => 'Hello from Sinch',
            ], 201),
        ]);

        $contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15551234567',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        $result = app(SmsSender::class)->send(
            new SmsMessage('+15551234567', 'Hello from Sinch'),
            $this->site,
            $contact,
            SendContext::manual(SendClass::Transactional),
        );

        $this->assertSame('01FC66621XXXXX', $result->providerMessageId);
        $this->assertSame(Provider::Sinch, $result->provider);
        $this->assertSame($this->account->id, $result->accountId);

        $message = Message::query()->findOrFail($result->messageId);
        $this->assertSame(Provider::Sinch, $message->provider);
        $this->assertSame($this->account->id, $message->communication_account_id);
        $this->assertSame(MessageStatus::Sent, $message->status);

        $this->postJson(
            "/api/webhooks/sinch/{$this->webhookToken}",
            $this->deliveryFixture('sinch_delivered.json'),
        )->assertOk();

        $message->refresh();
        $this->assertSame(MessageStatus::Delivered, $message->status);

        $this->postJson(
            "/api/webhooks/sinch/{$this->webhookToken}/inbound",
            $this->inboundFixture('sinch_mo.json'),
        )->assertOk();

        $inbound = Message::query()
            ->where('provider_message_id', '01FC66621MO0001')
            ->firstOrFail();
        $this->assertSame(MessageStatus::Received, $inbound->status);
        $this->assertSame($contact->id, $inbound->thread?->contact_id);

        $this->postJson(
            "/api/webhooks/sinch/{$this->webhookToken}/inbound",
            $this->inboundFixture('sinch_mo_stop.json'),
        )->assertOk();

        $stop = ChannelSuppression::query()
            ->active()
            ->where('channel', Channel::Sms)
            ->where('address', '+15551234567')
            ->firstOrFail();
        $this->assertSame(SuppressionScope::All, $stop->scope);
        $this->assertSame(SuppressionReason::StopKeyword, $stop->reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryFixture(string $name): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/communications/delivery/'.$name)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $data;
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
