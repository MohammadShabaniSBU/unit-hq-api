<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Jobs\SyncAccess;
use Illuminate\Support\Facades\DB;

/**
 * afterCommit sync nudges from fact writers (suspension, occupancy, overlock, …).
 * Nudges are latency; the hourly full sync is authoritative.
 */
final class AccessSync
{
    public static function nudge(int $contractId): void
    {
        $dispatch = static function () use ($contractId): void {
            SyncAccess::dispatch(siteId: null, contractId: $contractId, withDrift: false);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }

    public static function nudgeSite(int $siteId): void
    {
        $dispatch = static function () use ($siteId): void {
            SyncAccess::dispatch(siteId: $siteId, contractId: null, withDrift: false);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }
}
