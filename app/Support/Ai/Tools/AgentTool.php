<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;

interface AgentTool
{
    public function key(): string;

    public function description(): string;

    /**
     * Argument schema keyed by name.
     *
     * @return array<string, array{type: string, required?: bool, enum?: list<string>, description?: string}>
     */
    public function schema(): array;

    public function requiredVerification(): VerificationLevel;

    public function isWrite(): bool;

    /**
     * Argument keys that must identify the principal's contact.
     *
     * @return list<string>
     */
    public function contactScopedArgumentKeys(): array;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(AgentPrincipal $principal, array $arguments): ToolResult;
}
