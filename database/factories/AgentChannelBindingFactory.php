<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgentChannelBinding;
use App\Models\AiAgent;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentChannelBinding>
 */
class AgentChannelBindingFactory extends Factory
{
    protected $model = AgentChannelBinding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_agent_id' => AiAgent::factory(),
            'channel' => AgentChannel::Webchat,
            'site_id' => null,
            'mode' => BindingMode::Draft,
            'audience' => BindingAudience::KnownContacts,
            'outside_hours' => OutsideHoursPolicy::Inbox,
            'updated_by_employee_id' => null,
            'archived_at' => null,
        ];
    }

    public function off(): static
    {
        return $this->state(fn (): array => ['mode' => BindingMode::Off]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['mode' => BindingMode::Draft]);
    }

    public function auto(): static
    {
        return $this->state(fn (): array => ['mode' => BindingMode::Auto]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
