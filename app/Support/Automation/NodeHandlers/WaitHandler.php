<?php

declare(strict_types=1);

namespace App\Support\Automation\NodeHandlers;

use App\Enums\AutomationRunStepStatus;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\Setting;
use App\Models\Site;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\Parked;
use App\Support\Automation\RunContext;
use App\Support\Automation\TokenResolver;
use Carbon\Carbon;
use RuntimeException;
use Throwable;

/**
 * Parks the run until a relative offset or absolute datetime token resolves.
 * Optional align=send_window snaps resume to the org send-window start in site TZ.
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
            'relative' => $this->resolveRelative($config, $run),
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
    private function resolveRelative(array $config, AutomationRun $run): Carbon
    {
        $amount = (int) ($config['amount'] ?? 0);
        $unit = (string) ($config['unit'] ?? 'days');

        if ($amount < 0) {
            throw new RuntimeException('logic.wait relative amount must be non-negative');
        }

        $resumeAt = match ($unit) {
            'minutes' => now()->addMinutes($amount),
            'hours' => now()->addHours($amount),
            'days' => now()->addDays($amount),
            default => throw new RuntimeException("logic.wait unknown unit '{$unit}'"),
        };

        if (($config['align'] ?? null) === 'send_window') {
            $resumeAt = $this->alignToSendWindow($resumeAt, $run);
        }

        return $resumeAt;
    }

    private function alignToSendWindow(Carbon $resumeAt, AutomationRun $run): Carbon
    {
        $window = Setting::general()->sendWindowStart;
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $window, $matches)) {
            $matches = [null, '9', '00'];
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        $tz = $this->resolveTimezone($run);

        $local = $resumeAt->copy()->timezone($tz)->setTime($hour, $minute, 0);
        if ($local->lt($resumeAt->copy()->timezone($tz))) {
            $local->addDay();
        }

        return $local->utc();
    }

    private function resolveTimezone(AutomationRun $run): string
    {
        try {
            $site = match ($run->subject_type) {
                'delinquency' => Delinquency::query()
                    ->with('contract.unitItem.item.site')
                    ->find($run->subject_id)
                    ?->contract
                    ?->unitItem
                    ?->item
                    ?->site,
                'deal' => Deal::query()->with('site')->find($run->subject_id)?->site,
                'contract' => Contract::query()
                    ->with('unitItem.item.site')
                    ->find($run->subject_id)
                    ?->unitItem
                    ?->item
                    ?->site,
                default => null,
            };

            if ($site instanceof Site && is_string($site->timezone) && $site->timezone !== '') {
                return $site->timezone;
            }
        } catch (Throwable) {
            // fall through
        }

        return (string) config('app.timezone', 'UTC');
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
