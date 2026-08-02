<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessGrantState;
use App\Enums\AccessProviderName;
use App\Enums\AccessWebhookState;
use App\Enums\CredentialStatus;
use App\Enums\HoldType;
use App\Jobs\ProcessAccessWebhookEvent;
use App\Models\AccessEvent;
use App\Models\AccessGrant;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Models\AccessSuspension;
use App\Models\AccessWebhookEvent;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Interaction;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Support\Access\AccessProviderRegistry;
use App\Support\Access\FakeAccessProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessEventTest extends TestCase
{
    use RefreshDatabase;

    private AccessProviderAccount $account;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        FakeAccessProvider::reset();

        $registry = app(AccessProviderRegistry::class);
        $registry->register('sensorberg', FakeAccessProvider::class);

        $this->token = Str::random(40);
        $this->account = AccessProviderAccount::query()->create([
            'provider' => AccessProviderName::Sensorberg,
            'display_name' => 'Sensorberg',
            'credentials' => ['api_key' => 'fake_key_test'],
            'webhook_token' => $this->token,
            'webhook_state' => AccessWebhookState::Configured,
            'status' => CredentialStatus::Connected,
            'is_active' => true,
        ]);
    }

    public function test_idempotent_unmapped_unresolved_denied_interaction(): void
    {
        Bus::fake([ProcessAccessWebhookEvent::class]);

        $payload = [
            'event_id' => 'evt_denied_1',
            'type' => 'denied',
            'provider_point_id' => 'unknown-point-99',
            'credential_ref' => 'cred-orphan',
            'occurred_at' => now()->toIso8601String(),
        ];

        $first = $this->postJson('/api/webhooks/access/'.$this->token, $payload);
        $first->assertOk()->assertJsonPath('message', 'ok');
        $this->assertDatabaseCount('access_webhook_events', 1);
        Bus::assertDispatched(ProcessAccessWebhookEvent::class);

        $replay = $this->postJson('/api/webhooks/access/'.$this->token, $payload);
        $replay->assertOk();
        $this->assertDatabaseCount('access_webhook_events', 1);

        AccessWebhookEvent::query()->where('provider_event_id', 'evt_denied_1')
            ->update(['processing_status' => 'processed', 'processed_at' => now()]);
        Bus::fake([ProcessAccessWebhookEvent::class]);
        $this->postJson('/api/webhooks/access/'.$this->token, $payload)->assertOk();
        Bus::assertNotDispatched(ProcessAccessWebhookEvent::class);

        // Process unmapped + unresolved: stored, not lost.
        $pending = AccessWebhookEvent::query()->create([
            'access_provider_account_id' => $this->account->id,
            'provider_event_id' => 'evt_unmapped_1',
            'payload' => [
                'event_id' => 'evt_unmapped_1',
                'type' => 'granted',
                'provider_point_id' => 'never-mapped-point',
                'credential_ref' => 'cred-unknown',
            ],
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        $this->app->call([new ProcessAccessWebhookEvent($pending->id), 'handle']);

        $unmapped = AccessEvent::query()->where('provider_point_id', 'never-mapped-point')->first();
        $this->assertNotNull($unmapped);
        $this->assertNull($unmapped->access_point_id);
        $this->assertNull($unmapped->contact_id);

        $counts = AccessEvent::attentionCounts();
        $this->assertGreaterThanOrEqual(1, $counts['unmapped_points_count']);
        $this->assertGreaterThanOrEqual(1, $counts['unresolved_contacts_count']);

        // Denied during active suspension → Interaction.
        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create(['contact_id' => $contact->id]);
        $point = AccessPoint::factory()->create([
            'access_provider_account_id' => $this->account->id,
            'provider_point_id' => 'fake-door-al6-06',
            'label' => 'Unit AL6-06 door',
        ]);
        $grant = AccessGrant::factory()->create([
            'access_point_id' => $point->id,
            'contact_id' => $contact->id,
            'contract_id' => $contract->id,
            'provider_grant_id' => 'grant-suspended-1',
            'state' => AccessGrantState::Applied,
        ]);
        AccessSuspension::factory()->create([
            'contract_id' => $contract->id,
        ]);

        $deniedPending = AccessWebhookEvent::query()->create([
            'access_provider_account_id' => $this->account->id,
            'provider_event_id' => 'evt_denied_suspension',
            'payload' => [
                'event_id' => 'evt_denied_suspension',
                'type' => 'denied',
                'provider_point_id' => 'fake-door-al6-06',
                'grant_ref' => 'grant-suspended-1',
            ],
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        $this->app->call([new ProcessAccessWebhookEvent($deniedPending->id), 'handle']);

        $this->assertDatabaseHas('access_events', [
            'provider_point_id' => 'fake-door-al6-06',
            'contact_id' => $contact->id,
            'access_grant_id' => $grant->id,
            'event_type' => 'denied',
        ]);

        $interaction = Interaction::query()
            ->where('contact_id', $contact->id)
            ->where('summary', 'Access denied at Unit AL6-06 door')
            ->first();
        $this->assertNotNull($interaction);
        $this->assertSame('other', $interaction->channel);

        // Denied during overlock also lands an Interaction.
        $unit = Unit::factory()->create(['site_id' => $point->site_id]);
        $contact2 = Contact::factory()->create();
        $contract2 = Contract::factory()->create(['contact_id' => $contact2->id]);
        $door = AccessPoint::factory()->unitDoor($unit->id)->create([
            'access_provider_account_id' => $this->account->id,
            'site_id' => $point->site_id,
            'provider_point_id' => 'overlocked-door',
            'label' => 'Unit overlocked door',
        ]);
        AccessGrant::factory()->create([
            'access_point_id' => $door->id,
            'contact_id' => $contact2->id,
            'contract_id' => $contract2->id,
            'provider_grant_id' => 'grant-overlock-1',
            'state' => AccessGrantState::Applied,
        ]);
        UnitHold::factory()->overlock()->create([
            'unit_id' => $unit->id,
            'hold_type' => HoldType::Overlock,
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => null,
            'released_at' => null,
        ]);

        $overlockPending = AccessWebhookEvent::query()->create([
            'access_provider_account_id' => $this->account->id,
            'provider_event_id' => 'evt_denied_overlock',
            'payload' => [
                'event_id' => 'evt_denied_overlock',
                'type' => 'denied',
                'provider_point_id' => 'overlocked-door',
                'grant_ref' => 'grant-overlock-1',
            ],
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        $before = Interaction::query()->where('contact_id', $contact2->id)->count();
        $this->app->call([new ProcessAccessWebhookEvent($overlockPending->id), 'handle']);
        $this->assertSame($before + 1, Interaction::query()->where('contact_id', $contact2->id)->count());

        // Inactive account ignores.
        $this->account->update(['is_active' => false]);
        $beforeEvents = AccessWebhookEvent::query()->count();
        Bus::fake([ProcessAccessWebhookEvent::class]);
        $this->postJson('/api/webhooks/access/'.$this->token, [
            'event_id' => 'evt_after_inactive',
            'type' => 'granted',
            'provider_point_id' => 'fake-gate-1',
        ])->assertOk();
        $this->assertSame($beforeEvents, AccessWebhookEvent::query()->count());
        Bus::assertNotDispatched(ProcessAccessWebhookEvent::class);
    }
}
