<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentPendingAction;
use App\Models\AiAgent;
use App\Support\Ai\Enums\PendingActionStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Enums\WritePolicyMode;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Tools\ToolDispatcher;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\SetsUpProposableReservation;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class AgentWritePolicyUpdateTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;
    use SetsUpProposableReservation;

    #[Test]
    public function upserts_a_write_policy_for_an_agent_write_tool(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'sales', 'name' => 'Sales']);
        $employee = $this->employeeWithPermission(Permission::SettingsManage);
        Sanctum::actingAs($employee);

        $this->putJson("/api/ai/agents/{$agent->id}/write-policies", [
            'tool_key' => 'sales.create_offer',
            'mode' => WritePolicyMode::Commit->value,
            'max_per_conversation' => 3,
            'max_per_day' => 40,
            'min_verification' => VerificationLevel::ChannelAsserted->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $agent->id);

        $this->assertDatabaseHas('agent_write_policies', [
            'ai_agent_id' => $agent->id,
            'tool_key' => 'sales.create_offer',
            'mode' => WritePolicyMode::Commit->value,
            'max_per_conversation' => 3,
            'max_per_day' => 40,
            'min_verification' => VerificationLevel::ChannelAsserted->value,
            'updated_by_employee_id' => $employee->id,
        ]);
    }

    #[Test]
    public function rejects_a_min_verification_below_the_tool_floor(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'sales', 'name' => 'Sales']);
        Sanctum::actingAs($this->employeeWithPermission(Permission::SettingsManage));

        $this->putJson("/api/ai/agents/{$agent->id}/write-policies", [
            'tool_key' => 'sales.create_reservation',
            'mode' => WritePolicyMode::Propose->value,
            'min_verification' => VerificationLevel::Anonymous->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['min_verification']);
    }

    #[Test]
    public function rejects_propose_on_a_non_proposable_tool(): void
    {
        $agent = AiAgent::factory()->create(['key' => 'sales', 'name' => 'Sales']);
        Sanctum::actingAs($this->employeeWithPermission(Permission::SettingsManage));

        $this->putJson("/api/ai/agents/{$agent->id}/write-policies", [
            'tool_key' => 'crm.create_contact',
            'mode' => WritePolicyMode::Propose->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mode']);
    }

    #[Test]
    public function agent_action_approve_without_settings_manage_can_approve_but_not_put_policies(): void
    {
        $sales = AiAgent::factory()->create(['key' => 'sales', 'name' => 'Sales']);
        $approver = $this->employeeWithPermission(Permission::AgentActionApprove);

        $pending = $this->queueProposal();

        Sanctum::actingAs($approver);

        $this->putJson("/api/ai/agents/{$sales->id}/write-policies", [
            'tool_key' => 'sales.create_offer',
            'mode' => WritePolicyMode::Commit->value,
        ])->assertForbidden();

        $this->postJson("/api/agent-pending-actions/{$pending->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', PendingActionStatus::Approved->value);
    }

    private function queueProposal(): AgentPendingAction
    {
        $ctx = $this->setUpProposableReservation();
        $result = app(ToolDispatcher::class)->dispatch(
            $ctx->definition,
            $ctx->principal,
            'test.create_reservation',
            $this->reservationArgs(),
            $ctx,
        );

        $this->assertSame(CannedReply::pendingApproval('en'), $result->display);
        $this->recordInvocation($ctx, 'test.create_reservation', $this->reservationArgs(), $result, $ctx->principal);

        return AgentPendingAction::query()->latest('id')->firstOrFail();
    }
}
