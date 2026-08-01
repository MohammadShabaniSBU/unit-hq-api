<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Enums\AutomationCancelCause;
use App\Enums\AutomationRunStatus;
use App\Jobs\EvaluateRunGuards;
use App\Jobs\ResumeAutomationRun;
use App\Support\Automation\AutomationExecutor;
use App\Support\Automation\RunLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GuardTest extends TestCase
{
    use AutomationGraph;
    use RefreshDatabase;

    /** Guard continues while first_name == Start; fails (cancels) otherwise. */
    private function continueWhileStartGuard(): array
    {
        return [
            'logic' => 'and',
            'conditions' => [
                [
                    'field' => 'first_name',
                    'operator' => 'equals',
                    'value' => 'Start',
                ],
            ],
        ];
    }

    public function test_three_evaluation_points(): void
    {
        Queue::fake([ResumeAutomationRun::class]);
        Carbon::setTestNow('2026-08-01 10:00:00');

        // (a) Before step claim — n1 would change first_name; guard fails after n1… 
        // Guard is evaluated BEFORE each step against live subject. Start with
        // first_name=Start so first steps pass; after n1 sets N1, next boundary cancels.
        $stepPoint = $this->fiveNodeWaitGraph(
            ['mode' => 'relative', 'amount' => 1, 'unit' => 'hours'],
            $this->continueWhileStartGuard(),
        );

        (new AutomationExecutor)->execute($stepPoint['run']);
        $stepPoint['run']->refresh();

        $this->assertSame(AutomationRunStatus::Cancelled, $stepPoint['run']->status);
        $this->assertSame(AutomationCancelCause::Guard, $stepPoint['run']->cancel_cause);
        // n1 ran (subject was still Start at claim); subsequent nodes did not.
        $this->assertSame(1, $stepPoint['run']->steps()->where('node_id', $stepPoint['nodes']['n1']->id)->count());
        $this->assertSame(0, $stepPoint['run']->steps()->where('node_id', $stepPoint['nodes']['n2']->id)->count());

        // (b) At wait resume — park with null-like always-true guard, then tighten.
        $resumePoint = $this->fiveNodeWaitGraph(
            ['mode' => 'relative', 'amount' => 1, 'unit' => 'hours'],
            [
                'logic' => 'and',
                'conditions' => [
                    ['field' => 'first_name', 'operator' => 'is_not_empty'],
                ],
            ],
        );
        (new AutomationExecutor)->execute($resumePoint['run']);
        $resumePoint['run']->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $resumePoint['run']->status);

        // Make guard fail before resume: require first_name == Start (now N2).
        $resumePoint['run']->update(['guard' => $this->continueWhileStartGuard()]);

        Carbon::setTestNow('2026-08-01 11:05:00');
        (new ResumeAutomationRun($resumePoint['run']->id))->handle(app(AutomationExecutor::class));
        $resumePoint['run']->refresh();

        $this->assertSame(AutomationRunStatus::Cancelled, $resumePoint['run']->status);
        $this->assertSame(AutomationCancelCause::Guard, $resumePoint['run']->cancel_cause);
        $this->assertSame(0, $resumePoint['run']->steps()->where('node_id', $resumePoint['nodes']['n4']->id)->count());

        // (c) Opportunistic EvaluateRunGuards job after domain write.
        $eventPoint = $this->fiveNodeWaitGraph(
            ['mode' => 'relative', 'amount' => 2, 'unit' => 'days'],
            $this->continueWhileStartGuard(),
        );
        // Park without tripping the step-boundary guard: use a permissive guard to park, then set real guard.
        $eventPoint['run']->update([
            'guard' => [
                'logic' => 'and',
                'conditions' => [
                    ['field' => 'first_name', 'operator' => 'is_not_empty'],
                ],
            ],
        ]);
        (new AutomationExecutor)->execute($eventPoint['run']->fresh());
        $eventPoint['run']->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $eventPoint['run']->status);

        $eventPoint['run']->update(['guard' => $this->continueWhileStartGuard()]);
        // Subject already has first_name N2 — job should cancel.
        (new EvaluateRunGuards('contact', (int) $eventPoint['contact']->id))->handle();
        $eventPoint['run']->refresh();

        $this->assertSame(AutomationRunStatus::Cancelled, $eventPoint['run']->status);
        $this->assertSame(AutomationCancelCause::Guard, $eventPoint['run']->cancel_cause);

        // Null guard: no evaluator overhead / no cancel.
        $ungarded = $this->fiveNodeWaitGraph(
            ['mode' => 'relative', 'amount' => 1, 'unit' => 'minutes'],
            null,
        );
        (new AutomationExecutor)->execute($ungarded['run']);
        $ungarded['run']->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $ungarded['run']->status);

        Carbon::setTestNow();
    }

    public function test_deleted_subject_conservative_cancel(): void
    {
        Queue::fake([ResumeAutomationRun::class]);

        $graph = $this->fiveNodeWaitGraph(
            ['mode' => 'relative', 'amount' => 1, 'unit' => 'hours'],
            [
                'logic' => 'and',
                'conditions' => [
                    ['field' => 'first_name', 'operator' => 'is_not_empty'],
                ],
            ],
        );

        (new AutomationExecutor)->execute($graph['run']);
        $graph['run']->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $graph['run']->status);

        $graph['contact']->delete();

        RunLifecycle::evaluateGuard($graph['run']->fresh());
        $graph['run']->refresh();

        $this->assertSame(AutomationRunStatus::Cancelled, $graph['run']->status);
        $this->assertSame(AutomationCancelCause::TriggerObjectDeleted, $graph['run']->cancel_cause);
    }
}
