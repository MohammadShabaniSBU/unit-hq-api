<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Postgres-only: metabase_ro-style grants can read analytics and cannot read public.
 *
 * Runs outside the per-test transaction so a second connection can observe grants.
 */
class AnalyticsGrantsTest extends TestCase
{
    use RefreshDatabase;

    private string $role = 'analytics_grants_test_ro';

    private string $password = 'test-grants-secret';

    /**
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Analytics grants are Postgres-only.');
        }

        $this->dropRole();

        DB::statement(sprintf(
            "CREATE ROLE %s LOGIN PASSWORD '%s' CONNECTION LIMIT 5",
            $this->role,
            $this->password,
        ));
        DB::statement(sprintf('GRANT USAGE ON SCHEMA analytics TO %s', $this->role));
        DB::statement(sprintf('GRANT SELECT ON ALL TABLES IN SCHEMA analytics TO %s', $this->role));
        DB::statement(sprintf('REVOKE ALL ON SCHEMA public FROM %s', $this->role));
        DB::statement(sprintf('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM %s', $this->role));
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->dropRole();
        }

        parent::tearDown();
    }

    public function test_reporting_role_cannot_read_public(): void
    {
        $conn = $this->reportingConnection();

        $rows = $conn->select('select count(*)::int as n from analytics.v_revenue');
        $this->assertSame(0, (int) $rows[0]->n);

        foreach ([
            'analytics.v_payments',
            'analytics.v_rent_roll',
            'analytics.mv_unit_state_daily',
            'analytics.v_pipeline_events',
        ] as $view) {
            $conn->select("select 1 from {$view} limit 1");
        }

        $denied = false;
        try {
            $conn->select('select 1 from public.charges limit 1');
        } catch (\Throwable) {
            $denied = true;
        }
        $this->assertTrue($denied, 'Reporting role must not SELECT public.charges');
    }

    private function reportingConnection(): \Illuminate\Database\Connection
    {
        $base = config('database.connections.pgsql');
        config([
            'database.connections.analytics_grants_test' => array_merge($base, [
                'username' => $this->role,
                'password' => $this->password,
            ]),
        ]);

        DB::purge('analytics_grants_test');

        return DB::connection('analytics_grants_test');
    }

    private function dropRole(): void
    {
        DB::statement(sprintf(
            "DO \$\$
            BEGIN
                REASSIGN OWNED BY %s TO CURRENT_USER;
                DROP OWNED BY %s;
                DROP ROLE IF EXISTS %s;
            EXCEPTION WHEN undefined_object THEN
                NULL;
            END
            \$\$;",
            $this->role,
            $this->role,
            $this->role,
        ));
    }
}
