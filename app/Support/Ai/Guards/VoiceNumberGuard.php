<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Tools\FactBag;

/**
 * Digit rule, not a figure rule. Blocks `\d` on voice drafts.
 * Spelled-out figures ("eighty-nine euros") pass. S28-07 must write
 * invariant 73 as this digit rule, not as "no figure spoken".
 */
final class VoiceNumberGuard implements OutboundGuard
{
    public function key(): string
    {
        return 'voice_number';
    }

    public function check(string $draft, FactBag $facts, AgentContext $ctx): GuardrailVerdict
    {
        if ($ctx->channel->channel !== AgentChannel::Voice) {
            return GuardrailVerdict::pass(events: [['guard' => $this->key(), 'verdict' => 'pass']]);
        }

        if (preg_match('/\d/u', $draft) !== 1) {
            return GuardrailVerdict::pass(events: [['guard' => $this->key(), 'verdict' => 'pass']]);
        }

        $detail = ['reason' => 'digit_in_draft'];

        return GuardrailVerdict::retry(
            'Rewrite this reply with no digit characters. Spell out times and dates, or offer to text the exact quote. Do not state a figure aloud.',
            $this->key(),
            HandoffReason::ChannelConstraint,
            $detail,
            [['guard' => $this->key(), 'verdict' => 'deny', 'reason' => 'digit_in_draft', 'detail' => $detail]],
        );
    }
}
