<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Support\Facades\Http;

trait SeedsCommunicationAccounts
{
    protected function fakeCommunicationProviders(): void
    {
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-test-1'], 201),
            'api.twilio.com/*' => Http::response(['sid' => 'SM-test-1'], 201),
            'us.sms.api.sinch.com/*' => Http::response(['id' => '01FC-sinch-test'], 201),
            'api.aircall.io/*' => Http::response(['ping' => 'pong'], 200),
        ]);
    }

    protected function seedEmailAccount(?Site $site = null): CommunicationAccount
    {
        $site ??= Site::factory()->create();

        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'test-key'],
            'status' => CredentialStatus::Connected,
        ]);

        SiteSenderIdentity::query()->create([
            'site_id' => $site->id,
            'channel' => Channel::Email,
            'account_id' => $account->id,
            'from_name' => 'Keevaris',
            'from_email' => 'desk@example.com',
            'reply_to_email' => 'reply@example.com',
        ]);

        return $account;
    }

    protected function seedSmsAccount(?Site $site = null): CommunicationAccount
    {
        $site ??= Site::factory()->create();

        $account = CommunicationAccount::query()->create([
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
            'status' => CredentialStatus::Connected,
        ]);

        SiteSenderIdentity::query()->create([
            'site_id' => $site->id,
            'channel' => Channel::Sms,
            'from_number' => '+15550001111',
        ]);

        return $account;
    }

    protected function givePrimaryEmail(Contact $contact, string $email): ContactChannel
    {
        return ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Email,
            'value' => $email,
            'label' => 'primary',
            'is_primary' => true,
            'opted_in' => true,
        ]);
    }

    protected function givePrimaryPhone(Contact $contact, string $phone): ContactChannel
    {
        return ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => $phone,
            'label' => 'primary',
            'is_primary' => true,
            'opted_in' => true,
        ]);
    }
}
