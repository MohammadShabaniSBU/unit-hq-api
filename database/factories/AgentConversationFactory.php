<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Models\Contact;
use App\Models\Employee;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\VerificationLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentConversation>
 */
class AgentConversationFactory extends Factory
{
    protected $model = AgentConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_agent_id' => AiAgent::factory(),
            'audience' => AgentAudience::Customer,
            'origin' => AgentOrigin::Demo,
            'channel' => AgentChannel::Webchat,
            'employee_id' => null,
            'created_by_employee_id' => Employee::factory(),
            'contact_id' => Contact::factory(),
            'site_id' => null,
            'verification_level' => VerificationLevel::Verified,
            'state' => ConversationState::Active,
            'locale' => 'en',
            'message_thread_id' => null,
            'last_turn_at' => null,
            'closed_at' => null,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn (): array => [
            'audience' => AgentAudience::Internal,
            'channel' => AgentChannel::Internal,
            'origin' => AgentOrigin::Demo,
            'employee_id' => Employee::factory(),
            'contact_id' => null,
            'verification_level' => VerificationLevel::Anonymous,
        ]);
    }

    public function anonymous(): static
    {
        return $this->state(fn (): array => [
            'audience' => AgentAudience::Customer,
            'verification_level' => VerificationLevel::Anonymous,
            'contact_id' => null,
            'employee_id' => null,
        ]);
    }
}
