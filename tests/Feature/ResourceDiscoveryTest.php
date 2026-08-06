<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AnalyticsProvider;
use App\Enums\CredentialStatus;
use App\Models\AnalyticsAccount;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResourceDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private const API_KEY = 'mb_super_secret_api_key_do_not_echo';

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);
    }

    #[Test]
    public function lists_dashboards_and_questions(): void
    {
        $account = $this->makeMetabaseAccount();

        Http::fake([
            'https://metabase.example.com/api/dashboard' => Http::response([
                [
                    'id' => 10,
                    'name' => 'Ops Board',
                    'enable_embedding' => true,
                    'collection' => ['name' => 'Company'],
                ],
                [
                    'id' => 11,
                    'name' => 'Draft Board',
                    'enable_embedding' => false,
                    'collection' => null,
                ],
            ], 200),
            'https://metabase.example.com/api/card' => Http::response([
                [
                    'id' => 2,
                    'name' => 'Rent question',
                    'enable_embedding' => true,
                    'collection' => ['name' => 'Questions'],
                ],
            ], 200),
        ]);

        $dashboards = $this->getJson("/api/settings/analytics-accounts/{$account->id}/resources?kind=dashboard");
        $dashboards->assertOk();
        $dashData = $dashboards->json('data');
        $this->assertCount(2, $dashData);
        $this->assertSame('10', $dashData[0]['ref']);
        $this->assertTrue($dashData[0]['enabled_for_embedding']);
        $this->assertSame('Company', $dashData[0]['collection']);
        $this->assertFalse($dashData[1]['enabled_for_embedding']);

        $questions = $this->getJson("/api/settings/analytics-accounts/{$account->id}/resources?kind=question");
        $questions->assertOk();
        $this->assertSame('2', $questions->json('data.0.ref'));
        $this->assertSame('Rent question', $questions->json('data.0.name'));
    }

    #[Test]
    public function failure_returns_conflict_not_empty(): void
    {
        $account = $this->makeMetabaseAccount();

        Http::fake([
            'https://metabase.example.com/api/dashboard' => Http::response('down', 503),
        ]);

        $response = $this->getJson("/api/settings/analytics-accounts/{$account->id}/resources?kind=dashboard");

        $response->assertStatus(409);
        $this->assertSame('provider_unreachable', $response->json('message'));
        $this->assertNotSame([], $response->json('data'));
    }

    #[Test]
    public function error_body_omits_credentials(): void
    {
        $account = $this->makeMetabaseAccount();

        Http::fake([
            'https://metabase.example.com/api/dashboard' => Http::response(['message' => 'Invalid'], 401),
        ]);

        $response = $this->getJson("/api/settings/analytics-accounts/{$account->id}/resources?kind=dashboard");

        $response->assertStatus(409);
        $this->assertSame('credentials_unreadable', $response->json('message'));
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString(self::API_KEY, $body);
        $this->assertStringNotContainsString('mb_super_secret', $body);
    }

    #[Test]
    public function iframe_provider_returns_not_discoverable(): void
    {
        $account = AnalyticsAccount::query()->create([
            'provider' => AnalyticsProvider::Iframe,
            'display_name' => 'Iframe',
            'base_url' => 'https://charts.example.com/embed/{resource}',
            'credentials' => [],
            'is_default' => true,
            'connection_status' => CredentialStatus::Connected,
            'created_by' => $this->employee->id,
        ]);

        $response = $this->getJson("/api/settings/analytics-accounts/{$account->id}/resources?kind=dashboard");

        $response->assertStatus(409);
        $this->assertSame('provider_not_discoverable', $response->json('message'));
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
                'embedding_secret_key' => 'embed-secret',
                'api_key' => self::API_KEY,
            ],
            'is_default' => true,
            'connection_status' => CredentialStatus::Connected,
            'created_by' => $this->employee->id,
        ], $overrides));
    }
}
