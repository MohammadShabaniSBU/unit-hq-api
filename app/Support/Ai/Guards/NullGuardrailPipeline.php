<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Tools\FactBag;

/**
 * Always passes. S22-03 replaces this.
 *
 * S22-01 alone must not be demoed or evaluated as agent behaviour. The runtime
 * is correct; the agent is not yet safe. A plausible fake-driver draft is not
 * evidence that guardrails are unnecessary.
 */
final class NullGuardrailPipeline implements GuardrailPipeline
{
    public function check(string $draft, FactBag $facts, AgentContext $ctx): GuardrailVerdict
    {
        return GuardrailVerdict::pass();
    }
}
