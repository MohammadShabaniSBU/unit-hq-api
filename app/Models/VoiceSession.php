<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Auth\Concerns\VisibleToEmployee;
use Database\Factories\VoiceSessionFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Customer-facing Vocal Bridge call mapped onto an agent conversation.
 * Not copilot_voice_sessions — that table is employee-principal and browser-side.
 *
 * @property int $id
 * @property string $bridge_session_id
 * @property int $agent_conversation_id
 * @property int $voice_bridge_token_id
 * @property string|null $caller_number
 * @property int|null $contact_id
 * @property int $site_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AgentConversation $conversation
 * @property-read VoiceBridgeToken $bridgeToken
 * @property-read Contact|null $contact
 * @property-read Site $site
 * @property-read Collection<int, VoiceSessionTurn> $turns
 */
class VoiceSession extends Model
{
    /** @use HasFactory<VoiceSessionFactory> */
    use HasFactory;

    use VisibleToEmployee;

    protected $fillable = [
        'bridge_session_id',
        'agent_conversation_id',
        'voice_bridge_token_id',
        'caller_number',
        'contact_id',
        'site_id',
        'started_at',
        'ended_at',
        'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AgentConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'agent_conversation_id');
    }

    /** @return BelongsTo<VoiceBridgeToken, $this> */
    public function bridgeToken(): BelongsTo
    {
        return $this->belongsTo(VoiceBridgeToken::class, 'voice_bridge_token_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<VoiceSessionTurn, $this> */
    public function turns(): HasMany
    {
        return $this->hasMany(VoiceSessionTurn::class);
    }
}
