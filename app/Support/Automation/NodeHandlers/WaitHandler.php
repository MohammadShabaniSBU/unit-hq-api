<?php

declare(strict_types=1);

namespace App\Support\Automation\NodeHandlers;

use App\Enums\AutomationRunStepStatus;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\Parked;
use App\Support\Automation\RunContext;
use App\Support\Automation\TokenResolver;
use Carbon\Carbon;
use RuntimeException;
use Throwable;

/**
 * Parks the run until a relative offset or absolute datetime token resolves.
 * Business-hours / weekday windows are out of scope (S09 playbook param if needed).
 */
final class WaitHandler implements NodeHandler
{
    public function handle(
        AutomationRun $run,
        AutomationRunStep $step,
        AutomationNode $node,
        RunContext $context,
    ): array {
        $config = $node->config ?? [];
        $mode = (string) ($config['mode'] ?? 'relative');
        $resumeAt = match ($mode) {
            'relative' => $this->resolveRelative($config),
            'until' => $this->resolveUntil($config, $context),
            default => throw new RuntimeException("logic.wait unknown mode '{$mode}'"),
        };

        $output = [
            'mode' => $mode,
            'resume_at' => $resumeAt->toIso8601String(),
        ];

        $step->update([
            'status' => AutomationRunStepStatus::Waiting,
            'output' => $output,
            'completed_at' => null,
            'duration_ms' => null,
        ]);

        throw new Parked($resumeAt, (int) $node->id);
    }

    /** @param  array<string, mixed>  $config */
    private function resolveRelative(array $config): Carbon
    {
        $amount = (int) ($config['amount'] ?? 0);
        $unit = (string) ($config['unit'] ?? 'days');

        if ($amount < 0) {
            throw new RuntimeException('logic.wait relative amount must be non-negative');
        }

        return match ($unit) {
            'minutes' => now()->addMinutes($amount),
            'hours' => now()->addHours($amount),
            'days' => now()->addDays($amount),
            default => throw new RuntimeException("logic.wait unknown unit '{$unit}'"),
        };
    }

    /** @param  array<string, mixed>  $config */
    private function resolveUntil(array $config, RunContext $context): Carbon
    {
        $expression = (string) ($config['expression'] ?? '');
        if ($expression === '') {
            throw new RuntimeException('logic.wait until expression is required');
        }

        $resolved = TokenResolver::resolve($expression, $context);
        if ($resolved === '') {
            throw new RuntimeException('logic.wait until expression resolved empty');
        }

        try {
            return Carbon::parse($resolved);
        } catch (Throwable $e) {
            throw new RuntimeException('logic.wait until expression is not a datetime: '.$resolved, 0, $e);
        }
    }
}
