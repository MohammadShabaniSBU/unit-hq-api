<?php

declare(strict_types=1);

namespace Tests\Feature\Copilot;

use App\Ai\Agents\CrmCopilotAgent;
use App\Models\CopilotConversation;
use App\Models\Employee;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\QueuedAgentPrompt;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CopilotDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        config(['ai.conversations.generate_title' => false]);
    }

    #[Test]
    public function message_dispatches_queued_agent(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => 'Dispatch',
            'site_scope_snapshot' => null,
        ]);

        CrmCopilotAgent::fake(['Hello from the queue.'])->preventStrayPrompts();

        $response = $this->postJson("/api/copilot/conversations/{$conversation->id}/messages", [
            'message' => 'Find my contacts',
            'client_message_id' => (string) Str::uuid(),
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.conversation_id', $conversation->id)
            ->assertJsonPath('data.channel', "private-copilot.{$conversation->id}")
            ->assertJsonStructure(['data' => ['call_id', 'conversation_id', 'channel']]);

        CrmCopilotAgent::assertQueued(function (QueuedAgentPrompt $prompt) use ($conversation): bool {
            return $prompt->prompt === 'Find my contacts'
                && $prompt->agent->currentConversation() === $conversation->id;
        });
    }

    #[Test]
    public function duplicate_client_message_id_dispatches_once(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => 'Idempotent',
            'site_scope_snapshot' => null,
        ]);

        Queue::fake();

        $payload = [
            'message' => 'Hello once',
            'client_message_id' => (string) Str::uuid(),
        ];

        $first = $this->postJson("/api/copilot/conversations/{$conversation->id}/messages", $payload);
        $first->assertAccepted();

        $second = $this->postJson("/api/copilot/conversations/{$conversation->id}/messages", $payload);
        $second->assertAccepted()
            ->assertJsonPath('data.call_id', $first->json('data.call_id'))
            ->assertJsonPath('data.conversation_id', $first->json('data.conversation_id'))
            ->assertJsonPath('data.channel', $first->json('data.channel'));

        Queue::assertPushedOn('ai', BroadcastAgent::class);
        Queue::assertPushed(BroadcastAgent::class, 1);
    }
}
