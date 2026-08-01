<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\BillingRunItemOutcome;
use App\Models\BillingRun;
use App\Models\BillingRunItem;
use App\Support\Billing\BillingMath;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BillingRunResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BillingRun $run */
        $run = $this->resource;

        $startedAt = $run->started_at;
        $finishedAt = $run->finished_at;
        $durationSeconds = null;
        if ($startedAt !== null && $finishedAt !== null) {
            $durationSeconds = (int) $startedAt->diffInSeconds($finishedAt);
        }

        return [
            'id' => $run->id,
            'started_at' => $this->datetime($startedAt),
            'finished_at' => $this->datetime($finishedAt),
            'duration_seconds' => $durationSeconds,
            'trigger' => $run->trigger instanceof \BackedEnum
                ? $run->trigger->value
                : (string) $run->trigger,
            'horizon_date' => $this->date($run->horizon_date),
            'contracts_considered' => $run->contracts_considered,
            'contracts_billed' => $run->contracts_billed,
            'contracts_skipped' => $run->contracts_skipped,
            'contracts_failed' => $run->contracts_failed,
            'created_by' => $this->when(
                $run->relationLoaded('createdBy'),
                fn () => $run->createdBy !== null ? [
                    'id' => $run->createdBy->id,
                    'name' => $run->createdBy->name,
                ] : null
            ),
            'totals_by_currency' => $this->totalsByCurrency($run),
            'items' => $this->when(
                $run->relationLoaded('items'),
                fn () => BillingRunItemResource::collection($run->items)->resolve()
            ),
            'created_at' => $this->datetime($run->created_at),
        ];
    }

    /**
     * Gross billed totals grouped per currency — never summed across currencies.
     *
     * @return list<array{currency: string, amount: string}>
     */
    private function totalsByCurrency(BillingRun $run): array
    {
        /** @var Collection<int, BillingRunItem> $items */
        $items = $run->relationLoaded('items')
            ? $run->items
            : $run->items()->get();

        $totals = [];
        foreach ($items as $item) {
            $outcome = $item->outcome instanceof BillingRunItemOutcome
                ? $item->outcome
                : BillingRunItemOutcome::tryFrom((string) $item->outcome);

            if ($outcome !== BillingRunItemOutcome::Billed) {
                continue;
            }

            $currency = $item->currency;
            if ($currency === null || $currency === '') {
                continue;
            }

            $amount = $item->amount_total !== null ? (string) $item->amount_total : '0.00';
            if (! isset($totals[$currency])) {
                $totals[$currency] = '0.00';
            }
            $totals[$currency] = bcadd($totals[$currency], BillingMath::round2($amount), 2);
        }

        ksort($totals);

        $rows = [];
        foreach ($totals as $currency => $amount) {
            $rows[] = [
                'currency' => $currency,
                'amount' => $amount,
            ];
        }

        return $rows;
    }
}
