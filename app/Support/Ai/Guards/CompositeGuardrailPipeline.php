<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Tools\FactBag;

final class CompositeGuardrailPipeline implements GuardrailPipeline
{
    /** @var list<OutboundGuard> */
    private array $guards;

    /**
     * Pinned guard order. A test reflects this list.
     *
     * @var list<string>
     */
    public const GUARD_SEQUENCE = [
        'duplicate_draft',
        'grounding',
        'voice_number',
        'forbidden_claim',
        'disclosure',
        'channel',
    ];

    public function __construct(
        DuplicateDraftGuard $duplicate,
        GroundingGuard $grounding,
        VoiceNumberGuard $voiceNumber,
        ForbiddenClaimGuard $forbidden,
        DisclosureGuard $disclosure,
        ChannelGuard $channel,
    ) {
        $this->guards = [$duplicate, $grounding, $voiceNumber, $forbidden, $disclosure, $channel];
    }

    public function check(string $draft, FactBag $facts, AgentContext $ctx): GuardrailVerdict
    {
        $events = [];
        $subject = null;
        $current = $draft;

        foreach ($this->guards as $guard) {
            $verdict = $guard->check($current, $facts, $ctx);
            $events = array_merge($events, $verdict->events);

            if ($verdict->retry !== null || ! $verdict->passed) {
                return new GuardrailVerdict(
                    $verdict->passed,
                    $verdict->blockedBy,
                    $verdict->handoffReason,
                    $verdict->detail,
                    $verdict->mutatedDraft,
                    $verdict->retry,
                    $events,
                    $verdict->subject ?? $subject,
                );
            }

            if ($verdict->mutatedDraft !== null) {
                $current = $verdict->mutatedDraft;
            }
            if ($verdict->subject !== null) {
                $subject = $verdict->subject;
            }
        }

        return GuardrailVerdict::pass(
            $current !== $draft ? $current : null,
            $events,
            $subject,
        );
    }
}
