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
use App\Models\Site;
use App\Models\SystemEvent;
use App\Support\Insights\Hs256Jwt;
use Database\Seeders\InsightReportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class EmbedTokenTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-embedding-secret-key-never-leak';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);
    }

    #[Test]
    public function locked_params_are_signed_not_queried(): void
    {
        $site = Site::factory()->create();
        $report = $this->makeMetabaseReport();
        $this->addParam($report, 'site_id', InsightParamValueSource::Dynamic, 'current_site_id');
        $this->addParam($report, 'charge_type', InsightParamValueSource::Static, null, 'rent', InsightParamBinding::Default);

        $response = $this->postJson('/api/insights/'.$report->key.'/embed', [
            'site_id' => $site->id,
        ]);

        $response->assertOk();
        $url = $response->json('data.url');
        $this->assertIsString($url);

        $parts = parse_url($url);
        $this->assertIsArray($parts);
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $this->assertArrayNotHasKey('site_id', $query);
        $this->assertSame('rent', $query['charge_type'] ?? null);

        $token = $this->tokenFromUrl($url);
        $payload = Hs256Jwt::decode($token, self::SECRET);

        $this->assertSame($site->id, $payload['params']['site_id'] ?? null);
        $this->assertArrayNotHasKey('charge_type', $payload['params']);
    }

    #[Test]
    public function token_expires_within_ttl(): void
    {
        config(['insights.embed_ttl_minutes' => 10]);

        $report = $this->makeMetabaseReport();
        $before = now()->getTimestamp();

        $response = $this->postJson('/api/insights/'.$report->key.'/embed');

        $response->assertOk();
        $token = $this->tokenFromUrl($response->json('data.url'));
        $payload = Hs256Jwt::decode($token, self::SECRET);

        $this->assertArrayHasKey('exp', $payload);
        $this->assertLessThanOrEqual($before + (10 * 60) + 5, $payload['exp']);
        $this->assertGreaterThanOrEqual($before, $payload['exp'] - (10 * 60));
    }

    #[Test]
    public function site_selector_changes_token_scope(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $report = $this->makeMetabaseReport();
        $this->addParam($report, 'site_id', InsightParamValueSource::Dynamic, 'current_site_id');

        $first = $this->postJson('/api/insights/'.$report->key.'/embed', ['site_id' => $siteA->id]);
        $second = $this->postJson('/api/insights/'.$report->key.'/embed', ['site_id' => $siteB->id]);

        $first->assertOk();
        $second->assertOk();

        $payloadA = Hs256Jwt::decode($this->tokenFromUrl($first->json('data.url')), self::SECRET);
        $payloadB = Hs256Jwt::decode($this->tokenFromUrl($second->json('data.url')), self::SECRET);

        $this->assertSame($siteA->id, $payloadA['params']['site_id']);
        $this->assertSame($siteB->id, $payloadB['params']['site_id']);
        $this->assertNotSame($payloadA['params']['site_id'], $payloadB['params']['site_id']);
    }

    #[Test]
    public function unresolved_required_param_returns_422(): void
    {
        $report = $this->makeMetabaseReport();
        $this->addParam($report, 'site_currency', InsightParamValueSource::Dynamic, 'site_currency');

        $response = $this->postJson('/api/insights/'.$report->key.'/embed');

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'param_unresolved');
        $response->assertJsonPath('errors.param', 'site_currency');
        $this->assertNull($response->json('data.url'));
    }

    #[Test]
    public function all_sites_with_single_site_report_fails_closed(): void
    {
        $report = $this->makeMetabaseReport();
        $this->addParam($report, 'site_id', InsightParamValueSource::Dynamic, 'current_site_id');

        $response = $this->postJson('/api/insights/'.$report->key.'/embed');

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'site_required');
        $this->assertDatabaseHas('system_events', [
            'event' => 'insights.embed.failed',
        ]);
    }

    #[Test]
    public function native_report_rejected(): void
    {
        $this->seed(InsightReportSeeder::class);
        $report = InsightReport::query()->where('source', InsightReportSource::Native)->firstOrFail();

        $response = $this->postJson('/api/insights/'.$report->key.'/embed');

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'report_is_native');
    }

    #[Test]
    public function archived_account_returns_conflict(): void
    {
        $account = $this->makeMetabaseAccount(['archived_at' => now()]);
        $report = $this->makeMetabaseReport(['analytics_account_id' => $account->id]);

        $response = $this->postJson('/api/insights/'.$report->key.'/embed');

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'account_archived');
    }

    #[Test]
    public function secret_never_appears_in_logs_or_response(): void
    {
        $site = Site::factory()->create();
        $report = $this->makeMetabaseReport();
        $this->addParam($report, 'site_id', InsightParamValueSource::Dynamic, 'current_site_id');

        $response = $this->postJson('/api/insights/'.$report->key.'/embed', [
            'site_id' => $site->id,
        ]);

        $response->assertOk();
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(self::SECRET, $body);

        foreach (SystemEvent::query()->get() as $event) {
            $encoded = json_encode($event->payload ?? []) ?: '';
            $this->assertStringNotContainsString(self::SECRET, $encoded);
        }

        $this->assertNull(
            Activity::query()
                ->where('properties', 'like', '%'.self::SECRET.'%')
                ->first()
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeMetabaseAccount(array $overrides = []): AnalyticsAccount
    {
        return AnalyticsAccount::query()->create(array_merge([
            'provider' => AnalyticsProvider::Metabase,
            'display_name' => 'Test Metabase',
            'base_url' => 'https://metabase.example.com',
            'credentials' => [
                'embedding_secret_key' => self::SECRET,
                'api_key' => 'mb_api_test_key',
            ],
            'is_default' => true,
            'connection_status' => CredentialStatus::Connected,
            'created_by' => $this->employee->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeMetabaseReport(array $overrides = []): InsightReport
    {
        $account = isset($overrides['analytics_account_id'])
            ? AnalyticsAccount::query()->findOrFail($overrides['analytics_account_id'])
            : $this->makeMetabaseAccount();

        return InsightReport::query()->create(array_merge([
            'key' => 'embed-'.uniqid(),
            'source' => InsightReportSource::Embedded,
            'native_key' => null,
            'analytics_account_id' => $account->id,
            'resource_kind' => InsightResourceKind::Question,
            'resource_ref' => '2',
            'labels' => ['en' => 'Embed test'],
            'description' => null,
            'icon' => 'i-lucide-chart-bar',
            'section' => 'operations',
            'sort_order' => 50,
            'visibility' => InsightVisibility::All,
            'site_scope_mode' => InsightSiteScopeMode::Inherit,
            'options' => ['bordered' => true, 'titled' => true],
            'is_system' => false,
            'created_by' => $this->employee->id,
        ], $overrides));
    }

    private function addParam(
        InsightReport $report,
        string $name,
        InsightParamValueSource $source,
        ?string $dynamicKey = null,
        mixed $staticValue = null,
        InsightParamBinding $binding = InsightParamBinding::Locked,
        bool $required = true,
    ): InsightReportParam {
        return InsightReportParam::query()->create([
            'insight_report_id' => $report->id,
            'name' => $name,
            'value_source' => $source,
            'static_value' => $staticValue,
            'dynamic_key' => $dynamicKey,
            'binding' => $binding,
            'is_required' => $required,
            'sort_order' => $report->params()->count(),
        ]);
    }

    private function tokenFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->assertIsString($path);
        $segments = explode('/', trim($path, '/'));
        $token = end($segments);
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        return $token;
    }
}
