<?php

declare(strict_types=1);

namespace Tests\Feature\Copilot;

use App\Models\CopilotVoiceSession;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Site;
use App\Support\Auth\Permission;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CopilotVoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function token_forbidden_without_voice_permission(): void
    {
        $employee = Employee::factory()->withoutRoleGrant()->create();
        $site = Site::factory()->create();
        $roleId = (int) Role::query()->where('key', 'leasing_agent')->value('id');
        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $roleId,
            'site_id' => $site->id,
            'granted_by' => null,
        ]);
        Sanctum::actingAs($employee);

        $this->assertFalse($employee->fresh()?->can(Permission::CopilotVoiceUse->value));

        $this->postJson('/api/copilot/voice/token')->assertForbidden();
    }

    #[Test]
    public function token_unconfigured_when_key_absent(): void
    {
        config(['services.vocal_bridge.key' => null]);
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $this->postJson('/api/copilot/voice/token')
            ->assertStatus(422)
            ->assertJsonPath('message', 'errors.voice.not_configured');
    }

    #[Test]
    public function token_unavailable_on_upstream_failure_never_leaks_key(): void
    {
        $secret = 'vb_secret_never_leak';
        config([
            'services.vocal_bridge.key' => $secret,
            'services.vocal_bridge.token_url' => 'https://vocalbridgeai.com/api/v1/token',
        ]);
        Http::fake([
            'https://vocalbridgeai.com/api/v1/token' => Http::response(['error' => 'upstream'], 503),
        ]);

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = [
                'level' => $event->level,
                'message' => $event->message,
                'context' => $event->context,
            ];
        });

        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/copilot/voice/token');
        $response->assertStatus(502)
            ->assertJsonPath('message', 'errors.voice.token_unavailable');

        $this->assertStringNotContainsString($secret, (string) $response->getContent());
        $this->assertStringNotContainsString($secret, json_encode($logged, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function session_update_rejects_other_employee(): void
    {
        $owner = Employee::factory()->manager()->create();
        $other = Employee::factory()->manager()->create();

        $session = CopilotVoiceSession::query()->create([
            'employee_id' => $owner->id,
            'started_at' => now(),
        ]);

        Sanctum::actingAs($other);

        $this->patchJson("/api/copilot/voice/sessions/{$session->id}", [
            'end_reason' => 'hangup',
            'duration_seconds' => 12,
            'turn_count' => 1,
        ])->assertForbidden();

        $this->assertNull($session->fresh()?->ended_at);
    }

    #[Test]
    public function session_update_rejects_second_write_after_ended(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $session = CopilotVoiceSession::query()->create([
            'employee_id' => $employee->id,
            'started_at' => now(),
        ]);

        $this->patchJson("/api/copilot/voice/sessions/{$session->id}", [
            'end_reason' => 'hangup',
            'duration_seconds' => 12,
            'turn_count' => 1,
        ])->assertOk();

        $endedAt = $session->fresh()?->ended_at;
        $this->assertNotNull($endedAt);

        $this->patchJson("/api/copilot/voice/sessions/{$session->id}", [
            'end_reason' => 'error',
            'duration_seconds' => 99,
            'turn_count' => 9,
        ])->assertStatus(409);

        $fresh = $session->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($endedAt->equalTo($fresh->ended_at));
        $this->assertSame('hangup', $fresh->end_reason);
        $this->assertSame(12, $fresh->duration_seconds);
        $this->assertSame(1, $fresh->turn_count);
    }
}
