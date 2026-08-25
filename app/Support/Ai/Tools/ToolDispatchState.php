<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\AgentWritePolicy;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Agents\AgentDefinition;
use LogicException;

final class ToolDispatchState
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly AgentDefinition $definition,
        public readonly AgentPrincipal $principal,
        public readonly string $toolKey,
        public array $arguments,
        public readonly ?AgentContext $ctx,
        public ?AgentTool $tool = null,
        public ?AgentWritePolicy $policy = null,
    ) {}

    public function tool(): AgentTool
    {
        if ($this->tool === null) {
            throw new LogicException("Dispatch state has no tool for [{$this->toolKey}].");
        }

        return $this->tool;
    }
}
