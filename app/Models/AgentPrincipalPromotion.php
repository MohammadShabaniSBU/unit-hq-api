<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Ai\Enums\VerificationLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One mid-conversation principal promotion in an agent reasoning trace. Append-only.
 *
 * @property int $id
 * @property int $agent_conversation_id
 * @property int|null $agent_conversation_message_id
 * @property int|null $turn
 * @property int $seq
 * @property VerificationLevel $from_level
 * @property VerificationLevel $to_level
 * @property string $method
 * @property string|null $model
 * @property string|null $prompt_version
 * @property Carbon $created_at
 * @property-read AgentConversation $conversation
 * @property-read AgentConversationMessage|null $message
 */
class AgentPrincipalPromotion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'agent_conversation_id',
        'agent_conversation_message_id',
        'turn',
        'seq',
        'from_level',
        'to_level',
        'method',
        'model',
        'prompt_version',
    ];

    protected function casts(): array
    {
        return [
            'from_level' => VerificationLevel::class,
            'to_level' => VerificationLevel::class,
        ];
    }

    /** @return BelongsTo<AgentConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'agent_conversation_id');
    }

    /** @return BelongsTo<AgentConversationMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(AgentConversationMessage::class, 'agent_conversation_message_id');
    }
}
