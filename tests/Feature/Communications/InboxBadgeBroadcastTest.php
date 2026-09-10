<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Events\InboxBadgeUpdated;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\InboundReceiptApplier;
use App\Support\Communications\Provider;
use App\Support\Communications\Results\InboundMessage;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class InboxBadgeBroadcastTest extends TestCase
{
    use RefreshDatabase;
    use SeedsInboxThreads;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function inbox_view_may_subscribe(): void
    {
        $this->configureReverbAuth();
        Sanctum::actingAs(Employee::factory()->manager()->create());

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-inbox',
        ])->assertOk();
    }

    #[Test]
    public function lacking_inbox_view_may_not_subscribe(): void
    {
        $this->configureReverbAuth();
        Sanctum::actingAs(Employee::factory()->withoutRoleGrant()->create());

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-inbox',
        ])->assertForbidden();
    }

    #[Test]
    public function mark_read_dispatches_inbox_badge_updated(): void
    {
        Site::factory()->create();
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $contact = Contact::factory()->create();
        $thread = $this->makeInboxThread($contact, [
            'unread_count' => 2,
            'last_message_at' => now(),
        ]);

        Event::fake([InboxBadgeUpdated::class]);

        $this->postJson("/api/inbox/threads/{$thread->id}/read")->assertOk();

        Event::assertDispatched(InboxBadgeUpdated::class);
    }

    #[Test]
    public function inbound_message_dispatches_inbox_badge_updated(): void
    {
        Site::factory()->create();
        $account = $this->companyEmailAccount();
        $contact = Contact::factory()->create();
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Email,
            'value' => 'renter@example.com',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        Event::fake([InboxBadgeUpdated::class]);

        $result = InboundReceiptApplier::apply(
            Provider::Postmark,
            $account->id,
            $this->inboundEmail('badge-in-1', 'renter@example.com'),
        );

        $this->assertSame('message', $result['outcome']);
        Event::assertDispatched(InboxBadgeUpdated::class);
    }

    #[Test]
    public function unmatched_inbound_triage_dispatches_inbox_badge_updated(): void
    {
        Site::factory()->create();
        $account = $this->companyEmailAccount();

        Event::fake([InboxBadgeUpdated::class]);

        $result = InboundReceiptApplier::apply(
            Provider::Postmark,
            $account->id,
            $this->inboundEmail('badge-triage-1', 'unknown@example.com'),
        );

        $this->assertSame('triage', $result['outcome']);
        Event::assertDispatched(InboxBadgeUpdated::class);
    }

    private function configureReverbAuth(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1000',
            'broadcasting.connections.reverb.options' => [
                'host' => '127.0.0.1',
                'port' => 8080,
                'scheme' => 'http',
                'useTLS' => false,
            ],
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');
    }

    private function companyEmailAccount(): CommunicationAccount
    {
        return CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Postmark,
            'is_active' => true,
            'credentials' => ['server_token' => 'badge-token'],
            'webhook_url_token' => 'badge-broadcast-webhook',
            'status' => CredentialStatus::Connected,
        ]);
    }

    private function inboundEmail(string $providerMessageId, string $from): InboundMessage
    {
        return new InboundMessage(
            $providerMessageId,
            $providerMessageId.'-event',
            Channel::Email,
            $from,
            'inbox@example.com',
            'Badge ping',
            'Hello',
            null,
            [],
            [],
            false,
            null,
            ['MessageID' => $providerMessageId],
        );
    }
}
