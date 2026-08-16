<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Tools\FactBag;

final class DuplicateDraftGuard implements OutboundGuard
{
    public function key(): string
    {
        return 'duplicate_draft';
    }

    public function check(string $draft, FactBag $facts, AgentContext $ctx): GuardrailVerdict
    {
        $previous = $ctx->conversation->messages()
            ->reorder()
            ->where('role', AgentMessageRole::Assistant->value)
            ->whereNull('blocked_by')
            ->orderByDesc('sequence')
            ->limit(2)
            ->pluck('content');

        foreach ($previous as $body) {
            if (! is_string($body) || $body === '') {
                continue;
            }
            if ($this->isNearDuplicate($draft, $body)) {
                return GuardrailVerdict::block(
                    'duplicate_draft',
                    HandoffReason::RepeatedFailure,
                    ['detail' => 'near_duplicate'],
                    [['guard' => $this->key(), 'verdict' => 'block', 'detail' => 'near_duplicate']],
                );
            }
        }

        return GuardrailVerdict::pass(events: [['guard' => $this->key(), 'verdict' => 'pass']]);
    }

    private function isNearDuplicate(string $a, string $b): bool
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $aCut = substr($a, 0, 255);
        $bCut = substr($b, 0, 255);
        $max = max(strlen($aCut), strlen($bCut));
        if ($max === 0) {
            return false;
        }

        return (levenshtein($aCut, $bCut) / $max) <= 0.1;
    }

    private function normalize(string $text): string
    {
        $folded = KeywordMatcher::fold($text);
        $collapsed = preg_replace('/\s+/u', ' ', $folded) ?? $folded;

        return trim($collapsed);
    }
}
