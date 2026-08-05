<?php

declare(strict_types=1);

namespace Tests\Feature\Copilot;

use App\Ai\Agents\CrmCopilotAgent;
use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Site;
use App\Support\Auth\Permission;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class CopilotConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        config(['ai.conversations.generate_title' => false]);
    }

    #[Test]
    public function starting_conversation_persists_participant(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/copilot/conversations', [
            'title' => 'Pipeline help',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Pipeline help');

        $conversation = CopilotConversation::query()->findOrFail($response->json('data.id'));

        $this->assertSame('employee', $conversation->participant_type);
        $this->assertSame($employee->id, (int) $conversation->participant_id);
    }

    #[Test]
    public function continuing_loads_prior_messages(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => 'Prior history',
            'site_scope_snapshot' => null,
        ]);

        $now = now();
        DB::table('agent_conversation_messages')->insert([
            [
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversation->id,
                'participant_type' => 'employee',
                'participant_id' => $employee->id,
                'agent' => CrmCopilotAgent::class,
                'role' => 'user',
                'content' => 'What contacts do I have?',
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'approval_state' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversation->id,
                'participant_type' => 'employee',
                'participant_id' => $employee->id,
                'agent' => CrmCopilotAgent::class,
                'role' => 'assistant',
                'content' => 'You have several contacts.',
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'approval_state' => null,
                'created_at' => $now->copy()->addSecond(),
                'updated_at' => $now->copy()->addSecond(),
            ],
        ]);

        $agent = (new CrmCopilotAgent($employee))->continue($conversation->id, as: $employee);
        $history = iterator_to_array($agent->messages());

        $this->assertCount(2, $history);
        $this->assertSame('What contacts do I have?', $history[0]->content);
        $this->assertSame('You have several contacts.', $history[1]->content);

        CrmCopilotAgent::fake(['Here are more details.']);

        (new CrmCopilotAgent($employee))
            ->continue($conversation->id, as: $employee)
            ->prompt('Tell me more');

        CrmCopilotAgent::assertPrompted('Tell me more');

        $this->assertSame(
            4,
            ConversationMessage::query()->where('conversation_id', $conversation->id)->count(),
        );
        $this->assertDatabaseHas('agent_conversation_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Tell me more',
        ]);

        // HTTP contract: 202 dispatch with client_message_id — no client-supplied history.
        CrmCopilotAgent::fake(['Another turn.']);
        $this->postJson("/api/copilot/conversations/{$conversation->id}/messages", [
            'message' => 'One more thing',
            'client_message_id' => (string) Str::uuid(),
        ])->assertAccepted()
            ->assertJsonPath('data.conversation_id', $conversation->id);
    }

    #[Test]
    public function other_employee_cannot_view_conversation(): void
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

        $this->getJson("/api/copilot/conversations/{$conversation->id}")
            ->assertForbidden();

        $this->postJson("/api/copilot/conversations/{$conversation->id}/messages", [
            'message' => 'Hello',
            'client_message_id' => (string) Str::uuid(),
        ])->assertForbidden();


        $this->deleteJson("/api/copilot/conversations/{$conversation->id}")
            ->assertForbidden();
    }

    #[Test]
    public function site_scope_snapshot_written_on_create(): void
    {
        $site = Site::factory()->create();
        $employee = Employee::factory()->withoutRoleGrant()->create();

        RbacSystemRoleSeeder::upsertSystemRoles();
        $roleId = (int) Role::query()->where('key', 'leasing_agent')->value('id');
        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $roleId,
            'site_id' => $site->id,
            'granted_by' => null,
        ]);

        $employee->forgetPermissionMap();
        Sanctum::actingAs($employee);

        $expectedScope = $employee->siteIdsFor(Permission::ContactView);
        $this->assertSame([$site->id], $expectedScope);

        $response = $this->postJson('/api/copilot/conversations');
        $response->assertCreated();

        $conversation = CopilotConversation::query()->findOrFail($response->json('data.id'));
        $this->assertSame([$site->id], $conversation->site_scope_snapshot);

        // Snapshot is audit-only: conversation remains listed even if snapshot
        // would not match a hypothetical site filter.
        $conversation->forceFill(['site_scope_snapshot' => [999_999]])->save();

        $this->getJson('/api/copilot/conversations')
            ->assertOk()
            ->assertJsonFragment(['id' => $conversation->id]);
    }

    #[Test]
    public function agent_has_no_messages_method(): void
    {
        $ref = new ReflectionClass(CrmCopilotAgent::class);

        $this->assertArrayHasKey(RemembersConversations::class, $ref->getTraits());

        $messages = $ref->getMethod('messages');
        $this->assertStringEndsWith(
            'RemembersConversations.php',
            $messages->getFileName() ?? '',
        );
        $this->assertNotSame(
            $ref->getFileName(),
            $messages->getFileName(),
            'CrmCopilotAgent must not declare its own messages() method',
        );
    }

    #[Test]
    public function conversation_list_scoped_to_employee(): void
    {
        $employeeA = Employee::factory()->manager()->create();
        $employeeB = Employee::factory()->manager()->create();

        $own = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employeeA->id,
            'title' => 'A conversation',
            'site_scope_snapshot' => null,
        ]);

        CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employeeB->id,
            'title' => 'B conversation',
            'site_scope_snapshot' => null,
        ]);

        Sanctum::actingAs($employeeA);

        $response = $this->getJson('/api/copilot/conversations');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $own->id])
            ->assertJsonMissing(['title' => 'B conversation']);
    }
}
