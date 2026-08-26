<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentWritePolicy;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Tools\EntityArgumentExemptions;
use App\Support\Ai\Tools\ToolRegistry;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentToolCoverageTest extends TestCase
{
    use RefreshDatabase;

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

    #[Test]
    public function every_write_policy_tool_key_resolves_in_the_registry(): void
    {
        $this->seed(AiAgentSeeder::class);

        $registry = app(ToolRegistry::class);
        foreach (AgentWritePolicy::query()->pluck('tool_key') as $key) {
            $this->assertTrue(
                $registry->has($key),
                "agent_write_policies.tool_key [{$key}] is not registered in ToolRegistry.",
            );
        }
    }

    #[Test]
    public function every_propose_mode_policy_resolves_to_a_proposable_tool(): void
    {
        $this->seed(AiAgentSeeder::class);

        $registry = app(ToolRegistry::class);
        foreach (AgentWritePolicy::query()->where('mode', 'propose')->pluck('tool_key') as $key) {
            $this->assertTrue($registry->has($key), "Propose policy tool [{$key}] is not registered.");
            $this->assertInstanceOf(
                \App\Support\Ai\Tools\ProposableTool::class,
                $registry->get($key),
                "Propose policy tool [{$key}] must implement ProposableTool.",
            );
        }
    }

    #[Test]
    public function every_schema_id_argument_is_declared_or_exempt(): void
    {
        foreach (app(ToolRegistry::class)->all() as $key => $tool) {
            $declared = $tool->entityArguments();
            foreach ($this->schemaIdKeys($tool->schema()) as $idKey) {
                $this->assertTrue(
                    array_key_exists($idKey, $declared) || EntityArgumentExemptions::contains($idKey),
                    "Tool [{$key}] argument [{$idKey}] must be in entityArguments() or EntityArgumentExemptions.",
                );
            }
        }
    }

    #[Test]
    public function every_tool_that_puts_money_in_its_fact_bag_opts_into_the_summary_or_is_exempt(): void
    {
        $exempt = [
            'billing.balance',
            'billing.invoices',
            'billing.next_charge',
            'contract.summary',
        ];

        foreach (app(ToolRegistry::class)->all() as $key => $tool) {
            $path = (new \ReflectionClass($tool))->getFileName();
            $this->assertNotFalse($path);
            $source = (string) file_get_contents($path);
            if (! str_contains($source, '->money(')) {
                continue;
            }

            $this->assertTrue(
                $tool->retainInSummary() || in_array($key, $exempt, true),
                "Tool [{$key}] puts money in FactBag but neither retainInSummary() nor the exemption list.",
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @return list<string>
     */
    private function schemaIdKeys(array $schema): array
    {
        $keys = [];
        foreach ($schema as $name => $rules) {
            if (str_ends_with($name, '_id')) {
                $keys[] = $name;
            }
            $items = $rules['items'] ?? null;
            if (! is_array($items) || array_is_list($items)) {
                continue;
            }
            foreach ($items as $itemName => $itemRules) {
                if (is_string($itemName) && str_ends_with($itemName, '_id')) {
                    $keys[] = $itemName;
                }
            }
        }

        return $keys;
    }
}
