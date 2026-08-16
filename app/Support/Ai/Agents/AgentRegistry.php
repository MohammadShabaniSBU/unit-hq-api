<?php

declare(strict_types=1);

namespace App\Support\Ai\Agents;

use RuntimeException;

/**
 * Resolves `ai_agents.key` to a code definition (D-AI-6).
 */
final class AgentRegistry
{
    /** @var array<string, AgentDefinition> */
    private array $definitions = [];

    public function register(AgentDefinition $definition): void
    {
        $this->definitions[$definition->key()] = $definition;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function get(string $key): AgentDefinition
    {
        if (! isset($this->definitions[$key])) {
            throw new RuntimeException("Agent definition [{$key}] is not registered.");
        }

        return $this->definitions[$key];
    }

    public function for(string $key): AgentDefinition
    {
        return $this->get($key);
    }

    /**
     * @return array<string, AgentDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
