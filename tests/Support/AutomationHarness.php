<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AutomationCancelCause;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Events\ModelCreated;
use App\Events\ModelDeleted;
use App\Events\ModelUpdated;
use App\Jobs\EvaluateRunGuards;
use App\Jobs\ExecuteAutomationRun;
use App\Jobs\MatchAutomationTriggers;
use App\Jobs\ResumeAutomationRun;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\Employee;
use App\Support\Automation\AutomationExecutor;
use App\Support\Automation\RunLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Fixture-driven automation engine driver for tests.
 * Real matcher / executor / lifecycle; only time and resume queues are faked.
 */
final class AutomationHarness
{
    private Automation $automation;

    /** @var array<string, mixed> */
    private array $harnessMeta;

    private ?AutomationRun $run = null;

    /**
     * @param  array<string, mixed>  $harnessMeta
     */
    private function __construct(Automation $automation, array $harnessMeta)
    {
        $this->automation = $automation;
        $this->harnessMeta = $harnessMeta;
    }

    public static function load(string $name): self
    {
        $loaded = AutomationFixtureLoader::load($name);

        Event::fake([ModelCreated::class, ModelUpdated::class, ModelDeleted::class]);
        Queue::fake([ExecuteAutomationRun::class, ResumeAutomationRun::class]);

        return new self($loaded['automation'], $loaded['harness']);
    }

    public function automation(): Automation
    {
        return $this->automation;
    }

    public function run(): AutomationRun
    {
        if ($this->run === null) {
            throw new RuntimeException('No automation run captured yet. Call trigger() or triggerSchedule() first.');
        }

        return $this->run->fresh(['steps']) ?? $this->run;
    }

    /**
     * Enrol via the real TriggerMatcher (manual dispatch — model events are faked at load).
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $dirty
     */
    public function trigger(string $lifecycle, Model $subject, array $dirty = []): self
    {
        Queue::fake([ExecuteAutomationRun::class, ResumeAutomationRun::class]);

        $lifecycleKey = match ($lifecycle) {
            'object_created', 'created' => 'created',
            'object_updated', 'updated' => 'updated',
            default => $lifecycle,
        };

        $attributes = method_exists($subject, 'automationTriggerAttributes')
            ? $subject->automationTriggerAttributes()
            : $subject->attributesToArray();

        $causer = auth()->user();

        (new MatchAutomationTriggers(
            $lifecycleKey,
            (string) $subject->getMorphClass(),
            $subject->getKey(),
            $dirty,
            $attributes,
            $causer?->getMorphClass(),
            $causer?->getKey(),
        ))->handle();

        $run = AutomationRun::query()
            ->where('automation_id', $this->automation->id)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->latest('id')
            ->first();

        if ($run === null) {
            $this->failWithStepTable('Trigger did not enrol a run for this automation/subject.');
        }

        $this->run = $run;
        $this->applyHarnessMetaToRun();
        (new AutomationExecutor)->execute($this->run->fresh() ?? $this->run);
        $this->run->refresh();

        return $this;
    }

    public function triggerSchedule(): self
    {
        Queue::fake([ExecuteAutomationRun::class, ResumeAutomationRun::class]);

        Artisan::call('automations:run-scheduled');

        $run = AutomationRun::query()
            ->where('automation_id', $this->automation->id)
            ->latest('id')
            ->first();

        if ($run === null) {
            $this->failWithStepTable('Schedule command did not enrol a run for this automation.');
        }

        $this->run = $run;
        $this->applyHarnessMetaToRun();
        (new AutomationExecutor)->execute($this->run->fresh() ?? $this->run);
        $this->run->refresh();

        return $this;
    }

    /**
     * Advance test time and resume waiting runs.
     *
     * @param  'delayed'|'sweeper'  $via
     */
    public function travelTo(string $relative, string $via = 'delayed'): self
    {
        $this->requireRun();

        Carbon::setTestNow(now()->modify($relative));

        if ($via === 'sweeper') {
            // Drop any parked delayed jobs — sweeper is authoritative.
            Queue::fake([ResumeAutomationRun::class, ExecuteAutomationRun::class]);
            Artisan::call('automations:resume-waiting');
        } else {
            Queue::assertPushed(ResumeAutomationRun::class, function (ResumeAutomationRun $job): bool {
                return $job->runId === $this->run?->id;
            });
        }

        (new ResumeAutomationRun($this->run->id))->handle(app(AutomationExecutor::class));
        $this->run->refresh();

        return $this;
    }

