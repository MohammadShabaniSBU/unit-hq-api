<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentWritePolicy;
use App\Models\Contact;
use App\Models\Task;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\ToolDispatcher;
use App\Support\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\Support\Ai\SpyTool;
use Tests\Support\Ai\TestAgentDefinition;
use Tests\TestCase;

class AgentIdempotencyTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function identical_normalised_args_in_one_conversation_produce_one_row_and_a_replay(): void
    {
        $contact = Contact::factory()->create();
        $principal = AgentPrincipal::verified($contact->id, null, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        $args = [
            'title' => 'Call back',
            'related_to_type' => 'contact',
            'related_to_id' => $contact->id,
        ];

        $first = $this->dispatchTool('sales', 'crm.create_task', $principal, $args, $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $first->status);
        $this->assertFalse($first->replayed);
        $this->recordInvocation($ctx, 'crm.create_task', $args, $first, $principal);

        $second = $this->dispatchTool('sales', 'crm.create_task', $principal, $args, $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $second->status);
        $this->assertTrue($second->replayed);
        $this->assertTrue($second->data['replayed']);
        $this->assertSame(1, Task::query()->count());
        $this->assertSame($first->idempotencyKey, $second->idempotencyKey);
    }

    #[Test]
    public function string_and_integer_ids_share_an_idempotency_key(): void
    {
        $contact = Contact::factory()->create();
        $principal = AgentPrincipal::verified($contact->id, null, 'en');
        $ctx = $this->writeContext($principal, 'sales');

        $first = $this->dispatchTool('sales', 'crm.create_task', $principal, [
            'title' => 'Call back',
            'related_to_type' => 'contact',
            'related_to_id' => $contact->id,
        ], $ctx);
        $this->recordInvocation($ctx, 'crm.create_task', [
            'title' => 'Call back',
            'related_to_type' => 'contact',
            'related_to_id' => $contact->id,
        ], $first, $principal);

        $second = $this->dispatchTool('sales', 'crm.create_task', $principal, [
            'title' => 'Call back',
            'related_to_type' => 'contact',
            'related_to_id' => (string) $contact->id,
        ], $ctx);

        $this->assertTrue($second->replayed);
        $this->assertSame(1, Task::query()->count());
        $this->assertSame($first->idempotencyKey, $second->idempotencyKey);
    }

    #[Test]
    public function different_conversations_do_not_collide(): void
    {
        $contact = Contact::factory()->create();
        $principal = AgentPrincipal::verified($contact->id, null, 'en');
        $args = [
            'title' => 'Call back',
            'related_to_type' => 'contact',
            'related_to_id' => $contact->id,
        ];

        $firstCtx = $this->writeContext($principal, 'sales');
        $first = $this->dispatchTool('sales', 'crm.create_task', $principal, $args, $firstCtx);
        $this->recordInvocation($firstCtx, 'crm.create_task', $args, $first, $principal);

        $secondCtx = $this->writeContext($principal, 'sales');
        $second = $this->dispatchTool('sales', 'crm.create_task', $principal, $args, $secondCtx);

        $this->assertFalse($second->replayed);
        $this->assertSame(ToolInvocationStatus::Ok, $second->status);
        $this->assertSame(2, Task::query()->count());
        $this->assertNotSame($first->idempotencyKey, $second->idempotencyKey);
    }

    #[Test]
    public function read_tools_never_set_an_idempotency_key(): void
    {
        $spy = new SpyTool(
            key: 'test.read',
            required: VerificationLevel::Anonymous,
            contactKeys: [],
            throwOnHandle: false,
            write: false,
            schema: [
                'n' => [
                    'type' => 'integer',
                    'required' => true,
                    'description' => 'Discriminator',
                ],
            ],
        );
        $definition = new TestAgentDefinition('test-read', ['test.read']);
        app(ToolRegistry::class)->register($spy);
        app(AgentRegistry::class)->register($definition);

        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'test-read');

        $result = app(ToolDispatcher::class)->dispatch($definition, $principal, 'test.read', ['n' => 1], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertNull($result->idempotencyKey);
        $this->assertFalse($result->replayed);
    }

    #[Test]
    public function retry_at_max_per_conversation_replays_instead_of_quota_exceeded(): void
    {
        $contact = Contact::factory()->create();
        $principal = AgentPrincipal::verified($contact->id, null, 'en');
        $ctx = $this->writeContext($principal, 'sales');
        AgentWritePolicy::factory()->create([
            'ai_agent_id' => $ctx->agent->id,
            'tool_key' => 'crm.create_task',
            'max_per_conversation' => 1,
        ]);
        $ctx->agent->load('writePolicies');

        $args = [
            'title' => 'Call back',
            'related_to_type' => 'contact',
            'related_to_id' => $contact->id,
        ];

        $first = $this->dispatchTool('sales', 'crm.create_task', $principal, $args, $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $first->status);
        $this->recordInvocation($ctx, 'crm.create_task', $args, $first, $principal);

        $retry = $this->dispatchTool('sales', 'crm.create_task', $principal, $args, $ctx);
        $this->assertSame(ToolInvocationStatus::Ok, $retry->status);
        $this->assertTrue($retry->replayed);
        $this->assertNotSame(ToolDeniedReason::QuotaExceeded, $retry->deniedReason);
        $this->assertSame(1, Task::query()->count());
    }
}
