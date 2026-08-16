<?php

declare(strict_types=1);

namespace App\Support\Ai\Agents;

use App\Support\Ai\AgentContext;

interface AgentDefinition
{
    public function key(): string;

    public function systemPrompt(AgentContext $ctx): string;

    /**
     * @return list<string>
     */
    public function toolKeys(): array;

    /**
     * @return list<mixed>
     */
    public function handoffRules(): array;

    public function maxTurns(): int;

    /**
     * Extra patterns beyond the shared never-list.
     *
     * @return list<string>
     */
    public function forbiddenClaims(): array;
}
