<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Enums\BillingRunItemOutcome;
use App\Enums\ContractStatus;
use App\Models\BillingRunItem;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

/**
 * Postgres-only: two overlapping billing runs never double-bill the same contract.
 * Skipped on SQLite (local default without docker-compose.test.yml).
 */
class RunConcurrencyTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    /**
     * Child worker processes need committed rows; skip the per-test transaction wrap.
     *
     * @var list<string|null>
     */
    protected $connectionsToTransact = [];

    public function test_overlapping_runs_single_bill(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Race test is Postgres-only.');
        }

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ZC']);
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            [
                'amount' => '100.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'billing_interval' => BillingInterval::Month,
            'billing_interval_count' => 1,
            'billing_anchor_model' => BillingAnchorModel::Anniversary,
            'billing_anchor_date' => '2026-01-15',
            'move_in_date' => '2026-01-15',
            'billed_through' => '2026-07-15',
            'start_date' => '2026-01-15',
        ]);
        $contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => '2026-01-15',
            'effective_to' => null,
        ]);

        $contractId = $contract->id;
        $basePath = base_path();
        $scriptPath = sys_get_temp_dir().'/billing_run_race_'.uniqid('', true).'.php';

        file_put_contents($scriptPath, <<<PHP
<?php

declare(strict_types=1);

require '{$basePath}/vendor/autoload.php';

\$app = require '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-08-15 12:00:00', 'Europe/Madrid'));

\$contractId = {$contractId};

usleep(random_int(1000, 80000));

\$run = (new App\Support\Billing\BillingRunEngine)->run(
    trigger: App\Enums\BillingRunTrigger::Manual,
    contractId: \$contractId,
);

echo \$run->id . ':' . \$run->contracts_billed . ':' . \$run->contracts_skipped;
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
            });

            foreach (['a', 'b'] as $key) {
                $result = $results[$key];
                $this->assertTrue(
                    $result->successful(),
                    "Worker {$key} failed: {$result->errorOutput()} {$result->output()}"
                );
            }

            $billedItems = BillingRunItem::query()
                ->where('contract_id', $contractId)
                ->where('outcome', BillingRunItemOutcome::Billed)
                ->count();
            $this->assertSame(1, $billedItems, 'Exactly one run may bill the contract');

            $contract->refresh();
            // Inclusive horizon: Jul→Aug and Aug→Sep in one bill under the lock.
            $this->assertSame('2026-09-15', $contract->billedThrough());
        } finally {
            @unlink($scriptPath);
            Carbon::setTestNow();
            // Committed outside RefreshDatabase — leave a clean DB for later tests.
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }
}
