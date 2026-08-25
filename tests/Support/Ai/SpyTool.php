<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\AgentTool;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolResult;
use LogicException;

final class SpyTool implements AgentTool
{
    public bool $handleCalled = false;

    /** @var array<string, mixed> */
    public array $lastArguments = [];

    /**
     * @param  list<string>  $contactKeys
     * @param  array<string, array<string, mixed>>  $schema
     * @param  array<string, EntityType|string>  $entityArguments
     */
    public function __construct(
        private readonly string $key = 'test.spy',
        private readonly VerificationLevel $required = VerificationLevel::Verified,
        private readonly array $contactKeys = ['contact_id'],
        private readonly bool $throwOnHandle = true,
        private readonly bool $write = false,
        private readonly array $schema = [],
        private readonly array $entityArguments = [],
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
        if ($this->schema !== []) {
            return $this->schema;
        }

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
        return $this->write;
    }

    public function contactScopedArgumentKeys(): array
    {
        return $this->contactKeys;
    }

    public function entityArguments(): array
    {
        return $this->entityArguments;
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $this->handleCalled = true;
        $this->lastArguments = $arguments;

        if ($this->throwOnHandle) {
            throw new LogicException('SpyTool::handle() must not be called.');
        }

        return ToolResult::ok(['ok' => true], 'spy ok', new FactBag, resultType: 'task', resultId: 1);
    }
}
