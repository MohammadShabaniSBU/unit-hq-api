<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnalyticsProvider;
use App\Enums\CredentialStatus;
use App\Enums\InsightParamBinding;
use App\Enums\InsightParamValueSource;
use App\Enums\InsightReportSource;
use App\Enums\InsightValidationStatus;
use App\Enums\InsightVisibility;
use App\Models\AnalyticsAccount;
use App\Models\Employee;
use App\Models\InsightProvisionedResource;
use App\Models\InsightReport;
use App\Support\Insights\Provisioning\MetabaseBlueprints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InsightsProvisionCommandTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private AnalyticsAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        $this->account = AnalyticsAccount::query()->create([
            'provider' => AnalyticsProvider::Metabase,
            'display_name' => 'Test Metabase',
            'base_url' => 'https://metabase.example.com',
            'credentials' => [
                'embedding_secret_key' => 'embed-secret',
                'api_key' => 'mb_api_key',
            ],
            'is_default' => true,
            'connection_status' => CredentialStatus::Connected,
            'created_by' => $this->employee->id,
        ]);
    }

    #[Test]
    public function full_flow_dry_runs_then_writes_and_registers(): void
    {
        $this->fakeMetabase();

        $exit = Artisan::call('insights:provision', ['--only' => ['rent-roll']]);
        $this->assertSame(0, $exit);

        $recorded = Http::recorded();
        $events = collect($recorded)->map(
            fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url(),
        );

        $datasetIndexes = $events->values()->filter(
            fn (string $line): bool => str_contains($line, '/api/dataset'),
        )->keys();
        $firstWrite = $events->search(
            fn (string $line): bool => str_contains($line, '/api/card')
                || (str_contains($line, '/api/dashboard') && str_starts_with($line, 'POST')),
        );

        $this->assertSame(count(MetabaseBlueprints::get('rent-roll')['cards']) + 1, $datasetIndexes->count());
        $this->assertNotFalse($firstWrite);
        $this->assertLessThan($firstWrite, $datasetIndexes->max());

        $enable = collect($recorded)->first(
            function (array $pair): bool {
                $data = $pair[0]->data();

                return ($data['enable_embedding'] ?? false) === true
                    && ($data['embedding_params']['site_id'] ?? null) === 'locked';
            },
        );
        $this->assertNotNull($enable);

        $cardPost = collect($recorded)->first(
            fn (array $pair): bool => $pair[0]->method() === 'POST'
                && str_contains($pair[0]->url(), '/api/card'),
        );
        $this->assertNotNull($cardPost);
        $cardJson = json_decode($cardPost[0]->body());
        $this->assertIsObject($cardJson?->visualization_settings);

        $dashPut = collect($recorded)->first(
            fn (array $pair): bool => $pair[0]->method() === 'PUT'
                && str_contains($pair[0]->url(), '/api/dashboard'),
        );
        $this->assertNotNull($dashPut);
        $dashParams = $dashPut[0]->data()['parameters'] ?? [];
        $this->assertSame('id', $dashParams[0]['type'] ?? null);
        $this->assertSame('card', $dashParams[0]['values_source_type'] ?? null);

        $report = InsightReport::query()->where('key', 'mb-rent-roll')->first();
        $this->assertNotNull($report);
        $this->assertFalse($report->is_system);
        $this->assertSame(InsightReportSource::Embedded, $report->source);
        $this->assertSame(InsightValidationStatus::Valid, $report->validation_status);
        $this->assertNull($report->validation_detail);

        $param = $report->params()->where('name', 'site_id')->first();
        $this->assertNotNull($param);
        $this->assertSame(InsightParamValueSource::Dynamic, $param->value_source);
        $this->assertSame('current_site_id', $param->dynamic_key);
        $this->assertSame(InsightParamBinding::Locked, $param->binding);
        $this->assertFalse($param->is_required);

        $resource = InsightProvisionedResource::query()
            ->where('blueprint_key', 'rent-roll')
            ->first();
        $this->assertNotNull($resource);
        $this->assertSame(MetabaseBlueprints::hash('rent-roll'), $resource->definition_hash);
        $this->assertSame((string) $report->id, (string) $resource->insight_report_id);
    }

    #[Test]
    public function unchanged_rerun_makes_no_writes(): void
    {
        $this->fakeMetabase();
        Artisan::call('insights:provision', ['--only' => ['rent-roll']]);

        Http::fake();
        $this->fakeMetabase();

        $exit = Artisan::call('insights:provision', ['--only' => ['rent-roll']]);
        $this->assertSame(0, $exit);

        $writes = collect(Http::recorded())->filter(function (array $pair): bool {
            $method = $pair[0]->method();
            $url = $pair[0]->url();

            return in_array($method, ['POST', 'PUT'], true)
                && (str_contains($url, '/api/card')
                    || str_contains($url, '/api/dashboard')
                    || str_contains($url, '/api/collection')
                    || str_contains($url, '/api/dataset'));
        });

        $this->assertCount(0, $writes);
        $this->assertSame(1, InsightReport::query()->where('key', 'mb-rent-roll')->count());
        $this->assertSame(1, InsightProvisionedResource::query()->where('blueprint_key', 'rent-roll')->count());
    }

    #[Test]
    public function force_updates_cards_without_duplicate_dashboards(): void
    {
        $this->fakeMetabase();
        Artisan::call('insights:provision', ['--only' => ['rent-roll']]);

        Http::fake();
        $this->fakeMetabase();

        $exit = Artisan::call('insights:provision', [
            '--only' => ['rent-roll'],
            '--force' => true,
        ]);
        $this->assertSame(0, $exit);

        $events = collect(Http::recorded())->map(
            fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url(),
        );

        $this->assertTrue($events->contains(
            fn (string $line): bool => str_starts_with($line, 'PUT') && str_contains($line, '/api/card/'),
        ));
        $this->assertFalse($events->contains(
            fn (string $line): bool => str_starts_with($line, 'POST') && str_ends_with($line, '/api/dashboard'),
        ));
        $this->assertSame(1, InsightReport::query()->where('key', 'mb-rent-roll')->count());
    }

    #[Test]
    public function second_run_leaves_operator_labels_and_sort_order(): void
    {
        $this->fakeMetabase();
        Artisan::call('insights:provision', ['--only' => ['rent-roll']]);

        $report = InsightReport::query()->where('key', 'mb-rent-roll')->firstOrFail();
        $report->update([
            'labels' => ['en' => 'Cartera'],
            'sort_order' => 99,
            'visibility' => InsightVisibility::CompanyOnly,
        ]);

        Http::fake();
        $this->fakeMetabase();

        Artisan::call('insights:provision', [
            '--only' => ['rent-roll'],
            '--force' => true,
        ]);

        $report->refresh();
        $this->assertSame(['en' => 'Cartera'], $report->labels);
        $this->assertSame(99, $report->sort_order);
        $this->assertSame(InsightVisibility::CompanyOnly, $report->visibility);
        $this->assertNull($report->validation_detail);
        $this->assertSame(InsightValidationStatus::Valid, $report->validation_status);
    }

    #[Test]
    public function dry_run_failure_writes_nothing(): void
    {
        $this->fakeMetabase(failDataset: true);

        $exit = Artisan::call('insights:provision', [
            '--only' => ['rent-roll'],
            '--dry-run' => true,
        ]);
        $this->assertSame(1, $exit);

        $writes = collect(Http::recorded())->filter(function (array $pair): bool {
            $url = $pair[0]->url();

            return in_array($pair[0]->method(), ['POST', 'PUT'], true)
                && (str_contains($url, '/api/card') || str_contains($url, '/api/dashboard') || str_contains($url, '/api/collection'));
        });

        $this->assertCount(0, $writes);
        $this->assertSame(0, InsightReport::query()->count());
        $this->assertSame(0, InsightProvisionedResource::query()->count());
    }

    #[Test]
    public function disconnected_account_aborts_without_http(): void
    {
        $this->account->update(['connection_status' => CredentialStatus::Pending]);
        Http::fake();

        $exit = Artisan::call('insights:provision', ['--only' => ['rent-roll']]);
        $this->assertSame(1, $exit);
        Http::assertNothingSent();
        $this->assertSame(0, InsightProvisionedResource::query()->count());
    }

    private function fakeMetabase(bool $failDataset = false): void
    {
        $cardSeq = 10;

        Http::fake(function (Request $request) use (&$cardSeq, $failDataset) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_contains($url, '/api/database')) {
                return Http::response(['data' => [['id' => 2, 'name' => 'keevaris']]], 200);
            }

            if ($method === 'GET' && str_contains($url, '/api/collection')) {
                return Http::response([
                    ['id' => 7, 'name' => 'Keevaris', 'archived' => false],
                ], 200);
            }

            if ($method === 'POST' && str_contains($url, '/api/dataset')) {
                if ($failDataset) {
                    return Http::response([
                        'status' => 'failed',
                        'error' => 'permission denied for relation public.charges',
                    ], 200);
                }

                return Http::response(['status' => 'completed'], 200);
            }

            if ($method === 'POST' && str_contains($url, '/api/card')) {
                $cardSeq++;

                return Http::response(['id' => $cardSeq], 200);
            }

            if ($method === 'PUT' && preg_match('#/api/card/(\d+)#', $url, $matches) === 1) {
                return Http::response(['id' => (int) $matches[1]], 200);
            }

            if ($method === 'POST' && str_contains($url, '/api/dashboard') && ! preg_match('#/api/dashboard/\d+#', $url)) {
                return Http::response(['id' => 40], 200);
            }

            if ($method === 'PUT' && str_contains($url, '/api/dashboard')) {
                return Http::response(['id' => 40], 200);
            }

            if ($method === 'POST' && str_contains($url, '/api/collection')) {
                return Http::response(['id' => 7], 200);
            }

            return Http::response(['error' => 'unfaked '.$method.' '.$url], 500);
        });
    }
}
