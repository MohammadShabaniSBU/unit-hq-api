<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentConversation;
use App\Models\AgentHandoff;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\VoiceBridgeToken;
use App\Models\VoiceSession;
use App\Models\VoiceSessionTurn;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\HandoffTriggerSource;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use App\Support\Communications\Channel;
use App\Support\Communications\ContactChannelMatcher;
use App\Support\Communications\SiteLocale;
use App\Support\Time\SiteClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Vocal Bridge delegation: bind, map the VB session, run one turn, speak a
 * sentence. Never returns an error string the foreground model can read aloud.
 *
 * @phpstan-type BridgeBody array{text: string, transfer: bool, destination?: string}
 */
final class VoiceBridgeTurn
{
    public function __construct(
        private readonly AgentChannelBindings $bindings,
        private readonly AgentRuntime $runtime,
        private readonly VoiceTransfer $transfer,
    ) {}

    /**
     * @return array{text: string, transfer: bool, destination?: string}
     */
    public function handle(Request $request, VoiceBridgeToken $token): array
    {
        $site = Site::query()->find($token->site_id);
        $key = 'voice-bridge|'.$token->id;
        $max = (int) config('agents.voice.bridge_rate_per_minute', 60);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            SystemEvent::record('ai.voice.bridge_throttled', $token, []);

            return $this->handoffBody($site);
        }

        RateLimiter::hit($key, 60);

        $query = $this->string($request, 'query');
        $turnId = $this->string($request, 'turn_id', 'turnId');
        $sessionId = $this->string($request, 'session_id', 'sessionId');
        $callerNumber = $this->string($request, 'caller_number', 'from');

        if ($query === null || $turnId === null || $sessionId === null) {
            return $this->handoffBody($site);
        }

        $resolved = $this->bindings->resolve(AgentChannel::Voice, $token->site_id);
        if (
            $resolved === null
            || $resolved->mode === BindingMode::Off
            || $resolved->mode === BindingMode::Draft
        ) {
            return $this->handoffBody($site);
        }

        $identity = VoiceCallerIdentity::resolve($callerNumber);

        if (! $this->audienceAllows($resolved, $identity, $token->site_id)) {
            return $this->handoffBody($site);
        }

        $session = $this->findOrCreateSession($token, $resolved, $sessionId, $callerNumber, $identity);
        $conversation = $session->conversation;
        $principal = $this->principalFrom($conversation);

        $existing = $this->storedTurn($session, $turnId);
        if ($existing !== null) {
            return $this->bodyFromTurn($existing);
        }

        if (
            $site !== null
            && $resolved->outsideHours === OutsideHoursPolicy::Inbox
            && $this->outsideHours($site)
        ) {
            return $this->outsideHoursInbox($session, $turnId, $site);
        }

        $started = hrtime(true);

        try {
            $turn = $this->runtime->turn($conversation, $principal, $query);
        } catch (UniqueConstraintViolationException $e) {
            $replay = $this->storedTurn($session, $turnId);
            if ($replay !== null) {
                return $this->bodyFromTurn($replay);
            }

            throw $e;
        } catch (Throwable $e) {
            report($e);
            SystemEvent::record('ai.voice.turn_failed', $session, [
                'error' => $e->getMessage(),
            ]);

            return $this->persistHandoff(
                $session,
                $turnId,
                site: $site,
                latencyMs: (int) ((hrtime(true) - $started) / 1_000_000),
            );
        }

        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $redrafted = $this->wasRedrafted($turn);
        $detail = is_array($turn->handoff?->detail) ? $turn->handoff->detail : [];
        $timeBudget = ($detail['detail'] ?? null) === 'turn_timeout'
            || $elapsedMs >= AgentChannelLimits::turnTimeoutMs(AgentChannel::Voice);

        if ($timeBudget) {
            SystemEvent::record('ai.voice.turn_budget_exceeded', $session, [
                'latency_ms' => $elapsedMs,
            ]);

            return $this->persistTransfer(
                $session,
                $turnId,
                $this->transfer->handoffSentence(),
                HandoffReason::Error,
                $site,
                $turn->emittedMessageId,
                $elapsedMs,
                $redrafted,
                true,
                HandoffReason::Error,
            );
        }

