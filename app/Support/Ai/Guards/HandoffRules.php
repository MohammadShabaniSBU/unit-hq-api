<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Models\AgentConversation;
use App\Models\Delinquency;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Enums\HandoffRuleKey;

final class HandoffRules implements HandoffEvaluator
{
    public function __construct(
        private readonly AgentRegistry $agents,
    ) {}

    public function match(AgentConversation $conversation, AgentPrincipal $principal, string $input): ?HandoffMatch
    {
        if ($this->hasOpenDelinquency($principal)) {
            return new HandoffMatch(
                HandoffRuleKey::Delinquency->reason(),
                CannedReply::Handoff,
                ['rule' => HandoffRuleKey::Delinquency->value, 'matched' => 'open_delinquency'],
            );
        }

        $conversation->loadMissing('aiAgent');
        $locale = $this->localeKey($conversation->locale ?? $principal->locale);
        $lists = config("ai-handoff.rules.{$locale}");
        if (! is_array($lists)) {
            $lists = config('ai-handoff.rules.en', []);
        }

        $keys = HandoffRuleKey::cases();
        $definitionKeys = [];
        if ($this->agents->has($conversation->aiAgent->key)) {
            $definitionKeys = $this->agents->get($conversation->aiAgent->key)->handoffRules();
        }

        foreach (array_values(array_unique([...$keys, ...$definitionKeys], SORT_REGULAR)) as $key) {
            if (! $key instanceof HandoffRuleKey) {
                continue;
            }

            $keywords = $lists[$key->value] ?? [];
            if (! is_array($keywords) || $keywords === []) {
                continue;
            }

            /** @var list<string> $keywords */
            $matched = KeywordMatcher::firstMatch($input, $keywords);
            if ($matched !== null) {
                return new HandoffMatch(
                    $key->reason(),
                    CannedReply::Handoff,
                    ['rule' => $key->value, 'matched' => $matched],
                );
            }
        }

        return null;
    }

    private function hasOpenDelinquency(AgentPrincipal $principal): bool
    {
        if ($principal->contactId === null) {
            return false;
        }

        return Delinquency::query()
            ->open()
            ->whereHas('contract', fn ($query) => $query->where('contact_id', $principal->contactId))
            ->exists();
    }

    private function localeKey(string $locale): string
    {
        $base = strtolower(str_replace('_', '-', $locale));
        $base = explode('-', $base)[0];

        return in_array($base, ['en', 'es', 'fr'], true) ? $base : 'en';
    }
}
