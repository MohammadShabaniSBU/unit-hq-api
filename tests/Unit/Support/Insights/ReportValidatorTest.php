<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Insights;

use App\Enums\AnalyticsProvider;
use App\Enums\CredentialStatus;
use App\Enums\InsightParamBinding;
use App\Enums\InsightParamValueSource;
use App\Enums\InsightReportSource;
use App\Enums\InsightResourceKind;
use App\Enums\InsightSiteScopeMode;
use App\Enums\InsightValidationStatus;
use App\Enums\InsightVisibility;
use App\Models\AnalyticsAccount;
use App\Models\Employee;
use App\Models\InsightReport;
use App\Models\InsightReportParam;
use App\Support\Insights\AnalyticsProviderRegistry;
use App\Support\Insights\ReportValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportValidatorTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private ReportValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);
        $this->validator = new ReportValidator(app(AnalyticsProviderRegistry::class));
    }

    #[Test]
    public function locked_binding_requires_provider_locked(): void
    {
        $report = $this->makeReportWithParam('site_id', InsightParamBinding::Locked);
        $this->fakeResource(params: [
            ['slug' => 'site_id', 'name' => 'Site', 'type' => 'id', 'embedding_mode' => 'enabled', 'required' => false],
        ]);

        $result = $this->validator->validate($report->load(['params', 'analyticsAccount']));

        $this->assertSame(InsightValidationStatus::ParamMismatch, $result->status);
        $this->assertSame('embedding_mode_mismatch', $result->detail['reason'] ?? null);
        $this->assertStringContainsString('Locked', (string) ($result->detail['message'] ?? ''));
    }

    #[Test]
    public function default_binding_requires_provider_enabled(): void
    {
        $report = $this->makeReportWithParam(
            'charge_type',
            InsightParamBinding::Default,
            InsightParamValueSource::Static,
            staticValue: 'rent',
        );
        $this->fakeResource(params: [
            ['slug' => 'charge_type', 'name' => 'Charge', 'type' => 'string/=', 'embedding_mode' => 'locked', 'required' => false],
        ]);

        $result = $this->validator->validate($report->load(['params', 'analyticsAccount']));

        $this->assertSame(InsightValidationStatus::ParamMismatch, $result->status);
        $this->assertStringContainsString('Editable', (string) ($result->detail['message'] ?? ''));
    }

    #[Test]
    public function disabled_param_always_fails(): void
    {
        $report = $this->makeReportWithParam('site_id', InsightParamBinding::Locked);
        $this->fakeResource(params: [
            ['slug' => 'site_id', 'name' => 'Site', 'type' => 'id', 'embedding_mode' => 'disabled', 'required' => false],
        ]);

        $result = $this->validator->validate($report->load(['params', 'analyticsAccount']));

        $this->assertSame(InsightValidationStatus::ParamMismatch, $result->status);
    }

    #[Test]
    public function unknown_slug_is_param_mismatch(): void
    {
        $report = $this->makeReportWithParam('typo_slug', InsightParamBinding::Locked);
        $this->fakeResource(params: [
            ['slug' => 'site_id', 'name' => 'Site', 'type' => 'id', 'embedding_mode' => 'locked', 'required' => false],
        ]);

        $result = $this->validator->validate($report->load(['params', 'analyticsAccount']));

        $this->assertSame(InsightValidationStatus::ParamMismatch, $result->status);
        $this->assertSame('unknown_slugs', $result->detail['reason'] ?? null);
        $this->assertContains('typo_slug', $result->detail['unknown_slugs'] ?? []);
    }

    #[Test]
    public function type_mismatch_rejected(): void
    {
        $report = $this->makeReportWithParam(
            'site_ids',
            InsightParamBinding::Locked,
            InsightParamValueSource::Dynamic,
            dynamicKey: 'visible_site_ids',
        );
        $this->fakeResource(params: [
            ['slug' => 'site_ids', 'name' => 'Sites', 'type' => 'id', 'embedding_mode' => 'locked', 'required' => false],
        ]);

        $result = $this->validator->validate($report->load(['params', 'analyticsAccount']));

        $this->assertSame(InsightValidationStatus::ParamMismatch, $result->status);
        $this->assertSame('type_mismatch', $result->detail['reason'] ?? null);
    }

    #[Test]
    public function unreachable_provider_allows_save_with_warning(): void
    {
        $account = $this->makeAccount();
        Http::fake([
            'https://metabase.example.com/*' => Http::response('down', 503),
        ]);

        $response = $this->postJson('/api/settings/insight-reports', [
            'key' => 'unreachable-report',
            'source' => 'embedded',
            'analytics_account_id' => $account->id,
            'resource_kind' => 'dashboard',
            'resource_ref' => '10',
            'labels' => ['en' => 'Unreachable'],
            'params' => [
                [
                    'name' => 'site_id',
                    'value_source' => 'dynamic',
                    'dynamic_key' => 'current_site_id',
                    'binding' => 'locked',
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertTrue($response->json('data.validation_warning'));
        $this->assertSame(InsightValidationStatus::Unreachable->value, $response->json('data.validation_status'));
        $this->assertDatabaseHas('insight_reports', [
            'key' => 'unreachable-report',
            'validation_status' => InsightValidationStatus::Unreachable->value,
        ]);
    }

    #[Test]
    public function save_is_blocked_on_mismatch(): void
    {
        $account = $this->makeAccount();
        $this->fakeResource(
            accountBase: $account->base_url,
            params: [
                ['slug' => 'site_id', 'name' => 'Site', 'type' => 'id', 'embedding_mode' => 'enabled', 'required' => false],
            ],
        );

        $response = $this->postJson('/api/settings/insight-reports', [
            'key' => 'blocked-report',
            'source' => 'embedded',
            'analytics_account_id' => $account->id,
            'resource_kind' => 'dashboard',
            'resource_ref' => '10',
            'labels' => ['en' => 'Blocked'],
            'params' => [
                [
                    'name' => 'site_id',
                    'value_source' => 'dynamic',
                    'dynamic_key' => 'current_site_id',
                    'binding' => 'locked',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(InsightValidationStatus::ParamMismatch->value, $response->json('message'));
        $this->assertNotNull($response->json('errors.validation_detail'));
        $this->assertDatabaseMissing('insight_reports', ['key' => 'blocked-report']);
    }

    /**
     * @param  list<array<string, mixed>>  $params
     */
    private function fakeResource(
        array $params = [],
        string $accountBase = 'https://metabase.example.com',
        bool $enabled = true,
    ): void {
        $embeddingParams = [];
        foreach ($params as $param) {
            $embeddingParams[$param['slug']] = $param['embedding_mode'];
        }

        $parameters = array_map(static fn (array $p): array => [
            'slug' => $p['slug'],
            'name' => $p['name'],
            'type' => $p['type'],
            'required' => $p['required'] ?? false,
        ], $params);

        Http::fake([
            $accountBase.'/api/dashboard' => Http::response([
                [
                    'id' => 10,
                    'name' => 'Ops',
                    'enable_embedding' => $enabled,
                    'collection' => null,
                ],
            ], 200),
            $accountBase.'/api/dashboard/10' => Http::response([
                'id' => 10,
                'name' => 'Ops',
                'enable_embedding' => $enabled,
                'parameters' => $parameters,
                'embedding_params' => $embeddingParams,
            ], 200),
        ]);
    }

    private function makeAccount(): AnalyticsAccount
    {
        return AnalyticsAccount::query()->create([
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

    private function makeReportWithParam(
        string $name,
        InsightParamBinding $binding,
        InsightParamValueSource $valueSource = InsightParamValueSource::Dynamic,
        ?string $dynamicKey = 'current_site_id',
        mixed $staticValue = null,
    ): InsightReport {
        $account = $this->makeAccount();
        $report = InsightReport::query()->create([
            'key' => 'val-'.uniqid(),
            'source' => InsightReportSource::Embedded,
            'analytics_account_id' => $account->id,
            'resource_kind' => InsightResourceKind::Dashboard,
            'resource_ref' => '10',
            'labels' => ['en' => 'Val'],
            'sort_order' => 1,
            'visibility' => InsightVisibility::All,
            'site_scope_mode' => InsightSiteScopeMode::Inherit,
            'options' => [],
            'is_system' => false,
            'created_by' => $this->employee->id,
        ]);

        InsightReportParam::query()->create([
            'insight_report_id' => $report->id,
            'name' => $name,
            'value_source' => $valueSource,
            'static_value' => $staticValue,
            'dynamic_key' => $valueSource === InsightParamValueSource::Dynamic ? $dynamicKey : null,
            'binding' => $binding,
            'is_required' => true,
            'sort_order' => 0,
        ]);

        return $report;
    }
}
