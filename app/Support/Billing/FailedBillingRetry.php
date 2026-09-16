<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\BillingRunItemOutcome;
use App\Models\BillingRunItem;
use Illuminate\Support\Facades\DB;

final class FailedBillingRetry
{
    /**
     * Contract ids whose latest billing_run_items row is failed.
     *
     * @return Array<int, int>
     */
    public static function contractIds(?int $runId = null): array
    {
        $latestIds = BillingRunItem::query()
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('contract_id');

        $query = BillingRunItem::query()
            ->whereIn('id', $latestIds)
            ->where('outcome', BillingRunItemOutcome::Failed);

        if ($runId !== null) {
            $query->where('billing_run_id', $runId);
        }

        return $query->pluck('contract_id')->map(fn ($id): int => (int) $id)->all();
    }
}
