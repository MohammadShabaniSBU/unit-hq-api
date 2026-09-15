<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SystemEventPartitions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MaintainSystemEventsCommand extends Command
{
    protected $signature = 'system-events:maintain';

    protected $description = 'Create upcoming system_events partitions and drop/prune rows past tier-1 retention';

    public function handle(): int
    {
        if (! Schema::hasTable('system_events')) {
            $this->warn('system_events table does not exist.');

            return self::FAILURE;
        }

        $retentionDays = (int) config('logging_tiers.tier1_retention_days', 90);
        $cutoff = now()->subDays($retentionDays);

        if (DB::getDriverName() === 'pgsql') {
            $this->ensureUpcomingPartitions();
            $this->dropOldPartitions($cutoff);
        } else {
            $deleted = DB::table('system_events')->where('created_at', '<', $cutoff)->delete();
            $this->info("Deleted {$deleted} system_events older than {$cutoff->toDateTimeString()}.");
        }

        return self::SUCCESS;
    }

    private function ensureUpcomingPartitions(): void
    {
        $months = [now()->startOfMonth(), now()->startOfMonth()->addMonth()];

        foreach ($months as $from) {
            if (SystemEventPartitions::ensureMonth($from)) {
                $this->info('Created partition system_events_'.$from->format('Y_m').'.');
            }
        }
    }

    private function dropOldPartitions(\Illuminate\Support\Carbon $cutoff): void
    {
        $partitions = DB::select(
            "SELECT inhrelid::regclass::text AS name
             FROM pg_inherits
             JOIN pg_class parent ON pg_inherits.inhparent = parent.oid
             WHERE parent.relname = 'system_events'"
        );

        foreach ($partitions as $partition) {
            $name = $partition->name;
            if (! preg_match('/system_events_(\d{4})_(\d{2})$/', $name, $m)) {
                continue;
            }

            $partitionStart = \Illuminate\Support\Carbon::createFromDate((int) $m[1], (int) $m[2], 1)->startOfMonth();
            $partitionEnd = $partitionStart->copy()->addMonth();

            if ($partitionEnd->lte($cutoff)) {
                DB::statement("DROP TABLE IF EXISTS {$name}");
                $this->info("Dropped partition {$name}.");
            }
        }
    }
}
