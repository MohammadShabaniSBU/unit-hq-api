<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Tools\FactBag;

final class GroundingGuard implements OutboundGuard
{
    public function __construct(
        private readonly DraftTokenExtractor $extractor,
    ) {}

    public function key(): string
    {
        return 'grounding';
    }

    public function check(string $draft, FactBag $facts, AgentContext $ctx): GuardrailVerdict
    {
        $licensed = FactBag::fromKeys($facts->all())->merge($facts);
        foreach ($ctx->conversation->messages()
            ->where('role', AgentMessageRole::Assistant->value)
            ->whereNull('blocked_by')
            ->orderBy('sequence')
            ->get() as $message) {
            if (is_array($message->fact_keys) && $message->fact_keys !== []) {
                $licensed->merge(FactBag::fromKeys($message->fact_keys));
            }
        }

        $site = $this->site($ctx);

        foreach ($this->extractor->extract($draft, $site) as $token) {
            $ok = $token->type === DraftToken::Percent
                ? $licensed->containsPercent($token->normalized)
                : ($licensed->contains($token->normalized) || $licensed->contains($token->raw));

            if (! $ok) {
                return GuardrailVerdict::block(
                    'grounding',
                    HandoffReason::GroundingFailure,
                    ['token' => $token->raw],
                    [['guard' => $this->key(), 'verdict' => 'block', 'detail' => ['token' => $token->raw]]],
                );
            }
        }

        return GuardrailVerdict::pass(events: [['guard' => $this->key(), 'verdict' => 'pass']]);
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
