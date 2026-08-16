<?php

declare(strict_types=1);

namespace App\Support\Ai\Agents;

use RuntimeException;

/**
 * Resolves `ai_agents.key` to a code definition. Empty until S22-01 registers agents.
 */
final class AgentRegistry
{
    public static function get(string $key): AgentDefinition
    {
        throw new RuntimeException("Agent definition [{$key}] is not registered.");
    }
}
