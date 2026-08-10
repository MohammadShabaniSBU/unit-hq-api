<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiProviderAccount;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class AiProviderAccountControllerTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    private function fakeAnthropicModels(array $ids = ['claude-sonnet-5', 'claude-opus-5', 'claude-fable-5']): void
    {
        Http::fake([
            'api.anthropic.com/v1/models*' => Http::response([
                'data' => array_map(fn ($id) => ['id' => $id, 'type' => 'model', 'display_name' => $id], $ids),
                'has_more' => false,
                'first_id' => $ids[0] ?? null,
                'last_id' => $ids[array_key_last($ids)] ?? null,
            ], 200),
        ]);
    }

    #[Test]
    public function store_creates_account_and_populates_discovered_models(): void
    {
        $this->fakeAnthropicModels();
        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $response = $this->postJson('/api/settings/ai-provider-accounts', [
            'provider' => 'anthropic',
            'display_name' => 'Main subscription',
            'credentials' => ['api_key' => 'sk-ant-real-key-1234'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.connection_status', 'connected');
        $response->assertJsonPath('data.allowed_models', ['claude-sonnet-5', 'claude-opus-5', 'claude-fable-5']);
        $response->assertJsonPath('data.default_model', 'claude-sonnet-5');

        $this->assertStringNotContainsString('sk-ant-real-key-1234', $response->getContent());
    }

    #[Test]
    public function store_denies_without_credential_manage(): void
    {
        $this->fakeAnthropicModels();
        Sanctum::actingAs($this->employeeWithoutPermissions());

        $this->postJson('/api/settings/ai-provider-accounts', [
            'provider' => 'anthropic',
            'display_name' => 'Main subscription',
            'credentials' => ['api_key' => 'sk-ant-real-key-1234'],
        ])->assertForbidden();

        $this->assertSame(0, AiProviderAccount::query()->count());
    }

    #[Test]
    public function store_records_error_status_when_key_is_rejected(): void
    {
        Http::fake([
            'api.anthropic.com/v1/models*' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401),
        ]);
        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $response = $this->postJson('/api/settings/ai-provider-accounts', [
            'provider' => 'anthropic',
            'display_name' => 'Bad key',
            'credentials' => ['api_key' => 'sk-ant-bad'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.connection_status', 'error');
        $response->assertJsonPath('data.allowed_models', []);
        $response->assertJsonPath('data.default_model', null);
    }

    #[Test]
    public function update_rejects_default_model_outside_allowed_models(): void
    {
        $this->fakeAnthropicModels();
        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $account = AiProviderAccount::query()->create([
            'provider' => 'anthropic',
            'display_name' => 'Main',
            'credentials' => ['api_key' => 'sk-ant-key'],
            'allowed_models' => ['claude-sonnet-5', 'claude-opus-5'],
            'default_model' => 'claude-sonnet-5',
            'connection_status' => 'connected',
        ]);

        $this->patchJson("/api/settings/ai-provider-accounts/{$account->id}", [
            'allowed_models' => ['claude-sonnet-5'],
            'default_model' => 'claude-fable-5',
        ])->assertStatus(422);
    }

    #[Test]
    public function update_with_blank_credential_leaves_stored_key_unchanged(): void
    {
        $this->fakeAnthropicModels();
        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $account = AiProviderAccount::query()->create([
            'provider' => 'anthropic',
            'display_name' => 'Main',
            'credentials' => ['api_key' => 'sk-ant-original'],
            'allowed_models' => ['claude-sonnet-5'],
            'default_model' => 'claude-sonnet-5',
            'connection_status' => 'connected',
        ]);

        $this->patchJson("/api/settings/ai-provider-accounts/{$account->id}", [
            'credentials' => ['api_key' => ''],
        ])->assertOk();

        $this->assertSame('sk-ant-original', $account->fresh()->credentials['api_key']);
    }

    #[Test]
    public function set_default_requires_a_default_model(): void
    {
        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $account = AiProviderAccount::query()->create([
            'provider' => 'anthropic',
            'display_name' => 'Unverified',
            'credentials' => ['api_key' => 'sk-ant-key'],
            'allowed_models' => [],
            'default_model' => null,
            'connection_status' => 'error',
        ]);

        $this->postJson("/api/settings/ai-provider-accounts/{$account->id}/default")
            ->assertStatus(422);
    }

    #[Test]
    public function set_default_demotes_the_previous_default(): void
    {
        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $a = AiProviderAccount::query()->create([
            'provider' => 'anthropic', 'display_name' => 'A',
            'credentials' => ['api_key' => 'k1'], 'allowed_models' => ['claude-sonnet-5'],
            'default_model' => 'claude-sonnet-5', 'connection_status' => 'connected', 'is_default' => true,
        ]);
        $b = AiProviderAccount::query()->create([
            'provider' => 'anthropic', 'display_name' => 'B',
            'credentials' => ['api_key' => 'k2'], 'allowed_models' => ['claude-opus-5'],
            'default_model' => 'claude-opus-5', 'connection_status' => 'connected',
        ]);

        $this->postJson("/api/settings/ai-provider-accounts/{$b->id}/default")->assertOk();

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
    }

    #[Test]
    public function destroy_archives_instead_of_deleting(): void
    {
        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $account = AiProviderAccount::query()->create([
            'provider' => 'anthropic', 'display_name' => 'Main',
            'credentials' => ['api_key' => 'k1'], 'allowed_models' => ['claude-sonnet-5'],
            'default_model' => 'claude-sonnet-5', 'connection_status' => 'connected', 'is_default' => true,
        ]);

        $this->deleteJson("/api/settings/ai-provider-accounts/{$account->id}")->assertOk();

        $fresh = $account->fresh();
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->archived_at);
        $this->assertFalse($fresh->is_default);
    }
}
