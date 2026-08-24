<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentPendingAction;
use App\Support\Ai\Enums\PendingActionStatus;
use App\Support\Ai\PendingActionRecorder;
use App\Support\Ai\Tools\ToolDispatcher;
use App\Support\Ai\Tools\ToolResult;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\SetsUpProposableReservation;
use Tests\TestCase;

class PendingActionSupersedeTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpProposableReservation;

    #[Test]
    public function second_propose_inserts_then_supersedes_the_first_in_one_transaction(): void
    {
        $ctx = $this->setUpProposableReservation();
        $firstResult = app(ToolDispatcher::class)->dispatch(
            $ctx->definition,
            $ctx->principal,
            'test.create_reservation',
            $this->reservationArgs(),
            $ctx,
        );
        $first = $this->recordInvocation(
            $ctx,
            'test.create_reservation',
            $this->reservationArgs(),
            $firstResult,
            $ctx->principal,
        )->pendingAction;
        $this->assertNotNull($first);
        $this->assertSame(PendingActionStatus::Pending, $first->status);

        $secondResult = app(ToolDispatcher::class)->dispatch(
            $ctx->definition,
            $ctx->principal,
            'test.create_reservation',
            $this->reservationArgs(),
            $ctx,
        );
        $secondInvocation = $this->recordInvocation(
            $ctx,
            'test.create_reservation',
            $this->reservationArgs(),
            $secondResult,
            $ctx->principal,
        );
        $second = $secondInvocation->pendingAction;
        $this->assertNotNull($second);

        $this->assertSame(PendingActionStatus::Superseded, $first->fresh()->status);
        $this->assertSame(PendingActionStatus::Pending, $second->status);
        $this->assertSame(2, AgentPendingAction::query()->count());
    }

    #[Test]
    public function failed_insert_does_not_supersede_the_prior_row(): void
    {
        $ctx = $this->setUpProposableReservation();
        $firstResult = app(ToolDispatcher::class)->dispatch(
            $ctx->definition,
            $ctx->principal,
            'test.create_reservation',
            $this->reservationArgs(),
            $ctx,
        );
        $first = $this->recordInvocation(
            $ctx,
            'test.create_reservation',
            $this->reservationArgs(),
            $firstResult,
            $ctx->principal,
        )->pendingAction;
        $this->assertNotNull($first);

        $this->expectException(QueryException::class);
        try {
            app(PendingActionRecorder::class)->record(
                $first->invocation,
                ToolResult::requiresApproval(
                    'x',
                    ['site_id' => $this->site->id],
                    [],
                ),
            );
        } finally {
            $this->assertSame(PendingActionStatus::Pending, $first->fresh()->status);
            $this->assertSame(1, AgentPendingAction::query()->count());
        }
    }
}
