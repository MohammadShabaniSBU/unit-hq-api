<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentConversation;
use App\Models\Contact;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\VoiceBridgeToken;
use App\Models\VoiceSession;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Communications\Channel;
use App\Support\Communications\ContactChannelMatcher;
use App\Support\Communications\SiteLocale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * One lookup and one audience gate for every voice-session open — the
 * explicit lifecycle endpoint and VoiceBridgeTurn::handle() both call this.
 */
final class VoiceSessionOpener
{
    public function __construct(
        private readonly AgentChannelBindings $bindings,
    ) {}

    /**
     * @param  bool  $skipAudience  End-without-open fallback only. Mid-call
     *                              open() never sets this.
     */
    public function open(
        VoiceBridgeToken $token,
        string $bridgeSessionId,
        ?string $callerNumber,
        bool $skipAudience = false,
    ): ?VoiceSession {
        $existing = $this->findByBridgeSessionId($bridgeSessionId);
        if ($existing !== null) {
            $this->assertSameToken($existing, $token, $bridgeSessionId);

            return $existing;
        }

        $resolved = $this->bindings->resolve(AgentChannel::Voice, $token->site_id);
        if ($resolved === null) {
            return null;
        }

        $identity = VoiceCallerIdentity::resolve($callerNumber);
        if (! $skipAudience && ! $this->audienceAllows($resolved, $identity, $token->site_id)) {
            return null;
        }

        $normalized = $this->normalizeCaller($callerNumber);
        $site = Site::query()->find($token->site_id);
        $contact = $identity->contactId !== null
            ? Contact::query()->find($identity->contactId)
            : null;
        $locale = $this->locale($contact, $site);

        try {
            return DB::transaction(function () use ($token, $resolved, $bridgeSessionId, $normalized, $identity, $locale): VoiceSession {
                $conversation = AgentConversation::query()->create([
                    'ai_agent_id' => $resolved->agent->id,
                    'audience' => AgentAudience::Customer,
                    'origin' => AgentOrigin::Voice,
                    'channel' => AgentChannel::Voice,
                    'employee_id' => null,
                    'created_by_employee_id' => null,
                    'contact_id' => $identity->contactId,
                    'site_id' => $token->site_id,
                    'verification_level' => $identity->verification,
                    'state' => ConversationState::Active,
                    'locale' => $locale,
                ]);

                if ($identity->ambiguous) {
                    SystemEvent::record('ai.voice.caller_ambiguous', $conversation, [
                        'matches' => $identity->matches,
                    ]);
                }

                $session = VoiceSession::query()->create([
                    'bridge_session_id' => $bridgeSessionId,
                    'agent_conversation_id' => $conversation->id,
                    'voice_bridge_token_id' => $token->id,
                    'caller_number' => $normalized,
                    'contact_id' => $identity->contactId,
                    'site_id' => $token->site_id,
                    'started_at' => now(),
                ]);
                $session->setRelation('conversation', $conversation);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            $replay = $this->findByBridgeSessionId($bridgeSessionId);
            if ($replay !== null) {
                $this->assertSameToken($replay, $token, $bridgeSessionId);

                return $replay;
            }

            throw new \RuntimeException('Voice session insert raced and the row could not be re-read.');
        }
    }

    private function findByBridgeSessionId(string $bridgeSessionId): ?VoiceSession
    {
        return VoiceSession::query()
            ->with('conversation')
            ->where('bridge_session_id', $bridgeSessionId)
            ->first();
    }

    private function assertSameToken(VoiceSession $session, VoiceBridgeToken $token, string $bridgeSessionId): void
    {
        if ($session->voice_bridge_token_id === $token->id) {
            return;
        }

        SystemEvent::record('voice_session.cross_token', $session, [
            'requested_token_id' => $token->id,
            'owning_token_id' => $session->voice_bridge_token_id,
            'bridge_session_id' => $bridgeSessionId,
        ]);

        throw new VoiceSessionCrossTokenException;
    }

    private function audienceAllows(ResolvedBinding $binding, VoiceCallerIdentity $identity, int $siteId): bool
    {
        return match ($binding->audience) {
            BindingAudience::All => true,
            BindingAudience::KnownContacts => $identity->contactId !== null,
            BindingAudience::ExistingTenants => $identity->contactId !== null
                && AgentEligibility::hasInForceContractAtSite(
                    Contact::query()->find($identity->contactId),
                    $siteId,
                ),
        };
    }

    private function locale(?Contact $contact, ?Site $site): string
    {
        if (is_string($contact?->locale) && $contact->locale !== '') {
            return $contact->locale;
        }

        return SiteLocale::for($site);
    }

    private function normalizeCaller(?string $callerNumber): ?string
    {
        if ($callerNumber === null) {
            return null;
        }

        $normalized = ContactChannelMatcher::normalize(Channel::Call, $callerNumber);

        return $normalized !== '' ? $normalized : null;
    }
}
