<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccessProviderName;
use App\Enums\AccessWebhookState;
use App\Enums\AutomationRunStatus;
use App\Enums\CredentialStatus;
use App\Enums\EsignProvider;
use App\Enums\EsignWebhookState;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use App\Jobs\ProcessAccessWebhookEvent;
use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\AccessProviderAccount;
use App\Models\CommunicationAccount;
use App\Models\CommsWebhookEvent;
use App\Models\ContractNotice;
use App\Models\EsignProviderAccount;
use App\Models\EsignWebhookEvent;
use App\Models\PaymentProviderAccount;
use App\Support\Access\AccessProviderRegistry;
use App\Support\Access\FakeAccessProvider;
use App\Support\Auth\Actor;
use App\Support\Auth\SystemActor;
use App\Support\ESign\ESignProviderRegistry;
use App\Support\ESign\FakeESignProvider;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\RbacFixture;
use Tests\Support\AutomationHarness;
use Tests\TestCase;

/**
 * S17-06 — scheduled commands, webhooks, and automation handlers run with no
 * authenticated employee (the 03:00 test).
 */
class SystemActorSpineTest extends TestCase
{
    use RefreshDatabase;

    private RbacFixture $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fx = RbacFixture::create($this);
        // Fixture signs contracts as the owner — clear so Actor::current() is SystemActor.
        $this->app['auth']->forgetGuards();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function all_scheduled_commands_run_headless(): void
    {
        $this->assertInstanceOf(SystemActor::class, Actor::current());

        $this->artisan('list');

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $events = $schedule->events();
        $this->assertNotEmpty($events, 'Expected scheduled events from bootstrap/app.php');

        $ran = [];
        foreach ($events as $event) {
            $signature = $this->commandSignature($event);
            $this->assertNotNull($signature, 'Could not parse artisan signature from: '.$event->command);

            $exit = Artisan::call($signature);
            $this->assertSame(
                0,
                $exit,
                "Scheduled command [{$signature}] failed headless (exit {$exit}): ".Artisan::output(),
            );
            $ran[] = $signature;
        }

        // Sanity: known commands from the current schedule must have been covered
        // (list itself is still read from Schedule — not hardcoded as the runner).
        foreach ([
            'system-events:maintain',
            'activitylog:prune-tiers',
            'automations:run-scheduled',
            'automations:resume-waiting',
            'contracts:activate',
            'billing:run',
            'autopay:collect',
            'delinquency:run',
            'comms:sweep-orphan-attachments',
            'comms:sweep-uncorrelated-call-intents',
            'agents:sweep-pending-actions',
            'whatsapp:sync-templates',
            'esign:sweep-completion-pending',
            'esign:sweep-expired',
            'access:sync',
        ] as $needle) {
            $this->assertTrue(
                collect($ran)->contains(fn (string $s): bool => str_starts_with($s, $needle) || str_contains($s, $needle)),
                "Expected scheduled command containing [{$needle}] to run; got: ".implode(', ', $ran),
            );
        }
    }

