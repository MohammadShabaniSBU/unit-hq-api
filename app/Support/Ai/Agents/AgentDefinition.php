<?php

declare(strict_types=1);

namespace App\Support\Ai\Agents;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Enums\HandoffRuleKey;
use App\Models\Contact;

interface AgentDefinition
{
    public function key(): string;

    public function systemPrompt(AgentContext $ctx): string;

    /**
     * Stable identifier for the prompt template in force (channel +
     * verification + role). Does not include site identity or civil date.
     */
    public function promptVersion(AgentContext $ctx): string;

    /**
     * @return list<string>
     */
    public function toolKeys(): array;

    /**
     * Extra rule keys on top of the shared config set.
     *
     * @return list<HandoffRuleKey>
     */
    public function handoffRules(): array;

    public function maxTurns(): int;

    /**
     * Extra patterns beyond the shared never-list.
     *
     * @return list<string>
     */
    public function forbiddenClaims(): array;

    /**
     * Whether this agent should answer this contact at this site.
     * Decline leaves the inbound unread in the Inbox.
     */
    public function eligible(?Contact $contact, ?int $siteId): bool;
}
