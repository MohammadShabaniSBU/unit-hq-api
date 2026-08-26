<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Enums\ForbiddenClaimKey;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Tools\FactBag;

final class ForbiddenClaimGuard implements OutboundGuard
{
    public function key(): string
    {
        return 'forbidden_claim';
    }

    public function check(string $draft, FactBag $facts, AgentContext $ctx): GuardrailVerdict
    {
        $locale = $this->localeKey($ctx->conversation->locale ?? $ctx->principal->locale);
        $lists = config("ai-handoff.forbidden_claims.{$locale}");
        if (! is_array($lists)) {
            $lists = config('ai-handoff.forbidden_claims.en', []);
        }

        foreach ($lists as $class => $phrases) {
            if (! is_array($phrases)) {
                continue;
            }
            $key = ForbiddenClaimKey::tryFrom((string) $class);
            if ($key !== null && in_array($key, $ctx->licensedClaims, true)) {
                continue;
            }
            /** @var list<string> $phrases */
            $matched = KeywordMatcher::firstMatch($draft, $phrases);
            if ($matched !== null) {
                return $key !== null
                    ? $this->redraft($key, $matched, $locale)
                    : $this->block((string) $class, $matched);
            }
        }

        $extras = $ctx->definition->forbiddenClaims();
        if ($extras !== []) {
            $matched = KeywordMatcher::firstMatch($draft, $extras);
            if ($matched !== null) {
                return $this->block('extra', $matched);
            }
        }

        return GuardrailVerdict::pass(events: [['guard' => $this->key(), 'verdict' => 'pass']]);
    }

    private function redraft(ForbiddenClaimKey $key, string $matched, string $locale): GuardrailVerdict
    {
        $alternative = CannedReply::licensedAlternative($key, $locale);
        $instruction = "The draft claimed \"{$matched}\", which is not licensed this turn. Rewrite without that claim. A licensed alternative is: {$alternative}";

        return GuardrailVerdict::retry(
            $instruction,
            'forbidden_claim',
            HandoffReason::UnsupportedIntent,
            ['claim' => $key->value, 'matched' => $matched],
            [['guard' => $this->key(), 'verdict' => 'deny', 'detail' => ['claim' => $key->value, 'matched' => $matched]]],
        );
    }

    private function block(string $class, string $matched): GuardrailVerdict
    {
        return GuardrailVerdict::block(
            'forbidden_claim',
            HandoffReason::UnsupportedIntent,
            ['claim' => $class, 'matched' => $matched],
            [['guard' => $this->key(), 'verdict' => 'block', 'detail' => ['claim' => $class, 'matched' => $matched]]],
        );
    }

    private function localeKey(string $locale): string
    {
        $base = strtolower(str_replace('_', '-', $locale));
        $base = explode('-', $base)[0];

        return in_array($base, ['en', 'es', 'fr'], true) ? $base : 'en';
    }
}
