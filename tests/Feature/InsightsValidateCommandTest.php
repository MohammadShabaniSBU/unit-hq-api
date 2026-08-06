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
use App\Enums\InsightValidationStatus;
use App\Enums\InsightVisibility;
use App\Enums\LogChannel;
use App\Models\AnalyticsAccount;
use App\Models\Employee;
use App\Models\InsightReport;
use App\Models\InsightReportParam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class InsightsValidateCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function logs_only_transitions(): void
    {
        $employee = Employee::factory()->manager()->create();
        $account = AnalyticsAccount::query()->create([
            'provider' => AnalyticsProvider::Metabase,
            'display_name' => 'Test Metabase',
            'base_url' => 'https://metabase.example.com',
            'credentials' => [
                'embedding_secret_key' => 'embed-secret',
                'api_key' => 'mb_api_key',
            ],
            'is_default' => true,
            'connection_status' => CredentialStatus::Connected,
            'created_by' => $employee->id,
        ]);

        $report = InsightReport::query()->create([
            'key' => 'daily-board',
            'source' => InsightReportSource::Embedded,
            'analytics_account_id' => $account->id,
            'resource_kind' => InsightResourceKind::Dashboard,
            'resource_ref' => '10',
            'labels' => ['en' => 'Daily'],
            'sort_order' => 1,
            'visibility' => InsightVisibility::All,
            'site_scope_mode' => InsightSiteScopeMode::Inherit,
            'options' => [],
            'is_system' => false,
            'validation_status' => InsightValidationStatus::Valid,
            'created_by' => $employee->id,
        ]);

        InsightReportParam::query()->create([
            'insight_report_id' => $report->id,
            'name' => 'site_id',
            'value_source' => InsightParamValueSource::Dynamic,
            'dynamic_key' => 'current_site_id',
            'binding' => InsightParamBinding::Locked,
            'is_required' => true,
            'sort_order' => 0,
        ]);

        // First run: was valid, now mismatch → one activity row.
        Http::fake([
            'https://metabase.example.com/api/dashboard' => Http::response([
                ['id' => 10, 'name' => 'Ops', 'enable_embedding' => true],
            ], 200),
            'https://metabase.example.com/api/dashboard/10' => Http::response([
                'id' => 10,
                'parameters' => [
                    ['slug' => 'site_id', 'name' => 'Site', 'type' => 'id', 'required' => false],
                ],
                'embedding_params' => ['site_id' => 'enabled'],
            ], 200),
        ]);

        Artisan::call('insights:validate');

        $this->assertSame(
            InsightValidationStatus::ParamMismatch,
            $report->fresh()->validation_status,
        );
        $this->assertSame(1, Activity::query()
            ->where('description', 'insight.report.validation_failed')
            ->where('log_name', LogChannel::Facility->value)
            ->count());

        // Second run: still mismatch → no additional activity.
        Http::fake([
            'https://metabase.example.com/api/dashboard' => Http::response([
                ['id' => 10, 'name' => 'Ops', 'enable_embedding' => true],
            ], 200),
            'https://metabase.example.com/api/dashboard/10' => Http::response([
                'id' => 10,
                'parameters' => [
                    ['slug' => 'site_id', 'name' => 'Site', 'type' => 'id', 'required' => false],
                ],
                'embedding_params' => ['site_id' => 'enabled'],
            ], 200),
        ]);

        Artisan::call('insights:validate');

        $this->assertSame(1, Activity::query()
            ->where('description', 'insight.report.validation_failed')
            ->count());
    }
}
