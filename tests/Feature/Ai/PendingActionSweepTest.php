<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentPendingAction;
use App\Models\SystemEvent;
use App\Support\Ai\Enums\PendingActionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PendingActionSweepTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sweep_expires_past_due_rows_and_records_a_system_event(): void
    {
        $expired = AgentPendingAction::factory()->create([
            'expires_at' => now()->subMinute(),
        ]);
        $live = AgentPendingAction::factory()->create([
            'expires_at' => now()->addHour(),
        ]);

        $this->artisan('agents:sweep-pending-actions')->assertSuccessful();

        $this->assertSame(PendingActionStatus::Expired, $expired->fresh()->status);
        $this->assertSame(PendingActionStatus::Pending, $live->fresh()->status);
        $this->assertTrue(
            SystemEvent::query()->where('event', 'agents.pending_actions.swept')->exists(),
        );
    }

    #[Test]
    public function sweep_does_not_record_when_nothing_expired(): void
    {
        AgentPendingAction::factory()->create([
            'expires_at' => now()->addHour(),
        ]);

        $this->artisan('agents:sweep-pending-actions')->assertSuccessful();

        $this->assertFalse(
            SystemEvent::query()->where('event', 'agents.pending_actions.swept')->exists(),
        );
    }
}
