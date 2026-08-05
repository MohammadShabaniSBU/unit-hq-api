<?php

declare(strict_types=1);

namespace Tests\Feature\Copilot;

use App\Models\CopilotConversation;
use App\Models\Employee;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CopilotChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();

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
    public function participant_may_subscribe(): void
    {
        $employee = Employee::factory()->manager()->create();

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => 'Mine',
            'site_scope_snapshot' => null,
        ]);

        Sanctum::actingAs($employee);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-copilot.'.$conversation->id,
        ])->assertOk();
    }

    #[Test]
    public function non_participant_may_not_subscribe(): void
    {
        $owner = Employee::factory()->manager()->create();
        $other = Employee::factory()->manager()->create();

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $owner->id,
            'title' => 'Private',
            'site_scope_snapshot' => null,
        ]);

        Sanctum::actingAs($other);

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-copilot.'.$conversation->id,
        ])->assertForbidden();
    }
}
