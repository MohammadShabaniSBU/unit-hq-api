<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VoiceSessionTurnFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Idempotency store for one Vocal Bridge turn_id on a voice session.
 *
 * @property int $id
 * @property int $voice_session_id
 * @property string $turn_id
 * @property string $answer_text
 * @property bool $transfer
 * @property string|null $destination
 * @property int|null $agent_conversation_message_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read VoiceSession $session
 * @property-read AgentConversationMessage|null $conversationMessage
 */
class VoiceSessionTurn extends Model
{
    /** @use HasFactory<VoiceSessionTurnFactory> */
    use HasFactory;

    protected $fillable = [
        'voice_session_id',
        'turn_id',
        'answer_text',
        'transfer',
        'destination',
        'agent_conversation_message_id',
    ];

    protected function casts(): array
    {
        return [
            'transfer' => 'boolean',
        ];
    }

    /** @return BelongsTo<VoiceSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(VoiceSession::class, 'voice_session_id');
    }

    /** @return BelongsTo<AgentConversationMessage, $this> */
    public function conversationMessage(): BelongsTo
    {
        return $this->belongsTo(AgentConversationMessage::class, 'agent_conversation_message_id');
    }
}
