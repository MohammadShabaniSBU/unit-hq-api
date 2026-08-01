<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_midday_swap_reconciles_old(): void
    {
        $site = Site::factory()->create();

        $twilio = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Sms,
            'provider' => Provider::Twilio,
            'is_active' => true,
            'credentials' => [
                'account_sid' => 'ACxxx',
                'auth_token' => 'tok',
                'messaging_service_sid' => '',
            ],
            'webhook_url_token' => 'tok-twilio-switch',
            'status' => CredentialStatus::Connected,
        ]);

        $sinch = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Sms,
            'provider' => Provider::Sinch,
            'is_active' => false,
            'credentials' => [
                'service_plan_id' => 'plan-test',
                'api_token' => 'sinch-token',
                'region' => 'us',
            ],
            'webhook_url_token' => 'tok-sinch-switch',
            'status' => CredentialStatus::Connected,
        ]);

        SiteSenderIdentity::query()->create([
            'site_id' => $site->id,
            'channel' => Channel::Sms,
            'from_number' => '+15550001111',
        ]);

        $contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15551234567',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        Http::fake([
            'api.twilio.com/*' => Http::response([
                'sid' => 'SMaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ], 201),
            'us.sms.api.sinch.com/*' => Http::response([
                'id' => '01FC66621NEWSEND',
                'to' => ['+15551234567'],
            ], 201),
        ]);

        $old = app(SmsSender::class)->send(
            new SmsMessage('+15551234567', 'Via Twilio'),
            $site,
            $contact,
            SendContext::manual(SendClass::Transactional),
        );

        $this->assertSame(Provider::Twilio, $old->provider);
        $this->assertSame($twilio->id, $old->accountId);

        $oldMessage = Message::query()->findOrFail($old->messageId);
        $this->assertSame($twilio->id, $oldMessage->communication_account_id);

        // Mid-day swap: Sinch becomes active.
        $twilio->update(['is_active' => false]);
        $sinch->update(['is_active' => true]);

        // Old Twilio delivery report still reconciles via stored provenance.
        $this->postJson(
            '/api/webhooks/twilio/tok-twilio-switch',
            json_decode(
                (string) file_get_contents(base_path('tests/fixtures/communications/delivery/twilio_delivered.json')),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        )->assertOk();

        $oldMessage->refresh();
        $this->assertSame(MessageStatus::Delivered, $oldMessage->status);
        $this->assertSame($twilio->id, $oldMessage->communication_account_id);

        $fresh = app(SmsSender::class)->send(
            new SmsMessage('+15551234567', 'Via Sinch'),
            $site,
            $contact,
            SendContext::manual(SendClass::Transactional),
        );

        $this->assertSame(Provider::Sinch, $fresh->provider);
        $this->assertSame($sinch->id, $fresh->accountId);
        $this->assertSame('01FC66621NEWSEND', $fresh->providerMessageId);
    }
}
