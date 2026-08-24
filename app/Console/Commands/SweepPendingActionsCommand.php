<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AgentPendingAction;
use App\Models\SystemEvent;
use App\Support\Ai\Enums\PendingActionStatus;
use Illuminate\Console\Command;

class SweepPendingActionsCommand extends Command
{
    protected $signature = 'agents:sweep-pending-actions';

    protected $description = 'Expire pending agent proposals past expires_at';

    public function handle(): int
    {
        $updated = AgentPendingAction::query()
            ->where('status', PendingActionStatus::Pending)
            ->where('expires_at', '<', now())
            ->update(['status' => PendingActionStatus::Expired]);

        $this->info("Expired {$updated} pending action(s).");

        if ($updated > 0) {
            SystemEvent::record('agents.pending_actions.swept', null, [
                'expired' => $updated,
            ]);
        }

        return self::SUCCESS;
    }
}
