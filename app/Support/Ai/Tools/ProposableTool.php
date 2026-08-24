<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Leasing\LeasingActor;

/**
 * Write tool that can dry-run (propose) then persist (commit) under a given actor.
 *
 * payload = stable write inputs (ids, catalogue refs). Never now(), defaultExpiry(),
 * availability counts, or rendered display. preview holds those derived values
 * and is never compared on approve.
 *
 * Unresolvable site → non-ok ToolResult. Never ok without a site_id in payload.
 */
interface ProposableTool extends AgentTool
{
    /**
     * Validate against current state and build payload + preview. No writes.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function propose(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult;

    /**
     * Persist using the given actor. handle() uses agent; approval uses employee.
     *
     * @param  array<string, mixed>  $payload
     */
    public function commit(
        LeasingActor $actor,
        array $payload,
        AgentPrincipal $principal,
        ?AgentContext $ctx = null,
    ): ToolResult;
}
