<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VoiceSession;
use App\Models\VoiceSessionTurn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VoiceSessionTurn>
 */
class VoiceSessionTurnFactory extends Factory
{
    protected $model = VoiceSessionTurn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'voice_session_id' => VoiceSession::factory(),
            'turn_id' => Str::uuid()->toString(),
            'answer_text' => 'Let me put you through to someone who can help.',
            'transfer' => false,
            'agent_conversation_message_id' => null,
        ];
    }
}
