<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnalyticsProvider;
use App\Enums\CredentialStatus;
use App\Enums\InsightParamBinding;
use App\Enums\InsightParamValueSource;
use App\Enums\InsightReportSource;
use App\Enums\InsightResourceKind;
use App\Enums\InsightSiteScopeMode;
use App\Enums\InsightVisibility;
use App\Models\AnalyticsAccount;
use App\Models\Employee;
use App\Models\InsightReport;
use App\Models\InsightReportParam;
use App\Support\Insights\NativeReports;
use Database\Seeders\InsightReportSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InsightReportTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);
    }

    #[Test]
    public function dynamic_param_must_be_locked(): void
    {
        $report = $this->makeEmbeddedReport();

        $this->expectException(QueryException::class);

        InsightReportParam::query()->create([
            'insight_report_id' => $report->id,
            'name' => 'site_id',
            'value_source' => InsightParamValueSource::Dynamic,
            'static_value' => null,
            'dynamic_key' => 'current_site_id',
            'binding' => InsightParamBinding::Default,
            'is_required' => true,
            'sort_order' => 0,
        ]);
    }

    #[Test]
    public function native_requires_native_key(): void
    {
        $this->expectException(QueryException::class);

        InsightReport::query()->create([
            'key' => 'broken-native',
            'source' => InsightReportSource::Native,
            'native_key' => null,
            'sort_order' => 0,
            'visibility' => InsightVisibility::All,
            'site_scope_mode' => InsightSiteScopeMode::Inherit,
            'options' => [],
            'is_system' => false,
        ]);
    }

    #[Test]
    public function embedded_requires_account_and_resource(): void
    {
        $this->expectException(QueryException::class);

        InsightReport::query()->create([
            'key' => 'broken-embedded',
            'source' => InsightReportSource::Embedded,
            'native_key' => null,
            'analytics_account_id' => null,
            'resource_kind' => null,
            'resource_ref' => null,
            'sort_order' => 0,
            'visibility' => InsightVisibility::All,
            'site_scope_mode' => InsightSiteScopeMode::Inherit,
            'options' => [],
            'is_system' => false,
        ]);
    }

    #[Test]
    public function label_falls_back_across_locales(): void
    {
        $report = $this->makeEmbeddedReport([
            'labels' => ['es' => 'Solo español'],
        ]);

        $resolved = $report->resolveLabel('en');

        $this->assertSame('operator', $resolved['source']);
        $this->assertSame('Solo español', $resolved['label']);
    }

    #[Test]
    public function nav_feed_excludes_resource_identifiers(): void
    {
        $this->seed(InsightReportSeeder::class);
        $account = $this->makeAccount();
        $this->makeEmbeddedReport([
            'key' => 'ops-board',
            'analytics_account_id' => $account->id,
            'resource_ref' => '42',
            'labels' => ['en' => 'Ops board'],
            'sort_order' => 100,
        ]);

        $response = $this->getJson('/api/insights');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertGreaterThanOrEqual(11, count($data));

        foreach ($data as $item) {
            $this->assertArrayNotHasKey('resource_ref', $item);
            $this->assertArrayNotHasKey('analytics_account_id', $item);
            $this->assertArrayNotHasKey('params', $item);
        }

        $embedded = collect($data)->firstWhere('key', 'ops-board');
        $this->assertNotNull($embedded);
        $this->assertSame('Ops board', $embedded['label']);
        $this->assertSame('operator', $embedded['label_source']);
        $this->assertSame(CredentialStatus::Connected->value, $embedded['connection_status']);
        $this->assertArrayHasKey('options', $embedded);
        $this->assertArrayHasKey('validation_status', $embedded);
        $this->assertSame($account->provider->value, $embedded['provider']);
    }

    #[Test]
    public function reorder_is_atomic(): void
    {
        $this->seed(InsightReportSeeder::class);

        $ids = InsightReport::query()
            ->active()
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        $reversed = array_reverse($ids);

        $this->postJson('/api/settings/insight-reports/reorder', ['ids' => $reversed])
            ->assertOk();

        $ordered = InsightReport::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame($reversed, $ordered);
        $this->assertSame(range(0, count($ids) - 1), InsightReport::query()
            ->active()
            ->orderBy('sort_order')
            ->pluck('sort_order')
            ->all());
    }

    #[Test]
    public function system_report_cannot_be_archived(): void
    {
        $this->seed(InsightReportSeeder::class);
        $report = InsightReport::query()->where('native_key', 'dashboard')->firstOrFail();

        $this->postJson("/api/settings/insight-reports/{$report->id}/archive")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['insight_report']);

        $this->assertNull($report->fresh()->archived_at);
    }

    #[Test]
    public function patch_replaces_params_wholesale(): void
    {
        $report = $this->makeEmbeddedReport();

        InsightReportParam::query()->create([
            'insight_report_id' => $report->id,
            'name' => 'old_param',
            'value_source' => InsightParamValueSource::Static,
            'static_value' => 'keep-me-not',
            'binding' => InsightParamBinding::Default,
            'is_required' => true,
            'sort_order' => 0,
        ]);

        $this->patchJson("/api/settings/insight-reports/{$report->id}", [
            'params' => [
                [
                    'name' => 'site_id',
                    'value_source' => 'dynamic',
                    'dynamic_key' => 'current_site_id',
                    'binding' => 'locked',
                ],
                [
                    'name' => 'charge_type',
                    'value_source' => 'static',
                    'static_value' => 'rent',
                    'binding' => 'default',
                ],
            ],
        ])->assertOk();

        $names = $report->params()->pluck('name')->sort()->values()->all();
        $this->assertSame(['charge_type', 'site_id'], $names);
        $this->assertFalse($report->params()->where('name', 'old_param')->exists());
    }

    #[Test]
    public function delete_archives_not_deletes(): void
    {
        $report = $this->makeEmbeddedReport();

        $this->deleteJson("/api/settings/insight-reports/{$report->id}")
            ->assertOk();

        $this->assertDatabaseHas('insight_reports', [
            'id' => $report->id,
        ]);
        $this->assertNotNull($report->fresh()->archived_at);
    }

    #[Test]
    public function insights_check_reports_mismatches(): void
    {
        $this->seed(InsightReportSeeder::class);

        InsightReport::query()->where('native_key', 'demo')->delete();

        InsightReport::query()->create([
            'key' => 'ghost',
            'source' => InsightReportSource::Native,
            'native_key' => 'ghost-report',
            'sort_order' => 50,
            'visibility' => InsightVisibility::All,
            'site_scope_mode' => InsightSiteScopeMode::Inherit,
            'options' => [],
            'is_system' => false,
        ]);

        $exit = Artisan::call('insights:check');
        $this->assertSame(1, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('demo', $output);
        $this->assertStringContainsString('ghost-report', $output);
    }

    #[Test]
    public function native_label_resolves_to_i18n_key(): void
    {
        $this->seed(InsightReportSeeder::class);
        $report = InsightReport::query()->where('native_key', 'rent-roll')->firstOrFail();

        $resolved = $report->resolveLabel('en');

        $this->assertSame('i18n', $resolved['source']);
        $this->assertSame(NativeReports::get('rent-roll')['label_key'], $resolved['label']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAccount(): AnalyticsAccount
    {
        return AnalyticsAccount::query()->create([
            'provider' => AnalyticsProvider::Iframe,
            'display_name' => 'Test iframe',
            'base_url' => 'https://charts.example.com/embed/{resource}',
            'credentials' => [],
            'is_default' => true,
            'connection_status' => CredentialStatus::Connected,
            'created_by' => $this->employee->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmbeddedReport(array $overrides = []): InsightReport
    {
        $account = isset($overrides['analytics_account_id'])
            ? AnalyticsAccount::query()->findOrFail($overrides['analytics_account_id'])
            : $this->makeAccount();

        return InsightReport::query()->create(array_merge([
            'key' => 'embedded-'.uniqid(),
            'source' => InsightReportSource::Embedded,
            'native_key' => null,
            'analytics_account_id' => $account->id,
            'resource_kind' => InsightResourceKind::Question,
            'resource_ref' => '2',
            'labels' => ['en' => 'Embedded'],
            'description' => null,
            'icon' => 'i-lucide-chart-bar',
            'section' => 'operations',
            'sort_order' => 50,
            'visibility' => InsightVisibility::All,
            'site_scope_mode' => InsightSiteScopeMode::Inherit,
            'options' => [],
            'is_system' => false,
            'created_by' => $this->employee->id,
        ], $overrides));
    }
}
