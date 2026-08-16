<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Guards\GroundingGuard;
use App\Support\Ai\Tools\FactBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\TestCase;

class GroundingGuardTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function invented_amount_is_blocked(): void
    {
        $verdict = app(GroundingGuard::class)->check(
            'You owe €12.00.',
            new FactBag,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertFalse($verdict->passed);
        $this->assertSame('grounding', $verdict->blockedBy);
        $this->assertSame('€12.00', $verdict->detail['token'] ?? null);
    }

    #[Test]
    public function tool_sourced_amount_passes(): void
    {
        $facts = (new FactBag)->money('84.70', 'EUR');
        $verdict = app(GroundingGuard::class)->check(
            'The figure is €84.70.',
            $facts,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertTrue($verdict->passed);
    }

    #[Test]
    public function customer_echoed_amount_passes(): void
    {
        $facts = FactBag::fromCustomerMessage('You quoted €80.00 yesterday.');
        $verdict = app(GroundingGuard::class)->check(
            'You said €80.00.',
            $facts,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertTrue($verdict->passed);
    }

    #[Test]
    public function invented_tax_percent_is_blocked(): void
    {
        $verdict = app(GroundingGuard::class)->check(
            'That includes 21% tax.',
            new FactBag,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertFalse($verdict->passed);
        $this->assertSame('grounding', $verdict->blockedBy);
        $this->assertStringContainsString('21%', (string) ($verdict->detail['token'] ?? ''));
    }

    #[Test]
    public function comma_amount_matches_dot_amount(): void
    {
        $facts = (new FactBag)->money('84.70', 'EUR');
        $verdict = app(GroundingGuard::class)->check(
            'The price is €84,70.',
            $facts,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertTrue($verdict->passed);
    }

    #[Test]
    public function tool_sourced_percent_passes(): void
    {
        $facts = (new FactBag)->percent('21');
        $verdict = app(GroundingGuard::class)->check(
            'VAT is 21%.',
            $facts,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertTrue($verdict->passed);
    }
}
