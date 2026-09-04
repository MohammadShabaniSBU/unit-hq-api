<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentConversation;
use App\Models\AgentHandoff;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\VoiceBridgeToken;
use App\Models\VoiceSession;
use App\Models\VoiceSessionTurn;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\HandoffTriggerSource;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use App\Support\Time\SiteClock;
use Illuminate\Database\UniqueConstraintViolationException;
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
        private readonly VoiceSessionOpener $opener,
    ) {}

    /**
     * @return array{text: string, transfer: bool, destination?: string}
     */
    public function handle(VoiceBridgeInboundTurn $inbound, VoiceBridgeToken $token): array
    {
        $site = Site::query()->find($token->site_id);
        $key = 'voice-bridge|'.$token->id;
        $max = (int) config('agents.voice.bridge_rate_per_minute', 60);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            SystemEvent::record('ai.voice.bridge_throttled', $token, []);

            return $this->handoffBody($site);
        }

        RateLimiter::hit($key, 60);

        $query = $inbound->query;
        $turnId = $inbound->turnId;
        $sessionId = $inbound->sessionId;
        $callerNumber = $inbound->callerNumber;
        $callerUtterance = $inbound->callerUtterance;

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

        $session = $this->opener->open($token, $sessionId, $callerNumber);
        if ($session === null) {
            return $this->handoffBody($site);
        }

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
            return $this->outsideHoursInbox($session, $turnId, $site, callerUtterance: $callerUtterance);
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
                callerUtterance: $callerUtterance,
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
                callerUtterance: $callerUtterance,
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
                callerUtterance: $callerUtterance,
            );
        }

        $text = trim($turn->draft);
        if ($text === '') {
            SystemEvent::record('ai.voice.turn_failed', $session, [
                'error' => $turn->blockedBy ?? 'empty_draft',
            ]);

            return $this->persistHandoff(
                $session,
                $turnId,
                $turn->emittedMessageId,
                $site,
                $elapsedMs,
                $redrafted,
                callerUtterance: $callerUtterance,
            );
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
                callerUtterance: $callerUtterance,
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
            callerUtterance: $callerUtterance,
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
    private function outsideHoursInbox(VoiceSession $session, string $turnId, Site $site, ?string $callerUtterance = null): array
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
            callerUtterance: $callerUtterance,
        );
    }

    private function outsideHours(Site $site): bool
    {
        $settings = Setting::general();

        return ! SiteClock::withinWindow($site, $settings->sendWindowStart, $settings->sendWindowEnd);
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
        ?string $callerUtterance = null,
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
                callerUtterance: $callerUtterance,
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
            callerUtterance: $callerUtterance,
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
        ?string $callerUtterance = null,
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
            callerUtterance: $callerUtterance,
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
        ?string $callerUtterance = null,
    ): array {
        try {
            $row = VoiceSessionTurn::query()->create([
                'voice_session_id' => $session->id,
                'turn_id' => $turnId,
                'answer_text' => $text,
                'caller_utterance' => $callerUtterance,
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
