<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\AgentTool;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolResult;

final class RecordingTool implements AgentTool
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function key(): string
    {
        return 'test.record';
    }

    public function description(): string
    {
        return 'Records invocations and returns a grounded money figure.';
    }

    public function schema(): array
    {
        return [
            'label' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional label',
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
        $this->calls[] = $arguments;

        return ToolResult::ok(
            ['amount' => '84.70', 'currency' => 'EUR'],
            '€84,70 (incl. 21% IVA)',
            (new FactBag)->money('84.70', 'EUR'),
        );
    }
}
