<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Tools\FactBag;

interface OutboundGuard
{
    public function key(): string;

    public function check(string $draft, FactBag $facts, AgentContext $ctx): GuardrailVerdict;
}
