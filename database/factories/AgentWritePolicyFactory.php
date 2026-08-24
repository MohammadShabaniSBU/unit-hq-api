<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgentWritePolicy;
use App\Models\AiAgent;
use App\Support\Ai\Enums\WritePolicyMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentWritePolicy>
 */
class AgentWritePolicyFactory extends Factory
{
    protected $model = AgentWritePolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_agent_id' => AiAgent::factory(),
            'tool_key' => 'crm.create_task',
            'mode' => WritePolicyMode::Commit,
            'max_per_conversation' => null,
            'max_per_day' => null,
            'min_verification' => null,
            'updated_by_employee_id' => null,
        ];
    }

    public function off(): static
    {
        return $this->state(fn (): array => ['mode' => WritePolicyMode::Off]);
    }

    public function propose(): static
    {
        return $this->state(fn (): array => ['mode' => WritePolicyMode::Propose]);
    }
}
