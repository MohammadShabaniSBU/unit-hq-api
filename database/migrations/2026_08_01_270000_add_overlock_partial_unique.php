<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One live overlock per unit. S01's overlap constraint exempts overlock, so
 * idempotency lives here (S07-03 / S07-02 thin helper).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX unit_holds_one_live_overlock_idx
                 ON unit_holds (unit_id)
                 WHERE hold_type = \'overlock\' AND released_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS unit_holds_one_live_overlock_idx');
        }
    }
};
