<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Events\InboxBadgeUpdated;
use Illuminate\Support\Facades\DB;

final class InboxBadgeBroadcast
{
    public static function ping(): void
    {
        $dispatch = static function (): void {
            event(new InboxBadgeUpdated);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }
}
