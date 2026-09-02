<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ai\Drivers;

use App\Support\Ai\AiProviderRegistry;
use App\Support\Ai\Drivers\LaravelAiDriver;
use App\Support\Ai\Drivers\ProviderToolName;
use Laravel\Ai\AiManager;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolResult;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class LaravelAiDriverTest extends TestCase
{
    #[Test]
    public function tool_messages_with_json_ready_empty_bags_become_sdk_tool_results(): void
    {
        [$instructions, $sdk] = $this->toSdkMessages([
            [
                'role' => 'tool',
                'tool_call_id' => 'call-1',
                'tool_name' => 'facility.availability',
                'arguments' => new \stdClass,
                'content' => '{}',
            ],
        ]);

        $this->assertNull($instructions);
        $this->assertCount(1, $sdk);
        $this->assertInstanceOf(ToolResultMessage::class, $sdk[0]);

        $result = $sdk[0]->toolResults->first();
        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame([], $result->arguments);
        $this->assertSame('call-1', $result->id);
        $this->assertSame('facility_availability', $result->name);
    }

    #[Test]
    public function assistant_tool_calls_with_stdclass_arguments_become_sdk_tool_calls(): void
    {
        [$instructions, $sdk] = $this->toSdkMessages([
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call-2',
                    'name' => 'facility.size_guide',
                    'arguments' => new \stdClass,
                ]],
            ],
        ]);

        $this->assertNull($instructions);
        $this->assertInstanceOf(AssistantMessage::class, $sdk[0]);
        $this->assertSame([], $sdk[0]->toolCalls->first()->arguments);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array{0: ?string, 1: list<object>}
     */
    private function toSdkMessages(array $messages): array
    {
        $driver = new LaravelAiDriver(app(AiManager::class), app(AiProviderRegistry::class));
        $method = new ReflectionMethod(LaravelAiDriver::class, 'toSdkMessages');

        /** @var array{0: ?string, 1: list<object>} $mapped */
        $mapped = $method->invoke($driver, $messages, ProviderToolName::fromTools([]));

        return $mapped;
    }
}
