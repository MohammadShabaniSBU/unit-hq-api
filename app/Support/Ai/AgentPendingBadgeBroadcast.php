<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Events\AgentPendingBadgeUpdated;
use App\Models\AgentPendingAction;
use App\Support\Communications\InboxBadgeBroadcast;
use Illuminate\Support\Facades\DB;

final class AgentPendingBadgeBroadcast
{
    public static function ping(): void
    {
        $dispatch = static function (): void {
            event(new AgentPendingBadgeUpdated);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }

    public static function pingFor(AgentPendingAction $action): void
    {
        self::ping();

        if ($action->tool_key === 'channel.send') {
            InboxBadgeBroadcast::ping();
        }
    }
}
