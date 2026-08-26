<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\DisclosureSentence;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\FactBag;

final class DisclosureGuard implements OutboundGuard
{
    public function __construct(
        private readonly DraftTokenExtractor $extractor,
    ) {}

    public function key(): string
    {
        return 'disclosure';
    }

    public function check(string $draft, FactBag $facts, AgentContext $ctx): GuardrailVerdict
    {
        if ($ctx->principal->audience !== AgentAudience::Customer) {
            return GuardrailVerdict::pass(events: [['guard' => $this->key(), 'verdict' => 'pass']]);
        }

        if (! $ctx->principal->verification->satisfies(VerificationLevel::Verified)) {
            $leak = $this->leakToken($draft, $facts, $ctx);
            if ($leak !== null) {
                return GuardrailVerdict::block(
                    'disclosure',
                    HandoffReason::VerificationRequired,
                    ['token' => $leak],
                    [['guard' => $this->key(), 'verdict' => 'block', 'detail' => ['token' => $leak]]],
                );
            }
        }

        $prompted = DisclosureSentence::isFirstCustomerTurn($ctx);
        $mutated = self::ensurePresent($draft, $ctx);
        $events = [['guard' => $this->key(), 'verdict' => 'pass']];
        if ($prompted) {
            $events[0]['detail'] = ['prompted' => true, 'appended' => $mutated !== $draft];
        }

        return GuardrailVerdict::pass($mutated !== $draft ? $mutated : null, $events);
    }

    public static function ensurePresent(string $draft, AgentContext $ctx): string
    {
        if (! DisclosureSentence::isFirstCustomerTurn($ctx)) {
            return $draft;
        }

        $locale = $ctx->conversation->locale ?? $ctx->principal->locale;
        $phrase = DisclosureSentence::for($locale);
        if ($phrase === '') {
            return $draft;
        }

        if (DisclosureSentence::isPresentIn($draft, $locale)) {
            return $draft;
        }

        $trimmed = ltrim($draft);

        return $trimmed === '' ? $phrase : $phrase.' '.$trimmed;
    }

    public static function appendIfNeeded(string $draft, AgentContext $ctx): string
    {
        return self::ensurePresent($draft, $ctx);
    }

    public static function phraseFor(string $locale): string
    {
        return DisclosureSentence::for($locale);
    }

    private function leakToken(string $draft, FactBag $facts, AgentContext $ctx): ?string
    {
        $licensed = FactBag::fromKeys($facts->all())->merge($facts);

        $prior = $ctx->conversation->messages()
            ->where('role', AgentMessageRole::Assistant->value)
            ->whereNull('blocked_by')
            ->orderBy('sequence')
            ->get();

        foreach ($prior as $message) {
            $keys = $message->fact_keys;
            $verification = $message->principal_verification;
            if (! is_array($keys) || ! is_string($verification) || $verification === '') {
                continue;
            }

            try {
                $priorLevel = VerificationLevel::from($verification);
            } catch (\ValueError) {
                continue;
            }

            if (! $ctx->principal->verification->satisfies($priorLevel)) {
                continue;
            }

            $licensed->merge(FactBag::fromKeys($keys));
        }

        $site = $this->site($ctx);
        foreach ($this->extractor->extract($draft, $site) as $token) {
            if (! in_array($token->type, [DraftToken::Money, DraftToken::Identifier, DraftToken::Date], true)) {
                continue;
            }
            if ($licensed->contains($token->normalized) || $licensed->contains($token->raw)) {
                continue;
            }

            return $token->raw;
        }

        return null;
    }

    private function site(AgentContext $ctx): ?Site
    {
        $siteId = $ctx->principal->siteId ?? $ctx->conversation->site_id;
        if ($siteId === null) {
            return null;
        }

        return Site::query()->find($siteId);
    }
}
