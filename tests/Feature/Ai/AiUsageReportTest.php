<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiModelPrice;
use App\Models\AiUsageEvent;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Site;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiUsageReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function no_cross_currency_sum(): void
    {
        AiModelPrice::query()->delete();

        AiModelPrice::query()->create([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'input_per_mtok' => '1.0000',
            'cached_input_per_mtok' => null,
            'output_per_mtok' => '1.0000',
            'currency' => 'USD',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        AiModelPrice::query()->create([
            'provider' => 'openai',
            'model' => 'gpt-4.1',
            'input_per_mtok' => '2.0000',
            'cached_input_per_mtok' => null,
            'output_per_mtok' => '2.0000',
            'currency' => 'EUR',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $employee = Employee::factory()->manager()->create();

        $this->seedOkEvent($employee->id, 'anthropic', 'claude-sonnet-5', 1_000_000, 0);
        $this->seedOkEvent($employee->id, 'openai', 'gpt-4.1', 1_000_000, 0);

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/insights/ai-usage?from=2026-08-01&to=2026-08-05&group_by=employee');

        $response->assertOk();
        $currencies = collect($response->json('data.0.currencies'))->pluck('currency')->sort()->values()->all();
        $this->assertSame(['EUR', 'USD'], $currencies);

        // No top-level mixed sum — only per-currency buckets.
        $this->assertArrayNotHasKey('estimated_cost', $response->json('data.0'));
        $this->assertArrayNotHasKey('estimated_cost', $response->json('meta'));
    }

    #[Test]
    public function aggregate_requires_privileged_role(): void
    {
        $privileged = Employee::factory()->manager()->create();
        $agent = Employee::factory()->withoutRoleGrant()->create();
        $site = Site::factory()->create();

        $roleId = (int) Role::query()->where('key', 'leasing_agent')->value('id');
        EmployeeRole::query()->create([
            'employee_id' => $agent->id,
            'role_id' => $roleId,
            'site_id' => $site->id,
            'granted_by' => null,
        ]);
        $agent->forgetPermissionMap();

        Sanctum::actingAs($agent);
        $this->getJson('/api/insights/ai-usage?from=2026-08-01&to=2026-08-05')
            ->assertForbidden();

        $this->getJson('/api/insights/ai-usage/me?from=2026-08-01&to=2026-08-05')
            ->assertOk();

        Sanctum::actingAs($privileged);
        $this->getJson('/api/insights/ai-usage?from=2026-08-01&to=2026-08-05')
            ->assertOk();
    }

    private function seedOkEvent(
        int $employeeId,
        string $provider,
        string $model,
        int $input,
        int $output,
    ): void {
        AiUsageEvent::query()->create([
            'call_id' => (string) Str::uuid7(),
            'employee_id' => $employeeId,
            'purpose' => 'copilot',
            'provider' => $provider,
            'model' => $model,
            'status' => AiUsageEvent::STATUS_OK,
            'input_tokens' => $input,
            'cached_input_tokens' => 0,
            'output_tokens' => $output,
            'reasoning_tokens' => 0,
            'raw_usage' => ['prompt_tokens' => $input, 'completion_tokens' => $output],
            'started_at' => '2026-08-05 10:00:00',
            'settled_at' => '2026-08-05 10:00:01',
        ]);
    }
}
