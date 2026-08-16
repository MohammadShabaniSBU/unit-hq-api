<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiAgent;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Tools\ToolRegistry;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentDefinitionCoverageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_seeded_agent_key_resolves_and_tool_keys_are_registered(): void
    {
        $this->seed(AiAgentSeeder::class);

        $registry = app(AgentRegistry::class);
        $tools = app(ToolRegistry::class);

        foreach (AiAgent::query()->pluck('key') as $key) {
            $definition = $registry->get($key);
            $this->assertSame($key, $definition->key());

            foreach ($definition->toolKeys() as $toolKey) {
                $this->assertTrue(
                    $tools->has($toolKey),
                    "Definition [{$key}] claims unregistered tool [{$toolKey}].",
                );
            }
        }
    }
}
