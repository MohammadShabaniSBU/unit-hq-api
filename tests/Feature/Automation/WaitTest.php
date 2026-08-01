<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Jobs\ResumeAutomationRun;
use App\Support\Automation\AutomationExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WaitTest extends TestCase
{
    use AutomationGraph;
    use RefreshDatabase;

    public function test_park_release_resume_relative_and_until(): void
    {
        Queue::fake([ResumeAutomationRun::class]);
        Carbon::setTestNow('2026-08-01 10:00:00');

        // Relative
        $relative = $this->fiveNodeWaitGraph([
            'mode' => 'relative',
            'amount' => 3,
            'unit' => 'hours',
        ]);

        (new AutomationExecutor)->execute($relative['run']);
        $relative['run']->refresh();

        $this->assertSame(AutomationRunStatus::Waiting, $relative['run']->status);
        $this->assertNotNull($relative['run']->waiting_until);
        $this->assertTrue(
            $relative['run']->waiting_until->equalTo(Carbon::parse('2026-08-01 13:00:00')),
        );
        $this->assertSame(
            AutomationRunStepStatus::Waiting,
            $relative['run']->steps()->where('node_type', 'logic.wait')->first()?->status,
        );

        Queue::assertPushed(ResumeAutomationRun::class, function (ResumeAutomationRun $job) use ($relative): bool {
            return $job->runId === $relative['run']->id;
        });

        // Until
        $dueAt = '2026-08-02T15:30:00+00:00';
        $until = $this->fiveNodeWaitGraph([
            'mode' => 'until',
            'expression' => '{{trigger.due_at}}',
        ]);
        $until['run']->update([
            'trigger_payload' => array_merge($until['run']->trigger_payload ?? [], [
                'due_at' => $dueAt,
            ]),
        ]);

        (new AutomationExecutor)->execute($until['run']->fresh());
        $until['run']->refresh();

        $this->assertSame(AutomationRunStatus::Waiting, $until['run']->status);
        $this->assertTrue(
            $until['run']->waiting_until->equalTo(Carbon::parse($dueAt)),
        );

        Carbon::setTestNow();
    }

    public function test_sweeper_rescues_lost_delayed_job(): void
    {
        Queue::fake([ResumeAutomationRun::class]);
        Carbon::setTestNow('2026-08-01 10:00:00');

        $graph = $this->fiveNodeWaitGraph([
            'mode' => 'relative',
            'amount' => 1,
            'unit' => 'hours',
        ]);

        (new AutomationExecutor)->execute($graph['run']);
        $graph['run']->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $graph['run']->status);

        // Drop the delayed job — sweeper is authoritative.
        Queue::fake([ResumeAutomationRun::class]);

        Carbon::setTestNow('2026-08-01 11:01:00');
        Artisan::call('automations:resume-waiting');

        Queue::assertPushed(ResumeAutomationRun::class, function (ResumeAutomationRun $job) use ($graph): bool {
            return $job->runId === $graph['run']->id;
        });

        // Process resume (sync).
        (new ResumeAutomationRun($graph['run']->id))->handle(app(AutomationExecutor::class));
        $graph['run']->refresh();
        $graph['contact']->refresh();

        $this->assertSame(AutomationRunStatus::Succeeded, $graph['run']->status);
        $this->assertSame('N5', $graph['contact']->first_name);

        $waitStep = $graph['run']->steps()->where('node_type', 'logic.wait')->first();
        $this->assertSame(AutomationRunStepStatus::Succeeded, $waitStep?->status);
        $this->assertArrayHasKey('resumed_at', $waitStep?->output ?? []);

        Carbon::setTestNow();
    }

    public function test_resume_from_cursor_only(): void
    {
        Queue::fake([ResumeAutomationRun::class]);
        Carbon::setTestNow('2026-08-01 10:00:00');

        $graph = $this->fiveNodeWaitGraph([
            'mode' => 'relative',
            'amount' => 1,
            'unit' => 'minutes',
        ]);

        (new AutomationExecutor)->execute($graph['run']);
        $graph['run']->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $graph['run']->status);
        $this->assertSame('N2', $graph['contact']->fresh()->first_name);

        $stepsBefore = $graph['run']->steps()->count();
        $n1Count = $graph['run']->steps()->where('node_id', $graph['nodes']['n1']->id)->count();
        $n2Count = $graph['run']->steps()->where('node_id', $graph['nodes']['n2']->id)->count();
        $this->assertSame(1, $n1Count);
        $this->assertSame(1, $n2Count);
        $this->assertSame(0, $graph['run']->steps()->where('node_id', $graph['nodes']['n4']->id)->count());

        Carbon::setTestNow('2026-08-01 10:02:00');
        (new ResumeAutomationRun($graph['run']->id))->handle(app(AutomationExecutor::class));

        $graph['run']->refresh();
        $graph['contact']->refresh();

        $this->assertSame(AutomationRunStatus::Succeeded, $graph['run']->status);
        $this->assertSame('N5', $graph['contact']->first_name);

        // Nodes before the wait must not re-execute; only n4/n5 (+ wait flip) added.
        $this->assertSame(1, $graph['run']->steps()->where('node_id', $graph['nodes']['n1']->id)->count());
        $this->assertSame(1, $graph['run']->steps()->where('node_id', $graph['nodes']['n2']->id)->count());
        $this->assertSame(1, $graph['run']->steps()->where('node_id', $graph['nodes']['n4']->id)->count());
        $this->assertSame(1, $graph['run']->steps()->where('node_id', $graph['nodes']['n5']->id)->count());
        $this->assertSame(1, $graph['run']->steps()->where('node_type', 'trigger.object_created')->count());
        $this->assertGreaterThan($stepsBefore, $graph['run']->steps()->count());

        Carbon::setTestNow();
    }
}
