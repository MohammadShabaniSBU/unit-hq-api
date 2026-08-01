<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Enums\AutomationCancelCause;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Jobs\ResumeAutomationRun;
use App\Models\Employee;
use App\Support\Automation\AutomationExecutor;
use App\Support\Automation\RunLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CancelTest extends TestCase
{
    use AutomationGraph;
    use RefreshDatabase;

    public function test_claim_race_interleavings(): void
    {
        Queue::fake([ResumeAutomationRun::class]);
        Carbon::setTestNow('2026-08-01 10:00:00');

        $employee = Employee::factory()->manager()->create();

        // Cancel while pending wins over execute claim.
        $pending = $this->fiveNodeWaitGraph();
        $this->assertTrue(RunLifecycle::cancel($pending['run'], AutomationCancelCause::Manual, $employee));
        (new AutomationExecutor)->execute($pending['run']->fresh());
        $pending['run']->refresh();
        $this->assertSame(AutomationRunStatus::Cancelled, $pending['run']->status);
        $this->assertSame(AutomationCancelCause::Manual, $pending['run']->cancel_cause);
        $this->assertSame(0, $pending['run']->steps()->where('node_type', '!=', 'run.cancelled')->count());

        // Mid-wait cancel: resume must no-op.
        $waiting = $this->fiveNodeWaitGraph([
            'mode' => 'relative',
            'amount' => 2,
            'unit' => 'hours',
        ]);
        (new AutomationExecutor)->execute($waiting['run']);
        $waiting['run']->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $waiting['run']->status);

        Sanctum::actingAs($employee);
        $response = $this->postJson("/api/automation-runs/{$waiting['run']->id}/cancel");
        $response->assertOk();
        $waiting['run']->refresh();
        $this->assertSame(AutomationRunStatus::Cancelled, $waiting['run']->status);

        $cancelStep = $waiting['run']->steps()->where('node_type', 'run.cancelled')->first();
        $this->assertNotNull($cancelStep);
        $this->assertSame(AutomationRunStepStatus::Succeeded, $cancelStep->status);
        $this->assertSame('manual', $cancelStep->output['cause'] ?? null);

        // No phantom skips for unexecuted n4/n5.
        $this->assertSame(0, $waiting['run']->steps()->where('status', AutomationRunStepStatus::Skipped)->count());
        $this->assertSame(0, $waiting['run']->steps()->where('node_id', $waiting['nodes']['n4']->id)->count());

        Carbon::setTestNow('2026-08-01 13:00:00');
        (new ResumeAutomationRun($waiting['run']->id))->handle(app(AutomationExecutor::class));
        $waiting['run']->refresh();
        $waiting['contact']->refresh();

        $this->assertSame(AutomationRunStatus::Cancelled, $waiting['run']->status);
        $this->assertSame('N2', $waiting['contact']->first_name);

        // Terminal cancel refused.
        $response = $this->postJson("/api/automation-runs/{$waiting['run']->id}/cancel");
        $response->assertStatus(422);

        Carbon::setTestNow();
    }

    public function test_cancel_while_running_beats_succeed(): void
    {
        Queue::fake([ResumeAutomationRun::class]);

        $graph = $this->fiveNodeWaitGraph([
            'mode' => 'relative',
            'amount' => 1,
            'unit' => 'days',
        ]);
        $employee = Employee::factory()->manager()->create();

        // Force claim to running, then cancel before walk finishes via direct cancel.
        RunLifecycle::claimRunning($graph['run']);
        $this->assertTrue(RunLifecycle::cancel($graph['run']->fresh(), AutomationCancelCause::Manual, $employee));

        // Executor mid-flight observation: already cancelled → stop.
        (new AutomationExecutor)->execute($graph['run']->fresh());
        $graph['run']->refresh();

        $this->assertSame(AutomationRunStatus::Cancelled, $graph['run']->status);
        $this->assertNotSame(AutomationRunStatus::Succeeded, $graph['run']->status);
    }
}
