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
 * @property string|null $caller_utterance
 * @property bool $transfer
 * @property string|null $destination
 * @property int|null $agent_conversation_message_id
 * @property int|null $latency_ms
 * @property int|null $round_trip_ms
 * @property bool $filler_spoken
 * @property bool $redrafted
 * @property bool $budget_exceeded
 * @property string|null $handoff_reason
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
        'caller_utterance',
        'transfer',
        'destination',
        'agent_conversation_message_id',
        'latency_ms',
        'round_trip_ms',
        'filler_spoken',
        'redrafted',
        'budget_exceeded',
        'handoff_reason',
    ];

    protected function casts(): array
    {
        return [
            'transfer' => 'boolean',
            'filler_spoken' => 'boolean',
            'redrafted' => 'boolean',
            'budget_exceeded' => 'boolean',
        ];
    }

    public static function findByTurnId(VoiceSession $session, string $turnId): ?self
    {
        return self::query()
            ->where('voice_session_id', $session->id)
            ->where('turn_id', $turnId)
            ->first();
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
