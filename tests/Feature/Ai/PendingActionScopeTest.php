<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AgentPendingAction;
use App\Models\AgentToolInvocation;
use App\Models\Employee;
use App\Models\Site;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\PendingActionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesTwoSiteRbacFixture;
use Tests\TestCase;

class PendingActionScopeTest extends TestCase
{
    use CreatesTwoSiteRbacFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoSiteRbacFixture();
    }

    #[Test]
    public function site_manager_cannot_list_show_or_approve_another_sites_proposal(): void
    {
        $atA = $this->plantPending($this->siteA, AgentOrigin::Inbox);
        $atB = $this->plantPending($this->siteB, AgentOrigin::Inbox);

        $manager = Employee::factory()->withoutRoleGrant()->create();
        $this->grantRole($manager, 'site_manager', $this->siteA);
        $manager->forgetPermissionMap();
        Sanctum::actingAs($manager);

        $listed = $this->getJson('/api/agent-pending-actions')->assertOk()->json('data');
        $ids = array_column($listed, 'id');
        $this->assertContains($atA->id, $ids);
        $this->assertNotContains($atB->id, $ids);

        $this->getJson("/api/agent-pending-actions/{$atB->id}")->assertNotFound();
        $this->postJson("/api/agent-pending-actions/{$atB->id}/approve")->assertNotFound();
        $this->postJson("/api/agent-pending-actions/{$atB->id}/reject")->assertNotFound();

        $this->getJson("/api/agent-pending-actions/{$atA->id}")->assertOk();
    }

    #[Test]
    public function demo_origin_is_absent_from_index_and_badge_but_show_is_allowed(): void
    {
        $live = $this->plantPending($this->siteA, AgentOrigin::Inbox);
        $demo = $this->plantPending($this->siteA, AgentOrigin::Demo);

        Sanctum::actingAs($this->owner);

        $listed = $this->getJson('/api/agent-pending-actions')->assertOk()->json('data');
        $ids = array_column($listed, 'id');
        $this->assertContains($live->id, $ids);
        $this->assertNotContains($demo->id, $ids);

        $this->getJson('/api/agent-pending-actions/badge')
            ->assertOk()
            ->assertJsonPath('data.pending', 1);

        $this->getJson("/api/agent-pending-actions/{$demo->id}")->assertOk();
        $this->postJson("/api/agent-pending-actions/{$demo->id}/reject")->assertOk();
    }

    private function plantPending(Site $site, AgentOrigin $origin): AgentPendingAction
    {
        $conversation = AgentConversation::factory()->create([
            'site_id' => $site->id,
            'origin' => $origin,
        ]);
        $invocation = AgentToolInvocation::factory()->create([
            'agent_conversation_id' => $conversation->id,
            'tool_key' => 'test.propose',
        ]);

        return AgentPendingAction::factory()->create([
            'agent_conversation_id' => $conversation->id,
            'agent_tool_invocation_id' => $invocation->id,
            'ai_agent_id' => $conversation->ai_agent_id,
            'site_id' => $site->id,
            'tool_key' => 'test.propose',
            'payload' => ['site_id' => $site->id],
        ]);
    }
}
