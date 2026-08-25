<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiAgent;
use App\Models\AiModelPrice;
use App\Models\AiUsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckModelPricesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function succeeds_when_the_catalogue_covers_configured_models(): void
    {
        $this->artisan('agents:check-model-prices')->assertSuccessful();
    }

    #[Test]
    public function fails_when_a_settled_usage_row_has_no_effective_price(): void
    {
        AiModelPrice::query()->delete();

        $agent = AiAgent::factory()->create();

        AiUsageEvent::query()->create([
            'call_id' => (string) Str::uuid7(),
            'ai_agent_id' => $agent->id,
            'purpose' => 'agent',
            'provider' => null,
            'model' => 'claude-sonnet-4-6',
            'status' => AiUsageEvent::STATUS_OK,
            'input_tokens' => 10,
            'output_tokens' => 5,
            'started_at' => now()->subDay(),
            'settled_at' => now()->subDay(),
        ]);

        $this->artisan('agents:check-model-prices')
            ->expectsOutputToContain('missing effective price row')
            ->expectsOutputToContain('claude-sonnet-4-6')
            ->assertFailed();
    }
}
