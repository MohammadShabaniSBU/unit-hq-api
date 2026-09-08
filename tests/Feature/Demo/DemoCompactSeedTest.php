<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\Site;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoPipeline;
use Database\Seeders\Demo\DemoRbacGrants;
use Database\Seeders\Demo\DemoWorld;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Compact demo world: 10 days, MAD-01 + MAD-02, cast only.
 */
class DemoCompactSeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DemoClock needs afterCommit hooks; do not wrap the pipeline in a transaction.
     *
     * @var list<string|null>
     */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        DemoWorld::setCurrent(null);
        CastExecutor::resetWindow();

        parent::tearDown();
    }

    public function test_compact_pipeline_is_two_sites_and_ten_days(): void
    {
        $result = DemoPipeline::run($this->app, withCrowd: false, compact: true);

        $this->assertTrue(CastExecutor::isCompact());
        $this->assertSame(10, $result['days']);
        $this->assertSame(0, $result['crowd_count']);
        $this->assertSame('2025-06-10', CastExecutor::simEnd());

        $codes = Site::query()->orderBy('code')->pluck('code')->all();
        $this->assertSame(['MAD-01', 'MAD-02'], $codes);

        $this->assertTrue(
            Contact::query()
                ->where('first_name', 'Lucía')
                ->where('last_name', 'Ferrer')
                ->exists(),
            'Lucía Ferrer should exist after a compact seed',
        );

        $this->assertTrue(
            Employee::query()->where('email', 'agent-mad@example.com')->exists(),
        );
        $this->assertTrue(
            Employee::query()->where('email', 'agent-norte@example.com')->exists(),
        );
        $this->assertFalse(
            Employee::query()->where('email', 'agent-sur@example.com')->exists(),
            'agent-sur must not be pinned onto MAD-01 in compact',
        );
        $this->assertFalse(
            Employee::query()->where('email', 'sm-mad-03@example.com')->exists(),
        );

        DemoRbacGrants::verifyOrFail();
    }
}
