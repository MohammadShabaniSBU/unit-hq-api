<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Employee;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateAssistAuthTest extends TestCase
{
    use InteractsWithTemplateAssist;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        config(['ai.conversations.generate_title' => false]);
        Queue::fake();

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

    #[Test]
    public function owner_only_turn_and_channel(): void
    {
        $this->assistManager();
        [$family, $version] = $this->assistDraft();
        $sessionId = $this->openAssistSession($family);

        $turn = $this->postJson("/api/template-assist/sessions/{$sessionId}/turns", [
            'client_message_id' => 'msg-owner',
            'message' => 'Soften the tone',
            'template_version_id' => $version->id,
            'document' => $this->assistDocument(),
        ]);
        $turn->assertAccepted();
        $turnId = (int) $turn->json('data.turn_id');

        $this->getJson("/api/template-assist/turns/{$turnId}")->assertOk()
            ->assertJsonPath('data.status', 'running');

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-template-assist.'.$sessionId,
        ])->assertOk();

        $other = Employee::factory()->manager()->create();
        Sanctum::actingAs($other);

        $this->getJson("/api/template-assist/turns/{$turnId}")->assertNotFound();
        $this->postJson("/api/template-assist/turns/{$turnId}/status", [
            'status' => 'applied',
        ])->assertNotFound();
        $this->postJson("/api/template-assist/sessions/{$sessionId}/turns", [
            'client_message_id' => 'msg-other',
            'message' => 'Nope',
            'template_version_id' => $version->id,
            'document' => $this->assistDocument(),
        ])->assertNotFound();

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-template-assist.'.$sessionId,
        ])->assertForbidden();
    }
}
