<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\CredentialStatus;
use App\Models\CommsTriage;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class BadgeTest extends TestCase
{
    use RefreshDatabase;
    use SeedsInboxThreads;

    public function test_counts_within_poll_cycle(): void
    {
        Site::factory()->create();
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $contact = Contact::factory()->create();
        $unreadThread = $this->makeInboxThread($contact, [
            'subject' => 'Unread',
            'unread_count' => 2,
            'last_message_at' => now(),
        ]);
        $this->makeInboxThread($contact, [
            'subject' => 'Read',
            'unread_count' => 0,
            'last_message_at' => now()->subMinute(),
        ]);

        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Postmark,
            'is_active' => true,
            'credentials' => ['server_token' => 'badge-token'],
            'webhook_url_token' => 'badge-webhook',
            'status' => CredentialStatus::Connected,
        ]);

        CommsTriage::query()->create([
            'communication_account_id' => $account->id,
            'provider' => Provider::Postmark,
            'provider_message_id' => 'badge-triage-1',
            'channel' => Channel::Email,
            'sender_value' => 'pending@example.com',
            'preview' => [
                'from' => 'pending@example.com',
                'subject' => 'Parked',
                'body_text' => 'Hello',
                'channel' => 'email',
            ],
            'payload' => ['MessageID' => 'badge-triage-1'],
            'status' => 'pending',
        ]);

        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.unread_threads', 1)
            ->assertJsonPath('data.triage_count', 1);

        $this->postJson("/api/inbox/threads/{$unreadThread->id}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.unread_threads', 0)
            ->assertJsonPath('data.triage_count', 1);

        $this->postJson("/api/inbox/threads/{$unreadThread->id}/unread")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.unread_threads', 1)
            ->assertJsonPath('data.triage_count', 1);

        $this->getJson('/api/inbox/threads?unread=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $unreadThread->id)
            ->assertJsonPath('data.0.unread_count', 1);
    }
}