    #[Test]
    public function all_webhooks_run_headless(): void
    {
        $this->assertInstanceOf(SystemActor::class, Actor::current());

        Bus::fake([ProcessStripeWebhookEvent::class, ProcessAccessWebhookEvent::class]);

        // Stripe
        $stripe = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $this->fx->siteA->legal_entity_id,
            'webhook_secret' => 'whsec_spine',
            'account_token' => 'token_spine_'.str_repeat('s', 20),
        ]);
        $stripePayload = json_encode([
            'id' => 'evt_spine_1',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_spine_1', 'object' => 'payment_intent']],
        ], JSON_THROW_ON_ERROR);
        $ts = time();
        $sig = hash_hmac('sha256', "{$ts}.{$stripePayload}", 'whsec_spine');
        $this->call(
            'POST',
            '/api/webhooks/stripe/'.$stripe->account_token,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$sig}",
            ],
            content: $stripePayload,
        )->assertOk();
        $this->assertDatabaseHas('stripe_webhook_events', ['stripe_event_id' => 'evt_spine_1']);

        // E-sign
        $esignToken = Str::random(40);
        app(ESignProviderRegistry::class)->register('signable', FakeESignProvider::class);
        EsignProviderAccount::query()->create([
            'provider' => EsignProvider::Signable,
            'display_name' => 'Signable Spine',
            'credentials' => ['api_key' => 'fake'],
            'webhook_token' => $esignToken,
            'webhook_state' => EsignWebhookState::Configured,
            'status' => CredentialStatus::Connected,
            'is_active' => true,
        ]);
        $this->postJson('/api/webhooks/esign/'.$esignToken, [
            'event_id' => 'evt_esign_spine',
            'type' => 'envelope.viewed',
            'envelope_ref' => 'env_missing_ok',
        ])->assertOk();
        $this->assertTrue(EsignWebhookEvent::query()->where('provider_event_id', 'evt_esign_spine')->exists()
            || EsignWebhookEvent::query()->exists());

        // Access
        FakeAccessProvider::reset();
        app(AccessProviderRegistry::class)->register('sensorberg', FakeAccessProvider::class);
        $accessToken = Str::random(40);
        AccessProviderAccount::query()->create([
            'provider' => AccessProviderName::Sensorberg,
            'display_name' => 'Sensorberg Spine',
            'credentials' => ['api_key' => 'fake'],
            'webhook_token' => $accessToken,
            'webhook_state' => AccessWebhookState::Configured,
            'status' => CredentialStatus::Connected,
            'is_active' => true,
        ]);
        $this->postJson('/api/webhooks/access/'.$accessToken, [
            'event_id' => 'evt_access_spine',
            'type' => 'denied',
            'provider_point_id' => 'point-spine',
            'credential_ref' => 'cred-spine',
            'occurred_at' => now()->toIso8601String(),
        ])->assertOk();
        $this->assertDatabaseHas('access_webhook_events', ['provider_event_id' => 'evt_access_spine']);

        // Delivery (Brevo)
        $deliveryToken = 'tok-spine-delivery';
        CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'test-key'],
            'webhook_url_token' => $deliveryToken,
            'status' => CredentialStatus::Connected,
        ]);
        $this->postJson("/api/webhooks/brevo/{$deliveryToken}", [
            'event' => 'delivered',
            'message-id' => '<spine-orphan@example.com>',
            'email' => 'nobody@example.com',
            'date' => now()->toIso8601String(),
        ])->assertOk();
        $this->assertTrue(CommsWebhookEvent::query()->exists());
    }

    #[Test]
    public function automation_handlers_run_headless(): void
    {
        $this->assertInstanceOf(SystemActor::class, Actor::current());

        $contract = $this->fx->delinquencyA->contract()->with(['unitItem.item.site'])->firstOrFail();
        Gate::forUser(Actor::current())->authorize('vacate', $contract);

        AutomationHarness::load('record_notice_debt_chain')
            ->trigger('object_created', $this->fx->delinquencyA)
            ->assertRunStatus(AutomationRunStatus::Succeeded);

        $this->assertTrue(
            ContractNotice::query()->where('contract_id', $this->fx->delinquencyA->contract_id)->exists(),
        );
    }

    private function commandSignature(Event $event): ?string
    {
        $raw = (string) $event->command;

        // Laravel stores e.g. "'/usr/bin/php' 'artisan' billing:run --trigger=scheduled"
        if (preg_match("/artisan['\"]?\s+(.+)$/i", $raw, $matches)) {
            return trim($matches[1], " \t\"'");
        }

        // Fallback: already a signature.
        if (preg_match('/^[a-z0-9]+:[a-z0-9:\-]+/i', $raw)) {
            return $raw;
        }

        return null;
    }
}
