<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Automation;

use App\Enums\AutomationCancelCause;
use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\Employee;
use App\Support\Automation\RunLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LifecycleTest extends TestCase
{
    use RefreshDatabase;

    private AutomationRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $automation = Automation::query()->create([
            'name' => 'Lifecycle matrix',
            'status' => AutomationStatus::Draft,
            'version' => 1,
        ]);

        $trigger = AutomationNode::query()->create([
            'automation_id' => $automation->id,
            'node_key' => 'trigger',
            'kind' => AutomationNodeType::ObjectCreated->kind()->value,
            'type' => AutomationNodeType::ObjectCreated->value,
            'label' => 'trigger',
            'position_x' => 0,
            'position_y' => 0,
            'config' => [],
        ]);

        $this->run = AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'trigger_node_id' => $trigger->id,
            'status' => AutomationRunStatus::Pending,
            'depth' => 0,
        ]);
    }

    /**
     * @return array<string, array{0: AutomationRunStatus, 1: AutomationRunStatus}>
     */
    public static function permittedTransitionsProvider(): array
    {
        return [
            'pending→running' => [AutomationRunStatus::Pending, AutomationRunStatus::Running],
            'pending→cancelled' => [AutomationRunStatus::Pending, AutomationRunStatus::Cancelled],
            'running→waiting' => [AutomationRunStatus::Running, AutomationRunStatus::Waiting],
            'running→succeeded' => [AutomationRunStatus::Running, AutomationRunStatus::Succeeded],
            'running→failed' => [AutomationRunStatus::Running, AutomationRunStatus::Failed],
            'running→cancelled' => [AutomationRunStatus::Running, AutomationRunStatus::Cancelled],
            'waiting→running' => [AutomationRunStatus::Waiting, AutomationRunStatus::Running],
            'waiting→cancelled' => [AutomationRunStatus::Waiting, AutomationRunStatus::Cancelled],
        ];
    }

    #[DataProvider('permittedTransitionsProvider')]
    public function test_permitted_transitions(AutomationRunStatus $from, AutomationRunStatus $to): void
    {
        $this->run->update(['status' => $from]);

        $attrs = match ($to) {
            AutomationRunStatus::Waiting => [
                'waiting_until' => now()->addHour(),
                'current_node_id' => $this->run->trigger_node_id,
            ],
            AutomationRunStatus::Cancelled => [
                'cancel_cause' => AutomationCancelCause::Manual->value,
                'completed_at' => now(),
            ],
            AutomationRunStatus::Succeeded, AutomationRunStatus::Failed => [
                'completed_at' => now(),
            ],
            default => [],
        };

        $this->assertTrue(RunLifecycle::transition($this->run->fresh(), $to, $attrs));
        $this->assertSame($to, $this->run->fresh()->status);
    }

    /**
     * @return array<string, array{0: AutomationRunStatus, 1: AutomationRunStatus}>
     */
    public static function rejectedTransitionsProvider(): array
    {
        return [
            'pending→succeeded' => [AutomationRunStatus::Pending, AutomationRunStatus::Succeeded],
            'pending→waiting' => [AutomationRunStatus::Pending, AutomationRunStatus::Waiting],
            'waiting→succeeded' => [AutomationRunStatus::Waiting, AutomationRunStatus::Succeeded],
            'succeeded→running' => [AutomationRunStatus::Succeeded, AutomationRunStatus::Running],
            'failed→cancelled' => [AutomationRunStatus::Failed, AutomationRunStatus::Cancelled],
            'cancelled→running' => [AutomationRunStatus::Cancelled, AutomationRunStatus::Running],
        ];
    }

    #[DataProvider('rejectedTransitionsProvider')]
    public function test_terminal_and_illegal_rejected(AutomationRunStatus $from, AutomationRunStatus $to): void
    {
        $this->run->update(['status' => $from]);

        $this->expectException(ValidationException::class);
        RunLifecycle::transition($this->run->fresh(), $to);
    }

    public function test_claim_loses_race(): void
    {
        $this->assertTrue(RunLifecycle::claimRunning($this->run));
        $this->assertFalse(RunLifecycle::claimRunning($this->run->fresh()));
    }

    public function test_cancel_appends_synthetic_step(): void
    {
        $employee = Employee::factory()->manager()->create();
        $this->assertTrue(RunLifecycle::cancel($this->run, AutomationCancelCause::Manual, $employee));

        $step = $this->run->fresh()->steps()->where('node_type', 'run.cancelled')->first();
        $this->assertNotNull($step);
        $this->assertSame(AutomationCancelCause::Manual, $this->run->fresh()->cancel_cause);
        $this->assertSame($employee->id, $this->run->fresh()->cancelled_by);
    }
}
