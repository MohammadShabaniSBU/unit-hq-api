<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Agents\CrmCopilotAgent;
use App\Models\Employee;
use App\Support\Ai\CopilotConversationStore;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CopilotConversationStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        config(['ai.conversations.generate_title' => false]);
    }

    #[Test]
    public function container_binds_copilot_overlay(): void
    {
        $this->assertInstanceOf(CopilotConversationStore::class, $this->app->make(ConversationStore::class));
    }

    #[Test]
    public function sequential_pause_does_not_attach_earlier_results_to_last_step_blocks(): void
    {
        $employee = Employee::factory()->manager()->create();
        $store = $this->app->make(ConversationStore::class);
        $conversationId = $store->storeConversation('employee', $employee->id, 'Pause replay');

        $now = now();
        DB::table('copilot_conversation_messages')->insert([
            [
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversationId,
                'participant_type' => 'employee',
                'participant_id' => $employee->id,
                'agent' => CrmCopilotAgent::class,
                'role' => 'user',
                'content' => 'Create Jaiver at Centro Madrid',
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
                'conversation_id' => $conversationId,
                'participant_type' => 'employee',
                'participant_id' => $employee->id,
                'agent' => CrmCopilotAgent::class,
                'role' => 'assistant',
                'content' => '',
                'attachments' => '[]',
                'tool_calls' => json_encode([
                    ['id' => 'toolu_fetch', 'name' => 'FetchObjects', 'arguments' => ['object' => 'contacts']],
                    ['id' => 'toolu_cal', 'name' => 'ResolveCalendar', 'arguments' => ['phrase' => 'next Monday']],
                    ['id' => 'toolu_contact', 'name' => 'CreateContact', 'arguments' => ['first_name' => 'Jaiver']],
                ]),
                'tool_results' => json_encode([
                    ['id' => 'toolu_fetch', 'name' => 'FetchObjects', 'arguments' => [], 'result' => '{"contacts":[]}'],
                    ['id' => 'toolu_cal', 'name' => 'ResolveCalendar', 'arguments' => [], 'result' => '{"iso":"2026-09-21"}'],
                ]),
                'usage' => '[]',
                'meta' => json_encode([
                    'provider' => 'anthropic',
                    'provider_content_blocks' => [
                        ['type' => 'thinking', 'thinking' => 'create the contact', 'signature' => 'sig'],
                        ['type' => 'tool_use', 'id' => 'toolu_contact', 'name' => 'CreateContact', 'input' => ['first_name' => 'Jaiver']],
                    ],
                ]),
                'approval_state' => json_encode(['pending' => ['toolu_contact' => 'Create a contact']]),
                'created_at' => $now->copy()->addSecond(),
                'updated_at' => $now->copy()->addSecond(),
            ],
        ]);

        $messages = $store->getLatestConversationMessages($conversationId, 20)->values();

        $this->assertTrue($messages[1] instanceof AssistantMessage);
        $this->assertSame(['toolu_fetch', 'toolu_cal'], $messages[1]->toolCalls->pluck('id')->all());
        $this->assertSame([], $messages[1]->providerContentBlocks);

        $this->assertTrue($messages[2] instanceof ToolResultMessage);
        $this->assertSame(['toolu_fetch', 'toolu_cal'], $messages[2]->toolResults->pluck('id')->all());

        $this->assertTrue($messages[3] instanceof AssistantMessage);
        $this->assertSame(['toolu_contact'], $messages[3]->toolCalls->pluck('id')->all());
        $this->assertSame('toolu_contact', $messages[3]->providerContentBlocks[1]['id'] ?? null);
        $this->assertCount(4, $messages);
    }
}