    /**
     * @param  callable(): mixed  $callback
     */
    public function mutate(callable $callback, bool $evaluateGuards = false): self
    {
        $this->requireRun();
        $callback();

        if ($evaluateGuards && $this->run->subject_type !== null && $this->run->subject_id !== null) {
            (new EvaluateRunGuards(
                (string) $this->run->subject_type,
                (int) $this->run->subject_id,
            ))->handle();
            $this->run->refresh();
        }

        return $this;
    }

    public function cancel(?Employee $by = null): self
    {
        $this->requireRun();
        RunLifecycle::cancel($this->run, AutomationCancelCause::Manual, $by);
        $this->run->refresh();

        return $this;
    }

    public function assertRunStatus(AutomationRunStatus|string $status, AutomationCancelCause|string|null $cause = null): self
    {
        $this->requireRun();
        $this->run->refresh();

        $expected = $status instanceof AutomationRunStatus ? $status : AutomationRunStatus::from($status);

        if ($this->run->status !== $expected) {
            $this->failWithStepTable(sprintf(
                'Expected run status [%s], got [%s].',
                $expected->value,
                $this->run->status->value,
            ));
        }
        Assert::assertSame($expected, $this->run->status);

        if ($cause !== null) {
            $expectedCause = $cause instanceof AutomationCancelCause
                ? $cause
                : AutomationCancelCause::from($cause);

            if ($this->run->cancel_cause !== $expectedCause) {
                $this->failWithStepTable(sprintf(
                    'Expected cancel_cause [%s], got [%s].',
                    $expectedCause->value,
                    $this->run->cancel_cause?->value ?? 'null',
                ));
            }
            Assert::assertSame($expectedCause, $this->run->cancel_cause);
        }

        return $this;
    }

    /**
     * Assert ordered step sequence by node_key and/or node_type (non-synthetic steps).
     *
     * @param  list<string>  $expected
     */
    public function assertStepSequence(array $expected): self
    {
        $this->requireRun();
        $steps = $this->orderedSteps();

        $actual = [];
        foreach ($steps as $step) {
            if ($step->node_type === 'run.cancelled') {
                continue;
            }
            $key = $this->nodeKeyForStep($step);
            $actual[] = $key ?? (string) $step->node_type;
        }

        // Also accept matching by type alone when expected entries look like types.
        $actualTypes = [];
        foreach ($steps as $step) {
            if ($step->node_type === 'run.cancelled') {
                continue;
            }
            $actualTypes[] = (string) $step->node_type;
        }

        if ($actual !== $expected && $actualTypes !== $expected) {
            // Try mixed: expected item matches key OR type at each position.
            $ok = count($actual) === count($expected);
            if ($ok) {
                foreach ($expected as $i => $want) {
                    $key = $actual[$i] ?? null;
                    $type = $actualTypes[$i] ?? null;
                    if ($want !== $key && $want !== $type && ! str_ends_with((string) $type, $want)) {
                        // allow short forms like "send_email" for "action.send_email"
                        $typeShort = is_string($type) ? preg_replace('/^(action|logic|trigger)\./', '', $type) : null;
                        if ($want !== $typeShort) {
                            $ok = false;
                            break;
                        }
                    }
                }
            }

            if (! $ok) {
                $this->failWithStepTable(sprintf(
                    "Step sequence mismatch.\nExpected: %s\nActual keys: %s\nActual types: %s",
                    json_encode($expected),
                    json_encode($actual),
                    json_encode($actualTypes),
                ));
            }
        }

        Assert::assertTrue(true);

        return $this;
    }

