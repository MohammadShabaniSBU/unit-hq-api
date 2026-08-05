<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiModelPrice;
use App\Models\AiUsageEvent;
use App\Support\Ai\AiUsageCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class AiUsagePriceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cost_uses_price_in_effect_at_started_at(): void
    {
        AiModelPrice::query()->delete();

        AiModelPrice::query()->create([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'input_per_mtok' => '1.0000',
            'cached_input_per_mtok' => '0.1000',
            'output_per_mtok' => '2.0000',
            'currency' => 'USD',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
        ]);

        AiModelPrice::query()->create([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'input_per_mtok' => '10.0000',
            'cached_input_per_mtok' => '1.0000',
            'output_per_mtok' => '20.0000',
            'currency' => 'USD',
            'effective_from' => '2026-07-01',
            'effective_to' => null,
        ]);

        $callId = (string) Str::uuid7();
        $event = AiUsageEvent::query()->create([
            'call_id' => $callId,
            'purpose' => 'copilot',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'status' => AiUsageEvent::STATUS_OK,
            'input_tokens' => 1_000_000,
            'cached_input_tokens' => 0,
            'output_tokens' => 1_000_000,
            'reasoning_tokens' => 0,
            'started_at' => '2026-03-15 12:00:00',
            'settled_at' => '2026-03-15 12:00:01',
        ]);

        $cost = AiUsageCost::forEvent($event);
        $this->assertNotNull($cost);
        $this->assertSame('USD', $cost['currency']);
        // 1.0 + 2.0 under the Jan–Jun price, not the July 10+20 price.
        $this->assertSame('3.000000', $cost['estimated_cost']);
    }

    #[Test]
    public function new_version_closes_previous(): void
    {
        AiModelPrice::query()->delete();

        $first = AiModelPrice::publish('anthropic', 'claude-sonnet-5', [
            'input_per_mtok' => '3.0000',
            'cached_input_per_mtok' => '0.3000',
            'output_per_mtok' => '15.0000',
            'effective_from' => '2026-01-01',
        ]);

        $second = AiModelPrice::publish('anthropic', 'claude-sonnet-5', [
            'input_per_mtok' => '4.0000',
            'output_per_mtok' => '16.0000',
            'effective_from' => '2026-08-01',
        ]);

        $first->refresh();
        $this->assertSame('2026-07-31', $first->effective_to?->toDateString());
        $this->assertNull($second->effective_to);

        $this->expectException(RuntimeException::class);
        $first->update(['input_per_mtok' => '99.0000']);
    }
}
