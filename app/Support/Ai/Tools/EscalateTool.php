<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\VerificationLevel;

final class EscalateTool implements AgentTool
{
    public function key(): string
    {
        return 'agent.escalate';
    }

    public function description(): string
    {
        return 'Hand this conversation to a human teammate. Use when the customer asks for a person, the request is outside your tools, or you cannot answer from tool results.';
    }

    public function schema(): array
    {
        return [
            'reason' => [
                'type' => 'string',
                'required' => true,
                'enum' => array_map(fn (HandoffReason $reason): string => $reason->value, HandoffReason::cases()),
                'description' => 'Handoff reason',
            ],
            'summary' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Short summary of why a human should take over',
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

    public function entityArguments(): array
    {
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $reason = HandoffReason::from((string) $arguments['reason']);
        $summary = (string) $arguments['summary'];

        return ToolResult::ok(
            [
                'reason' => $reason->value,
                'summary' => $summary,
            ],
            'Connecting you with a teammate who can help with this.',
            new FactBag,
            $reason,
        );
    }
}
