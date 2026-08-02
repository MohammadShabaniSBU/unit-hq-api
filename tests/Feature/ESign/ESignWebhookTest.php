<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Enums\CredentialStatus;
use App\Enums\EsignProvider;
use App\Enums\EsignWebhookState;
use App\Jobs\ProcessEsignWebhookEvent;
use App\Models\EsignProviderAccount;
use App\Models\EsignWebhookEvent;
use App\Models\SystemEvent;
use App\Support\ESign\EnvelopeSpec;
use App\Support\ESign\ESignProviderRegistry;
use App\Support\ESign\FakeESignProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class ESignWebhookTest extends TestCase
{
    use RefreshDatabase;

    private EsignProviderAccount $account;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        FakeESignProvider::reset();

        $registry = app(ESignProviderRegistry::class);
        $registry->register('signable', FakeESignProvider::class);

        $this->token = Str::random(40);
        $this->account = EsignProviderAccount::query()->create([
            'provider' => EsignProvider::Signable,
            'display_name' => 'Signable',
            'credentials' => ['api_key' => 'fake_key_test'],
            'webhook_token' => $this->token,
            'webhook_state' => EsignWebhookState::Configured,
            'status' => CredentialStatus::Connected,
            'is_active' => true,
        ]);
    }

    public function test_idempotent_unknown_inactive(): void
    {
        Bus::fake([ProcessEsignWebhookEvent::class]);

        $adapter = FakeESignProvider::make(['api_key' => 'fake_key_test']);
        $ref = $adapter->createEnvelope(new EnvelopeSpec(
            pdfBytes: '%PDF-1.4 stub',
            title: 'Stub contract',
            signer: ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
        ));

        $payload = [
            'event_id' => 'evt_signed_1',
            'type' => 'signed',
            'envelope_ref' => $ref->providerRef,
            'signer' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
        ];

        $first = $this->postJson('/api/webhooks/esign/'.$this->token, $payload);
        $first->assertOk()->assertJsonPath('message', 'ok');

        $this->assertDatabaseCount('esign_webhook_events', 1);
        Bus::assertDispatched(ProcessEsignWebhookEvent::class);

        // Replay: same event id inserts once; already-pending may re-dispatch but count stays 1.
        $replay = $this->postJson('/api/webhooks/esign/'.$this->token, $payload);
        $replay->assertOk();
        $this->assertDatabaseCount('esign_webhook_events', 1);

        // Mark processed, then replay must not dispatch again.
        EsignWebhookEvent::query()->where('provider_event_id', 'evt_signed_1')
            ->update(['processing_status' => 'processed', 'processed_at' => now()]);
        Bus::fake([ProcessEsignWebhookEvent::class]);
        $replayProcessed = $this->postJson('/api/webhooks/esign/'.$this->token, $payload);
        $replayProcessed->assertOk();
        $this->assertDatabaseCount('esign_webhook_events', 1);
        Bus::assertNotDispatched(ProcessEsignWebhookEvent::class);

        // Unknown event type: ack + Tier-1.
        Bus::fake([ProcessEsignWebhookEvent::class]);
        $unknown = $this->postJson('/api/webhooks/esign/'.$this->token, [
            'event_id' => 'evt_weird_1',
            'type' => 'something-weird',
            'envelope_ref' => $ref->providerRef,
        ]);
        $unknown->assertOk();
        $this->assertNotNull(
            SystemEvent::query()->where('event', 'webhook.esign.unknown_type')->first()
        );
        Bus::assertDispatched(ProcessEsignWebhookEvent::class);

        // Inactive account ignores (acks, no new events).
        $this->account->update(['is_active' => false]);
        $before = EsignWebhookEvent::query()->count();
        Bus::fake([ProcessEsignWebhookEvent::class]);

        $inactive = $this->postJson('/api/webhooks/esign/'.$this->token, [
            'event_id' => 'evt_after_inactive',
            'type' => 'signed',
            'envelope_ref' => $ref->providerRef,
        ]);
        $inactive->assertOk();
        $this->assertSame($before, EsignWebhookEvent::query()->count());
        Bus::assertNotDispatched(ProcessEsignWebhookEvent::class);

        // Round-trip: process pending signed event + downloadSigned.
        $this->account->update(['is_active' => true]);
        $event = EsignWebhookEvent::query()
            ->where('provider_event_id', 'evt_signed_1')
            ->firstOrFail();
        $event->processing_status = 'pending';
        $event->processed_at = null;
        $event->save();

        $this->app->call([new ProcessEsignWebhookEvent($event->id), 'handle']);

        $event->refresh();
        $this->assertSame('processed', $event->processing_status);

        $signed = FakeESignProvider::make()->downloadSigned($ref->providerRef);
        $this->assertSame(FakeESignProvider::STUB_PDF, $signed->pdfBytes);
    }
}
