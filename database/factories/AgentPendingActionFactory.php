<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgentConversation;
use App\Models\AgentPendingAction;
use App\Models\AgentToolInvocation;
use App\Models\Site;
use App\Support\Ai\Enums\PendingActionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentPendingAction>
 */
class AgentPendingActionFactory extends Factory
{
    protected $model = AgentPendingAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $site = Site::factory();

        return [
            'agent_conversation_id' => AgentConversation::factory(),
            'agent_tool_invocation_id' => AgentToolInvocation::factory(),
            'ai_agent_id' => fn (array $attributes) => AgentConversation::query()
                ->findOrFail($attributes['agent_conversation_id'])
                ->ai_agent_id,
            'site_id' => $site,
            'tool_key' => 'test.propose',
            'payload' => fn (array $attributes): array => [
                'site_id' => $attributes['site_id'],
            ],
            'preview' => [],
            'status' => PendingActionStatus::Pending,
            'resolved_by_employee_id' => null,
            'resolved_at' => null,
            'rejection_reason' => null,
            'result_type' => null,
            'result_id' => null,
            'failure_reason' => null,
            'expires_at' => now()->addMinutes((int) config('agents.pending_action_ttl_minutes', 120)),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (AgentPendingAction $action): void {
            $invocation = AgentToolInvocation::query()->find($action->agent_tool_invocation_id);
            if ($invocation === null) {
                return;
            }

            $action->agent_conversation_id = $invocation->agent_conversation_id;
            $action->ai_agent_id = $invocation->conversation()->value('ai_agent_id') ?? $action->ai_agent_id;
            $action->tool_key = $invocation->tool_key;
        });
    }
}
