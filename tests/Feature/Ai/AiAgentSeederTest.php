<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentWritePolicy;
use App\Models\AiAgent;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Enums\WritePolicyMode;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiAgentSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeds_support_sales_and_concierge(): void
    {
        $this->seed(AiAgentSeeder::class);

        $this->assertSame(3, AiAgent::query()->count());

        $support = AiAgent::query()->where('key', 'support')->firstOrFail();
        $this->assertSame('Support Agent (archived)', $support->name);
        $this->assertFalse($support->is_active);
        $this->assertNotNull($support->archived_at);

        $sales = AiAgent::query()->where('key', 'sales')->firstOrFail();
        $this->assertSame('Sales Agent (archived)', $sales->name);
        $this->assertFalse($sales->is_active);
        $this->assertNotNull($sales->archived_at);

        $concierge = AiAgent::query()->where('key', 'concierge')->firstOrFail();
        $this->assertSame('Customer Agent', $concierge->name);
        $this->assertTrue($concierge->is_active);
        $this->assertNull($concierge->archived_at);
        $this->assertSame(config('agents.default_model'), $support->model);

        $registry = app(AgentRegistry::class);
        $this->assertSame('support', $registry->get('support')->key());
        $this->assertSame('sales', $registry->get('sales')->key());
        $this->assertSame('concierge', $registry->get('concierge')->key());

        $this->assertSalesPair($sales);
        $this->assertSalesPair($concierge);
        $this->assertRequestCodePolicy($concierge);
        $this->assertNull(
            AgentWritePolicy::query()
                ->where('ai_agent_id', $sales->id)
                ->where('tool_key', 'identity.request_code')
                ->first(),
        );
    }

    #[Test]
    public function rerun_is_idempotent(): void
    {
        $this->seed(AiAgentSeeder::class);
        $before = AiAgent::query()
            ->orderBy('key')
            ->get(['id', 'key', 'name', 'model', 'is_active', 'archived_at'])
            ->toArray();

        $this->seed(AiAgentSeeder::class);

        $this->assertSame(
            $before,
            AiAgent::query()
                ->orderBy('key')
                ->get(['id', 'key', 'name', 'model', 'is_active', 'archived_at'])
                ->toArray(),
        );
        $this->assertSame(3, AiAgent::query()->count());
        $this->assertSame(2, AgentWritePolicy::query()->where('tool_key', 'sales.create_offer')->count());
        $this->assertSame(2, AgentWritePolicy::query()->where('tool_key', 'sales.create_reservation')->count());
        $this->assertSame(1, AgentWritePolicy::query()->where('tool_key', 'identity.request_code')->count());
    }

    private function assertSalesPair(AiAgent $agent): void
    {
        $policy = AgentWritePolicy::query()
            ->where('ai_agent_id', $agent->id)
            ->where('tool_key', 'sales.create_offer')
            ->first();
        $this->assertNotNull($policy);
        $this->assertSame(WritePolicyMode::Commit, $policy->mode);
        $this->assertSame(2, $policy->max_per_conversation);
        $this->assertSame(50, $policy->max_per_day);

        $hold = AgentWritePolicy::query()
            ->where('ai_agent_id', $agent->id)
            ->where('tool_key', 'sales.create_reservation')
            ->first();
        $this->assertNotNull($hold);
        $this->assertSame(WritePolicyMode::Propose, $hold->mode);
        $this->assertSame(1, $hold->max_per_conversation);
        $this->assertSame(20, $hold->max_per_day);
    }

    private function assertRequestCodePolicy(AiAgent $agent): void
    {
        $policy = AgentWritePolicy::query()
            ->where('ai_agent_id', $agent->id)
            ->where('tool_key', 'identity.request_code')
            ->first();
        $this->assertNotNull($policy);
        $this->assertSame(WritePolicyMode::Commit, $policy->mode);
        $this->assertSame(3, $policy->max_per_conversation);
        $this->assertSame(10, $policy->max_per_day);
    }
}
