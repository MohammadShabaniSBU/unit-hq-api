<?php

declare(strict_types=1);

namespace Tests\Feature\Facility;

use App\Models\Site;
use App\Models\VoiceBridgeToken;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class VoiceBridgeTokenControllerTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function index_is_scoped_to_the_site_and_omits_the_secret(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $ours = VoiceBridgeToken::factory()->create(['site_id' => $siteA->id]);
        VoiceBridgeToken::factory()->create(['site_id' => $siteB->id]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $response = $this->getJson("/api/sites/{$siteA->id}/voice-bridge-tokens");

        $response->assertOk()
            ->assertJsonStructure(['message', 'data'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ours->id)
            ->assertJsonMissingPath('data.0.secret');

        $this->assertArrayNotHasKey('secret', $ours->toArray());
        $this->assertArrayNotHasKey('secret_previous', $ours->toArray());
    }

    #[Test]
    public function store_returns_the_secret_once_inside_the_success_envelope(): void
    {
        $site = Site::factory()->create();
        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $response = $this->postJson("/api/sites/{$site->id}/voice-bridge-tokens", [
            'phone_number' => '+15551234567',
            'main_line_number' => '+15557654321',
            'voicemail_number' => '+15550001111',
            'label' => 'Front desk',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['message', 'data' => [
                'id',
                'token',
                'site_id',
                'phone_number',
                'main_line_number',
                'voicemail_number',
                'label',
                'is_revoked',
                'secret',
            ]])
            ->assertJsonPath('data.phone_number', '+15551234567')
            ->assertJsonPath('data.label', 'Front desk')
            ->assertJsonPath('data.is_revoked', false);

        $secret = $response->json('data.secret');
        $this->assertIsString($secret);
        $this->assertSame(40, strlen($secret));

        $token = VoiceBridgeToken::query()->findOrFail($response->json('data.id'));
        $this->assertSame($secret, $token->secret);
        $this->assertArrayNotHasKey('secret', $token->toArray());

        $this->getJson("/api/sites/{$site->id}/voice-bridge-tokens")
            ->assertOk()
            ->assertJsonMissingPath('data.0.secret');
    }

    #[Test]
    public function store_rejects_a_duplicate_phone_number_with_422(): void
    {
        $site = Site::factory()->create();
        VoiceBridgeToken::factory()->create(['phone_number' => '+15551234567']);
        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $this->postJson("/api/sites/{$site->id}/voice-bridge-tokens", [
            'phone_number' => '+15551234567',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    #[Test]
    public function update_changes_numbers_and_label_without_touching_the_secret(): void
    {
        $site = Site::factory()->create();
        $token = VoiceBridgeToken::factory()->create([
            'site_id' => $site->id,
            'phone_number' => '+15550000001',
            'label' => 'Old',
        ]);
        $originalSecret = $token->secret;

        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $this->patchJson("/api/sites/{$site->id}/voice-bridge-tokens/{$token->id}", [
            'phone_number' => '+15550000002',
            'main_line_number' => '+15550000003',
            'voicemail_number' => '+15550000004',
            'label' => 'Lobby',
        ])->assertOk()
            ->assertJsonStructure(['message', 'data'])
            ->assertJsonPath('data.phone_number', '+15550000002')
            ->assertJsonPath('data.label', 'Lobby')
            ->assertJsonMissingPath('data.secret');

        $token->refresh();
        $this->assertSame($originalSecret, $token->secret);
        $this->assertSame('+15550000002', $token->phone_number);
        $this->assertSame('Lobby', $token->label);
    }

    #[Test]
    public function regenerate_secret_rotates_and_returns_the_new_value_once(): void
    {
        $site = Site::factory()->create();
        $token = VoiceBridgeToken::factory()->create(['site_id' => $site->id]);
        $oldSecret = $token->secret;

        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $response = $this->postJson(
            "/api/sites/{$site->id}/voice-bridge-tokens/{$token->id}/regenerate-secret",
        );

        $response->assertOk()
            ->assertJsonStructure(['message', 'data' => ['id', 'secret']])
            ->assertJsonPath('data.id', $token->id);

        $newSecret = $response->json('data.secret');
        $this->assertIsString($newSecret);
        $this->assertSame(40, strlen($newSecret));
        $this->assertNotSame($oldSecret, $newSecret);

        $token->refresh();
        $this->assertSame($newSecret, $token->secret);
        $this->assertSame($oldSecret, $token->secret_previous);

        $this->getJson("/api/sites/{$site->id}/voice-bridge-tokens")
            ->assertOk()
            ->assertJsonMissingPath('data.0.secret');
    }

    #[Test]
    public function revoke_sets_revoked_at(): void
    {
        $site = Site::factory()->create();
        $token = VoiceBridgeToken::factory()->create(['site_id' => $site->id]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $this->postJson("/api/sites/{$site->id}/voice-bridge-tokens/{$token->id}/revoke")
            ->assertOk()
            ->assertJsonStructure(['message', 'data'])
            ->assertJsonPath('data.is_revoked', true)
            ->assertJsonMissingPath('data.secret');

        $token->refresh();
        $this->assertTrue($token->isRevoked());
        $this->assertNotNull($token->revoked_at);
    }

    #[Test]
    public function denies_without_credential_manage(): void
    {
        $site = Site::factory()->create();
        $token = VoiceBridgeToken::factory()->create(['site_id' => $site->id]);

        Sanctum::actingAs($this->employeeWithoutPermissions());

        $this->getJson("/api/sites/{$site->id}/voice-bridge-tokens")->assertForbidden();
        $this->postJson("/api/sites/{$site->id}/voice-bridge-tokens", [
            'phone_number' => '+15551111111',
        ])->assertForbidden();
        $this->patchJson("/api/sites/{$site->id}/voice-bridge-tokens/{$token->id}", [
            'label' => 'Nope',
        ])->assertForbidden();
        $this->postJson("/api/sites/{$site->id}/voice-bridge-tokens/{$token->id}/regenerate-secret")
            ->assertForbidden();
        $this->postJson("/api/sites/{$site->id}/voice-bridge-tokens/{$token->id}/revoke")
            ->assertForbidden();
    }

    #[Test]
    public function denies_when_credential_manage_is_scoped_to_another_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        VoiceBridgeToken::factory()->create(['site_id' => $siteA->id]);

        Sanctum::actingAs($this->employeeWithSiteScopedPermission(Permission::CredentialManage, $siteB));

        $this->getJson("/api/sites/{$siteA->id}/voice-bridge-tokens")->assertForbidden();
        $this->postJson("/api/sites/{$siteA->id}/voice-bridge-tokens", [
            'phone_number' => '+15552222222',
        ])->assertForbidden();
    }

    #[Test]
    public function returns_404_when_token_belongs_to_a_different_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $foreign = VoiceBridgeToken::factory()->create(['site_id' => $siteB->id]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::CredentialManage));

        $this->patchJson("/api/sites/{$siteA->id}/voice-bridge-tokens/{$foreign->id}", [
            'label' => 'Cross-site',
        ])->assertNotFound();
        $this->postJson("/api/sites/{$siteA->id}/voice-bridge-tokens/{$foreign->id}/regenerate-secret")
            ->assertNotFound();
        $this->postJson("/api/sites/{$siteA->id}/voice-bridge-tokens/{$foreign->id}/revoke")
            ->assertNotFound();
    }
}
