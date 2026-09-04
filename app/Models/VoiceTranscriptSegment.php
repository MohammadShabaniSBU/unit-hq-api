<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One raw utterance on a voice session. Unverified speech — caller words,
 * the fast model's own turns, or a spoken delegated answer. Not
 * agent_conversation_messages: those rows have all passed GroundingGuard.
 *
 * @property int $id
 * @property int $voice_session_id
 * @property int $sequence
 * @property string $role
 * @property string $text
 * @property string $source
 * @property int|null $voice_session_turn_id
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read VoiceSession $session
 * @property-read VoiceSessionTurn|null $turn
 */
class VoiceTranscriptSegment extends Model
{
    protected $fillable = [
        'voice_session_id',
        'sequence',
        'role',
        'text',
        'source',
        'voice_session_turn_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<VoiceSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(VoiceSession::class, 'voice_session_id');
    }

    /** @return BelongsTo<VoiceSessionTurn, $this> */
    public function turn(): BelongsTo
    {
        return $this->belongsTo(VoiceSessionTurn::class, 'voice_session_turn_id');
    }
}
