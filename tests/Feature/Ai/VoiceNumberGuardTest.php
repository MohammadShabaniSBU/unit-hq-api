<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\ChannelProfile;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Guards\VoiceNumberGuard;
use App\Support\Ai\Tools\FactBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\TestCase;

class VoiceNumberGuardTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function non_voice_draft_with_digits_passes(): void
    {
        $verdict = app(VoiceNumberGuard::class)->check(
            'The small unit is €89 a month.',
            new FactBag,
            $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'concierge'),
        );

        $this->assertTrue($verdict->passed);
        $this->assertNull($verdict->retry);
        $this->assertSame('voice_number', $verdict->events[0]['guard']);
    }

    #[Test]
    public function voice_draft_with_digits_retries(): void
    {
        $verdict = app(VoiceNumberGuard::class)->check(
            'The small unit is €89 a month.',
            new FactBag,
            $this->voiceContext(),
        );

        $this->assertFalse($verdict->passed);
        $this->assertSame('voice_number', $verdict->blockedBy);
        $this->assertNotNull($verdict->retry);
        $this->assertSame('deny', $verdict->events[0]['verdict']);
        $this->assertSame('digit_in_draft', $verdict->events[0]['reason']);
    }

    #[Test]
    public function voice_draft_without_digits_passes(): void
    {
        $verdict = app(VoiceNumberGuard::class)->check(
            "I've sent the exact quote by text.",
            new FactBag,
            $this->voiceContext(),
        );

        $this->assertTrue($verdict->passed);
        $this->assertNull($verdict->retry);
    }

    #[Test]
    public function voice_draft_with_spelled_out_figure_passes(): void
    {
        $verdict = app(VoiceNumberGuard::class)->check(
            'eighty-nine euros',
            new FactBag,
            $this->voiceContext(),
        );

        $this->assertTrue($verdict->passed);
        $this->assertNull($verdict->retry);
    }

    private function voiceContext(): AgentContext
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'concierge');
        $ctx->conversation->channel = AgentChannel::Voice;
        $ctx->conversation->save();

        return new AgentContext(
            $ctx->principal,
            ChannelProfile::for(AgentChannel::Voice),
            $ctx->definition,
            $ctx->conversation,
            $ctx->agent,
        );
    }
}
