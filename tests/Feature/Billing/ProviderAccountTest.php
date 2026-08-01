<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\CredentialStatus;
use App\Enums\LogChannel;
use App\Jobs\ProcessStripeWebhookEvent;
use App\Models\Activity;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\PaymentProviderAccount;
use App\Models\Site;
use App\Models\StripeWebhookEvent;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Payments\PaymentsNotConfigured;
use App\Support\Payments\ProviderAccountResolver;
use App\Support\Payments\StripeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Mockery;
use Stripe\Exception\InvalidRequestException;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ProviderAccountTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private LegalEntity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        $this->actingAs($this->employee);
        $this->entity = LegalEntity::factory()->create(['legal_name' => 'Acme Storage SL']);
        Config::set('app.url', 'https://example.com');
    }

    public function test_connect_verify_status_paths(): void
    {
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('verifyBalance')
                ->once()
                ->with('sk_test_bad')
                ->andThrow(new InvalidRequestException('Invalid API Key provided'));

            $mock->shouldReceive('verifyBalance')->once()->with('sk_test_good')->andReturnNull();
            $mock->shouldReceive('retrieveAccount')
                ->once()
                ->with('sk_test_good')
                ->andReturn(['id' => 'acct_good_1']);

            $mock->shouldReceive('verifyBalance')->once()->with('sk_test_rotated')->andReturnNull();
            $mock->shouldReceive('retrieveAccount')
                ->once()
                ->with('sk_test_rotated')
                ->andReturn(['id' => 'acct_other_2']);

            $mock->shouldReceive('createWebhookEndpoint')
                ->once()
                ->andReturn(['id' => 'we_test_1', 'secret' => 'whsec_test_1']);

            $mock->shouldReceive('deleteWebhookEndpoint')
                ->once()
                ->with('sk_test_rotated', 'we_test_1')
                ->andReturnNull();
        });

        $error = $this->putJson("/api/legal-entities/{$this->entity->id}/stripe-settings", [
            'publishable_key' => 'pk_test_x',
            'secret_key' => 'sk_test_bad',
        ]);

        $error->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.last_error', 'Invalid API Key provided');

        $this->assertDatabaseHas('payment_provider_accounts', [
            'legal_entity_id' => $this->entity->id,
            'status' => CredentialStatus::Error->value,
        ]);

        $ok = $this->putJson("/api/legal-entities/{$this->entity->id}/stripe-settings", [
            'publishable_key' => 'pk_test_good',
            'secret_key' => 'sk_test_good',
        ]);

        $ok->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.provider_account_id', 'acct_good_1')
            ->assertJsonPath('data.provider_account_mismatch', false);

        $rotated = $this->putJson("/api/legal-entities/{$this->entity->id}/stripe-settings", [
            'secret_key' => 'sk_test_rotated',
        ]);

        $rotated->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.provider_account_id', 'acct_other_2')
            ->assertJsonPath('data.provider_account_mismatch', true);

        $webhook = $this->postJson("/api/legal-entities/{$this->entity->id}/stripe-settings/webhook");
        $webhook->assertOk()->assertJsonPath('data.webhook_configured', true);

        $account = PaymentProviderAccount::query()
            ->where('legal_entity_id', $this->entity->id)
            ->firstOrFail();
        $this->assertSame('we_test_1', $account->webhook_endpoint_id);
        $this->assertSame('whsec_test_1', $account->webhook_secret);

        $this->deleteJson("/api/legal-entities/{$this->entity->id}/stripe-settings")
            ->assertNoContent();

        $this->assertDatabaseMissing('payment_provider_accounts', [
            'legal_entity_id' => $this->entity->id,
        ]);
    }

    public function test_per_account_event_idempotency(): void
    {
        $entityB = LegalEntity::factory()->create();

        $accountA = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $this->entity->id,
            'webhook_secret' => 'whsec_a',
            'account_token' => 'token_account_a_'.str_repeat('a', 20),
        ]);
        $accountB = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $entityB->id,
            'webhook_secret' => 'whsec_b',
            'account_token' => 'token_account_b_'.str_repeat('b', 20),
        ]);

        Bus::fake([ProcessStripeWebhookEvent::class]);

        $payload = $this->stripeEventPayload('evt_shared_1');

        $this->postSignedWebhook($accountA->account_token, $payload, 'whsec_a')->assertOk();
        $this->postSignedWebhook($accountB->account_token, $payload, 'whsec_b')->assertOk();

        $this->assertSame(2, StripeWebhookEvent::query()->where('stripe_event_id', 'evt_shared_1')->count());

        $this->postSignedWebhook($accountA->account_token, $payload, 'whsec_a')->assertOk();

        $this->assertSame(
            1,
            StripeWebhookEvent::query()
                ->where('payment_provider_account_id', $accountA->id)
                ->where('stripe_event_id', 'evt_shared_1')
                ->count()
        );

        $this->postJson('/api/webhooks/stripe/unknown-token-zzzz', [])->assertNotFound();
    }

    public function test_archived_entity_acks_ignores(): void
    {
        $account = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $this->entity->id,
            'webhook_secret' => 'whsec_archived',
            'account_token' => 'token_archived_'.str_repeat('c', 20),
        ]);

        $this->entity->forceFill(['archived_at' => now()])->save();

        Bus::fake([ProcessStripeWebhookEvent::class]);

        $payload = $this->stripeEventPayload('evt_archived_1');

        $this->postSignedWebhook($account->account_token, $payload, 'whsec_archived')
            ->assertOk()
            ->assertJsonPath('message', 'ok');

        $this->assertSame(0, StripeWebhookEvent::query()->count());
        Bus::assertNotDispatched(ProcessStripeWebhookEvent::class);

        // Inactive account also acks-and-ignores.
        $this->entity->forceFill(['archived_at' => null])->save();
        $account->forceFill(['is_active' => false])->save();

        $this->postSignedWebhook($account->account_token, $payload, 'whsec_archived')->assertOk();

        $this->assertSame(0, StripeWebhookEvent::query()->count());
    }

    public function test_resolver_walks_and_fails_loudly(): void
    {
        $site = Site::factory()->create(['legal_entity_id' => $this->entity->id]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $this->employee->id,
        );
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $contract = Contract::factory()->create();
        $contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => $contract->start_date,
            'effective_to' => null,
        ]);

        try {
            ProviderAccountResolver::forContract($contract);
            $this->fail('Expected PaymentsNotConfigured');
        } catch (PaymentsNotConfigured $e) {
            $this->assertSame('Acme Storage SL', $e->legalEntityName);
            $this->assertSame($this->entity->id, $e->legalEntityId);
        }

        $account = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $this->entity->id,
        ]);

        $resolved = ProviderAccountResolver::forContract($contract->fresh());
        $this->assertTrue($resolved->is($account));
    }

    public function test_credential_discipline(): void
    {
        $this->mock(StripeClient::class, function (Mockery\MockInterface $mock): void {
            $mock->shouldReceive('verifyBalance')->twice()->andReturnNull();
            $mock->shouldReceive('retrieveAccount')
                ->twice()
                ->andReturn(['id' => 'acct_disc_1']);
            $mock->shouldReceive('deleteWebhookEndpoint')->zeroOrMoreTimes();
        });

        $create = $this->putJson("/api/legal-entities/{$this->entity->id}/stripe-settings", [
            'publishable_key' => 'pk_test_disc',
            'secret_key' => 'sk_test_discipline_abcd',
        ]);

        $create->assertOk()
            ->assertJsonPath('data.secret_key_masked', '••••••abcd')
            ->assertJsonPath('data.has_secret_key', true)
            ->assertJsonMissingPath('data.secret_key')
            ->assertJsonMissingPath('data.account_token');

        $this->assertNotNull(
            Activity::query()
                ->where('log_name', LogChannel::Core->value)
                ->where('description', 'payment_provider_account.created')
                ->first()
        );

        $blank = $this->putJson("/api/legal-entities/{$this->entity->id}/stripe-settings", [
            'publishable_key' => 'pk_test_updated',
            'secret_key' => '',
        ]);
        $blank->assertOk()->assertJsonPath('data.publishable_key', 'pk_test_updated');

        $account = PaymentProviderAccount::query()
            ->where('legal_entity_id', $this->entity->id)
            ->firstOrFail();
        $this->assertSame('sk_test_discipline_abcd', $account->secret_key);

        $this->putJson("/api/legal-entities/{$this->entity->id}/stripe-settings", [
            'secret_key' => 'sk_test_rotated_wxyz',
        ])->assertOk();

        $this->assertNotNull(
            Activity::query()
                ->where('description', 'payment_provider_account.rotated')
                ->first()
        );

        // Corrupt ciphertext → credentials_unreadable, not 500.
        DB::table('payment_provider_accounts')
            ->where('id', $account->id)
            ->update(['secret_key' => 'not-valid-laravel-ciphertext']);

        $show = $this->getJson("/api/legal-entities/{$this->entity->id}/stripe-settings");
        $show->assertOk()
            ->assertJsonPath('data.credentials_unreadable', true)
            ->assertJsonPath('data.has_secret_key', false);

        $this->deleteJson("/api/legal-entities/{$this->entity->id}/stripe-settings")
            ->assertNoContent();

        $this->assertNotNull(
            Activity::query()
                ->where('description', 'payment_provider_account.removed')
                ->first()
        );
    }

    private function stripeEventPayload(string $eventId): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'api_version' => '2024-06-20',
            'created' => time(),
            'type' => 'payment_intent.succeeded',
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'data' => [
                'object' => [
                    'id' => 'pi_test_1',
                    'object' => 'payment_intent',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function postSignedWebhook(string $accountToken, string $payload, string $secret): \Illuminate\Testing\TestResponse
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return $this->call(
            'POST',
            "/api/webhooks/stripe/{$accountToken}",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            content: $payload,
        );
    }
}
