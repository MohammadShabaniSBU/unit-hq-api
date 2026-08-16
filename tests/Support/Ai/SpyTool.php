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

final class SpyTool implements AgentTool
{
    public bool $handleCalled = false;

    public function __construct(
        private readonly string $key = 'test.spy',
        private readonly VerificationLevel $required = VerificationLevel::Verified,
        /** @var list<string> */
        private readonly array $contactKeys = ['contact_id'],
        private readonly bool $throwOnHandle = true,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function description(): string
    {
        return 'Spy tool for dispatch tests.';
    }

    public function schema(): array
    {
        return [
            'contact_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Contact id',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return $this->required;
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function contactScopedArgumentKeys(): array
    {
        return $this->contactKeys;
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $this->handleCalled = true;

        if ($this->throwOnHandle) {
            throw new LogicException('SpyTool::handle() must not be called.');
        }

        return ToolResult::ok(['ok' => true], 'spy ok', new FactBag);
    }
}
