<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SQLite: analytics migration must be a no-op so the default suite still runs.
 */
class AnalyticsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_noop_on_sqlite(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->markTestSkipped('SQLite-only: analytics schema is Postgres-only.');
        }

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::select('select * from analytics.v_revenue');
    }
}
