<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Models\Campaign;
use App\Models\SubscriptionList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Postgres-only: two submits allocate consecutive issue numbers.
 * Skipped on SQLite.
 */
class NewsletterIssueRaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Child worker processes need committed rows.
     *
     * @var list<string|null>
     */
    protected $connectionsToTransact = [];

    public function test_concurrent_submits_take_consecutive_issue_numbers(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Race test is Postgres-only.');
        }

        $list = SubscriptionList::factory()->create();
        $first = Campaign::factory()->create([
            'type' => CampaignType::Newsletter,
            'status' => CampaignStatus::Draft,
            'subscription_list_id' => $list->id,
        ]);
        $second = Campaign::factory()->create([
            'type' => CampaignType::Newsletter,
            'status' => CampaignStatus::Draft,
            'subscription_list_id' => $list->id,
        ]);

        $basePath = base_path();
        $scriptPath = sys_get_temp_dir().'/newsletter_issue_race_'.uniqid('', true).'.php';

        file_put_contents($scriptPath, <<<PHP
<?php

declare(strict_types=1);

require '{$basePath}/vendor/autoload.php';

\$app = require '{$basePath}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\$campaignId = (int) (\$argv[1] ?? 0);

usleep(random_int(1000, 50000));

\$n = Illuminate\Support\Facades\DB::transaction(function () use (\$campaignId) {
    \$campaign = App\Models\Campaign::query()->whereKey(\$campaignId)->lockForUpdate()->firstOrFail();
    \$number = app(App\Support\Marketing\CampaignLifecycle::class)->assignNewsletterIssueNumber(\$campaign);
    \$campaign->newsletter_issue_number = \$number;
    \$campaign->save();

    return \$number;
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
            $results = Process::concurrently(function (Pool $pool) use ($basePath, $env, $scriptPath, $first, $second) {
                $pool->as('a')->path($basePath)->env($env)->command(['php', $scriptPath, (string) $first->id]);
                $pool->as('b')->path($basePath)->env($env)->command(['php', $scriptPath, (string) $second->id]);
            });

            $numbers = [];
            foreach (['a', 'b'] as $key) {
                $result = $results[$key];
                $this->assertTrue(
                    $result->successful(),
                    "Worker {$key} failed: {$result->errorOutput()} {$result->output()}"
                );
                $numbers[] = (int) trim($result->output());
            }

            sort($numbers);
            $this->assertSame([1, 2], $numbers);
        } finally {
            @unlink($scriptPath);
        }
    }
}
