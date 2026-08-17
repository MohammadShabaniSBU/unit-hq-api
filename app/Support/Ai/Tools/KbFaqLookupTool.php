<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Knowledge\KnowledgeBase;

final class KbFaqLookupTool implements AgentTool
{
    public function key(): string
    {
        return 'kb.faq_lookup';
    }

    public function description(): string
    {
        return 'Look up a curated FAQ snippet by key. No free-text search. Returns not_found rather than improvising policy.';
    }

    public function schema(): array
    {
        return [
            'key' => [
                'type' => 'string',
                'required' => true,
                'description' => 'FAQ key from the curated list (access_hours, insurance_required, notice_period, prohibited_items, overlock_policy, deposit, id_required, payment_methods)',
            ],
            'site_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Optional site for per-site override',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::Anonymous;
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $key = (string) $arguments['key'];
        if (! in_array($key, KnowledgeBase::KEYS, true)) {
            return ToolResult::notFound("No FAQ snippet for key [{$key}].");
        }

        $siteId = isset($arguments['site_id']) ? (int) $arguments['site_id'] : $principal->siteId;
        $site = $siteId !== null ? Site::query()->find($siteId) : null;
        $snippet = KnowledgeBase::snippet($key, $principal->locale, $site);
        if ($snippet === null) {
            return ToolResult::notFound("No FAQ snippet for key [{$key}].");
        }

        return ToolResult::ok(
            [
                'key' => $key,
                'snippet' => $snippet,
            ],
            $snippet,
            (new FactBag)->absorb($snippet, $site),
        );
    }
}
