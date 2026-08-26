<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ProposableTool;
use App\Support\Ai\Tools\ToolResult;
use App\Support\Leasing\LeasingActor;
use LogicException;

final class ProposableSpyTool implements ProposableTool
{
    public bool $handleCalled = false;

    public bool $proposeCalled = false;

    public bool $commitCalled = false;

    /**
     * @param  list<string>  $contactKeys
     */
    public function __construct(
        private readonly string $key = 'test.spy',
        private readonly VerificationLevel $required = VerificationLevel::Verified,
        private readonly array $contactKeys = ['contact_id'],
        private readonly ?int $siteId = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function description(): string
    {
        return 'Proposable spy tool.';
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
        return true;
    }

    public function retainInSummary(): bool
    {
        return false;
    }

    public function contactScopedArgumentKeys(): array
    {
        return $this->contactKeys;
    }

    public function entityArguments(): array
    {
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $this->handleCalled = true;

        throw new LogicException('ProposableSpyTool::handle() must not be called.');
    }

    public function propose(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $this->proposeCalled = true;

        if ($this->siteId === null || $this->siteId <= 0) {
            return ToolResult::error('Site could not be resolved for this proposal.');
        }

        return ToolResult::ok(
            [
                'payload' => [
                    'site_id' => $this->siteId,
                    'contact_id' => $arguments['contact_id'] ?? null,
                ],
                'preview' => [
                    'expires_at' => now()->toIso8601String(),
                ],
            ],
            '',
            new FactBag,
        );
    }

    public function commit(
        LeasingActor $actor,
        array $payload,
        AgentPrincipal $principal,
        ?AgentContext $ctx = null,
    ): ToolResult {
        $this->commitCalled = true;

        throw new LogicException('ProposableSpyTool::commit() must not be called.');
    }
}
