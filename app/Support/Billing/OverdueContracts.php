<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Query-time overdue contract detection — same definition as Contract::overdueAmount().
 */
final class OverdueContracts
{
    /**
     * @param  list<int>  $contractIds
     * @return list<int>
     */
    public static function idsAmong(array $contractIds): array
    {
        if ($contractIds === []) {
            return [];
        }

        $today = Carbon::today()->toDateString();

        $rows = DB::table('charges')
            ->leftJoin('allocations', 'allocations.charge_id', '=', 'charges.id')
            ->whereIn('charges.contract_id', $contractIds)
            ->where('charges.due_date', '<', $today)
            ->groupBy('charges.id', 'charges.contract_id', 'charges.amount')
            ->havingRaw('(CAST(charges.amount AS DECIMAL(10,2)) - COALESCE(SUM(allocations.amount), 0)) > 0')
            ->select('charges.contract_id')
            ->distinct()
            ->pluck('contract_id');

        return $rows
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
