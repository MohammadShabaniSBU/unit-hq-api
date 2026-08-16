<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Guards\ForbiddenClaimGuard;
use App\Support\Ai\Tools\FactBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\TestCase;

class ForbiddenClaimGuardTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function payment_confirmation_is_blocked(): void
    {
        $this->assertBlocked('Your payment has been received this morning.');
    }

    #[Test]
    public function fee_waiver_is_blocked(): void
    {
        $this->assertBlocked("I've waived the late fee for you.");
    }

    #[Test]
    public function access_grant_is_blocked(): void
    {
        $this->assertBlocked('You can get in now, the lock is off.');
    }

    #[Test]
    public function availability_guarantee_is_blocked(): void
    {
        $this->assertBlocked("I've held it for you until Friday.");
    }

    #[Test]
    public function legal_advice_is_blocked(): void
    {
        $this->assertBlocked('You are not liable for that damage.');
    }

    #[Test]
    public function contract_mutation_is_blocked(): void
    {
        $this->assertBlocked("I've updated your contract to the new rate.");
    }

    private function assertBlocked(string $draft): void
    {
        $verdict = app(ForbiddenClaimGuard::class)->check(
            $draft,
            new FactBag,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'support'),
        );

        $this->assertFalse($verdict->passed, $draft);
        $this->assertSame('forbidden_claim', $verdict->blockedBy);
        $this->assertSame(HandoffReason::UnsupportedIntent, $verdict->handoffReason);
    }
}
