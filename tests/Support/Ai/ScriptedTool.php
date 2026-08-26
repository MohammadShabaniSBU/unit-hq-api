<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\AgentTool;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolResult;
use LogicException;

final class ScriptedTool implements AgentTool
{
    /** @var list<ToolResult> */
    public array $script = [];

    public int $calls = 0;

    public function __construct(private readonly string $key = 'test.script') {}

    public function key(): string
    {
        return $this->key;
    }

    public function description(): string
    {
        return 'Returns a scripted sequence of tool results.';
    }

    public function schema(): array
    {
        return [];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::Anonymous;
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function retainInSummary(): bool
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
        $this->calls++;
        if ($this->script === []) {
            throw new LogicException('ScriptedTool queue is empty.');
        }

        return array_shift($this->script) ?? ToolResult::ok(['ok' => true], 'ok', new FactBag);
    }
}
