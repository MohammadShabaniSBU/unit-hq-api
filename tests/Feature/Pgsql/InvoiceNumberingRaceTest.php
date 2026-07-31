<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Models\InvoiceSeries;
use App\Models\LegalEntity;
use App\Support\Fiscal\InvoiceNumbering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Postgres-only: parallel transactions allocate consecutive distinct numbers.
 * Skipped on SQLite (local default without docker-compose.test.yml).
 */
class InvoiceNumberingRaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Child worker processes need committed rows; skip the per-test transaction wrap.
     *
     * @var list<string|null>
     */
    protected $connectionsToTransact = [];

    public function test_concurrent_allocations_distinct_consecutive(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Race test is Postgres-only.');
        }

        $entity = LegalEntity::factory()->create();
        $series = InvoiceSeries::factory()->create([
            'legal_entity_id' => $entity->id,
            'code' => 'RACE',
            'next_number' => 1,
            'is_default' => false,
        ]);

        $seriesId = $series->id;
        $basePath = base_path();
        $scriptPath = sys_get_temp_dir().'/invoice_numbering_race_'.uniqid('', true).'.php';

        file_put_contents($scriptPath, <<<PHP
<?php

declare(strict_types=1);

require '{$basePath}/vendor/autoload.php';

\$app = require '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\$seriesId = {$seriesId};

usleep(random_int(1000, 50000));

\$n = Illuminate\Support\Facades\DB::transaction(function () use (\$seriesId) {
    \$series = App\Models\InvoiceSeries::query()->findOrFail(\$seriesId);

    return App\Support\Fiscal\InvoiceNumbering::allocate(\$series);
});

echo \$n;
PHP);

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
        ];

        try {
            $results = Process::concurrently(function (Pool $pool) use ($basePath, $env, $scriptPath) {
                $pool->as('a')->path($basePath)->env($env)->command(['php', $scriptPath]);
                $pool->as('b')->path($basePath)->env($env)->command(['php', $scriptPath]);
                $pool->as('c')->path($basePath)->env($env)->command(['php', $scriptPath]);
                $pool->as('d')->path($basePath)->env($env)->command(['php', $scriptPath]);
            });

            $numbers = [];
            foreach (['a', 'b', 'c', 'd'] as $key) {
                $result = $results[$key];
                $this->assertTrue(
                    $result->successful(),
                    "Worker {$key} failed: {$result->errorOutput()} {$result->output()}"
                );
                $numbers[] = (int) trim($result->output());
            }

            sort($numbers);
            $this->assertSame([1, 2, 3, 4], $numbers);
            $this->assertSame(5, $series->fresh()->next_number);
        } finally {
            @unlink($scriptPath);
        }
    }

    public function test_lock_serialises_nested_allocations(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Race test is Postgres-only.');
        }

        $entity = LegalEntity::factory()->create();
        $series = InvoiceSeries::factory()->create([
            'legal_entity_id' => $entity->id,
            'code' => 'LOCK',
            'next_number' => 10,
            'is_default' => false,
        ]);

        $a = DB::transaction(fn () => InvoiceNumbering::allocate($series));
        $b = DB::transaction(fn () => InvoiceNumbering::allocate($series));

        $this->assertSame(10, $a);
        $this->assertSame(11, $b);
        $this->assertSame(12, $series->fresh()->next_number);
    }
}
