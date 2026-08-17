<?php

declare(strict_types=1);

namespace Tests\Feature\Copilot;

use App\Ai\Agents\CrmCopilotAgent;
use App\Models\CopilotConversation;
use App\Models\Employee;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\QueuedAgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CopilotApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        config(['ai.conversations.generate_title' => false]);
    }

    #[Test]
    public function approvable_tool_pauses_turn(): void
    {
        $employee = Employee::factory()->manager()->create();

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => 'Approvals',
            'site_scope_snapshot' => null,
        ]);

        CrmCopilotAgent::fake([
            AgentResponse::fakeWithPendingApprovals([
                new PendingApproval(
                    id: 'call_abc',
                    tool: 'CreateContact',
                    arguments: ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
                    reason: 'Create a contact',
                ),
            ]),
        ])->preventStrayPrompts();

        $response = (new CrmCopilotAgent($employee))
            ->continue($conversation->id, as: $employee)
            ->prompt('Please create Ada Lovelace');

        $this->assertTrue($response->hasPendingApprovals());
        $this->assertSame('call_abc', $response->pendingApprovals->first()->id);
        $this->assertSame('CreateContact', $response->pendingApprovals->first()->tool);
    }

    #[Test]
    public function approve_resumes_and_executes(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => 'Approve resume',
            'site_scope_snapshot' => null,
        ]);

        CrmCopilotAgent::fake(['Contact created.'])->preventStrayPrompts();

        $this->postJson("/api/copilot/conversations/{$conversation->id}/decisions", [
            'decisions' => [
                'call_abc' => ['action' => 'approve'],
            ],
        ])->assertAccepted();

        CrmCopilotAgent::assertQueued(function (QueuedAgentPrompt $prompt): bool {
            return $prompt->hasApprovalDecisions()
                && $prompt->approvalDecisions?->get('call_abc')?->isApproved() === true;
        });
    }

    #[Test]
    public function partial_decisions_reject_remaining(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => 'Partial',
            'site_scope_snapshot' => null,
        ]);

        CrmCopilotAgent::fake(['Continued after partial reject.'])->preventStrayPrompts();

        $this->postJson("/api/copilot/conversations/{$conversation->id}/decisions", [
            'decisions' => [
                'call_abc' => ['action' => 'approve'],
            ],
        ])->assertAccepted();

        CrmCopilotAgent::assertQueued(function (QueuedAgentPrompt $prompt): bool {
            if (! $prompt->hasApprovalDecisions() || $prompt->approvalDecisions === null) {
                return false;
            }

            return $prompt->approvalDecisions->get('call_abc')?->isApproved() === true
                && $prompt->approvalDecisions->get('*')?->isRejected() === true;
        });
    }

    #[Test]
    public function decisions_authorized_against_participant(): void
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

        CrmCopilotAgent::fake(['Should not run'])->preventStrayPrompts();

        $this->postJson("/api/copilot/conversations/{$conversation->id}/decisions", [
            'decisions' => [
                'call_abc' => ['action' => 'approve'],
            ],
        ])->assertForbidden();

        CrmCopilotAgent::assertNeverQueued();
    }

    #[Test]
    public function voice_source_on_decisions_constructs_spoken_agent(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => 'Voice resume',
            'site_scope_snapshot' => null,
        ]);

        CrmCopilotAgent::fake(['Task created.'])->preventStrayPrompts();

        $this->postJson("/api/copilot/conversations/{$conversation->id}/decisions", [
            'decisions' => [
                'call_abc' => ['action' => 'approve'],
            ],
            'source' => 'voice',
        ])->assertAccepted();

        CrmCopilotAgent::assertQueued(function (QueuedAgentPrompt $prompt): bool {
            $agent = $prompt->agent;

            return $agent instanceof CrmCopilotAgent
                && $agent->voice === true
                && str_contains((string) $agent->instructions(), 'at most two short spoken sentences');
        });
    }
}
