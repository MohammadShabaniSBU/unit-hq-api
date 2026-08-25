<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Models\SystemEvent;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\InboundSiteContext;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboundSiteContextTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function matches_operator_owned_destination_number_not_customer_from(): void
    {
        $site = Site::factory()->create();
        SiteSenderIdentity::query()->create([
            'site_id' => $site->id,
            'channel' => Channel::Sms,
            'from_number' => '+34911000001',
        ]);

        $resolved = InboundSiteContext::resolve(
            AgentChannel::Sms,
            null,
            '+34911000001',
        );
        $this->assertSame($site->id, $resolved);

        $customerFrom = InboundSiteContext::resolve(
            AgentChannel::Sms,
            null,
            '+34600111222',
        );
        $this->assertNull($customerFrom);
    }

    #[Test]
    public function identity_wins_over_disagreeing_account_and_logs_warning(): void
    {
        $identitySite = Site::factory()->create(['name' => 'Identity site']);
        $accountSite = Site::factory()->create(['name' => 'Account site']);
        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Site,
            'site_id' => $accountSite->id,
            'channel' => Channel::Sms,
            'provider' => Provider::Twilio,
            'is_active' => true,
            'credentials' => ['sid' => 'AC-test', 'token' => 'secret'],
            'status' => CredentialStatus::Connected,
        ]);
        $identity = SiteSenderIdentity::query()->create([
            'site_id' => $identitySite->id,
            'channel' => Channel::Sms,
            'account_id' => $account->id,
            'from_number' => '+34911000001',
        ]);

        $resolved = InboundSiteContext::resolve(
            AgentChannel::Sms,
            $account,
            '+34911000001',
        );

        $this->assertSame($identitySite->id, $resolved);
        $this->assertDatabaseHas('system_events', [
            'event' => 'ai.inbound.site_disagreement',
            'subject_id' => $identity->id,
        ]);
        $event = SystemEvent::query()->where('event', 'ai.inbound.site_disagreement')->first();
        $this->assertNotNull($event);
        $this->assertSame($identitySite->id, $event->payload['identity_site_id']);
        $this->assertSame($accountSite->id, $event->payload['account_site_id']);
    }

    #[Test]
    public function site_scoped_account_seeds_when_no_destination_identity(): void
    {
        $site = Site::factory()->create();
        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Site,
            'site_id' => $site->id,
            'channel' => Channel::Sms,
            'provider' => Provider::Twilio,
            'is_active' => true,
            'credentials' => ['sid' => 'AC-test', 'token' => 'secret'],
            'status' => CredentialStatus::Connected,
        ]);

        $resolved = InboundSiteContext::resolve(AgentChannel::Sms, $account, null);

        $this->assertSame($site->id, $resolved);
    }

    #[Test]
    public function webchat_leaves_site_null(): void
    {
        $this->assertNull(InboundSiteContext::resolve(AgentChannel::Webchat, null, '+34911000001'));
    }
}
