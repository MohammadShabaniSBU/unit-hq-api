<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Communications;

use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\Provider;
use App\Support\Communications\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefers_site_scoped_active_account_over_company(): void
    {
        $site = Site::factory()->create();

        CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'company-key'],
            'status' => CredentialStatus::Connected,
        ]);

        $siteAccount = CommunicationAccount::query()->create([
            'scope' => AccountScope::Site,
            'site_id' => $site->id,
            'channel' => Channel::Email,
            'provider' => Provider::Postmark,
            'is_active' => true,
            'credentials' => ['server_token' => 'site-token'],
            'status' => CredentialStatus::Connected,
        ]);

        $resolved = app(ProviderResolver::class)->resolve(Channel::Email, $site);

        $this->assertSame($siteAccount->id, $resolved->account->id);
        $this->assertSame(Provider::Postmark, $resolved->account->provider);
    }

    public function test_falls_back_to_company_account(): void
    {
        $site = Site::factory()->create();

        $company = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'company-key'],
            'status' => CredentialStatus::Connected,
        ]);

        $resolved = app(ProviderResolver::class)->resolve(Channel::Email, $site);

        $this->assertSame($company->id, $resolved->account->id);
    }

    public function test_refuses_archived_site(): void
    {
        $site = Site::factory()->create(['archived_at' => now()]);

        CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'company-key'],
            'status' => CredentialStatus::Connected,
        ]);

        $this->expectException(ChannelNotConfigured::class);
        $this->expectExceptionMessage('archived');

        app(ProviderResolver::class)->resolve(Channel::Email, $site);
    }

    public function test_throws_when_no_account_configured(): void
    {
        $this->expectException(ChannelNotConfigured::class);

        app(ProviderResolver::class)->resolve(Channel::Sms);
    }
}
