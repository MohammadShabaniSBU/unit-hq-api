<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationCancelCause;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Enums\ConditionSource;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Single funnel for automation run status changes. Executors and cancellers race
 * via conditional UPDATEs on the status column — the row is the lock.
 */
final class RunLifecycle
{
    /**
     * @var array<string, list<AutomationRunStatus>>
     */
    private const MAP = [
        'pending' => [AutomationRunStatus::Running, AutomationRunStatus::Cancelled],
        'running' => [
            AutomationRunStatus::Waiting,
            AutomationRunStatus::Succeeded,
            AutomationRunStatus::Failed,
            AutomationRunStatus::Cancelled,
        ],
        'waiting' => [AutomationRunStatus::Running, AutomationRunStatus::Cancelled],
        'succeeded' => [],
        'failed' => [],
        'cancelled' => [],
    ];

    /**
     * @param  array<string, mixed>  $attrs
     *
     * @throws ValidationException
     */
    public static function transition(AutomationRun $run, AutomationRunStatus $to, array $attrs = []): bool
    {
        $from = $run->status;

        if (! self::isPermitted($from, $to)) {
            throw ValidationException::withMessages([
                'status' => [__('errors.automations.transition_not_allowed', [
                    'from' => $from->value,
                    'to' => $to->value,
                ])],
            ]);
        }

        $payload = array_merge($attrs, [
            'status' => $to->value,
            'updated_at' => now(),
        ]);

        if ($to->isTerminal()) {
            $payload['active_key'] = null;
        }

        $affected = AutomationRun::query()
            ->whereKey($run->id)
            ->where('status', $from->value)
            ->update($payload);

        $run->refresh();

        return $affected > 0;
    }

    public static function isPermitted(AutomationRunStatus $from, AutomationRunStatus $to): bool
    {
        return in_array($to, self::MAP[$from->value] ?? [], true);
    }

    /**
     * @return list<AutomationRunStatus>
     */
    public static function allowed(AutomationRunStatus $from): array
    {
        return self::MAP[$from->value] ?? [];
    }

    /**
     * Claim pending|waiting → running. Returns false if another actor won the race.
     */
    public static function claimRunning(AutomationRun $run): bool
    {
        $run->refresh();

        if (! in_array($run->status, [AutomationRunStatus::Pending, AutomationRunStatus::Waiting], true)) {
            return false;
        }

        return self::transition($run, AutomationRunStatus::Running, [
            'started_at' => $run->started_at ?? now(),
            'waiting_until' => null,
        ]);
    }

    public static function park(AutomationRun $run, CarbonInterface $until, int $currentNodeId): bool
    {
        $run->refresh();

        if ($run->status !== AutomationRunStatus::Running) {
            return false;
        }

        return self::transition($run, AutomationRunStatus::Waiting, [
            'waiting_until' => $until,
            'current_node_id' => $currentNodeId,
        ]);
    }

    public static function succeed(AutomationRun $run): bool
    {
        $run->refresh();

        if ($run->status !== AutomationRunStatus::Running) {
            return false;
        }

        return self::transition($run, AutomationRunStatus::Succeeded, [
            'completed_at' => now(),
            'error' => null,
            'waiting_until' => null,
            'current_node_id' => null,
        ]);
    }

    public static function fail(AutomationRun $run, string $error): bool
    {
        $run->refresh();

        if ($run->status !== AutomationRunStatus::Running) {
            return false;
        }

        return self::transition($run, AutomationRunStatus::Failed, [
            'error' => $error,
            'completed_at' => now(),
            'waiting_until' => null,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public static function cancel(
        AutomationRun $run,
        AutomationCancelCause $cause,
        ?Employee $by = null,
    ): bool {
        $run->refresh();

        if ($run->status->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => [__('errors.automations.already_terminal', [
                    'status' => $run->status->value,
                ])],
            ]);
        }

        if (! self::isPermitted($run->status, AutomationRunStatus::Cancelled)) {
            throw ValidationException::withMessages([
                'status' => [__('errors.automations.transition_not_allowed', [
                    'from' => $run->status->value,
                    'to' => AutomationRunStatus::Cancelled->value,
                ])],
            ]);
        }

        $ok = self::transition($run, AutomationRunStatus::Cancelled, [
            'cancel_cause' => $cause->value,
            'cancelled_by' => $by?->id,
            'completed_at' => now(),
            'waiting_until' => null,
        ]);

        if ($ok) {
            AutomationRunStep::query()->create([
                'run_id' => $run->id,
                'node_id' => null,
                'node_type' => 'run.cancelled',
                'status' => AutomationRunStepStatus::Succeeded,
                'input' => null,
                'output' => [
                    'cause' => $cause->value,
                    'cancelled_by' => $by?->id,
                ],
                'started_at' => now(),
                'completed_at' => now(),
                'duration_ms' => 0,
            ]);
        }

        return $ok;
    }

    /**
     * Guard true → continue. Guard fails → cancel(guard).
     * Null guard → no evaluator call. Subject load/eval errors → cancel(trigger_object_deleted).
     */
    public static function evaluateGuard(AutomationRun $run): void
    {
        $run->refresh();

        if ($run->guard === null || $run->status->isTerminal()) {
            return;
        }

        try {
            if ($run->subject_type === null || $run->subject_id === null) {
                self::cancel($run, AutomationCancelCause::TriggerObjectDeleted);

                return;
            }

            /** @var Model|null $subject */
            $subject = $run->subject()->first();
            if ($subject === null) {
                self::cancel($run, AutomationCancelCause::TriggerObjectDeleted);

                return;
            }

            $entityType = (string) $run->subject_type;
            $values = self::subjectValues($subject, $entityType);
            $context = new ConditionContext(
                source: ConditionSource::Live,
                entityType: $entityType,
            );
            $passes = ConditionEvaluator::matchesGroupWithContext($run->guard, $values, $context);

            if (! $passes) {
                self::cancel($run, AutomationCancelCause::Guard);
            }
        } catch (Throwable) {
            if (! $run->fresh()?->status->isTerminal()) {
                self::cancel($run->fresh() ?? $run, AutomationCancelCause::TriggerObjectDeleted);
            }
        }
    }

    /**
     * Live native scalars + EAV keyed as attr:{id}.
     *
     * @return array<string, mixed>
     */
    private static function subjectValues(Model $subject, string $entityType): array
    {
        $values = [];

        foreach ($subject->getAttributes() as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $values[(string) $key] = $value;
            }
        }

        foreach (CustomAttributeBag::forEntity($entityType, $subject->getKey()) as $key => $value) {
            $values[$key] = $value;
        }

        return $values;
    }
}
