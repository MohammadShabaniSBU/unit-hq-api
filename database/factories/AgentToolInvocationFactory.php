<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgentConversation;
use App\Models\AgentToolInvocation;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentToolInvocation>
 */
class AgentToolInvocationFactory extends Factory
{
    protected $model = AgentToolInvocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_conversation_id' => AgentConversation::factory(),
            'agent_conversation_message_id' => null,
            'tool_key' => 'test.propose',
            'arguments' => [],
            'result' => null,
            'result_summary' => null,
            'status' => ToolInvocationStatus::Denied,
            'denied_reason' => ToolDeniedReason::RequiresApproval,
            'required_verification' => null,
            'principal_verification' => null,
            'duration_ms' => null,
            'idempotency_key' => null,
            'result_type' => null,
            'result_id' => null,
            'fact_keys' => null,
        ];
    }
}
