<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use RuntimeException;

final class ToolRegistry
{
    /** @var array<string, AgentTool> */
    private array $tools = [];

    public function register(AgentTool $tool): void
    {
        $this->tools[$tool->key()] = $tool;
    }

    public function has(string $key): bool
    {
        return isset($this->tools[$key]);
    }

    public function get(string $key): AgentTool
    {
        if (! isset($this->tools[$key])) {
            throw new RuntimeException("Agent tool [{$key}] is not registered.");
        }

        return $this->tools[$key];
    }

    /**
     * @return array<string, AgentTool>
     */
    public function all(): array
    {
        return $this->tools;
    }
}