    public function assertStepStatus(string $nodeKeyOrType, AutomationRunStepStatus|string $status): self
    {
        $this->requireRun();
        $expected = $status instanceof AutomationRunStepStatus
            ? $status
            : AutomationRunStepStatus::from($status);

        $step = $this->findStep($nodeKeyOrType);
        if ($step === null) {
            $this->failWithStepTable("No step found for [{$nodeKeyOrType}].");
        }

        if ($step->status !== $expected) {
            $this->failWithStepTable(sprintf(
                'Expected step [%s] status [%s], got [%s].',
                $nodeKeyOrType,
                $expected->value,
                $step->status->value,
            ));
        }
        Assert::assertSame($expected, $step->status);

        return $this;
    }

    /**
     * @param  list<string>  $nodeKeys
     */
    public function assertSkipped(array $nodeKeys): self
    {
        $this->requireRun();

        foreach ($nodeKeys as $key) {
            $step = $this->findStep($key);
            if ($step === null) {
                $this->failWithStepTable("Expected skipped step [{$key}] but none was recorded.");
            }
            if ($step->status !== AutomationRunStepStatus::Skipped) {
                $this->failWithStepTable(sprintf(
                    'Expected step [%s] skipped, got [%s].',
                    $key,
                    $step->status->value,
                ));
            }
            Assert::assertSame(AutomationRunStepStatus::Skipped, $step->status);
        }

        return $this;
    }

    public function stepTable(): string
    {
        if ($this->run === null) {
            return "(no run)\n";
        }

        $this->run->loadMissing('steps');
        $nodes = AutomationNode::query()
            ->where('automation_id', $this->automation->id)
            ->pluck('node_key', 'id');

        $lines = [
            sprintf('Run #%d status=%s cause=%s', $this->run->id, $this->run->status->value, $this->run->cancel_cause?->value ?? '-'),
            str_pad('id', 6).str_pad('node_key', 24).str_pad('node_type', 28).str_pad('status', 12).'error',
            str_repeat('-', 90),
        ];

        foreach ($this->orderedSteps() as $step) {
            $key = $step->node_id !== null ? (string) ($nodes[$step->node_id] ?? '?') : '-';
            $error = is_array($step->error) ? json_encode($step->error) : (string) ($step->error ?? '');
            $lines[] = str_pad((string) $step->id, 6)
                .str_pad($key, 24)
                .str_pad((string) $step->node_type, 28)
                .str_pad($step->status->value, 12)
                .$error;
        }

        return implode("\n", $lines)."\n";
    }

    private function applyHarnessMetaToRun(): void
    {
        if ($this->run === null) {
            return;
        }

        $updates = [];

        if (array_key_exists('guard', $this->harnessMeta) && $this->harnessMeta['guard'] !== null) {
            $updates['guard'] = $this->harnessMeta['guard'];
        }

        if (is_array($this->harnessMeta['trigger_payload'] ?? null)) {
            $updates['trigger_payload'] = array_merge(
                $this->run->trigger_payload ?? [],
                $this->harnessMeta['trigger_payload'],
            );
        }

        if ($updates !== []) {
            $this->run->update($updates);
            $this->run->refresh();
        }
    }

    private function requireRun(): void
    {
        if ($this->run === null) {
            Assert::fail('No automation run captured yet. Call trigger() or triggerSchedule() first.');
        }
    }

    /** @return list<AutomationRunStep> */
    private function orderedSteps(): array
    {
        return $this->run?->steps()->orderBy('id')->get()->all() ?? [];
    }

    private function nodeKeyForStep(AutomationRunStep $step): ?string
    {
        if ($step->node_id === null) {
            return null;
        }

        return AutomationNode::query()->whereKey($step->node_id)->value('node_key');
    }

    private function findStep(string $nodeKeyOrType): ?AutomationRunStep
    {
        $nodeId = AutomationNode::query()
            ->where('automation_id', $this->automation->id)
            ->where('node_key', $nodeKeyOrType)
            ->value('id');

        if ($nodeId !== null) {
            return $this->run?->steps()->where('node_id', $nodeId)->orderByDesc('id')->first();
        }

        $exact = $this->run?->steps()
            ->where('node_type', $nodeKeyOrType)
            ->orderByDesc('id')
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        return $this->run?->steps()
            ->where('node_type', 'like', '%'.$nodeKeyOrType)
            ->orderByDesc('id')
            ->first();
    }

    private function failWithStepTable(string $message): never
    {
        Assert::fail($message."\n\n".$this->stepTable());
    }
}
