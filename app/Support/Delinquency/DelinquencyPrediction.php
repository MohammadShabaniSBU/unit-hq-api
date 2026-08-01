<?php

declare(strict_types=1);

namespace App\Support\Delinquency;

use App\Enums\DelinquencyPolicyAction;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicyStep;
use App\Support\Billing\BillingMath;
use Carbon\CarbonImmutable;

/**
 * Display-only next-step prediction from case anchor + policy.
 * Never stored — must match what the next engine run would execute.
 */
final class DelinquencyPrediction
{
    /**
     * @return array{action: string, offset_days: int, days_until: int, predicted_on: string, policy_step_id: int}|null
     */
    public static function nextStep(Delinquency $case): ?array
    {
        if (! $case->isOpen() || $case->isPaused()) {
            return null;
        }

        $case->loadMissing(['policy.steps', 'steps', 'contract.unitItem.item.site']);
        $contract = $case->contract;
        if ($contract === null) {
            return null;
        }

        $today = DelinquencyState::siteToday($contract);
        $anchor = CarbonImmutable::parse($case->anchor_due_date->toDateString())->startOfDay();
        $elapsed = BillingMath::daysBetween($anchor, $today);

        $executedIds = $case->steps
            ->whereNotNull('policy_step_id')
            ->pluck('policy_step_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $unexecuted = $case->policy?->steps
            ?->sortBy([
                ['sort', 'asc'],
                ['id', 'asc'],
            ])
            ->filter(fn (DelinquencyPolicyStep $step): bool => ! in_array((int) $step->id, $executedIds, true))
            ->values();

        if ($unexecuted === null || $unexecuted->isEmpty()) {
            return null;
        }

        // Prefer the first already-due unexecuted step (what the next run fires),
        // otherwise the soonest future step.
        /** @var DelinquencyPolicyStep $next */
        $next = $unexecuted->first(
            fn (DelinquencyPolicyStep $step): bool => (int) $step->offset_days <= $elapsed
        ) ?? $unexecuted->first();

        $offset = (int) $next->offset_days;
        $daysUntil = max(0, $offset - $elapsed);
        $predictedOn = $today->addDays($daysUntil)->toDateString();

        $action = $next->action instanceof DelinquencyPolicyAction
            ? $next->action->value
            : (string) $next->action;

        return [
            'action' => $action,
            'offset_days' => $offset,
            'days_until' => $daysUntil,
            'predicted_on' => $predictedOn,
            'policy_step_id' => (int) $next->id,
        ];
    }
}
