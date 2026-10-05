<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AccessProviderName;
use App\Enums\AccessWebhookState;
use App\Enums\AnalyticsProvider;
use App\Enums\CredentialStatus;
use App\Enums\EsignProvider;
use App\Enums\EsignWebhookState;
use App\Models\AccessProviderAccount;
use App\Models\AiProviderAccount;
use App\Models\AnalyticsAccount;
use App\Models\CommunicationAccount;
use App\Models\Employee;
use App\Models\EsignProviderAccount;
use App\Models\LegalEntity;
use App\Models\PaymentProviderAccount;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationCredentialsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const AI_KEY = 'sk-ant-round-trip-secret';

    private const STRIPE_SECRET = 'sk_test_round_trip_secret';

    #[Test]
    public function export_and_import_round_trip_restores_keys_and_config(): void
    {
        $path = storage_path('framework/testing/integration-credentials.json');
        $employee = Employee::factory()->withoutRoleGrant()->create();
        $entity = LegalEntity::factory()->create(['tax_id' => 'B12345674']);
        $site = Site::factory()->create(['code' => 'MAD-01']);

        $ai = AiProviderAccount::query()->create([
            'provider' => 'anthropic',
            'display_name' => 'Main',
            'credentials' => ['api_key' => self::AI_KEY],
            'allowed_models' => ['claude-sonnet-5'],
            'default_model' => 'claude-sonnet-5',
            'is_default' => true,
            'connection_status' => CredentialStatus::Connected,
            'created_by' => $employee->id,
        ]);

        $payment = PaymentProviderAccount::factory()->create([
            'legal_entity_id' => $entity->id,
            'display_name' => 'Stripe',
            'publishable_key' => 'pk_test_round_trip',
            'secret_key' => self::STRIPE_SECRET,
            'webhook_secret' => 'whsec_round_trip',
            'webhook_endpoint_id' => 'we_round_trip',
            'provider_account_id' => 'acct_round_trip',
            'account_token' => 'stripe-account-token-round-trip',
            'status' => CredentialStatus::Connected,
            'is_active' => true,
        ]);

        $email = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Postmark,
            'is_active' => true,
            'credentials' => ['server_token' => 'postmark-server-token'],
            'webhook_url_token' => 'postmark-webhook-token',
            'status' => CredentialStatus::Connected,
        ]);

        $sms = CommunicationAccount::query()->create([
            'scope' => AccountScope::Site,
            'site_id' => $site->id,
            'channel' => Channel::Sms,
            'provider' => Provider::Twilio,
            'is_active' => true,
            'credentials' => ['auth_token' => 'twilio-auth-token'],
            'webhook_url_token' => 'twilio-webhook-token',
            'status' => CredentialStatus::Connected,
        ]);

        $esign = EsignProviderAccount::query()->create([
            'provider' => EsignProvider::Signable,
            'display_name' => 'Signable',
            'credentials' => ['api_key' => 'signable-api-key'],
            'webhook_token' => 'esign-webhook-token',
            'webhook_state' => EsignWebhookState::Configured,
            'webhook_endpoint_ids' => ['hook_1'],
            'status' => CredentialStatus::Connected,
            'is_active' => true,
        ]);

        $access = AccessProviderAccount::factory()->create([
            'provider' => AccessProviderName::Sensorberg,
            'display_name' => 'Sensorberg',
            'credentials' => ['client_secret' => 'sensorberg-client-secret'],
            'webhook_token' => 'access-webhook-token',
            'webhook_state' => AccessWebhookState::Configured,
            'webhook_endpoint_ids' => ['point_hook'],
            'status' => CredentialStatus::Connected,
            'is_active' => true,
        ]);

        $analytics = AnalyticsAccount::query()->create([
            'provider' => AnalyticsProvider::Metabase,
            'display_name' => 'Metabase',
            'base_url' => 'https://metabase.example.com',
            'private_base_url' => 'http://metabase.internal',
            'credentials' => ['api_key' => 'mb_api_key', 'embedding_secret_key' => 'embed-secret'],
            'is_default' => true,
            'connection_status' => CredentialStatus::Connected,
            'created_by' => $employee->id,
        ]);

        try {
            $this->artisan('integrations:credentials', ['action' => 'export', 'path' => $path])
                ->expectsOutputToContain('Exported ai: 1, payment: 1, communication: 2, esign: 1, access: 1, analytics: 1.')
                ->doesntExpectOutputToContain(self::AI_KEY)
                ->doesntExpectOutputToContain(self::STRIPE_SECRET)
                ->assertSuccessful();

            $this->assertSame(0600, fileperms($path) & 0777);

            $raw = (string) file_get_contents($path);
            $this->assertStringContainsString(self::AI_KEY, $raw);
            $this->assertStringContainsString(self::STRIPE_SECRET, $raw);

            $document = json_decode($raw, true);
            $this->assertSame(1, $document['version']);
            $this->assertSame('B12345674', $document['accounts']['payment'][0]['legal_entity_tax_id']);

            $ai->update([
                'credentials' => ['api_key' => 'wiped-ai'],
                'allowed_models' => ['claude-opus-5'],
                'default_model' => 'claude-opus-5',
                'is_default' => false,
                'connection_status' => CredentialStatus::Error,
            ]);
            $other = AiProviderAccount::query()->create([
                'provider' => 'anthropic',
                'display_name' => 'Other',
                'credentials' => ['api_key' => 'other-key-stays'],
                'allowed_models' => ['claude-opus-5'],
                'default_model' => 'claude-opus-5',
                'is_default' => true,
                'connection_status' => CredentialStatus::Connected,
            ]);

            $payment->delete();
            $entity->update(['archived_at' => now()]);
            $second = LegalEntity::factory()->create(['tax_id' => 'B12345674']);

            $email->update([
                'credentials' => ['server_token' => 'wiped-postmark'],
                'webhook_url_token' => 'wiped-postmark-hook',
                'is_active' => false,
            ]);
            $sms->update(['credentials' => ['auth_token' => 'wiped-twilio']]);
            $esign->update([
                'credentials' => ['api_key' => 'wiped-signable'],
                'webhook_token' => 'wiped-esign-hook',
            ]);
            $access->update([
                'credentials' => ['client_secret' => 'wiped-sensorberg'],
                'webhook_token' => 'wiped-access-hook',
            ]);
            $analytics->update([
                'credentials' => ['api_key' => 'wiped-mb'],
                'base_url' => 'https://wiped.example.com',
                'private_base_url' => null,
                'is_default' => false,
            ]);

            $this->artisan('integrations:credentials', ['action' => 'import', 'path' => $path])
                ->expectsOutputToContain('Imported ai: 1, payment: 1, communication: 2, esign: 1, access: 1, analytics: 1.')
                ->doesntExpectOutputToContain(self::AI_KEY)
                ->assertSuccessful();

            $ai->refresh();
            $this->assertSame(self::AI_KEY, $ai->credentials['api_key']);
            $this->assertSame(['claude-sonnet-5'], $ai->allowed_models);
            $this->assertSame('claude-sonnet-5', $ai->default_model);
            $this->assertTrue($ai->is_default);
            $this->assertSame(CredentialStatus::Connected, $ai->connection_status);
            $this->assertSame($employee->id, $ai->created_by);

            $other->refresh();
            $this->assertSame('other-key-stays', $other->credentials['api_key']);
            $this->assertFalse($other->is_default);

            $restored = PaymentProviderAccount::query()->where('legal_entity_id', $second->id)->first();
            $this->assertNotNull($restored);
            $this->assertNotSame($payment->id, $restored->id);
            $this->assertSame(self::STRIPE_SECRET, $restored->secret_key);
            $this->assertSame('whsec_round_trip', $restored->webhook_secret);
            $this->assertSame('pk_test_round_trip', $restored->publishable_key);
            $this->assertSame('stripe-account-token-round-trip', $restored->account_token);
            $this->assertSame('we_round_trip', $restored->webhook_endpoint_id);
            $this->assertSame('acct_round_trip', $restored->provider_account_id);
            $this->assertTrue($restored->is_active);
            $this->assertSame(CredentialStatus::Connected, $restored->status);

            $email->refresh();
            $this->assertSame('postmark-server-token', $email->credentials['server_token']);
            $this->assertSame('postmark-webhook-token', $email->webhook_url_token);
            $this->assertTrue($email->is_active);

            $sms->refresh();
            $this->assertSame($site->id, $sms->site_id);
            $this->assertSame('twilio-auth-token', $sms->credentials['auth_token']);

            $esign->refresh();
            $this->assertSame('signable-api-key', $esign->credentials['api_key']);
            $this->assertSame('esign-webhook-token', $esign->webhook_token);
            $this->assertSame(['hook_1'], $esign->webhook_endpoint_ids);

            $access->refresh();
            $this->assertSame('sensorberg-client-secret', $access->credentials['client_secret']);
            $this->assertSame('access-webhook-token', $access->webhook_token);

            $analytics->refresh();
            $this->assertSame('mb_api_key', $analytics->credentials['api_key']);
            $this->assertSame('embed-secret', $analytics->credentials['embedding_secret_key']);
            $this->assertSame('https://metabase.example.com', $analytics->base_url);
            $this->assertSame('http://metabase.internal', $analytics->private_base_url);
            $this->assertTrue($analytics->is_default);
            $this->assertSame($employee->id, $analytics->created_by);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function import_rolls_back_when_a_legal_entity_is_missing(): void
    {
        $path = storage_path('framework/testing/integration-credentials-rollback.json');
        $entity = LegalEntity::factory()->create(['tax_id' => 'B12345674']);

        $ai = AiProviderAccount::query()->create([
            'provider' => 'anthropic',
            'display_name' => 'Main',
            'credentials' => ['api_key' => self::AI_KEY],
            'allowed_models' => ['claude-sonnet-5'],
            'default_model' => 'claude-sonnet-5',
            'is_default' => true,
            'connection_status' => CredentialStatus::Connected,
        ]);

        $payment = PaymentProviderAccount::factory()->create([
            'legal_entity_id' => $entity->id,
            'display_name' => 'Stripe',
            'secret_key' => self::STRIPE_SECRET,
            'publishable_key' => 'pk_test_round_trip',
            'account_token' => 'stripe-account-token-rollback',
            'status' => CredentialStatus::Connected,
        ]);

        try {
            $this->artisan('integrations:credentials', ['action' => 'export', 'path' => $path])
                ->assertSuccessful();

            $ai->update(['credentials' => ['api_key' => 'mutated-ai-key']]);
            $payment->update(['secret_key' => 'mutated-stripe-key']);
            $entity->update(['tax_id' => 'B99999999']);

            $this->artisan('integrations:credentials', ['action' => 'import', 'path' => $path])
                ->expectsOutputToContain('Legal entity B12345674 was not found.')
                ->doesntExpectOutputToContain(self::AI_KEY)
                ->doesntExpectOutputToContain('mutated-ai-key')
                ->assertFailed();

            $this->assertSame('mutated-ai-key', $ai->fresh()->credentials['api_key']);
            $this->assertSame('mutated-stripe-key', $payment->fresh()->secret_key);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function export_skips_unreadable_credentials(): void
    {
        $path = storage_path('framework/testing/integration-credentials-unreadable.json');

        AiProviderAccount::query()->create([
            'provider' => 'anthropic',
            'display_name' => 'Main',
            'credentials' => ['api_key' => self::AI_KEY],
            'allowed_models' => ['claude-sonnet-5'],
            'default_model' => 'claude-sonnet-5',
            'connection_status' => CredentialStatus::Connected,
        ]);

        $broken = AiProviderAccount::query()->create([
            'provider' => 'anthropic',
            'display_name' => 'Broken',
            'credentials' => ['api_key' => 'will-be-corrupted'],
            'allowed_models' => [],
            'connection_status' => CredentialStatus::Error,
        ]);

        DB::table('ai_provider_accounts')->where('id', $broken->id)->update([
            'credentials' => 'not-valid-ciphertext',
        ]);

        try {
            $this->artisan('integrations:credentials', ['action' => 'export', 'path' => $path])
                ->expectsOutputToContain('Skipped AI account "Broken" because its credentials could not be decrypted.')
                ->expectsOutputToContain('Exported ai: 1, payment: 0, communication: 0, esign: 0, access: 0, analytics: 0.')
                ->doesntExpectOutputToContain(self::AI_KEY)
                ->assertSuccessful();

            $document = json_decode((string) file_get_contents($path), true);
            $names = array_column($document['accounts']['ai'], 'display_name');
            $this->assertSame(['Main'], $names);
            $this->assertStringContainsString(self::AI_KEY, (string) file_get_contents($path));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function unknown_action_fails(): void
    {
        $this->artisan('integrations:credentials', ['action' => 'sync'])
            ->expectsOutputToContain('Action must be export or import.')
            ->assertFailed();
    }
}
