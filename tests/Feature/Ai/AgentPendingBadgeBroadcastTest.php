<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Events\AgentPendingBadgeUpdated;
use App\Events\InboxBadgeUpdated;
use App\Models\AgentPendingAction;
use App\Models\Employee;
use App\Support\Ai\AgentPendingBadgeBroadcast;
use App\Support\Ai\Enums\PendingActionStatus;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Tools\ToolDispatcher;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\SetsUpProposableReservation;
use Tests\TestCase;

class AgentPendingBadgeBroadcastTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpProposableReservation;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function approver_may_subscribe(): void
    {
        $this->configureReverbAuth();
        Sanctum::actingAs(Employee::factory()->manager()->create());

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-agent-pending-actions',
        ])->assertOk();
    }

    #[Test]
    public function lacking_approve_may_not_subscribe(): void
    {
        $this->configureReverbAuth();
        Sanctum::actingAs(Employee::factory()->withoutRoleGrant()->create());

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-agent-pending-actions',
        ])->assertForbidden();
    }

    #[Test]
    public function recording_a_proposal_dispatches_pending_badge_updated(): void
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

        Event::fake([AgentPendingBadgeUpdated::class]);

        $this->recordInvocation(
            $ctx,
            'test.create_reservation',
            $this->reservationArgs(),
            $result,
            $ctx->principal,
        );

        Event::assertDispatched(AgentPendingBadgeUpdated::class);
    }

    #[Test]
    public function approving_a_proposal_dispatches_pending_badge_updated(): void
    {
        $pending = $this->queueProposal();
        Sanctum::actingAs($this->employee);

        Event::fake([AgentPendingBadgeUpdated::class]);

        $this->postJson("/api/agent-pending-actions/{$pending->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', PendingActionStatus::Approved->value);

        Event::assertDispatched(AgentPendingBadgeUpdated::class);
    }

    #[Test]
    public function sweep_dispatches_pending_and_inbox_badge_updated(): void
    {
        AgentPendingAction::factory()->create([
            'expires_at' => now()->subMinute(),
        ]);

        Event::fake([AgentPendingBadgeUpdated::class, InboxBadgeUpdated::class]);

        $this->artisan('agents:sweep-pending-actions')->assertSuccessful();

        Event::assertDispatched(AgentPendingBadgeUpdated::class);
        Event::assertDispatched(InboxBadgeUpdated::class);
    }

    #[Test]
    public function channel_send_pending_also_pings_inbox_badge(): void
    {
        $action = AgentPendingAction::factory()->create();
        $action->tool_key = 'channel.send';

        Event::fake([AgentPendingBadgeUpdated::class, InboxBadgeUpdated::class]);

        AgentPendingBadgeBroadcast::pingFor($action);

        Event::assertDispatched(AgentPendingBadgeUpdated::class);
        Event::assertDispatched(InboxBadgeUpdated::class);
    }

    private function configureReverbAuth(): void
    {
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
