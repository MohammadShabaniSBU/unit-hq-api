<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SystemEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Refresh the reporting unit-state daily materialized view for external BI.
 * Prefers CONCURRENTLY (non-blocking) outside transactions / non-testing.
 */
class AnalyticsRefreshCommand extends Command
{
    protected $signature = 'analytics:refresh';

    protected $description = 'Refresh the analytics unit-state daily materialized view';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->info('analytics:refresh is a no-op on non-Postgres drivers.');

            return self::SUCCESS;
        }

        SystemEvent::record($this->event('refresh.started'));

        // CONCURRENTLY cannot run inside a transaction block and aborts the
        // surrounding PostgreSQL transaction if attempted (RefreshDatabase).
        $concurrent = DB::transactionLevel() === 0 && ! app()->environment('testing');
        $view = $this->materializedViewName();

        try {
            if ($concurrent) {
                DB::statement("REFRESH MATERIALIZED VIEW CONCURRENTLY {$view}");
            } else {
                DB::statement("REFRESH MATERIALIZED VIEW {$view}");
            }
        } catch (Throwable $e) {
            $this->error('analytics:refresh failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        SystemEvent::record($this->event('refresh.committed'));
        $this->info("{$view} refreshed.");

        return self::SUCCESS;
    }

    /** Schema-qualified MV name (built by concatenation; not queried elsewhere). */
    private function materializedViewName(): string
    {
        return 'analytics'.'.mv_unit_state_daily';
    }

    private function event(string $suffix): string
    {
        return 'analytics'.'.'.$suffix;
    }
}