        if (($detail['detail'] ?? null) === 'provider_throttled') {
            SystemEvent::record('ai.voice.provider_throttled', $session, []);

            return $this->persistTransfer(
                $session,
                $turnId,
                $this->transfer->handoffSentence(),
                HandoffReason::Error,
                $site,
                $turn->emittedMessageId,
                $elapsedMs,
                $redrafted,
                false,
                HandoffReason::Error,
            );
        }

        $text = trim($turn->draft);
        if ($text === '') {
            SystemEvent::record('ai.voice.turn_failed', $session, [
                'error' => $turn->blockedBy ?? 'empty_draft',
            ]);

            return $this->persistHandoff($session, $turnId, $turn->emittedMessageId, $site, $elapsedMs, $redrafted);
        }

        if ($turn->handoff !== null) {
            return $this->persistTransfer(
                $session,
                $turnId,
                $text,
                $turn->handoff->reason,
                $site,
                $turn->emittedMessageId,
                $elapsedMs,
                $redrafted,
                false,
                $turn->handoff->reason,
            );
        }

        return $this->persistAnswer(
            $session,
            $turnId,
            $text,
            false,
            $turn->emittedMessageId,
            latencyMs: $elapsedMs,
            redrafted: $redrafted,
        );
    }

    /**
     * @return array{text: string, transfer: bool, destination?: string}
     */
    private function handoffBody(?Site $site): array
    {
        return $this->bodyFromResult(
            $this->transfer->handoffSentence(),
            $this->transfer->resolve(HandoffReason::Error, $site),
        );
    }

    /**
     * @return array{text: string, transfer: bool, destination?: string}
     */
    private function outsideHoursInbox(VoiceSession $session, string $turnId, Site $site): array
    {
        $conversation = $session->conversation;

        AgentHandoff::query()->create([
            'agent_conversation_id' => $conversation->id,
            'reason' => HandoffReason::OutOfHours,
            'trigger_source' => HandoffTriggerSource::Rule,
            'detail' => ['policy' => OutsideHoursPolicy::Inbox->value],
        ]);

        $conversation->state = ConversationState::AwaitingHuman;
        $conversation->last_turn_at = now();
        $conversation->save();

        SystemEvent::record('ai.voice.outside_hours', $session, [
            'reason' => HandoffReason::OutOfHours->value,
        ]);

        return $this->persistTransfer(
            $session,
            $turnId,
            $this->transfer->cannedText(HandoffReason::OutOfHours),
            HandoffReason::OutOfHours,
            $site,
        );
    }

    private function outsideHours(Site $site): bool
    {
        $settings = Setting::general();

        return ! SiteClock::withinWindow($site, $settings->sendWindowStart, $settings->sendWindowEnd);
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

    private function findOrCreateSession(
        VoiceBridgeToken $token,
        ResolvedBinding $binding,
        string $bridgeSessionId,
        ?string $callerNumber,
        VoiceCallerIdentity $identity,
    ): VoiceSession {
        $existing = VoiceSession::query()
            ->with('conversation')
            ->where('bridge_session_id', $bridgeSessionId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $normalized = $this->normalizeCaller($callerNumber);
        $site = Site::query()->find($token->site_id);
        $contact = $identity->contactId !== null
            ? Contact::query()->find($identity->contactId)
            : null;
        $locale = $this->locale($contact, $site);

        try {
            return DB::transaction(function () use ($token, $binding, $bridgeSessionId, $normalized, $identity, $locale): VoiceSession {
                $conversation = AgentConversation::query()->create([
                    'ai_agent_id' => $binding->agent->id,
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
            $replay = VoiceSession::query()
                ->with('conversation')
                ->where('bridge_session_id', $bridgeSessionId)
                ->first();

            if ($replay !== null) {
                return $replay;
            }

            throw new \RuntimeException('Voice session insert raced and the row could not be re-read.');
        }
    }

    private function principalFrom(AgentConversation $conversation): AgentPrincipal
    {
        $locale = $conversation->locale ?? (string) config('app.locale');

        if ($conversation->contact_id === null) {
            return AgentPrincipal::anonymous($conversation->site_id, $locale);
        }

        return AgentPrincipal::channelAsserted(
            $conversation->contact_id,
            $conversation->site_id,
            $locale,
        );
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

    private function storedTurn(VoiceSession $session, string $turnId): ?VoiceSessionTurn
    {
        return VoiceSessionTurn::query()
            ->where('voice_session_id', $session->id)
            ->where('turn_id', $turnId)
            ->first();
    }

    /**
     * @return array{text: string, transfer: bool, destination?: string}
     */
    private function bodyFromTurn(VoiceSessionTurn $turn): array
    {
        $body = [
            'text' => $turn->answer_text,
            'transfer' => $turn->transfer,
        ];
        if ($turn->transfer && is_string($turn->destination) && $turn->destination !== '') {
            $body['destination'] = $turn->destination;
        }

        return $body;
    }

    /**
     * @return array{text: string, transfer: bool, destination?: string}
     */
    private function bodyFromResult(string $text, VoiceTransferResult $result): array
    {
        if (! $result->transfer) {
            return [
                'text' => $this->transfer->apology(),
                'transfer' => false,
            ];
        }

        $body = [
            'text' => $text,
            'transfer' => true,
        ];
        if (is_string($result->destination) && $result->destination !== '') {
            $body['destination'] = $result->destination;
        }

        return $body;
    }

    /**
     * @return array{text: string, transfer: bool, destination?: string}
     */
    private function persistTransfer(
        VoiceSession $session,
        string $turnId,
        string $text,
        HandoffReason $reason,
        ?Site $site,
        ?int $messageId = null,
        ?int $latencyMs = null,
        bool $redrafted = false,
        bool $budgetExceeded = false,
        ?HandoffReason $handoffReason = null,
    ): array {
        $result = $this->transfer->resolve($reason, $site);
        if (! $result->transfer) {
            return $this->persistAnswer(
                $session,
                $turnId,
                $this->transfer->apology(),
                false,
                $messageId,
                latencyMs: $latencyMs,
                redrafted: $redrafted,
                budgetExceeded: $budgetExceeded,
                handoffReason: $handoffReason ?? $reason,
            );
        }

        return $this->persistAnswer(
            $session,
            $turnId,
            $text,
            true,
            $messageId,
            $result->destination,
            $latencyMs,
            $redrafted,
            $budgetExceeded,
            $handoffReason ?? $reason,
        );
    }

    /**
     * @return array{text: string, transfer: bool, destination?: string}
     */
    private function persistHandoff(
        VoiceSession $session,
        string $turnId,
        ?int $messageId = null,
        ?Site $site = null,
        ?int $latencyMs = null,
        bool $redrafted = false,
    ): array {
        return $this->persistTransfer(
            $session,
            $turnId,
            $this->transfer->handoffSentence(),
            HandoffReason::Error,
            $site ?? $session->site,
            $messageId,
            $latencyMs,
            $redrafted,
            false,
            HandoffReason::Error,
        );
    }

    /**
     * @return array{text: string, transfer: bool, destination?: string}
     */
    private function persistAnswer(
        VoiceSession $session,
        string $turnId,
        string $text,
        bool $transfer,
        ?int $messageId = null,
        ?string $destination = null,
        ?int $latencyMs = null,
        bool $redrafted = false,
        bool $budgetExceeded = false,
        ?HandoffReason $handoffReason = null,
    ): array {
        try {
            $row = VoiceSessionTurn::query()->create([
                'voice_session_id' => $session->id,
                'turn_id' => $turnId,
                'answer_text' => $text,
                'transfer' => $transfer,
                'destination' => $transfer ? $destination : null,
                'agent_conversation_message_id' => $messageId,
                'latency_ms' => $latencyMs,
                'redrafted' => $redrafted,
                'budget_exceeded' => $budgetExceeded,
                'handoff_reason' => $handoffReason?->value,
            ]);
        } catch (UniqueConstraintViolationException) {
            $replay = $this->storedTurn($session, $turnId);
            if ($replay !== null) {
                return $this->bodyFromTurn($replay);
            }

            throw new \RuntimeException('Voice session turn insert raced and the row could not be re-read.');
        }

        return $this->bodyFromTurn($row);
    }

    private function string(Request $request, string $snake, ?string $camel = null): ?string
    {
        $value = $request->input($snake);
        if ((! is_string($value) || $value === '') && $camel !== null) {
            $value = $request->input($camel);
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function wasRedrafted(AgentTurn $turn): bool
    {
        foreach ($turn->guardrailEvents as $event) {
            $detail = $event['detail'] ?? null;
            if (is_array($detail) && ($detail['redraft'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
