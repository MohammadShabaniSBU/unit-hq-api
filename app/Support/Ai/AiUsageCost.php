<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AiModelPrice;
use App\Models\AiUsageEvent;

/**
 * Derive estimated AI cost at read time from the price version in effect on
 * started_at. Reasoning tokens are billed at the output rate.
 *
 * This is attribution telemetry — never ledger money and never reconciled to
 * a provider invoice.
 */
final class AiUsageCost
{
    /**
     * @return array{estimated_cost: string, currency: string}|null
     */
    public static function forEvent(AiUsageEvent $event): ?array
    {
        if ($event->provider === null || $event->model === null) {
            return null;
        }

        $price = AiModelPrice::query()
            ->activeFor(
                $event->provider,
                $event->model,
                $event->started_at->toDateString(),
            )
            ->first();

        if ($price === null) {
            return null;
        }

        return [
            'estimated_cost' => self::compute($event, $price),
            'currency' => $price->currency,
        ];
    }

    public static function compute(AiUsageEvent $event, AiModelPrice $price): string
    {
        $input = bcmul(
            bcdiv((string) $event->input_tokens, '1000000', 10),
            (string) $price->input_per_mtok,
            10,
        );

        $cachedRate = $price->cached_input_per_mtok ?? '0';
        $cached = bcmul(
            bcdiv((string) $event->cached_input_tokens, '1000000', 10),
            (string) $cachedRate,
            10,
        );

        // Reasoning tokens fold into the output rate (no dedicated rate column yet).
        $outputTokens = (string) ($event->output_tokens + $event->reasoning_tokens);
        $output = bcmul(
            bcdiv($outputTokens, '1000000', 10),
            (string) $price->output_per_mtok,
            10,
        );

        return bcadd(bcadd($input, $cached, 10), $output, 6);
    }
}
