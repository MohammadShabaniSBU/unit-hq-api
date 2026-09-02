<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AgentConversation;
use App\Models\VoiceBridgeToken;
use App\Models\VoiceSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VoiceSession>
 */
class VoiceSessionFactory extends Factory
{
    protected $model = VoiceSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bridge_session_id' => Str::uuid()->toString(),
            'agent_conversation_id' => AgentConversation::factory(),
            'voice_bridge_token_id' => VoiceBridgeToken::factory(),
            'caller_number' => null,
            'contact_id' => null,
            'site_id' => function (array $attributes): int {
                return (int) VoiceBridgeToken::query()->findOrFail($attributes['voice_bridge_token_id'])->site_id;
            },
            'started_at' => now(),
            'ended_at' => null,
        ];
    }
}
