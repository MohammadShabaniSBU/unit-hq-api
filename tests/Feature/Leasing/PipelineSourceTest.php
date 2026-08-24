<?php

declare(strict_types=1);

namespace Tests\Feature\Leasing;

use App\Enums\PipelineSource;
use App\Models\AiAgent;
use App\Models\Deal;
use App\Models\Offer;
use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PipelineSourceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function offers_reject_ai_agent_source_without_agent_id(): void
    {
        $deal = Deal::factory()->create();

        $this->expectException(QueryException::class);

        Offer::query()->create([
            'deal_id' => $deal->id,
            'contact_id' => $deal->contact_id,
            'token' => Str::random(64),
            'status' => 'draft',
            'expires_at' => now()->addDay(),
            'source' => PipelineSource::AiAgent,
            'ai_agent_id' => null,
        ]);
    }

    #[Test]
    public function reservations_reject_ai_agent_source_without_agent_id(): void
    {
        $this->expectException(QueryException::class);

        Reservation::factory()->create([
            'source' => PipelineSource::AiAgent,
            'ai_agent_id' => null,
        ]);
    }

    #[Test]
    public function offers_accept_ai_agent_source_with_agent_id(): void
    {
        $deal = Deal::factory()->create();
        $agent = AiAgent::factory()->create();

        $offer = Offer::query()->create([
            'deal_id' => $deal->id,
            'contact_id' => $deal->contact_id,
            'token' => Str::random(64),
            'status' => 'draft',
            'expires_at' => now()->addDay(),
            'source' => PipelineSource::AiAgent,
            'ai_agent_id' => $agent->id,
        ]);

        $this->assertSame(PipelineSource::AiAgent, $offer->source);
        $this->assertSame($agent->id, $offer->ai_agent_id);
    }

    #[Test]
    public function reservations_accept_ai_agent_source_with_agent_id(): void
    {
        $agent = AiAgent::factory()->create();

        $reservation = Reservation::factory()->create([
            'source' => PipelineSource::AiAgent,
            'ai_agent_id' => $agent->id,
        ]);

        $this->assertSame(PipelineSource::AiAgent, $reservation->source);
        $this->assertSame($agent->id, $reservation->ai_agent_id);
    }
}
