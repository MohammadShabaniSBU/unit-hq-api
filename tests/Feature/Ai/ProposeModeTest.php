<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentPendingAction;
use App\Models\Offer;
use App\Models\Reservation;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\PendingActionStatus;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Tools\ToolDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\SetsUpProposableReservation;
use Tests\TestCase;

class ProposeModeTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpProposableReservation;

    #[Test]
    public function propose_mode_never_calls_handle_and_writes_no_pipeline_rows(): void
    {
        $ctx = $this->setUpProposableReservation();
        $principal = $ctx->principal;

        $result = app(ToolDispatcher::class)->dispatch(
            $ctx->definition,
            $principal,
            'test.create_reservation',
            $this->reservationArgs(),
            $ctx,
        );

        $this->assertSame(ToolInvocationStatus::Denied, $result->status);
        $this->assertSame(ToolDeniedReason::RequiresApproval, $result->deniedReason);
        $this->assertFalse($this->reservationTool->handleCalled);
        $this->assertSame(CannedReply::pendingApproval('en'), $result->display);
        $this->assertSame(0, Reservation::query()->count());
        $this->assertSame(0, Offer::query()->count());

        $this->recordInvocation($ctx, 'test.create_reservation', $this->reservationArgs(), $result, $principal);

        $this->assertSame(1, AgentPendingAction::query()->count());
        $this->assertSame(PendingActionStatus::Pending, AgentPendingAction::query()->firstOrFail()->status);
    }

    #[Test]
    public function canned_approval_line_is_localized_and_never_a_success_claim(): void
    {
        foreach (['en', 'es', 'fr'] as $locale) {
            $line = CannedReply::pendingApproval($locale);
            $this->assertNotSame('', $line);
            $this->assertStringNotContainsString('reserved', mb_strtolower($line));
            $this->assertStringNotContainsString('held', mb_strtolower($line));
            $this->assertStringNotContainsString('created', mb_strtolower($line));
            $this->assertDoesNotMatchRegularExpression('/\d/', $line);
        }

        $ctx = $this->setUpProposableReservation();
        $principal = AgentPrincipal::anonymous($this->site->id, 'es');
        $ctx = new AgentContext(
            $principal,
            $ctx->channel,
            $ctx->definition,
            $ctx->conversation,
            $ctx->agent,
        );

        $result = app(ToolDispatcher::class)->dispatch(
            $ctx->definition,
            $principal,
            'test.create_reservation',
            $this->reservationArgs(),
            $ctx,
        );

        $this->assertSame(CannedReply::pendingApproval('es'), $result->display);
    }

    #[Test]
    public function unresolvable_site_is_a_propose_failure_not_requires_approval(): void
    {
        $ctx = $this->setUpProposableReservation();
        $this->deal->update(['site_id' => null]);

        $result = app(ToolDispatcher::class)->dispatch(
            $ctx->definition,
            $ctx->principal,
            'test.create_reservation',
            $this->reservationArgs(),
            $ctx,
        );

        $this->assertNotSame(ToolDeniedReason::RequiresApproval, $result->deniedReason);
        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertNotSame(CannedReply::pendingApproval('en'), $result->display);
        $this->assertSame(0, AgentPendingAction::query()->count());
        $this->assertFalse($this->reservationTool->handleCalled);
    }

    #[Test]
    public function propose_payload_is_stable_across_clock_advances(): void
    {
        $this->setUpProposableReservation();
        Carbon::setTestNow('2026-08-24 10:00:00');

        $first = $this->reservationTool->propose(
            AgentPrincipal::anonymous($this->site->id, 'en'),
            $this->reservationArgs(),
        );
        $this->assertSame(ToolInvocationStatus::Ok, $first->status);

        Carbon::setTestNow('2026-08-27 10:00:00');

        $second = $this->reservationTool->propose(
            AgentPrincipal::anonymous($this->site->id, 'en'),
            $this->reservationArgs(),
        );
        $this->assertSame(ToolInvocationStatus::Ok, $second->status);

        $this->assertSame(
            AgentPendingAction::canonicalPayload($first->data['payload']),
            AgentPendingAction::canonicalPayload($second->data['payload']),
        );
        $this->assertNotSame($first->data['preview']['expires_at'], $second->data['preview']['expires_at']);

        Carbon::setTestNow();
    }
}
