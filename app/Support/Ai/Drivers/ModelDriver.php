<?php

declare(strict_types=1);

namespace App\Support\Ai\Drivers;

use App\Support\Ai\Tools\AgentTool;
use Closure;

interface ModelDriver
{
    /**
     * Single provider turn. Must not execute AgentTool::handle().
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<AgentTool>  $tools
     */
    public function stream(array $messages, array $tools, string $model, ?Closure $onDelta, ?int $timeoutSeconds = null): ModelResponse;
}
