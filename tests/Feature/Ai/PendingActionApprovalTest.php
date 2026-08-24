<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\LogChannel;
use App\Models\Activity;
use App\Models\AgentPendingAction;
use App\Models\Contract;
use App\Models\Price;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Models\UnitOccupancy;
use App\Support\Ai\Enums\PendingActionStatus;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Tools\ToolDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\SetsUpProposableReservation;
use Tests\TestCase;

class PendingActionApprovalTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpProposableReservation;

    #[Test]
    public function approve_creates_a_reservation_with_employee_causer_and_two_activity_rows(): void
    {
        $pending = $this->queueProposal();
        Sanctum::actingAs($this->employee);

        $expectedPayload = $this->reservationTool->propose(
            $pending->conversation->principal(),
            $this->reservationArgs(),
        )->data['payload'];

        $this->postJson("/api/agent-pending-actions/{$pending->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', PendingActionStatus::Approved->value)
            ->assertJsonPath('data.resolved_by_employee_id', $this->employee->id);

        $this->assertSame(1, Reservation::query()->count());
        $reservation = Reservation::query()->firstOrFail();
        $this->assertSame($pending->fresh()->result_id, $reservation->id);
        $this->assertSame(
            AgentPendingAction::canonicalPayload($expectedPayload),
            AgentPendingAction::canonicalPayload($this->reservationTool->lastCommitPayload ?? []),
        );
        $this->assertArrayNotHasKey('expires_at', $this->reservationTool->lastCommitPayload ?? []);
        $this->assertArrayNotHasKey('available_units', $this->reservationTool->lastCommitPayload ?? []);

        $created = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'reservation.created')
            ->where('subject_id', $reservation->id)
            ->get();
        $this->assertCount(1, $created);
        $this->assertSame($this->employee->id, $created->first()?->causer_id);

        $approved = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'agent.pending_action.approved')
            ->where('subject_id', $reservation->id)
            ->get();
        $this->assertCount(1, $approved);
        $this->assertSame($this->employee->id, $approved->first()?->causer_id);
        $this->assertSame($pending->ai_agent_id, $approved->first()?->properties->get('ai_agent_id'));
        $this->assertSame($pending->agent_conversation_id, $approved->first()?->properties->get('agent_conversation_id'));
        $this->assertSame($pending->id, $approved->first()?->properties->get('agent_pending_action_id'));
    }

    #[Test]
    public function approve_path_commits_fresh_payload_not_the_stored_row(): void
    {
        $src = (string) file_get_contents(app_path('Support/Ai/PendingActionCommit.php'));

        $this->assertMatchesRegularExpression(
            '/\$tool->commit\(\s*LeasingActor::employee\(\$approver\),\s*\$freshPayload/',
            $src,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$tool->commit\(\s*LeasingActor::employee\(\$approver\),\s*\$row->payload/',
            $src,
        );
        $this->assertStringNotContainsString('ReservationCreation::create', $src);
        $this->assertStringNotContainsString('OfferCreation::', $src);
    }

    #[Test]
    public function stale_price_returns_422_and_leaves_the_row_pending(): void
    {
        $pending = $this->queueProposal();
        $this->replaceCataloguePrice();
        Sanctum::actingAs($this->employee);

        $this->postJson("/api/agent-pending-actions/{$pending->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['failure_reason']);

        $this->assertSame(PendingActionStatus::Pending, $pending->fresh()->status);
        $this->assertSame(0, Reservation::query()->count());
        $this->assertNull($this->reservationTool->lastCommitPayload);
    }

    #[Test]
    public function occupying_the_last_unit_returns_422_and_leaves_the_row_pending(): void
    {
        $pending = $this->queueProposal();
        $this->occupy($this->unit);
        Sanctum::actingAs($this->employee);

        $this->postJson("/api/agent-pending-actions/{$pending->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['failure_reason']);

        $this->assertSame(PendingActionStatus::Pending, $pending->fresh()->status);
        $this->assertSame(0, Reservation::query()->count());
    }

    #[Test]
    public function expired_proposal_returns_422_and_flips_status(): void
    {
        $pending = $this->queueProposal();
        $pending->update(['expires_at' => now()->subMinute()]);
        Sanctum::actingAs($this->employee);

        $this->postJson("/api/agent-pending-actions/{$pending->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pending_action']);

        $this->assertSame(PendingActionStatus::Expired, $pending->fresh()->status);
        $this->assertSame(0, Reservation::query()->count());
    }

    #[Test]
    public function second_approve_conflicts_under_the_lock(): void
    {
        $pending = $this->queueProposal();
        Sanctum::actingAs($this->employee);

        $this->postJson("/api/agent-pending-actions/{$pending->id}/approve")->assertOk();
        $this->postJson("/api/agent-pending-actions/{$pending->id}/approve")
            ->assertStatus(409);

        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame(PendingActionStatus::Approved, $pending->fresh()->status);
    }

    #[Test]
    public function reject_does_not_write_leasing_rows(): void
    {
        $pending = $this->queueProposal();
        Sanctum::actingAs($this->employee);

        $this->postJson("/api/agent-pending-actions/{$pending->id}/reject", [
            'reason' => 'Not this class.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', PendingActionStatus::Rejected->value)
            ->assertJsonPath('data.rejection_reason', 'Not this class.');

        $this->assertSame(0, Reservation::query()->count());
        $this->assertSame($this->employee->id, $pending->fresh()->resolved_by_employee_id);
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

    private function replaceCataloguePrice(): void
    {
        $rate = UnitClassRate::query()
            ->where('site_id', $this->site->id)
            ->where('unit_class_id', $this->unitClass->id)
            ->firstOrFail();
        $old = $rate->price;
        $this->assertNotNull($old);
        $old->update(['effective_to' => now()->toDateString()]);

        Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id' => $rate->id,
            'scope' => Price::SCOPE_CATALOGUE,
            'amount' => '120.00',
            'currency' => 'EUR',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);
    }

    private function occupy(Unit $unit): void
    {
        $contract = Contract::factory()->create([
            'contact_id' => $this->contact->id,
            'currency' => 'EUR',
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-01-01',
            'ended_on' => null,
        ]);
    }
}
