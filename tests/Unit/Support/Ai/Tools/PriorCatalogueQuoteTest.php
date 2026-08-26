<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai\Tools;

use App\Models\AgentToolInvocation;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Tools\PriorCatalogueQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\TestCase;

class PriorCatalogueQuoteTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function latest_for_reads_pricing_quote_result(): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales');
        $this->seedOk($ctx->conversation->id, 'pricing.quote', [
            'site_id' => 1,
            'unit_class_id' => 6,
            'price_id' => 40,
            'tax_rate_id' => 7,
        ]);

        $latest = PriorCatalogueQuote::latestFor($ctx, 1, 6);

        $this->assertNotNull($latest);
        $this->assertSame(40, $latest['price_id']);
        $this->assertSame(7, $latest['tax_rate_id']);
        $this->assertTrue(PriorCatalogueQuote::namesClass($ctx, 1, 6));
        $this->assertFalse(PriorCatalogueQuote::namesClass($ctx, 2, 6));
        $this->assertFalse(PriorCatalogueQuote::namesClass($ctx, 1, 99));
    }

    #[Test]
    public function latest_for_matches_propose_offer_line_items_on_the_row_ids(): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales');
        $invocation = $this->seedOk($ctx->conversation->id, 'sales.propose_offer', [
            'site_id' => 1,
            'unit_class_id' => 6,
            'line_items' => [
                [
                    'label' => ' decoy first ',
                    'site_id' => 1,
                    'unit_class_id' => 2,
                    'price_id' => 10,
                    'tax_rate_id' => 7,
                ],
                [
                    'label' => 'wanted class',
                    'site_id' => 1,
                    'unit_class_id' => 6,
                    'price_id' => 40,
                    'tax_rate_id' => 7,
                ],
            ],
        ]);

        $latest = PriorCatalogueQuote::latestFor($ctx, 1, 6);

        $this->assertNotNull($latest);
        $this->assertSame(40, $latest['price_id']);
        $this->assertSame(7, $latest['tax_rate_id']);
        $this->assertSame($invocation->id, $latest['invocation_id']);
    }

    #[Test]
    public function latest_for_does_not_match_on_label_or_index(): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales');
        $this->seedOk($ctx->conversation->id, 'sales.propose_offer', [
            'line_items' => [
                [
                    'label' => 'Trastero 8',
                    'site_id' => 1,
                    'unit_class_id' => 2,
                    'price_id' => 10,
                    'tax_rate_id' => 7,
                ],
            ],
        ]);

        $this->assertNull(PriorCatalogueQuote::latestFor($ctx, 1, 6));
    }

    #[Test]
    public function latest_for_most_recent_ok_wins(): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales');
        $this->seedOk($ctx->conversation->id, 'pricing.quote', [
            'site_id' => 1,
            'unit_class_id' => 6,
            'price_id' => 40,
            'tax_rate_id' => 7,
        ]);
        $newer = $this->seedOk($ctx->conversation->id, 'pricing.quote', [
            'site_id' => 1,
            'unit_class_id' => 6,
            'price_id' => 41,
            'tax_rate_id' => 8,
        ]);

        $latest = PriorCatalogueQuote::latestFor($ctx, 1, 6);

        $this->assertNotNull($latest);
        $this->assertSame(41, $latest['price_id']);
        $this->assertSame(8, $latest['tax_rate_id']);
        $this->assertSame($newer->id, $latest['invocation_id']);
    }

    #[Test]
    public function latest_for_ignores_non_ok_invocations(): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales');
        AgentToolInvocation::query()->create([
            'agent_conversation_id' => $ctx->conversation->id,
            'tool_key' => 'pricing.quote',
            'arguments' => ['site_id' => 1, 'unit_class_id' => 6],
            'result' => [
                'site_id' => 1,
                'unit_class_id' => 6,
                'price_id' => 40,
                'tax_rate_id' => 7,
            ],
            'result_summary' => 'failed',
            'status' => ToolInvocationStatus::Error,
            'principal_verification' => $ctx->principal->verification,
        ]);

        $this->assertNull(PriorCatalogueQuote::latestFor($ctx, 1, 6));
    }

    #[Test]
    public function latest_for_wrong_site_returns_null(): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales');
        $this->seedOk($ctx->conversation->id, 'pricing.quote', [
            'site_id' => 1,
            'unit_class_id' => 6,
            'price_id' => 40,
            'tax_rate_id' => 7,
        ]);

        $this->assertNull(PriorCatalogueQuote::latestFor($ctx, 2, 6));
    }

    #[Test]
    public function latest_for_null_context_returns_null(): void
    {
        $this->assertNull(PriorCatalogueQuote::latestFor(null, 1, 6));
        $this->assertFalse(PriorCatalogueQuote::namesClass(null, 1, 6));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function seedOk(int $conversationId, string $toolKey, array $result): AgentToolInvocation
    {
        return AgentToolInvocation::query()->create([
            'agent_conversation_id' => $conversationId,
            'tool_key' => $toolKey,
            'arguments' => [],
            'result' => $result,
            'result_summary' => 'ok',
            'status' => ToolInvocationStatus::Ok,
        ]);
    }
}
