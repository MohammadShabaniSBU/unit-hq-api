<?php

declare(strict_types=1);

namespace App\Support\Ai\Drivers;

use App\Support\Ai\Tools\AgentTool;
use LogicException;

/**
 * Anthropic / OpenAI tool names must match ^[a-zA-Z0-9_-]{1,64}$.
 * Catalogue keys stay dotted; the live driver maps at the wire boundary.
 * Reverse is an index, not str_replace — kb.faq_lookup is not invertible.
 */
final class ProviderToolName
{
    /**
     * @param  array<string, string>  $toWire
     * @param  array<string, string>  $fromWire
     */
    private function __construct(
        private readonly array $toWire,
        private readonly array $fromWire,
    ) {}

    /**
     * @param  list<AgentTool>  $tools
     */
    public static function fromTools(array $tools): self
    {
        $toWire = [];
        $fromWire = [];

        foreach ($tools as $tool) {
            $key = $tool->key();
            $wire = str_replace('.', '_', $key);

            if (isset($fromWire[$wire]) && $fromWire[$wire] !== $key) {
                throw new LogicException(
                    "Provider tool name collision: [{$wire}] maps from [{$fromWire[$wire]}] and [{$key}].",
                );
            }

            $toWire[$key] = $wire;
            $fromWire[$wire] = $key;
        }

        return new self($toWire, $fromWire);
    }

    public function toWire(string $key): string
    {
        return $this->toWire[$key] ?? str_replace('.', '_', $key);
    }

    public function fromWire(string $name): string
    {
        return $this->fromWire[$name] ?? $name;
    }
}
