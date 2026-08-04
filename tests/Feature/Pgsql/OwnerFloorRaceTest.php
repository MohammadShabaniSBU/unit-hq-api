<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Postgres-only: two concurrent revocations of the last two owner grants leave exactly one.
 */
class OwnerFloorRaceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string|null> */
    protected $connectionsToTransact = [];

    #[Test]
    public function concurrent_revocation_leaves_one_owner(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Owner floor race test is Postgres-only.');
        }

        RbacSystemRoleSeeder::upsertSystemRoles();
        $ownerRoleId = (int) Role::query()->where('key', 'owner')->value('id');

        $a = Employee::factory()->manager()->create();
        $b = Employee::factory()->manager()->create();

        $grantA = EmployeeRole::query()
            ->where('employee_id', $a->id)
            ->where('role_id', $ownerRoleId)
            ->whereNull('site_id')
            ->firstOrFail();
        $grantB = EmployeeRole::query()
            ->where('employee_id', $b->id)
            ->where('role_id', $ownerRoleId)
            ->whereNull('site_id')
            ->firstOrFail();

        $basePath = base_path();
        $scriptPath = sys_get_temp_dir().'/owner_floor_race_'.uniqid('', true).'.php';
        $grantIds = var_export([(int) $grantA->id, (int) $grantB->id], true);

        file_put_contents($scriptPath, <<<PHP
<?php

declare(strict_types=1);

require '{$basePath}/vendor/autoload.php';

\$app = require '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\$grantIds = {$grantIds};
\$grantId = \$grantIds[(int) \$argv[1]];

usleep(random_int(1000, 80000));

try {
    \$grant = App\Models\EmployeeRole::query()->findOrFail(\$grantId);
    App\Support\Auth\OwnerFloor::revoke(\$grant);
    echo "ok";
} catch (Illuminate\Validation\ValidationException \$e) {
    echo "blocked";
} catch (Throwable \$e) {
    fwrite(STDERR, \$e->getMessage());
    exit(1);
}
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
            $results = Process::concurrently(function (Pool $pool) use ($scriptPath, $env, $basePath): void {
                $pool->as('a')->path($basePath)->env($env)->command(['php', $scriptPath, '0']);
                $pool->as('b')->path($basePath)->env($env)->command(['php', $scriptPath, '1']);
            });

            $outcomes = [];
            foreach (['a', 'b'] as $key) {
                $result = $results[$key];
                $this->assertTrue(
                    $result->successful(),
                    "Worker {$key} failed: {$result->errorOutput()} {$result->output()}"
                );
                $outcomes[] = trim($result->output());
            }

            sort($outcomes);
            $this->assertSame(['blocked', 'ok'], $outcomes);
            $this->assertSame(
                1,
                EmployeeRole::query()->where('role_id', $ownerRoleId)->whereNull('site_id')->count(),
            );
        } finally {
            @unlink($scriptPath);
            // DB cascade skips Eloquent OwnerFloor hooks.
            Employee::query()->whereIn('id', [$a->id, $b->id])->delete();
        }
    }
}
