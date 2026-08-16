<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentToolCoverageTest extends TestCase
{
    #[Test]
    public function every_registered_tool_has_schema_description_and_appears_in_a_definition(): void
    {
        $tools = app(ToolRegistry::class)->all();
        $this->assertNotEmpty($tools);

        $claimed = [];
        foreach (app(AgentRegistry::class)->all() as $definition) {
            foreach ($definition->toolKeys() as $key) {
                $claimed[$key] = true;
            }
        }

        foreach ($tools as $key => $tool) {
            $this->assertNotSame('', $tool->description(), "Tool [{$key}] has an empty description.");
            $this->assertNotEmpty($tool->schema(), "Tool [{$key}] has an empty schema.");
            $this->assertArrayHasKey(
                $key,
                $claimed,
                "Tool [{$key}] is registered but claimed by no AgentDefinition.",
            );
        }
    }
}
