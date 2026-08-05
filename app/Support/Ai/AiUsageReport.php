<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AiUsageEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds grouped AI usage totals with per-currency estimated cost.
 * Never sums cost across currencies.
 */
final class AiUsageReport
{
    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{
     *         from: string,
     *         to: string,
     *         group_by: string,
     *         orphaned_count: int,
     *         estimated_token_share: float
     *     }
     * }
     */
    public static function build(
        Carbon $from,
        Carbon $to,
        string $groupBy,
        ?int $employeeId = null,
    ): array {
        $events = AiUsageEvent::query()
            ->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->get();

        $orphanedCount = $events->where('status', AiUsageEvent::STATUS_ORPHANED)->count();

        $settled = $events->whereIn('status', [
            AiUsageEvent::STATUS_OK,
            AiUsageEvent::STATUS_FAILED,
            AiUsageEvent::STATUS_FAILED_OVER,
        ]);

        $totalTokens = (int) $settled->sum(fn (AiUsageEvent $e) => $e->totalTokens());
        $estimatedTokens = (int) $settled
            ->where('tokens_estimated', true)
            ->sum(fn (AiUsageEvent $e) => $e->totalTokens());

        $estimatedShare = $totalTokens > 0
            ? round($estimatedTokens / $totalTokens, 4)
            : 0.0;

        $groups = $settled
            ->groupBy(fn (AiUsageEvent $e) => self::groupKey($e, $groupBy))
            ->map(fn (Collection $rows, string|int $key) => self::summarizeGroup((string) $key, $rows, $groupBy))
            ->values()
            ->all();

        return [
            'data' => $groups,
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'group_by' => $groupBy,
                'orphaned_count' => $orphanedCount,
                'estimated_token_share' => $estimatedShare,
            ],
        ];
    }

    private static function groupKey(AiUsageEvent $event, string $groupBy): string
    {
        return match ($groupBy) {
            'employee' => (string) ($event->employee_id ?? 'null'),
            'model' => (string) ($event->model ?? 'unknown'),
            'purpose' => $event->purpose,
            'day' => $event->started_at->toDateString(),
            default => (string) ($event->employee_id ?? 'null'),
        };
    }

    /**
     * @param  Collection<int, AiUsageEvent>  $rows
     * @return array<string, mixed>
     */
    private static function summarizeGroup(string $key, Collection $rows, string $groupBy): array
    {
        /** @var array<string, array{currency: string, estimated_cost: string, input_tokens: int, cached_input_tokens: int, output_tokens: int, reasoning_tokens: int, tool_calls: int, turns: int}> $byCurrency */
        $byCurrency = [];

        foreach ($rows as $event) {
            $cost = AiUsageCost::forEvent($event);
            $currency = $cost['currency'] ?? 'NONE';

            if (! isset($byCurrency[$currency])) {
                $byCurrency[$currency] = [
                    'currency' => $currency === 'NONE' ? null : $currency,
                    'estimated_cost' => '0.000000',
                    'input_tokens' => 0,
                    'cached_input_tokens' => 0,
                    'output_tokens' => 0,
                    'reasoning_tokens' => 0,
                    'tool_calls' => 0,
                    'turns' => 0,
                ];
            }

            $byCurrency[$currency]['estimated_cost'] = bcadd(
                $byCurrency[$currency]['estimated_cost'],
                $cost['estimated_cost'] ?? '0',
                6,
            );
            $byCurrency[$currency]['input_tokens'] += $event->input_tokens;
            $byCurrency[$currency]['cached_input_tokens'] += $event->cached_input_tokens;
            $byCurrency[$currency]['output_tokens'] += $event->output_tokens;
            $byCurrency[$currency]['reasoning_tokens'] += $event->reasoning_tokens;
            $byCurrency[$currency]['tool_calls'] += $event->tool_calls;
            $byCurrency[$currency]['turns']++;
        }

        $groupField = match ($groupBy) {
            'employee' => 'employee_id',
            'model' => 'model',
            'purpose' => 'purpose',
            'day' => 'day',
            default => 'employee_id',
        };

        $groupValue = match ($groupBy) {
            'employee' => $key === 'null' ? null : (int) $key,
            default => $key,
        };

        return [
            $groupField => $groupValue,
            'currencies' => array_values($byCurrency),
        ];
    }
}
