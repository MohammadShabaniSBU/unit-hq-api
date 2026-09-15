<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent monthly partitions for Postgres system_events.
 */
final class SystemEventPartitions
{
    public static function ensureMonth(CarbonInterface $month): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        $parent = DB::selectOne(
            "SELECT 1 FROM pg_class WHERE relname = 'system_events'",
        );
        if ($parent === null) {
            return false;
        }

        $from = $month->copy()->startOfMonth();
        $name = 'system_events_'.$from->format('Y_m');

        $exists = DB::selectOne(
            'SELECT 1 FROM pg_class WHERE relname = ?',
            [$name],
        );

        if ($exists !== null) {
            return false;
        }

        $to = $from->copy()->addMonth();
        DB::statement(sprintf(
            "CREATE TABLE %s PARTITION OF system_events FOR VALUES FROM ('%s') TO ('%s')",
            $name,
            $from->toDateString(),
            $to->toDateString(),
        ));
        DB::statement("CREATE INDEX {$name}_request_id_idx ON {$name} (request_id)");
        DB::statement("CREATE INDEX {$name}_event_created_at_idx ON {$name} (event, created_at)");
        DB::statement("CREATE INDEX {$name}_subject_created_at_idx ON {$name} (subject_type, subject_id, created_at)");

        return true;
    }
}
