<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversationMessage;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Guards\DuplicateDraftGuard;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\VoiceTranscriptMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\TestCase;

class DuplicateDraftGuardTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function mirrored_front_desk_row_does_not_block_a_similar_draft(): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales');
        $recap = $this->sharedOpening().'Quote A: the 5 square metre locker is eighty five euros all in.';
        AgentConversationMessage::query()->create([
            'agent_conversation_id' => $ctx->conversation->id,
            'sequence' => 1,
            'role' => AgentMessageRole::Assistant,
            'content' => VoiceTranscriptMirror::FRONT_DESK_PREFIX.$recap,
            'voice_source' => 'fast_model',
        ]);

        $verdict = app(DuplicateDraftGuard::class)->check($recap, new FactBag, $ctx);

        $this->assertTrue($verdict->passed);
    }

    #[Test]
    public function shared_opening_with_a_different_tail_passes(): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales');
        $opening = $this->sharedOpening();
        AgentConversationMessage::query()->create([
            'agent_conversation_id' => $ctx->conversation->id,
            'sequence' => 1,
            'role' => AgentMessageRole::Assistant,
            'content' => $opening.'Quote A: the 5 square metre locker is eighty five euros all in for a month.',
        ]);

        $verdict = app(DuplicateDraftGuard::class)->check(
            $opening.'Quote B: the sixteen metre store is two hundred twenty euros and we can text the list.',
            new FactBag,
            $ctx,
        );

        $this->assertTrue($verdict->passed);
    }

    #[Test]
    public function matching_prefix_and_suffix_still_blocks(): void
    {
        $ctx = $this->writeContext(AgentPrincipal::anonymous(null, 'en'), 'sales');
        $body = $this->sharedOpening().'Quote A: the 5 square metre locker is eighty five euros all in for a month.';
        AgentConversationMessage::query()->create([
            'agent_conversation_id' => $ctx->conversation->id,
            'sequence' => 1,
            'role' => AgentMessageRole::Assistant,
            'content' => $body,
        ]);

        $verdict = app(DuplicateDraftGuard::class)->check($body, new FactBag, $ctx);

        $this->assertFalse($verdict->passed);
        $this->assertSame('duplicate_draft', $verdict->blockedBy);
        $this->assertSame('near_duplicate', $verdict->detail['detail'] ?? null);
    }

    private function sharedOpening(): string
    {
        return str_repeat('We have units available at Madrid Centro right now. ', 5);
    }
}
